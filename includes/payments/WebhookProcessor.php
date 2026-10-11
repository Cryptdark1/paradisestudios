<?php

/**
 * Paradise Studios - inbound webhook processing.
 *
 * A webhook is an UNTRUSTED notification. It is a hint that something happened,
 * not proof. This class therefore:
 *
 *   1. verifies the HMAC-SHA512 signature over the EXACT raw request body;
 *   2. records the event, including ones it rejects, so there is an audit trail;
 *   3. resolves OUR payment from OUR reference - never from the booking id in
 *      the payload, which an attacker controls;
 *   4. re-verifies the transaction with the provider rather than trusting the
 *      payload's amount, currency or status;
 *   5. applies the change through PaymentService, which is idempotent.
 *
 * A webhook can never mark a booking paid on its own authority: step 4 means
 * even a correctly signed event is only acted on once the provider is asked
 * directly.
 *
 * ORDERING AND DUPLICATION
 *   Both are handled by recording the event first and relying on two unique
 *   constraints:
 *     - UNIQUE fingerprint  catches a byte-identical replay;
 *     - UNIQUE (provider, event_id) catches the same logical event arriving
 *       under a different envelope.
 *   A late event for an already-succeeded payment is reported as a duplicate
 *   rather than re-applying the change.
 */

require_once __DIR__ . '/PaymentService.php';
require_once __DIR__ . '/RefundService.php';
require_once __DIR__ . '/OwnerTransferService.php';

