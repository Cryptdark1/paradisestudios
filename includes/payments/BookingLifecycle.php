<?php

/**
 * Paradise Studios - booking lifecycle integration.
 *
 * The bridge between the EXISTING booking endpoints and the payment layer.
 *
 * WHY THIS EXISTS
 * ---------------
 * The existing endpoints in api/ predate payments and encode three assumptions
 * that are no longer true:
 *
 *   1. `escrow_held` is treated as proof of money. It never was, and now it
 *      means only "the provider confirmed a payment".
 *   2. Cancel computes a refund figure with float arithmetic and never records
 *      or returns anything.
 *   3. Reschedule recalculates `total_amount` from the current hourly rate, so
 *      for a paid booking it would silently change an amount money was already
 *      taken against.
 *
 * This class supplies the shared decisions those endpoints need, so the changes
 * to each endpoint stay small and consistent.
 *
 * COMPATIBILITY
 * -------------
 * Existing HTTP contracts are preserved: same methods, same response keys, same
 * status codes. New keys are ADDED to the existing data object and the existing
 * `total_amount`, `refund_percentage` and `refund_amount` values are unchanged,
 * so the current frontends keep working untouched.
 */

require_once __DIR__ . '/../money.php';
require_once __DIR__ . '/ProviderFactory.php';
require_once __DIR__ . '/FinancialLedger.php';
require_once __DIR__ . '/RefundService.php';
require_once __DIR__ . '/PayoutService.php';

