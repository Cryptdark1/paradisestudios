<?php

/**
 * Paradise Studios - payment service.
 *
 * Owns the money lifecycle: initialise, verify, confirm. Everything that makes
 * a booking paid happens here and nowhere else.
 *
 * TRUST MODEL
 *   The browser is never trusted. It may say "the user clicked pay" and it may
 *   say "I paid"; neither is evidence. The only trusted statement is what the
 *   PROVIDER says, fetched server-to-server with our own credentials, and only
 *   when the reference, amount, currency and booking all agree with what we
 *   recorded when we asked for the money.
 *
 *   Consequently a redirect to the success page cannot mark anything paid. The
 *   success page calls confirm(), which re-asks the provider; if the provider
 *   has not confirmed, nothing happens.
 *
 * IDEMPOTENCY
 *   Three independent guards, so no single failure double-credits anyone:
 *     1. the payment row is locked FOR UPDATE during confirmation;
 *     2. a payment already in 'succeeded' short-circuits and returns the
 *        original result instead of writing anything;
 *     3. UNIQUE (provider, provider_reference) and UNIQUE reference on payments,
 *        plus UNIQUE booking_id on owner_payouts, stop duplicates at the
 *        database even if the application logic were bypassed.
 *
 * MONEY
 *   Amounts are integer minor units throughout. The amount charged is read from
 *   bookings.total_amount_minor, which the server itself derived from the studio
 *   rate - never from anything the client supplied.
 */

require_once __DIR__ . '/ProviderFactory.php';
require_once __DIR__ . '/FinancialLedger.php';
require_once __DIR__ . '/../money.php';

