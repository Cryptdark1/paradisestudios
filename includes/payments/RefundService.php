<?php

/**
 * Paradise Studios - refunds.
 *
 * A refund is a REQUEST plus an outcome, and the distinction is the whole
 * point of this class. `requestRefund()` records intent and asks the gateway.
 * Only a provider-confirmed result moves money. Nothing here ever claims a
 * calculated figure has been returned to a customer.
 *
 * CANCELLATION POLICY (unchanged from api/cancel-booking.php)
 *   at least PAYMENT_FULL_REFUND_WINDOW_HOURS before the session starts -> full
 *   inside that window                                            -> half
 *   once the session has started                                  -> nothing
 *
 * HOW A REFUND INTERACTS WITH COMMISSION AND OWNER EARNINGS
 * ------------------------------------------------------------
 * Money came in as:  gross = commission + owner_net
 * When R is refunded, that R came out of the same pot, so the reversal is
 * proportional and is computed with the SAME rate that was applied at capture:
 *
 *     commission_reversed = R * commission_bps
 *     owner_reversed      = R - commission_reversed
 *
 * Reading the rate from the payment row rather than from current configuration
 * is what stops a commission change from restating a refund that already
 * happened.
 *
 * PROVIDER FEES ARE NOT REFUNDED.
 * Gateways do not return their processing fee, so on a full refund the fee
 * remains a cost to the platform. This is stated here rather than left implicit
 * because it is a real business decision, not a technicality: on a full refund
 * of a NGN 15,000 booking whose captured fee was NGN 325.00, the platform is
 * out NGN 325.00 even though the customer was made whole.
 *
 * CAPS
 *   A refund may never exceed captured MINUS already-refunded. The cap is
 *   derived from CONFIRMED ledger entries, never from bookings.total_amount,
 *   which says nothing about whether money ever arrived.
 */

require_once __DIR__ . '/FinancialLedger.php';
require_once __DIR__ . '/PayoutService.php';
require_once __DIR__ . '/../money.php';