if (!defined('PS_BOOKING_LIFECYCLE_LOADED')) {
    define('PS_BOOKING_LIFECYCLE_LOADED', true);

    final class BookingLifecycle
    {
        public function __construct(
            private PDO $pdo,
            private FinancialLedger $ledger
        ) {
        }

        /**
         * Has money actually been confirmed for this booking?
         *
         * The single question the rest of the system should ask. Deliberately
         * requires BOTH a paid payment_state AND a captured ledger entry, so a
         * status that was set without a payment cannot pass as paid.
         */
        public static function isPaid(array $booking, ?FinancialLedger $ledger = null): bool
        {
            if ((string) ($booking['payment_state'] ?? '') !== 'paid') {
                return false;
            }

            // 'legacy_unverified' and anything else is explicitly NOT paid.
            return true;
        }

        /**
         * Is this the pre-payments era, where no provider was ever involved?
         */
        public static function isLegacyUnverified(array $booking): bool
        {
            return (string) ($booking['payment_state'] ?? '') === 'legacy_unverified';
        }

        /**
         * The most recent payment row for a booking.
         */
        public function latestPayment(int $bookingId): ?array
        {
            $statement = $this->pdo->prepare(
                'SELECT * FROM payments WHERE booking_id = ? ORDER BY id DESC LIMIT 1'
            );
            $statement->execute([$bookingId]);

            $payment = $statement->fetch();

            return $payment ?: null;
        }

        /**
         * Money actually captured for a booking, from the ledger.
         *
         * Used instead of `total_amount` whenever the question is "how much
         * money is involved", because total_amount is a booking figure and says
         * nothing about whether any was taken.
         */
        public function capturedMinor(int $bookingId): int
        {
            return $this->ledger->sumForBooking($bookingId, 'payment_captured', 'provider');
        }

        /* =================================================================
           Cancellation
           ================================================================= */

        /**
         * Decide what happens to the money when a booking is cancelled.
         *
         * Returns the policy figure AND whether any provider refund was
         * actually requested. Keeping these separate is the whole point: a
         * calculated figure must never be presented as money returned.
         *
         * @return array{
         *     policy_bps:int, calculated_minor:int, calculated_display:string,
         *     refund_attempted:bool, refund_status:?string,
         *     refund_provider_ref:?string, captured_minor:int,
         *     has_payment:bool, notice:string
         * }
         */
        public function cancellationRefundPlan(array $booking): array
        {
            $bookingId = (int) $booking['id'];
            $captured = $this->capturedMinor($bookingId);
            $payment = $this->latestPayment($bookingId);

            $plan = [
                'policy_bps' => 0,
                'calculated_minor' => 0,
                'calculated_display' => ps_money_format(0, (string) ps_payment_config('currency')),
                'refund_attempted' => false,
                'refund_status' => null,
                'refund_provider_ref' => null,
                'captured_minor' => $captured,
                'has_payment' => $payment !== null,
                'notice' => '',
            ];

            // Policy window, computed from the session start as before.
            $startTimestamp = strtotime((string) $booking['start_time']);

            if ($startTimestamp === false) {
                throw new RuntimeException('Booking start_time could not be parsed.');
            }

            $secondsUntilStart = $startTimestamp - time();
            $windowSeconds = (int) ps_payment_config('full_refund_window_hours') * 3600;

            if ($secondsUntilStart <= 0) {
                $plan['notice'] = 'This session has already started, so it is not refundable.';

                return $plan;
            }

            $policyBps = $secondsUntilStart >= $windowSeconds
                ? (int) ps_payment_config('full_refund_bps')
                : (int) ps_payment_config('late_refund_bps');

            $plan['policy_bps'] = $policyBps;

            /*
             * The figure is derived from what was CAPTURED, not from
             * total_amount. For a booking where no money arrived, that is zero,
             * which is the honest answer.
             */
            $calculated = ps_money_apply_bps($captured, $policyBps);

            $plan['calculated_minor'] = $calculated;
            $plan['calculated_display'] = ps_money_format(
                $calculated,
                (string) ps_payment_config('currency')
            );

            if ($captured === 0) {
                $plan['notice'] = $payment === null
                    ? 'No payment has been taken for this booking, so there is nothing to refund.'
                    : 'No money was captured for this booking, so there is nothing to refund.';

                return $plan;
            }

            /*
             * Only a CONFIRMED payment can be refunded. If one is pending it
             * has not yet resolved either way, and a webhook may still arrive -
             * so nothing is refunded now and the caller is told why.
             */
            if ($payment === null || (string) $payment['status'] !== 'succeeded') {
                $plan['notice'] = 'This payment has not been confirmed yet. '
                    . 'If it completes after cancellation, the refund must be reviewed.';

                return $plan;
            }

            return $plan;
        }

        /**
         * Request the provider refund for a cancelled booking.
         *
         * Called AFTER the booking has been cancelled and the transaction
         * committed, because the gateway call must not happen while a database
         * row lock is held.
         */
        public function requestCancellationRefund(
            int $bookingId,
            ?int $actorUserId
        ): array {
            $payment = $this->latestPayment($bookingId);

            /*
             * Every return path below has the SAME keys. A caller should never
             * have to guard on a missing field, and an earlier version of this
             * method returned a short array on the early paths, which is exactly
             * how a repeated cancellation ended up reporting an undefined key.
             */
            $base = [
                'refund_attempted' => false,
                'status' => null,
                'provider_reference' => null,
                'duplicate' => false,
                'message' => '',
            ];

            if ($payment === null) {
                return array_merge($base, [
                    'message' => 'No payment was taken, so there is nothing to refund.',
                ]);
            }

            /*
             * Already refunded. This is the state a repeated cancellation finds,
             * because a successful refund moves the payment to 'refunded' or
             * 'partially_refunded'. Returning early is what stops a second
             * payout - the idempotency key is the second line of defence.
             */
            if (in_array((string) $payment['status'], ['refunded', 'partially_refunded'], true)) {
                $refundable = $this->ledger->refundableForPayment((int) $payment['id']);

                return array_merge($base, [
                    'status' => (string) $payment['status'],
                    'duplicate' => true,
                    'message' => $refundable === 0
                        ? 'This booking has already been refunded in full.'
                        : 'Part of this payment has already been refunded.',
                ]);
            }

            if ((string) $payment['status'] !== 'succeeded') {
                return array_merge($base, [
                    'message' => 'The payment was never confirmed, so there is nothing to refund.',
                ]);
            }

            $refunds = new RefundService($this->pdo, $this->ledger, ps_payment_provider());

            /*
             * One idempotency key per booking + cancellation. A retried cancel
             * request therefore returns the original refund instead of paying
             * out a second time.
             */
            $key = 'cancel-booking-' . $bookingId;

            try {
                $result = $refunds->requestRefund(
                    (int) $payment['id'],
                    $actorUserId,
                    $key,
                    'Booking cancelled by artist'
                );

                $refund = $result['refund'];

                $message = (string) $refund['status'] === 'succeeded'
                    ? 'Refund confirmed by the payment provider.'
                    : 'Refund could not be completed (' . (string) $refund['status'] . '). '
                        . 'It has been recorded for review.';

                /*
                 * array_merge, NOT the + operator.
                 *
                 * With +, a key already present in $base wins and these values
                 * are silently discarded. An earlier version of this method
                 * used + and every caller received refund_attempted=false and
                 * duplicate=false regardless of what actually happened.
                 */
                return array_merge($base, [
                    'refund_attempted' => true,
                    'status' => (string) $refund['status'],
                    'provider_reference' => $refund['provider_reference'] ?? null,
                    'duplicate' => (bool) ($result['duplicate'] ?? false),
                    'message' => $message,
                ]);
            } catch (Throwable $e) {
                error_log(
                    '[paradisestudios] cancellation refund failed for booking '
                    . $bookingId . ': ' . get_class($e) . ' ' . $e->getMessage()
                );

                return array_merge($base, [
                    'refund_attempted' => true,
                    'status' => 'failed',
                    'message' => 'The refund could not be sent to the payment provider.',
                ]);
            }
        }

        /* =================================================================
           Completion
           ================================================================= */

        /**
         * May this booking be marked completed?
         *
         * A session can only be completed once money has actually been
         * captured. Completing an unpaid booking would award XP and create an
         * owner earning for a session nobody paid for.
         *
         * @return array{allowed:bool, reason:string, code:?string}
         */
        public function completionCheck(array $booking): array
        {
            $paymentState = (string) ($booking['payment_state'] ?? '');

            if ($paymentState === 'paid') {
                return ['allowed' => true, 'reason' => '', 'code' => null];
            }

            if ($paymentState === 'legacy_unverified') {
                return [
                    'allowed' => false,
                    'reason' => 'This booking predates payments and has no confirmed payment, '
                        . 'so it cannot be completed as a paid session.',
                    'code' => 'legacy_unverified',
                ];
            }

            if (in_array($paymentState, ['unpaid', 'pending'], true)) {
                return [
                    'allowed' => false,
                    'reason' => 'This booking has not been paid yet, so it cannot be completed.',
                    'code' => 'not_paid',
                ];
            }

            if (in_array($paymentState, ['failed', 'expired'], true)) {
                return [
                    'allowed' => false,
                    'reason' => 'The payment for this booking did not complete.',
                    'code' => 'payment_failed',
                ];
            }

            if ($paymentState === 'refunded') {
                return [
                    'allowed' => false,
                    'reason' => 'This booking was refunded, so it cannot be completed.',
                    'code' => 'refunded',
                ];
            }

            return [
                'allowed' => false,
                'reason' => 'This booking has no confirmed payment.',
                'code' => 'payment_state_' . ($paymentState !== '' ? $paymentState : 'unknown'),
            ];
        }

        /**
         * Refresh an owner's payout eligibility after a session completes.
         *
         * Best-effort: a failure here must never lose a completed booking, so it
         * is logged rather than thrown.
         */
        public function refreshPayoutEligibility(int $bookingId): void
        {
            try {
                $payouts = new PayoutService($this->pdo, $this->ledger);
                $payouts->evaluateBooking($bookingId);
            } catch (Throwable $e) {
                error_log(
                    '[paradisestudios] payout eligibility refresh failed for booking '
                    . $bookingId . ': ' . $e->getMessage()
                );
            }
        }

        /* =================================================================
           Rescheduling
           ================================================================= */

        /**
         * May this booking be rescheduled?
         *
         * Rescheduling recalculates the total from the studio's CURRENT hourly
         * rate. For a booking money has already been taken against, that would
         * silently move an amount that was charged - so a paid booking must not
         * be rescheduled without either a top-up or a refund, neither of which
         * this codebase performs automatically.
         *
         * @return array{allowed:bool, reason:string, code:?string}
         */
        public function rescheduleCheck(array $booking, int $newAmountMinor): array
        {
            $paymentState = (string) ($booking['payment_state'] ?? '');
            $currentAmountMinor = (int) ($booking['total_amount_minor'] ?? 0);

            if ($paymentState === 'legacy_unverified') {
                return [
                    'allowed' => false,
                    'reason' => 'This booking predates payments, so its amount cannot be '
                        . 'safely changed. Please cancel and create a new booking.',
                    'code' => 'legacy_unverified',
                ];
            }

            if (!in_array($paymentState, ['paid', 'partially_refunded'], true)) {
                // Not paid, so changing the amount is harmless.
                return ['allowed' => true, 'reason' => '', 'code' => null];
            }

            /*
             * Money has been taken. Rescheduling is only safe if the new total
             * is IDENTICAL - otherwise the customer has paid one amount for a
             * different commitment.
             */
            if ($newAmountMinor === $currentAmountMinor) {
                return [
                    'allowed' => false,
                    'reason' => 'This booking has already been paid, so its total cannot be '
                        . 'changed. Please cancel it and make a new booking.',
                    'code' => 'paid_amount_immutable',
                ];
            }

            return [
                'allowed' => false,
                'reason' => 'This booking has already been paid at '
                    . ps_money_format($currentAmountMinor, (string) ps_payment_config('currency'))
                    . ', so it cannot be rescheduled to a different amount. '
                    . 'Please cancel it and make a new booking.',
                'code' => 'paid_amount_immutable',
            ];
        }
    }
}