if (!defined('PS_WEBHOOK_LOADED')) {
    define('PS_WEBHOOK_LOADED', true);

    final class PaymentWebhookProcessor
    {
        /**
         * Events this application acts on. Anything else is acknowledged and
         * ignored, which stops the provider retrying forever for something we
         * deliberately do not handle.
         */
        private const SUPPORTED_EVENTS = [
            'charge.success',
            'charge.failed',
            'charge.abandoned',
            'charge.refunded',
            'refund.succeeded',
            'refund.failed',
            'refund.processed',
            'transfer.success',
            'transfer.failed',
            'transfer.reversed',
        ];

        public function __construct(
            private PDO $pdo,
            private PaymentService $payments,
            private ?RefundService $refunds = null,
            private ?OwnerTransferService $transfers = null
        ) {
            $provider = ps_payment_provider();
            $this->refunds ??= new RefundService($pdo, new FinancialLedger($pdo), $provider);
            if ($provider instanceof PayoutProviderInterface) {
                $this->transfers ??= new OwnerTransferService($pdo, $provider, new FinancialLedger($pdo));
            }
        }

        /**
         * Handle one raw webhook.
         *
         * @param string      $rawBody   the exact bytes received, never re-encoded
         * @param string|null $signature the X-Paystack-Signature header value
         *
         * @return array{status:string, http_status:int, event_id:?string}
         */
        public function handle(string $rawBody, ?string $signature): array
        {
            $provider = ps_payment_provider();

            // 1. Signature. Nothing else may happen before this succeeds.
            if (!$this->signatureIsValid($rawBody, $signature)) {
                $this->record(
                    fingerprint: $this->fingerprint($rawBody),
                    eventId: null,
                    eventType: null,
                    signatureValid: false,
                    status: 'invalid_signature',
                    payload: $this->sanitise($rawBody),
                    errorMessage: 'Signature verification failed.',
                    httpStatus: 401
                );

                return [
                    'status' => 'invalid_signature',
                    'http_status' => 401,
                    'event_id' => null,
                ];
            }

            $decoded = json_decode($rawBody, true);

            if (!is_array($decoded)) {
                $this->record(
                    fingerprint: $this->fingerprint($rawBody),
                    eventId: null,
                    eventType: null,
                    signatureValid: true,
                    status: 'failed',
                    payload: $this->sanitise($rawBody),
                    errorMessage: 'Body was not valid JSON.',
                    httpStatus: 400
                );

                return ['status' => 'failed', 'http_status' => 400, 'event_id' => null];
            }

            $eventType = (string) ($decoded['event'] ?? '');
            $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

            /*
             * Our own reference. Only this maps an event onto a payment - the
             * payload's amount, currency and status are deliberately ignored
             * here and re-fetched from the provider in step 4.
             */
            $candidateReference = $data['reference'] ?? ($data['transaction']['reference'] ?? null);
            $reference = is_string($candidateReference) ? $candidateReference : null;

            $eventId = $this->eventIdFrom($decoded, $rawBody);

            $fingerprint = $this->fingerprint($rawBody);

            // 2. Duplicate detection, before any state is touched.
            if ($this->alreadySeen($fingerprint, $eventId)) {
                return [
                    'status' => 'duplicate',
                    'http_status' => 200,
                    'event_id' => $eventId,
                ];
            }

            // 3. Unsupported events are acknowledged and ignored.
            if (!in_array($eventType, self::SUPPORTED_EVENTS, true)) {
                $this->record(
                    fingerprint: $fingerprint,
                    eventId: $eventId,
                    eventType: $eventType !== '' ? $eventType : null,
                    signatureValid: true,
                    status: 'ignored',
                    payload: $this->sanitise($rawBody),
                    errorMessage: 'Unsupported event type.',
                    httpStatus: 200
                );

                return ['status' => 'ignored', 'http_status' => 200, 'event_id' => $eventId];
            }

            $eventRowId = $this->record(
                fingerprint: $fingerprint,
                eventId: $eventId,
                eventType: $eventType,
                signatureValid: true,
                status: 'processing',
                payload: $this->sanitise($rawBody),
                errorMessage: null,
                httpStatus: null
            );

            if ($reference === null) {
                $this->finalise($eventRowId, 'failed', 'No reference in the event payload.');

                return ['status' => 'failed', 'http_status' => 400, 'event_id' => $eventId];
            }

            // 4. Reconcile money-moving event types against server-side state.
            try {
                if (str_starts_with($eventType, 'refund.') || $eventType === 'charge.refunded') {
                    $result = $this->refunds->processProviderWebhook($eventType, $data);
                    $this->finalise($eventRowId, $result['status'], null, $result, 200);
                    return ['status' => $result['status'], 'http_status' => 200, 'event_id' => $eventId];
                }
                if (str_starts_with($eventType, 'transfer.')) {
                    if (!$this->transfers) {
                        throw new RuntimeException('Transfer reconciliation is unavailable.');
                    }
                    $result = $this->transfers->reconcileByReference($reference);
                    $this->finalise($eventRowId, (string) $result['status'], null, $result, 200);
                    return ['status' => (string) $result['status'], 'http_status' => 200, 'event_id' => $eventId];
                }

                $this->payments->confirm($reference);
                $this->finalise($eventRowId, 'processed', null, [], 200);

                return ['status' => 'processed', 'http_status' => 200, 'event_id' => $eventId];
            } catch (PaymentVerificationException $e) {
                /*
                 * A signed event that cannot be verified is a real anomaly -
                 * amount mismatch, unknown reference, or a transaction the
                 * provider will not confirm. It is recorded and refused, and
                 * NOTHING about the booking changes.
                 */
                $this->finalise(
                    $eventRowId,
                    'failed',
                    'Verification failed: ' . $e->reason(),
                    $e->detail(),
                    200
                );

                return ['status' => 'failed', 'http_status' => 200, 'event_id' => $eventId];
            } catch (PaymentPendingException $e) {
                $this->finalise($eventRowId, 'pending', 'Provider outcome is not conclusive.', [], 500);
                return ['status' => 'pending', 'http_status' => 500, 'event_id' => $eventId];
            } catch (Throwable $e) {
                /*
                 * Anything unexpected returns 500 so the provider retries. A
                 * retry is safe: the fingerprint and event-id constraints make
                 * a replay a no-op.
                 */
                $this->finalise($eventRowId, 'failed', 'Unexpected error handling the event.', [], 500);

                error_log(
                    '[paradisestudios] webhook ' . $eventType . ' failed: '
                    . get_class($e) . ' ' . $e->getMessage()
                );

                return ['status' => 'failed', 'http_status' => 500, 'event_id' => $eventId];
            }
        }

        /* ----------------------------------------------------------------
           Internals
           ---------------------------------------------------------------- */

        /**
         * Signature check.
         *
         * Delegated to the provider, so the algorithm lives with the provider
         * it belongs to and the endpoint never needs to know which gateway is
         * configured. Both the Paystack adapter and the local mock implement it
         * for real with HMAC-SHA512 over the exact raw body.
         */
        private function signatureIsValid(string $rawBody, ?string $signature): bool
        {
            return ps_payment_provider()->verifySignature($rawBody, $signature);
        }

        /**
         * A stable hash of the exact bytes received.
         *
         * This is what makes a byte-identical replay recognisable even when the
         * provider reuses or omits an event id.
         */
        private function fingerprint(string $rawBody): string
        {
            return hash('sha256', $rawBody);
        }

        /**
         * The provider's own event identifier, when it supplies one.
         */
        private function eventIdFrom(array $decoded, string $rawBody): ?string
        {
            foreach (['id', 'event_id'] as $key) {
                if (isset($decoded[$key]) && is_scalar($decoded[$key])) {
                    $value = (string) $decoded[$key];

                    if (preg_match('/^[A-Za-z0-9_.\-]{1,191}$/', $value) === 1) {
                        return $value;
                    }
                }
            }

            return null;
        }

        /**
         * Has this exact event already been handled?
         *
         * Only events whose signature was VALID count as already seen.
         *
         * The fingerprint is a hash of the body alone, so a body that arrived
         * once with a bad signature and then again with a correct one has the
         * same fingerprint both times. Counting the rejected attempt would mean
         * the genuine, correctly signed event is then discarded as a duplicate
         * and the payment is never confirmed - a spoofed request could
         * therefore permanently block a real payment.
         *
         * Recording the rejected attempt is still useful for the audit trail;
         * it just must not veto the legitimate retry.
         */
        private function alreadySeen(string $fingerprint, ?string $eventId): bool
        {
            $statement = $this->pdo->prepare(
                'SELECT id FROM payment_webhook_events
                 WHERE fingerprint = ? AND signature_valid = 1
                   AND (status IN (?, ?, ?, ?, ?) OR (status = ? AND http_status < 500))
                 LIMIT 1'
            );
            $statement->execute([
                $fingerprint,
                'processed',
                'ignored',
                'succeeded',
                'success',
                'reversed',
                'failed',
            ]);

            if ($statement->fetch()) {
                return true;
            }

            if ($eventId !== null) {
                $statement = $this->pdo->prepare(
                    'SELECT id FROM payment_webhook_events
                     WHERE provider = ? AND event_id = ? AND signature_valid = 1
                       AND (status IN (?, ?, ?, ?, ?) OR (status = ? AND http_status < 500))
                     LIMIT 1'
                );
                $statement->execute([
                    ps_payment_provider()->name(),
                    $eventId,
                    'processed',
                    'ignored',
                    'succeeded',
                    'success',
                    'reversed',
                    'failed',
                ]);

                if ($statement->fetch()) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Store the event. Returns the row id.
         *
         * A fingerprint can legitimately arrive more than once: the same body
         * replayed with a different (also invalid) signature, for example. The
         * UNIQUE constraint then rejects the INSERT, so the existing row is
         * updated instead of letting a duplicate-key error escape as a 500.
         *
         * That matters beyond tidiness - a 500 tells the provider to retry, so
         * an endpoint that crashes on a replayed body turns a harmless replay
         * into a retry loop.
         */
        private function record(
            string $fingerprint,
            ?string $eventId,
            ?string $eventType,
            bool $signatureValid,
            string $status,
            ?string $payload,
            ?string $errorMessage,
            ?int $httpStatus
        ): int {
            $providerName = ps_payment_provider()->name();

            try {
                $statement = $this->pdo->prepare('
                    INSERT INTO payment_webhook_events
                        (provider, event_id, event_type, fingerprint, signature_valid,
                         status, payload, error_message, http_status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');

                $statement->execute([
                    $providerName,
                    $eventId,
                    $eventType,
                    $fingerprint,
                    $signatureValid ? 1 : 0,
                    $status,
                    $payload,
                    $errorMessage !== null ? substr($errorMessage, 0, 500) : null,
                    $httpStatus,
                ]);

                return (int) $this->pdo->lastInsertId();
            } catch (PDOException $e) {
                if (!$this->isDuplicateKey($e)) {
                    throw $e;
                }
            }

            /*
             * Seen before. Refresh the diagnostic rather than storing a second
             * copy, so the table stays one row per distinct payload. Setting
             * signature_valid here matters: a body first seen with a bad
             * signature and then re-delivered correctly must be upgraded in
             * place, and alreadySeen() only counts valid rows.
             */
            $this->pdo->prepare(
                'UPDATE payment_webhook_events
                 SET status = ?, signature_valid = ?, http_status = ?,
                     event_id = COALESCE(?, event_id),
                     error_message = ?
                 WHERE fingerprint = ?'
            )->execute([
                $status,
                $signatureValid ? 1 : 0,
                $httpStatus,
                $eventId,
                $errorMessage !== null ? substr($errorMessage, 0, 500) : null,
                $fingerprint,
            ]);

            $statement = $this->pdo->prepare(
                'SELECT id FROM payment_webhook_events WHERE fingerprint = ? LIMIT 1'
            );
            $statement->execute([$fingerprint]);
            $id = $statement->fetchColumn();
            if (!$id && $eventId !== null) {
                $statement = $this->pdo->prepare(
                    'SELECT id FROM payment_webhook_events WHERE provider = ? AND event_id = ? LIMIT 1'
                );
                $statement->execute([$providerName, $eventId]);
                $id = $statement->fetchColumn();
            }

            return (int) $id;
        }

        /**
         * Is this a unique-constraint violation? SQLSTATE 23000 with code 1062
         * is MySQL/MariaDB's duplicate entry.
         */
        private function isDuplicateKey(PDOException $e): bool
        {
            return $e->getCode() === '23000'
                || (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062);
        }

        private function finalise(
            int $eventRowId,
            string $status,
            ?string $errorMessage = null,
            array $detail = [],
            ?int $httpStatus = null
        ): void {
            $statement = $this->pdo->prepare(
                'UPDATE payment_webhook_events
                 SET status = ?, error_message = ?, processed_at = ?,
                     http_status = COALESCE(?, http_status)
                 WHERE id = ?'
            );

            $statement->execute([
                $status,
                $errorMessage !== null
                    ? substr($errorMessage, 0, 500)
                    : (($detail !== []) ? substr((string) json_encode($detail), 0, 500) : null),
                gmdate('Y-m-d H:i:s'),
                $httpStatus,
                $eventRowId,
            ]);
        }

        /**
         * Reduce a raw body to the fields worth keeping, and cap its size.
         *
         * Deliberately selective rather than storing the payload verbatim:
         * keeps the diagnostic trail while guaranteeing no card data, secret or
         * unbounded body is ever written to the database.
         */
        private function sanitise(string $rawBody): string
        {
            $decoded = json_decode($rawBody, true);

            if (!is_array($decoded)) {
                return substr($rawBody, 0, 1000);
            }

            $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

            $safe = [
                'event' => (string) ($decoded['event'] ?? ''),
                'data' => [
                    'reference' => $data['reference'] ?? null,
                    'status' => $data['status'] ?? null,
                    'amount' => isset($data['amount']) && is_numeric($data['amount'])
                        ? (int) $data['amount']
                        : null,
                    'currency' => $data['currency'] ?? null,
                    'channel' => $data['channel'] ?? null,
                    'paid_at' => $data['paid_at'] ?? null,
                    'refunded' => isset($data['refunded']) ? (bool) $data['refunded'] : null,
                ],
            ];

            return (string) json_encode($safe, JSON_UNESCAPED_SLASHES);
        }
    }
}