if (!defined('PS_PAYMENT_SERVICE_LOADED')) {
    define('PS_PAYMENT_SERVICE_LOADED', true);

    final class PaymentService
    {
        public function __construct(
            private PDO $pdo,
            private FinancialLedger $ledger,
            private ?PaymentProviderInterface $provider = null
        ) {
            $this->provider ??= ps_payment_provider();
        }

        /* =================================================================
           Reference generation
           ================================================================= */

        /**
         * A unique reference for one payment attempt.
         *
         * Sent to the provider and echoed back on the webhook, so it must be
         * unique forever. The random suffix means an attacker cannot guess
         * another booking's reference to probe payment status.
         */
        public function generateReference(int $bookingId): string
        {
            return 'ps_' . $bookingId . '_'
                . gmdate('YmdHis') . '_'
                . bin2hex(random_bytes(6));
        }

        /* =================================================================
           Initialisation
           ================================================================= */

        /**
         * Start a payment for a booking.
         *
         * Re-reads the booking and studio rate from the database rather than
         * accepting an amount, so the charged figure cannot be influenced by
         * the client. Ownership is enforced: only the booking's own artist may
         * pay for it.
         *
         * @throws PaymentValidationException  booking is not payable
         * @throws PaymentProviderException   booking is not the caller's
         */
        public function initialize(int $bookingId, int $actorUserId): array
        {
            $booking = $this->loadPayableBooking($bookingId, $actorUserId);

            $provider = $this->provider();

            $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify('+' . (int) ps_payment_config('payment_expiry_minutes') . ' minutes');

            $reference = $this->generateReference($bookingId);
            $commissionBps = (int) ps_payment_config('commission_bps');

            /*
             * The payment row is written BEFORE the provider is contacted, so a
             * provider response can always be matched back to a row we created.
             * If the provider call then fails the row is marked 'failed' rather
             * than deleted, leaving the attempt auditable.
             */
            $this->pdo->beginTransaction();

            try {
                $bookingLock = $this->pdo->prepare('
                    SELECT status, payment_state FROM bookings WHERE id = ? FOR UPDATE
                ');
                $bookingLock->execute([$bookingId]);
                $lockedBooking = $bookingLock->fetch(PDO::FETCH_ASSOC);
                if (
                    !$lockedBooking
                    || in_array($lockedBooking['status'], ['cancelled', 'completed', 'disputed'], true)
                    || in_array($lockedBooking['payment_state'], ['paid', 'legacy_unverified'], true)
                ) {
                    throw new PaymentValidationException('This booking is no longer payable.');
                }
                $activePayment = $this->pdo->prepare("
                    SELECT id, reference, authorization_url, expires_at
                    FROM payments
                    WHERE booking_id = ? AND status IN ('initialized', 'pending', 'processing')
                    ORDER BY id DESC LIMIT 1
                ");
                $activePayment->execute([$bookingId]);
                $existingAttempt = $activePayment->fetch(PDO::FETCH_ASSOC);
                if ($existingAttempt) {
                    $this->pdo->commit();
                    if (is_string($existingAttempt['authorization_url']) && $existingAttempt['authorization_url'] !== '') {
                        return [
                            'payment_id' => (int) $existingAttempt['id'],
                            'reference' => (string) $existingAttempt['reference'],
                            'amount_minor' => (int) $booking['total_amount_minor'],
                            'currency' => (string) ps_payment_config('currency'),
                            'amount_display' => ps_money_format(
                                (int) $booking['total_amount_minor'],
                                (string) ps_payment_config('currency')
                            ),
                            'authorization_url' => (string) $existingAttempt['authorization_url'],
                            'expires_at' => (string) $existingAttempt['expires_at'],
                            'booking_status' => (string) $booking['status'],
                            'payment_state' => 'pending',
                            'notice' => 'An existing payment attempt is being reused.',
                        ];
                    }
                    throw new PaymentPendingException(
                        'An existing payment attempt is awaiting provider confirmation.',
                        'processing',
                        (int) $existingAttempt['id']
                    );
                }

                $statement = $this->pdo->prepare('
                    INSERT INTO payments
                        (booking_id, artist_id, studio_id, owner_id, provider, reference,
                         currency, amount_minor, commission_bps, status, expires_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');

                $statement->execute([
                    $bookingId,
                    (int) $booking['artist_id'],
                    (int) $booking['studio_id'],
                    (int) $booking['owner_id'],
                    $provider->name(),
                    $reference,
                    (string) ps_payment_config('currency'),
                    (int) $booking['total_amount_minor'],
                    $commissionBps,
                    'pending',
                    $expiresAt->format('Y-m-d H:i:s'),
                ]);

                $paymentId = (int) $this->pdo->lastInsertId();

                $this->pdo->prepare('
                    UPDATE bookings
                    SET payment_state = ?, payment_expires_at = ?
                    WHERE id = ? AND payment_state IN (?, ?)
                ')->execute([
                    'pending',
                    $expiresAt->format('Y-m-d H:i:s'),
                    $bookingId,
                    'unpaid',
                    'failed',
                ]);

                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }

            try {
                $initialised = $provider->initialize(
                    /*
                     * The provider must be given OUR unique reference, not the
                     * booking id. The reference is what comes back on the
                     * webhook and what verify() resolves against; handing over
                     * the booking id would make two payment attempts for the
                     * same booking indistinguishable to the gateway.
                     */
                    $reference,
                    (int) $booking['total_amount_minor'],
                    (string) ps_payment_config('currency'),
                    (string) $booking['artist_email'],
                    [
                        'booking_id' => (int) $booking['id'],
                        'studio_id' => (int) $booking['studio_id'],
                    ]
                );
            } catch (Throwable $e) {
                if ($e instanceof PaymentConfigurationException) {
                    $this->markPaymentFailed($paymentId, $this->safeReason($e));
                    throw $e;
                }

                if ($e instanceof PaymentProviderException && $e->safeCode() === 'provider_rejected') {
                    $this->markPaymentFailed($paymentId, $e->safeCode());
                    throw $e;
                }

                // A timeout or malformed response may mean the provider accepted
                // the request. Keep the same attempt open; never invite a second charge.
                $this->markPaymentProcessing($paymentId);
                throw new PaymentPendingException(
                    'Payment initialization has an uncertain result and must be checked before retrying.',
                    'unknown',
                    $paymentId
                );
            }

            /*
             * Paystack returns its own reference at initialisation. Recording
             * it now means a later verify() can use the gateway's identifier
             * rather than relying on our reference being echoed correctly.
             *
             * UNIQUE (provider, provider_reference) makes this safe: if the
             * gateway somehow hands back an id we already hold, the write
             * fails loudly instead of silently attaching one transaction to
             * two payments.
             */
            if (isset($initialised['raw']['data']['id'])) {
                $this->pdo->prepare(
                    'UPDATE payments SET provider_reference = ? WHERE id = ?'
                )->execute([(string) $initialised['raw']['data']['id'], $paymentId]);
            }

            $this->pdo->prepare(
                'UPDATE payments SET metadata = ?, authorization_url = ? WHERE id = ?'
            )->execute([
                json_encode(
                    ['access_code' => $initialised['access_code'] ?? null],
                    JSON_UNESCAPED_SLASHES
                ),
                (string) $initialised['authorization_url'],
                $paymentId,
            ]);

            return [
                'payment_id' => $paymentId,
                'reference' => $reference,
                'amount_minor' => (int) $booking['total_amount_minor'],
                'currency' => (string) ps_payment_config('currency'),
                'amount_display' => ps_money_format(
                    (int) $booking['total_amount_minor'],
                    (string) ps_payment_config('currency')
                ),
                'authorization_url' => (string) $initialised['authorization_url'],
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'booking_status' => (string) $booking['status'],
                'payment_state' => 'pending',
                /*
                 * Stated plainly so no caller can present this as escrow: the
                 * booking is NOT paid yet and no funds are held anywhere.
                 */
                'notice' => 'No funds are held until the provider confirms this payment.',
            ];
        }

        /**
         * Load a booking that may be paid, and prove the actor may pay it.
         */
        private function loadPayableBooking(int $bookingId, int $actorUserId): array
        {
            $statement = $this->pdo->prepare('
                SELECT
                    b.id, b.artist_id, b.studio_id, b.status, b.payment_state,
                    b.total_amount, b.total_amount_minor,
                    s.owner_id, s.name AS studio_name,
                    u.email AS artist_email
                FROM bookings b
                INNER JOIN studios s ON s.id = b.studio_id
                INNER JOIN users   u ON u.id = b.artist_id
                WHERE b.id = ?
                LIMIT 1
            ');
            $statement->execute([$bookingId]);
            $booking = $statement->fetch();

            if (!$booking) {
                throw new PaymentProviderException('Booking not found.', 'booking_not_found', 404);
            }

            /*
             * Authorisation. A payment may only be started by the artist who
             * owns the booking. Checked before any state is touched.
             */
            if ((int) $booking['artist_id'] !== $actorUserId) {
                throw new PaymentProviderException('Booking not found.', 'booking_not_found', 404);
            }

            if ((int) $booking['total_amount_minor'] <= 0) {
                throw new PaymentValidationException(
                    'This booking has no valid payable amount.'
                );
            }

            /*
             * Only an unpaid booking may be paid. A cancelled or completed
             * booking must never take money again.
             */
            if (in_array((string) $booking['status'], ['cancelled', 'completed', 'disputed'], true)) {
                throw new PaymentValidationException(
                    'This booking is ' . $booking['status'] . ' and cannot be paid.'
                );
            }

            if (in_array((string) $booking['payment_state'], ['paid', 'legacy_unverified'], true)) {
                throw new PaymentValidationException(
                    'This booking has already been paid.'
                );
            }

            return $booking;
        }

        /* =================================================================
           Confirmation - the only path that makes money arrive
           ================================================================= */

        /**
         * Verify with the provider and, only if everything agrees, credit it.
         *
         * Safe to call any number of times from any number of places: a webhook
         * and a browser redirect may both arrive for the same payment and
         * neither can credit it twice.
         *
         * @return array{confirmed:bool, already_confirmed:bool, payment_id:int,
         *               booking_id:int, amount_minor:int, currency:string,
         *               commission_minor:int, owner_net_minor:int,
         *               owner_id:int, reason:?string}
         */
        public function confirm(string $reference, ?string $expectedBookingId = null): array
        {
            $payment = $this->findPaymentByReference($reference);

            if (
                $expectedBookingId !== null
                && (string) $payment['booking_id'] !== $expectedBookingId
            ) {
                throw new PaymentVerificationException(
                    'This payment does not belong to the booking being paid.',
                    'booking_mismatch'
                );
            }

            /*
             * FAST PATH. Already confirmed: return the original outcome and
             * write nothing. This is what makes duplicate webhooks and a
             * browser retry harmless.
             */
            if ((string) $payment['status'] === 'succeeded') {
                return $this->alreadyConfirmedResult($payment);
            }
            if ((string) $payment['status'] === 'duplicate_capture') {
                $this->refundLateOrDuplicateCapture($payment);
                return [
                    'confirmed' => true,
                    'already_confirmed' => true,
                    'payment_id' => (int) $payment['id'],
                    'booking_id' => (int) $payment['booking_id'],
                    'amount_minor' => (int) $payment['amount_minor'],
                    'currency' => (string) $payment['currency'],
                    'commission_minor' => 0,
                    'owner_net_minor' => 0,
                    'owner_id' => (int) $payment['owner_id'],
                    'reason' => 'duplicate_capture_refund_required',
                ];
            }

            /*
             * REPAIR. A row written by an older build may be stuck at 'failed'
             * even though the provider only ever said "processing" or "queued".
             * Because a failed payment refuses to be confirmed, such a row is
             * stuck permanently even though the transaction may have gone
             * through.
             *
             * Only rows whose recorded reason is one of the transient statuses
             * are reopened. A genuinely failed payment keeps its terminal state.
             */
            if (
                (string) $payment['status'] === 'failed'
                && $this->wasMisfiledAsFailed((string) ($payment['failure_reason'] ?? ''))
            ) {
                $this->reopenMisfiledPayment((int) $payment['id']);

                $payment = $this->findPaymentByReference($reference);
            }

            if ((string) $payment['status'] === 'failed') {
                throw new PaymentVerificationException(
                    'This payment already failed and cannot be confirmed.',
                    'payment_failed'
                );
            }

            /*
             * Paystack's verify endpoint resolves the transaction by OUR
             * reference. Do not substitute the gateway's numeric transaction
             * id; it is recorded only as metadata for the confirmed capture.
             */
            $provider = $this->provider();
            $verification = $provider->verify(
                (string) $payment['reference']
            );

            $rawStatus = $verification['status'] ?? null;
            $status = is_string($rawStatus)
                ? strtolower(trim($rawStatus))
                : '';

            /*
             * STILL DECIDING.
             *
             * 'processing', 'queued' and friends are ordinary states for a card
             * payment that is settling. They say nothing about whether money
             * moved, and they are NOT failures.
             *
             * The payment is recorded as processing and the booking stays
             * pending, so the customer keeps the slot and can retry the check.
             * Nothing here decides anything about the money.
             */
            if (self::isTransientProviderStatus($status)) {
                $this->markPaymentProcessing((int) $payment['id']);

                throw new PaymentPendingException(
                    'The payment provider has not finished processing this payment yet.',
                    $status !== '' ? substr($status, 0, 64) : 'unknown',
                    (int) $payment['id']
                );
            }

            if (self::isTerminalProviderStatus($status)) {
                /*
                 * A TERMINAL outcome - failed, abandoned, reversed - is recorded
                 * as a genuine failure, not ignored. These mean the money did
                 * not move and never will for this transaction.
                 */
                $this->markPaymentFailed((int) $payment['id'], 'provider_status_' . $status);

                throw new PaymentVerificationException(
                    'The payment provider did not confirm this payment (status: ' . $status . ').',
                    'provider_not_successful',
                    ['provider_status' => $status]
                );
            }

            if ($status !== 'success') {
                $this->markPaymentProcessing((int) $payment['id']);

                throw new PaymentPendingException(
                    'The payment provider returned an unrecognized status. Please check again shortly.',
                    $status !== '' ? substr($status, 0, 64) : 'unknown',
                    (int) $payment['id']
                );
            }

            /*
             * Everything below is a mismatch check. Any failure here means we
             * must NOT credit the booking, even though the provider said
             * 'success' - because it is not our transaction, or not our money.
             */
            $lateCancelled = false;
            try {
                $this->assertMatches($payment, $verification, $expectedBookingId);
            } catch (PaymentVerificationException $e) {
                if ($e->reason() !== 'booking_cancelled') {
                    throw $e;
                }
                $lateCancelled = true;
            }

            $result = $this->applyCapture($payment, $verification, $lateCancelled);
            if ($lateCancelled || ($result['reason'] ?? null) === 'duplicate_capture_refund_required') {
                $this->refundLateOrDuplicateCapture($payment);
            }
            return $result;
        }

        /**
         * Verify every attribute that must agree before money is credited.
         */
        private function assertMatches(array $payment, array $verification, ?string $expectedBookingId): void
        {
            // 1. The reference must be ours.
            if (
                !is_string($verification['reference'] ?? null)
                || trim($verification['reference']) === ''
            ) {
                throw new PaymentVerificationException(
                    'The provider did not report a reference, so the payment cannot be verified.',
                    'reference_missing'
                );
            }

            if ((string) $verification['reference'] !== (string) $payment['reference']) {
                throw new PaymentVerificationException(
                    'The provider returned a different reference than the one we recorded.',
                    'reference_mismatch',
                    [
                        'expected_reference' => (string) $payment['reference'],
                        'received_reference' => (string) $verification['reference'],
                    ]
                );
            }

            // 2. The amount must be exactly what we asked for.
            $expectedAmount = (int) $payment['amount_minor'];
            $receivedAmount = $verification['amount_minor'] ?? null;

            if (!is_int($receivedAmount) || $receivedAmount <= 0) {
                throw new PaymentVerificationException(
                    'The provider did not report a valid amount, so the payment cannot be verified.',
                    'amount_missing'
                );
            }

            if ($receivedAmount !== $expectedAmount) {
                throw new PaymentVerificationException(
                    'The provider confirmed a different amount than the one charged.',
                    'amount_mismatch',
                    ['expected_minor' => $expectedAmount, 'received_minor' => $receivedAmount]
                );
            }

            // 3. The currency must match.
            $expectedCurrency = strtoupper((string) $payment['currency']);
            $receivedCurrency = $verification['currency'] ?? null;

            if (!is_string($receivedCurrency) || trim($receivedCurrency) === '') {
                throw new PaymentVerificationException(
                    'The provider did not report a currency, so the payment cannot be verified.',
                    'currency_missing'
                );
            }

            $receivedCurrency = strtoupper($receivedCurrency);

            if ($receivedCurrency !== $expectedCurrency) {
                throw new PaymentVerificationException(
                    'The provider confirmed a different currency than the one charged.',
                    'currency_mismatch',
                    [
                        'expected_currency' => $expectedCurrency,
                        'received_currency' => $receivedCurrency,
                    ]
                );
            }

            if ((int) ($payment['booking_artist_id'] ?? 0) !== (int) $payment['artist_id']) {
                throw new PaymentVerificationException(
                    'This payment no longer belongs to the booking artist.',
                    'booking_owner_mismatch'
                );
            }

            // 4. The booking must still be ours and still payable.
            if ($expectedBookingId !== null && (string) $payment['booking_id'] !== (string) $expectedBookingId) {
                throw new PaymentVerificationException(
                    'This payment does not belong to the booking being paid.',
                    'booking_mismatch'
                );
            }

            $bookingStatus = (string) ($payment['booking_status'] ?? '');

            if (in_array($bookingStatus, ['cancelled'], true)) {
                /*
                 * Money genuinely arrived after the booking was cancelled. The
                 * capture is still recorded - that is what actually happened -
                 * but the booking must not be resurrected. It is flagged for
                 * the refund path instead.
                 */
                throw new PaymentVerificationException(
                    'This booking was cancelled; the captured payment requires a refund.',
                    'booking_cancelled',
                    ['booking_id' => (int) $payment['booking_id']]
                );
            }
        }

        /**
         * Write the capture, its split, and the owner's earning - atomically.
         */
        private function applyCapture(array $payment, array $verification, bool $lateCancelled = false): array
        {
            $paymentId = (int) $payment['id'];
            $bookingId = (int) $payment['booking_id'];

            $this->pdo->beginTransaction();

            try {
                /*
                 * Re-read the payment FOR UPDATE. Between the fast-path check
                 * above and this lock, a concurrent webhook may have confirmed
                 * the same payment. The lock plus a second status check inside
                 * the transaction is what actually prevents double crediting.
                 */
                $statement = $this->pdo->prepare(
                    'SELECT id, status FROM payments WHERE id = ? FOR UPDATE'
                );
                $statement->execute([$paymentId]);
                $current = $statement->fetch();

                if (!$current) {
                    throw new PaymentVerificationException(
                        'The payment disappeared during confirmation.',
                        'payment_missing'
                    );
                }

                if ((string) $current['status'] === 'succeeded') {
                    // A concurrent request won the race. Roll back and report.
                    $this->pdo->rollBack();

                    $fresh = $this->findPaymentByReference((string) $payment['reference']);

                    return $this->alreadyConfirmedResult($fresh);
                }

                if (!in_array((string) $current['status'], ['pending', 'processing'], true)) {
                    throw new PaymentVerificationException(
                        'This payment is no longer eligible for confirmation.',
                        'payment_not_confirmable'
                    );
                }

                $bookingLock = $this->pdo->prepare(
                    'SELECT status FROM bookings WHERE id = ? FOR UPDATE'
                );
                $bookingLock->execute([$bookingId]);
                $bookingStatus = $bookingLock->fetchColumn();
                if ($bookingStatus === false) {
                    throw new PaymentVerificationException('The booking disappeared during confirmation.', 'booking_missing');
                }
                $lateCancelled = $lateCancelled || $bookingStatus === 'cancelled';
                $priorCapture = $this->pdo->prepare("
                    SELECT id FROM payments
                    WHERE booking_id = ? AND id != ? AND status IN ('succeeded', 'duplicate_capture', 'partially_refunded', 'refunded')
                    LIMIT 1
                ");
                $priorCapture->execute([$bookingId, $paymentId]);
                $duplicateCapture = (bool) $priorCapture->fetchColumn();

                $gross = (int) $payment['amount_minor'];
                $commissionBps = (int) $payment['commission_bps'];

                /*
                 * The rate stored on the payment is used, never the current
                 * setting. Changing the commission later must not restate what
                 * this booking was split as.
                 */
                $split = ps_money_split($gross, $commissionBps);

                $providerFee = $verification['fee_minor'] === null
                    ? null
                    : (int) $verification['fee_minor'];

                $this->ledger->recordCapture(
                    $split,
                    $bookingId,
                    $paymentId,
                    (int) $payment['owner_id'],
                    (int) $payment['artist_id'],
                    $providerFee
                );

                $paidAt = $verification['paid_at'] !== null
                    ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $verification['paid_at']))
                    : gmdate('Y-m-d H:i:s');

                $this->pdo->prepare('
                    UPDATE payments
                    SET status = ?, failure_reason = ?, provider_reference = COALESCE(?, provider_reference),
                        provider_amount_minor = ?, provider_currency = ?,
                        provider_fee_minor = ?, provider_channel = ?,
                        paid_at = ?
                    WHERE id = ?
                ')->execute([
                    $duplicateCapture ? 'duplicate_capture' : 'succeeded',
                    $duplicateCapture ? 'duplicate_capture_refund_required' : null,
                    $verification['provider_reference'],
                    (int) ($verification['amount_minor'] ?? $gross),
                    $verification['currency'],
                    $providerFee,
                    $verification['channel'],
                    $paidAt,
                    $paymentId,
                ]);

                /*
                 * Create the owner's earning. UNIQUE booking_id means this can
                 * only ever exist once per booking even under a race.
                 */
                if (!$duplicateCapture) {
                    $this->createOwnerEarning($payment, $split, $providerFee ?? 0);
                }

                /*
                 * The booking becomes paid. `status` moves to 'escrow_held',
                 * which is retained for backward compatibility and now means
                 * "payment confirmed by the provider". It does NOT mean a third
                 * party is holding funds.
                 */
                $this->pdo->prepare("
                    UPDATE bookings
                    SET payment_state = ?, payment_expires_at = NULL,
                        status = CASE WHEN status IN ('cancelled', 'completed', 'disputed')
                                      THEN status ELSE ? END
                    WHERE id = ?
                ")->execute(['paid', 'escrow_held', $bookingId]);

                if ($duplicateCapture) {
                    $this->pdo->prepare("
                        UPDATE owner_payouts SET status = 'on_hold',
                            hold_reason = 'duplicate_payment_requires_refund'
                        WHERE booking_id = ? AND status IN ('pending_session', 'pending_delay', 'eligible')
                    ")->execute([$bookingId]);
                }

                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }

            return [
                'confirmed' => true,
                'already_confirmed' => false,
                'payment_id' => $paymentId,
                'booking_id' => $bookingId,
                'amount_minor' => $gross,
                'currency' => (string) $payment['currency'],
                'commission_minor' => $split['commission_minor'],
                'owner_net_minor' => $split['owner_net_minor'],
                'owner_id' => (int) $payment['owner_id'],
                'reason' => $duplicateCapture
                    ? 'duplicate_capture_refund_required'
                    : ($lateCancelled ? 'cancelled_booking_refund_required' : null),
            ];
        }

        private function refundLateOrDuplicateCapture(array $payment): void
        {
            require_once __DIR__ . '/RefundService.php';
            $refunds = new RefundService($this->pdo, $this->ledger, $this->provider());
            $refunds->requestRefund(
                (int) $payment['id'],
                null,
                'auto-capture-' . (int) $payment['id'],
                'Payment captured for a cancelled booking or duplicate payment.',
                (int) $payment['amount_minor'],
                'adjustment'
            );
        }

        /**
         * Record the owner's earning and when it may become eligible.
         *
         * Eligibility is recorded, never executed. No transfer is initiated and
         * no bank recipient is created anywhere in this codebase.
         */
        private function createOwnerEarning(array $payment, array $split, int $providerFeeMinor): void
        {
            $bookingId = (int) $payment['booking_id'];

            $sessionEndsAt = $this->pdo->prepare(
                'SELECT end_time FROM bookings WHERE id = ?'
            );
            $sessionEndsAt->execute([$bookingId]);
            $endTime = (string) $sessionEndsAt->fetchColumn();

            $statement = $this->pdo->prepare('
                INSERT INTO owner_payouts
                    (booking_id, payment_id, owner_id, currency,
                     gross_minor, commission_minor, provider_fee_minor, owner_net_minor,
                     commission_bps, status, session_ends_at, provider)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');

            $statement->execute([
                $bookingId,
                (int) $payment['id'],
                (int) $payment['owner_id'],
                (string) $payment['currency'],
                (int) $split['gross_minor'],
                (int) $split['commission_minor'],
                $providerFeeMinor,
                (int) $split['owner_net_minor'],
                (int) $split['commission_bps'],
                'pending_session',
                $endTime !== '' ? $endTime : null,
                (string) $payment['provider'],
            ]);
        }

        /**
         * The outcome for a payment that was already confirmed.
         *
         * Recomputed from the ledger rather than trusted from a cache, so the
         * reported split always matches what was actually written.
         */
        private function alreadyConfirmedResult(array $payment): array
        {
            $commission = $this->ledger->sumForBooking(
                (int) $payment['booking_id'],
                'platform_commission',
                'platform'
            );

            return [
                'confirmed' => true,
                'already_confirmed' => true,
                'payment_id' => (int) $payment['id'],
                'booking_id' => (int) $payment['booking_id'],
                'amount_minor' => (int) $payment['amount_minor'],
                'currency' => (string) $payment['currency'],
                'commission_minor' => $commission,
                'owner_net_minor' => $this->ledger->sumForBooking(
                    (int) $payment['booking_id'],
                    'owner_earning',
                    'owner'
                ),
                'owner_id' => (int) $payment['owner_id'],
                'reason' => null,
            ];
        }

        /* =================================================================
           Lookups and status
           ================================================================= */

        public function findPaymentByReference(string $reference): array
        {
            $statement = $this->pdo->prepare('
                SELECT p.*, b.status AS booking_status,
                       b.artist_id AS booking_artist_id
                FROM payments p
                INNER JOIN bookings b ON b.id = p.booking_id
                WHERE p.reference = ?
                LIMIT 1
            ');
            $statement->execute([$reference]);

            $payment = $statement->fetch();

            if (!$payment) {
                throw new PaymentVerificationException(
                    'No payment exists with that reference.',
                    'reference_unknown'
                );
            }

            return $payment;
        }

        /**
         * Current payment position for a booking, for the interface.
         *
         * Returns facts only. It never asserts that money is held anywhere.
         */
        public function statusForBooking(int $bookingId, int $actorUserId): array
        {
            $statement = $this->pdo->prepare('
                SELECT b.id, b.artist_id, b.studio_id, b.status, b.payment_state,
                       b.total_amount, b.total_amount_minor, b.payment_expires_at
                FROM bookings b
                WHERE b.id = ? AND b.artist_id = ?
                LIMIT 1
            ');
            $statement->execute([$bookingId, $actorUserId]);

            $booking = $statement->fetch();

            if (!$booking) {
                throw new PaymentProviderException('Booking not found.', 'booking_not_found', 404);
            }

            $payment = null;
            $paymentStatement = $this->pdo->prepare(
                'SELECT id, reference, status, amount_minor, currency, created_at, paid_at
                 FROM payments
                 WHERE booking_id = ?
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $paymentStatement->execute([$bookingId]);
            $payment = $paymentStatement->fetch() ?: null;

            $amountMinor = (int) $booking['total_amount_minor'];

            return [
                'booking_id' => (int) $booking['id'],
                'booking_status' => (string) $booking['status'],
                'payment_state' => (string) $booking['payment_state'],
                'amount_minor' => $amountMinor,
                'currency' => (string) ps_payment_config('currency'),
                'amount_display' => ps_money_format($amountMinor, (string) ps_payment_config('currency')),
                'is_paid' => in_array(
                    (string) $booking['payment_state'],
                    ['paid', 'partially_refunded', 'refunded'],
                    true
                ),
                'expires_at' => $booking['payment_expires_at'],
                'payment' => $payment === null ? null : [
                    'reference' => (string) $payment['reference'],
                    'status' => (string) $payment['status'],
                    'amount_minor' => (int) $payment['amount_minor'],
                    'created_at' => (string) $payment['created_at'],
                    'paid_at' => $payment['paid_at'],
                ],
                'refundable_minor' => $payment === null
                    ? 0
                    : $this->ledger->refundableForPayment((int) $payment['id']),
            ];
        }

        /* =================================================================
           Internals
           ================================================================= */

        /**
         * Record that a payment attempt definitively failed.
         *
         * Both rows are updated: the payment becomes 'failed' AND the booking's
         * payment_state follows, so the interface never shows a booking stuck
         * at 'pending' for an attempt the provider has already rejected.
         *
         * The session status is deliberately left alone - cancelling or
         * expiring a booking is a separate decision from a failed payment.
         */
        private function markPaymentFailed(int $paymentId, string $reason): void
        {
            $this->pdo->prepare(
                "UPDATE payments
                 SET status = 'failed', failure_reason = ?
                 WHERE id = ? AND status NOT IN ('succeeded', 'refunded')"
            )->execute([substr($reason, 0, 255), $paymentId]);

            $this->pdo->prepare(
                "UPDATE bookings b
                 INNER JOIN payments p ON p.booking_id = b.id
                 SET b.payment_state = 'failed'
                 WHERE p.id = ?
                   AND b.payment_state = 'pending'
                   AND b.status NOT IN ('cancelled', 'completed')"
            )->execute([$paymentId]);
        }

        /**
         * Provider statuses that mean "not decided yet", not "no".
         *
         * Paystack uses processing and queued for a card payment still settling.
         * The rest are defensive: a gateway in front of this code should not be
         * able to make a settling transaction look terminal.
         */
        public static function isTransientProviderStatus(string $status): bool
        {
            return in_array(
                strtolower(trim($status)),
                ['processing', 'queued', 'pending', 'initialized', 'accepted', 'in_progress'],
                true
            );
        }

        /**
         * Provider outcomes that definitively end an attempt without capture.
         */
        public static function isTerminalProviderStatus(string $status): bool
        {
            return in_array(
                strtolower(trim($status)),
                ['failed', 'abandoned', 'reversed', 'refunded', 'expired', 'cancelled', 'canceled', 'declined'],
                true
            );
        }

        /**
         * Was this row filed as failed even though the provider had not actually
         * given up?
         */
        private function wasMisfiledAsFailed(string $failureReason): bool
        {
            $reason = strtolower(trim($failureReason));

            if (str_starts_with($reason, 'provider_status_')) {
                $status = substr($reason, 16);

                return self::isTransientProviderStatus($status)
                    || (!self::isTerminalProviderStatus($status) && $status !== 'success');
            }

            return false;
        }

        /**
         * Re-open a row an older build misfiled as a permanent failure.
         *
         * Only reachable through wasMisfiledAsFailed(), so a genuinely failed
         * payment is never reopened.
         */
        private function reopenMisfiledPayment(int $paymentId): void
        {
            $this->pdo->prepare(
                "UPDATE payments
                 SET status = 'pending', failure_reason = NULL, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            )->execute([$paymentId]);

            $this->pdo->prepare(
                "UPDATE bookings b
                 INNER JOIN payments p ON p.booking_id = b.id
                 SET b.payment_state = 'pending'
                 WHERE p.id = ?
                   AND b.payment_state = 'failed'
                   AND b.status NOT IN ('cancelled', 'completed')"
            )->execute([$paymentId]);

            error_log(
                '[paradisestudios] reopened payment ' . $paymentId
                . ' misfiled as failed while the provider was still processing'
            );
        }

        /**
         * Record that a payment attempt is still being decided by the provider.
         *
         * The opposite of markPaymentFailed(): the payment becomes 'processing'
         * and the BOOKING IS LEFT ALONE at 'pending'. Nothing about the money is
         * decided, so nothing about the booking is decided either.
         *
         * The guard excludes any row that already reached a final state, so a
         * late "processing" from a duplicate webhook can never undo a real
         * success or a real failure.
         */
        private function markPaymentProcessing(int $paymentId): void
        {
            $this->pdo->prepare(
                "UPDATE payments
                 SET status = 'processing',
                     failure_reason = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?
                   AND status NOT IN ('succeeded', 'refunded', 'failed', 'abandoned')"
            )->execute([$paymentId]);
        }

        /**
         * Reduce any exception to a short, safe reason string.
         *
         * Never stores a message that could contain a key or provider payload.
         */
        private function safeReason(Throwable $e): string
        {
            if ($e instanceof PaymentProviderException) {
                return $e->safeCode();
            }

            if ($e instanceof PaymentConfigurationException) {
                return 'provider_not_configured';
            }

            return 'initialize_failed';
        }

        private function provider(): PaymentProviderInterface
        {
            return $this->provider ??= ps_payment_provider();
        }
    }
}