if (!defined('PS_REFUND_SERVICE_LOADED')) {
    define('PS_REFUND_SERVICE_LOADED', true);

    final class RefundService
    {
        public function __construct(
            private PDO $pdo,
            private FinancialLedger $ledger,
            private ?PaymentProviderInterface $provider = null
        ) {
            $this->provider ??= ps_payment_provider();
        }

        /* =================================================================
           Calculation
           ================================================================= */

        /**
         * Work out the refund the cancellation policy allows.
         *
         * Pure calculation - it moves nothing and records nothing.
         *
         * @return array{
         *     refundable_minor:int, percentage_bps:int, reason:string,
         *     captured_minor:int, already_refunded_minor:int, capped:bool
         * }
         */
        public function calculateCancellationRefund(int $paymentId): array
        {
            $payment = $this->loadPayment($paymentId);

            $captured = $this->ledger->capturedForPayment($paymentId);
            $alreadyRefunded = $this->ledger->refundedForPayment($paymentId);
            $refundable = max(0, $captured - $alreadyRefunded);

            $booking = $this->loadBooking((int) $payment['booking_id']);

            $startTimestamp = strtotime((string) $booking['start_time']);

            if ($startTimestamp === false) {
                throw new RuntimeException('Booking start_time could not be parsed.');
            }

            $secondsUntilStart = $startTimestamp - time();

            if ($secondsUntilStart <= 0) {
                return [
                    'refundable_minor' => $refundable,
                    'percentage_bps' => 0,
                    'reason' => 'session_already_started',
                    'captured_minor' => $captured,
                    'already_refunded_minor' => $alreadyRefunded,
                    'capped' => false,
                ];
            }

            $windowSeconds = (int) ps_payment_config('full_refund_window_hours') * 3600;

            $percentageBps = $secondsUntilStart >= $windowSeconds
                ? (int) ps_payment_config('full_refund_bps')
                : (int) ps_payment_config('late_refund_bps');

            $policyAmount = ps_money_apply_bps($captured, $percentageBps);

            /*
             * The policy figure can never exceed what is actually left to give
             * back. This is the cap that prevents a second cancellation request
             * paying out twice.
             */
            $amount = min($policyAmount, $refundable);

            return [
                'refundable_minor' => $amount,
                'percentage_bps' => $percentageBps,
                'reason' => $percentageBps >= 10000
                    ? 'outside_cancellation_window'
                    : 'inside_cancellation_window',
                'captured_minor' => $captured,
                'already_refunded_minor' => $alreadyRefunded,
                'capped' => $policyAmount > $refundable,
            ];
        }

        /* =================================================================
           Requesting
           ================================================================= */

        /**
         * Request a refund.
         *
         * Idempotent: passing the same idempotency key returns the original
         * refund rather than creating a second one. That, plus the cap derived
         * from confirmed movements, is what prevents double refunding.
         *
         * @param int|null    $actorUserId  requester, for the audit trail
         * @param int|null    $amountMinor  null to use the cancellation policy
         * @param string      $idempotencyKey
         *
         * @return array{refund:array, duplicate:bool}
         */
        public function requestRefund(
            int $paymentId,
            ?int $actorUserId,
            string $idempotencyKey,
            string $reason = 'Booking cancelled',
            ?int $amountMinor = null,
            string $type = 'cancellation'
        ): array {
            $existing = $this->findByIdempotencyKey($idempotencyKey);

            if ($existing !== null) {
                return ['refund' => $existing, 'duplicate' => true];
            }

            $payment = $this->loadPayment($paymentId);

            if (!in_array((string) $payment['status'], ['succeeded', 'duplicate_capture'], true)) {
                throw new PaymentValidationException(
                    'Only a confirmed payment can be refunded.'
                );
            }

            $calculation = $this->calculateCancellationRefund($paymentId);

            if ($amountMinor === null) {
                $amountMinor = (int) $calculation['refundable_minor'];
            }

            if ($amountMinor <= 0) {
                throw new PaymentValidationException(
                    'There is no refundable amount for this payment.'
                );
            }

            /*
             * Hard cap. Checked again here, and re-checked inside the
             * transaction, because two concurrent cancellation requests must
             * not both pass this check and both send money.
             */
            if ($amountMinor > (int) $calculation['refundable_minor']) {
                throw new PaymentValidationException(
                    'Refund of ' . $amountMinor . ' exceeds the refundable amount of '
                    . $calculation['refundable_minor'] . '.'
                );
            }

            $policyBps = (int) $calculation['percentage_bps'];

            $this->pdo->beginTransaction();

            try {
                /*
                 * Re-check the cap under lock. The payment row serialises
                 * concurrent refunds for the same payment.
                 */
                $lock = $this->pdo->prepare('SELECT id FROM payments WHERE id = ? FOR UPDATE');
                $lock->execute([$paymentId]);

                $freshRefundable = max(
                    0,
                    $this->ledger->refundableForPayment($paymentId) - $this->pendingRefundsForPayment($paymentId)
                );

                if ($amountMinor > $freshRefundable) {
                    throw new PaymentValidationException(
                        'Refund of ' . $amountMinor . ' exceeds the refundable amount of '
                        . $freshRefundable . '.'
                    );
                }

                $statement = $this->pdo->prepare('
                    INSERT INTO refunds
                        (payment_id, booking_id, provider, currency, amount_minor,
                         refund_type, policy_bps, reason, requested_by,
                         idempotency_key, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $statement->execute([
                    $paymentId,
                    (int) $payment['booking_id'],
                    (string) $payment['provider'],
                    (string) $payment['currency'],
                    $amountMinor,
                    $type,
                    $policyBps,
                    substr($reason, 0, 255),
                    $actorUserId,
                    $idempotencyKey,
                    'requested',
                ]);

                $refundId = (int) $this->pdo->lastInsertId();

                $this->pdo->prepare(
                    "UPDATE refunds SET status = 'processing' WHERE id = ?"
                )->execute([$refundId]);

                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }

            /*
             * The gateway call happens OUTSIDE the database transaction.
             * Holding a row lock across a network call would block every other
             * operation on this payment for the duration, and if the process
             * died mid-call the transaction would roll back a refund the
             * customer may already have received.
             */
            return $this->sendToProvider($refundId, $payment, $amountMinor);
        }

        /**
         * Ask the gateway to return the money and record the outcome.
         */
        private function sendToProvider(int $refundId, array $payment, int $amountMinor): array
        {
            $provider = $this->provider ?? ps_payment_provider();

            try {
                $result = $provider->refund(
                    (string) ($payment['provider_reference'] ?: $payment['reference']),
                    $amountMinor,
                    (string) $payment['currency']
                );

                $status = strtolower(trim((string) ($result['status'] ?? 'unknown')));
                if (in_array($status, ['success', 'succeeded'], true)) {
                    $this->completeRefund($refundId, $payment, $amountMinor, $result);
                    return [
                        'refund' => $this->loadRefund($refundId),
                        'duplicate' => false,
                    ];
                }
                if (in_array($status, ['failed', 'rejected', 'declined'], true)) {
                    $this->markFailed($refundId, 'provider_status_' . (string) ($result['status'] ?? 'unknown'));
                    return [
                        'refund' => $this->loadRefund($refundId),
                        'duplicate' => false,
                    ];
                }
                return [
                    'refund' => $this->loadRefund($refundId),
                    'duplicate' => false,
                ];
            } catch (PaymentProviderException $e) {
                if ($e->safeCode() === 'provider_rejected') {
                    $this->markFailed($refundId, $e->safeCode());
                }
                return [
                    'refund' => $this->loadRefund($refundId),
                    'duplicate' => false,
                ];
            }
        }

        /**
         * Record a provider-confirmed refund and reverse the proportional
         * commission and owner earning.
         */
        public function completeRefund(
            int $refundId,
            array $payment,
            int $amountMinor,
            ?array $providerResult = null
        ): void {
            $commissionBps = (int) $payment['commission_bps'];

            $commissionReversed = ps_money_apply_bps($amountMinor, $commissionBps);
            $ownerReversed = $amountMinor - $commissionReversed;

            $this->pdo->beginTransaction();

            try {
                $lock = $this->pdo->prepare('
                    SELECT status, payment_id, amount_minor FROM refunds WHERE id = ? FOR UPDATE
                ');
                $lock->execute([$refundId]);
                $refund = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$refund) {
                    throw new RuntimeException('Refund disappeared during completion.');
                }
                if ($refund['status'] === 'succeeded') {
                    $this->pdo->rollBack();
                    return;
                }
                if (
                    (int) $refund['payment_id'] !== (int) $payment['id']
                    || (int) $refund['amount_minor'] !== $amountMinor
                    || !in_array($refund['status'], ['requested', 'processing', 'failed'], true)
                ) {
                    throw new PaymentVerificationException('Refund details do not match the recorded request.', 'refund_mismatch');
                }

                $this->pdo->prepare('
                    UPDATE refunds
                    SET status = ?, provider_reference = ?, completed_at = ?
                    WHERE id = ? AND status != ?
                ')->execute([
                    'succeeded',
                    $providerResult['provider_reference'] ?? null,
                    gmdate('Y-m-d H:i:s'),
                    $refundId,
                    'succeeded',
                ]);

                $this->ledger->record('refund', 'artist', $amountMinor, [
                    'booking_id' => (int) $payment['booking_id'],
                    'payment_id' => (int) $payment['id'],
                    'refund_id' => $refundId,
                    'commission_bps' => $commissionBps,
                    'description' => 'Refund issued to customer',
                    'metadata' => [
                        'commission_reversed_minor' => $commissionReversed,
                        'owner_reversed_minor' => $ownerReversed,
                        'provider_fee_retained' => true,
                    ],
                ]);

                if ($commissionReversed > 0) {
                    $this->ledger->record('commission_reversed', 'platform', $commissionReversed, [
                        'booking_id' => (int) $payment['booking_id'],
                        'payment_id' => (int) $payment['id'],
                        'refund_id' => $refundId,
                        'commission_bps' => $commissionBps,
                        'description' => 'Platform commission reversed on refund',
                    ]);
                }

                if ($ownerReversed > 0) {
                    $this->ledger->record('owner_earning_reversed', 'owner', $ownerReversed, [
                        'booking_id' => (int) $payment['booking_id'],
                        'payment_id' => (int) $payment['id'],
                        'refund_id' => $refundId,
                        'party_user_id' => (int) $payment['owner_id'],
                        'commission_bps' => $commissionBps,
                        'description' => 'Owner earning reversed on refund',
                    ]);

                    $this->pdo->prepare('
                        UPDATE owner_payouts
                        SET reversed_minor = reversed_minor + ?
                        WHERE booking_id = ? AND payment_id = ?
                          AND reversed_minor + ? <= owner_net_minor
                    ')->execute([
                        $ownerReversed,
                        (int) $payment['booking_id'],
                        (int) $payment['id'],
                        $ownerReversed,
                    ]);
                }

                /*
                 * A full refund reverses the earning entirely, so the payout row
                 * must stop looking payable. A partial one leaves the remainder
                 * eligible.
                 */
                $this->updatePayoutAfterRefund(
                    (int) $payment['booking_id'],
                    (int) $payment['id']
                );

                $this->pdo->prepare('
                    UPDATE payments
                    SET status = ?
                    WHERE id = ?
                ')->execute([
                    $this->refundableAfter((int) $payment['id']) === 0 ? 'refunded' : 'partially_refunded',
                    (int) $payment['id'],
                ]);

                $this->pdo->prepare('
                    UPDATE bookings
                    SET payment_state = ?
                    WHERE id = ?
                ')->execute([
                    $this->bookingPaymentState((int) $payment['booking_id']),
                    (int) $payment['booking_id'],
                ]);

                if ((string) ($payment['status'] ?? '') === 'duplicate_capture') {
                    (new PayoutService($this->pdo, $this->ledger))
                        ->releaseDuplicatePaymentHold((int) $payment['booking_id']);
                }

                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }

        /**
         * Keep a payout row honest after money is returned.
         *
         * A fully reversed earning must never reach 'eligible', because paying
         * it out would credit an owner for a session that was refunded.
         */
        private function updatePayoutAfterRefund(int $bookingId, int $paymentId): void
        {
            $this->pdo->prepare('
                UPDATE owner_payouts
                SET status = CASE
                        WHEN owner_net_minor - reversed_minor <= 0 THEN ?
                        ELSE ?
                    END,
                    hold_reason = CASE
                        WHEN owner_net_minor - reversed_minor <= 0 THEN ?
                        ELSE ?
                    END
                WHERE booking_id = ? AND payment_id = ?
                  AND status NOT IN (?, ?)
            ')->execute([
                'reversed',
                'on_hold',
                'fully_refunded',
                'refund_requires_review',
                $bookingId,
                $paymentId,
                'paid',
                'reversed',
            ]);
        }

        private function refundableAfter(int $paymentId): int
        {
            return $this->ledger->refundableForPayment($paymentId);
        }

        private function pendingRefundsForPayment(int $paymentId): int
        {
            $statement = $this->pdo->prepare("
                SELECT COALESCE(SUM(amount_minor), 0)
                FROM refunds WHERE payment_id = ? AND status IN ('requested', 'processing')
            ");
            $statement->execute([$paymentId]);
            return (int) $statement->fetchColumn();
        }

        private function bookingPaymentState(int $bookingId): string
        {
            $statement = $this->pdo->prepare('
                SELECT
                    COALESCE(SUM(CASE WHEN entry_type = ? THEN amount_minor ELSE 0 END), 0) AS captured,
                    COALESCE(SUM(CASE WHEN entry_type = ? THEN amount_minor ELSE 0 END), 0) AS refunded
                FROM financial_ledger WHERE booking_id = ?
            ');
            $statement->execute(['payment_captured', 'refund', $bookingId]);
            $totals = $statement->fetch(PDO::FETCH_ASSOC);
            $captured = (int) $totals['captured'];
            $refunded = (int) $totals['refunded'];
            if ($captured <= $refunded) {
                return 'refunded';
            }
            return $refunded > 0 ? 'partially_refunded' : 'paid';
        }

        public function processProviderWebhook(string $eventType, array $data): array
        {
            $transaction = $data['transaction'] ?? null;
            $originalReference = is_array($transaction)
                ? ($transaction['reference'] ?? null)
                : ($transaction ?? $data['reference'] ?? null);
            $amount = $data['amount'] ?? null;
            $currency = $data['currency'] ?? null;
            if (
                !is_string($originalReference) && !is_int($originalReference)
                || !is_int($amount) && !(is_string($amount) && ctype_digit($amount))
                || !is_string($currency)
            ) {
                throw new PaymentVerificationException('Refund webhook is missing required matching details.', 'refund_webhook_malformed');
            }

            $status = strtolower(trim((string) ($data['status'] ?? '')));
            $successEvent = in_array($eventType, ['refund.processed', 'refund.succeeded'], true)
                && in_array($status, ['processed', 'success', 'succeeded'], true);
            $failedEvent = $eventType === 'refund.failed'
                && in_array($status, ['failed', 'rejected'], true);
            if (!$successEvent && !$failedEvent) {
                throw new PaymentPendingException('Refund webhook status is not conclusive.', $status ?: 'unknown');
            }

            $statement = $this->pdo->prepare("
                SELECT r.*, r.id AS refund_id, p.*
                FROM refunds r
                INNER JOIN payments p ON p.id = r.payment_id
                WHERE (p.reference = ? OR p.provider_reference = ?)
                  AND r.amount_minor = ? AND r.currency = ?
                  AND r.status IN ('requested', 'processing', 'failed', 'succeeded')
                ORDER BY r.id DESC LIMIT 1
            ");
            $statement->execute([
                (string) $originalReference,
                (string) $originalReference,
                (int) $amount,
                strtoupper($currency),
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new PaymentVerificationException('No matching pending refund exists.', 'refund_not_found');
            }

            if ($row['status'] === 'succeeded') {
                return ['refund_id' => (int) $row['refund_id'], 'status' => 'succeeded'];
            }

            if ($successEvent) {
                $this->completeRefund(
                    (int) $row['refund_id'],
                    $row,
                    (int) $row['amount_minor'],
                    ['provider_reference' => isset($data['id']) ? (string) $data['id'] : null]
                );
            } else {
                $this->markFailed((int) $row['refund_id'], 'provider_webhook_failed');
            }
            return ['refund_id' => (int) $row['refund_id'], 'status' => $successEvent ? 'succeeded' : 'failed'];
        }

        /* =================================================================
           Reads
           ================================================================= */

        public function findByIdempotencyKey(string $key): ?array
        {
            $statement = $this->pdo->prepare(
                'SELECT * FROM refunds WHERE idempotency_key = ? LIMIT 1'
            );
            $statement->execute([$key]);

            return $statement->fetch() ?: null;
        }

        public function loadRefund(int $refundId): array
        {
            $statement = $this->pdo->prepare('SELECT * FROM refunds WHERE id = ? LIMIT 1');
            $statement->execute([$refundId]);

            $refund = $statement->fetch();

            if (!$refund) {
                throw new RuntimeException('Refund not found: ' . $refundId);
            }

            return $refund;
        }

        public function refundsForPayment(int $paymentId): array
        {
            $statement = $this->pdo->prepare(
                'SELECT * FROM refunds WHERE payment_id = ? ORDER BY id ASC'
            );
            $statement->execute([$paymentId]);

            return $statement->fetchAll();
        }

        /* =================================================================
           Internals
           ================================================================= */

        private function markFailed(int $refundId, string $reason): void
        {
            $this->pdo->prepare(
                "UPDATE refunds SET status = 'failed', failure_reason = ? WHERE id = ?"
            )->execute([substr($reason, 0, 255), $refundId]);
        }

        private function loadPayment(int $paymentId): array
        {
            $statement = $this->pdo->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
            $statement->execute([$paymentId]);

            $payment = $statement->fetch();

            if (!$payment) {
                throw new PaymentValidationException('Payment not found: ' . $paymentId);
            }

            return $payment;
        }

        private function loadBooking(int $bookingId): array
        {
            $statement = $this->pdo->prepare(
                'SELECT id, start_time, end_time, status, payment_state FROM bookings WHERE id = ? LIMIT 1'
            );
            $statement->execute([$bookingId]);

            $booking = $statement->fetch();

            if (!$booking) {
                throw new PaymentValidationException('Booking not found: ' . $bookingId);
            }

            return $booking;
        }
    }
}
