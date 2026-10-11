<?php

/**
 * Paradise Studios - owner earnings and payout ELIGIBILITY.
 *
 * ELIGIBILITY IS NOT PAYOUT. This class decides when money an owner has earned
 * may be released, and records that decision. It never creates a bank recipient,
 * never initiates a transfer, and never asks a payment provider to hold funds
 * on an owner's behalf. No payment provider works that way: money captured for
 * a booking belongs to the platform merchant account until it is paid out, and
 * nothing here pretends otherwise.
 *
 * WHEN AN EARNING BECOMES ELIGIBLE
 *   All of the following must hold:
 *     1. the payment is confirmed  (payment_state is 'paid')
 *     2. the session has ENDED     (now >= booking.end_time)
 *     3. the delay has passed      (now >= end_time + PAYOUT_ELIGIBILITY_DELAY_HOURS)
 *     4. the booking is not disputed
 *     5. the earning has not been fully reversed by a refund
 *
 * The delay exists so a customer who has just been charged has time to raise a
 * problem before an owner is credited.
 */

require_once __DIR__ . '/FinancialLedger.php';

if (!defined('PS_PAYOUT_SERVICE_LOADED')) {
    define('PS_PAYOUT_SERVICE_LOADED', true);

    final class PayoutService
    {
        public function __construct(
            private PDO $pdo,
            private FinancialLedger $ledger
        ) {
        }

        /**
         * Decide and record the eligibility of one booking's earning.
         *
         * Idempotent: calling it repeatedly re-evaluates and moves the row to
         * whichever state is currently correct.
         *
         * @return array{booking_id:int, status:string, eligible:bool,
         *               reason:string, owner_net_minor:int,
         *               reversed_minor:int, eligible_at:?string}
         */
        public function evaluateBooking(int $bookingId): array
        {
            $statement = $this->pdo->prepare('
                SELECT
                    op.*,
                    b.status AS booking_status,
                    b.payment_state,
                    b.end_time
                FROM owner_payouts op
                INNER JOIN bookings b ON b.id = op.booking_id
                WHERE op.booking_id = ?
                LIMIT 1
            ');
            $statement->execute([$bookingId]);

            $row = $statement->fetch();

            if (!$row) {
                throw new PaymentValidationException(
                    'No owner earning exists for booking ' . $bookingId . '.'
                );
            }

            $reason = 'eligible';
            $nextStatus = 'eligible';

            /*
             * A fully reversed earning is terminal. It must never become
             * eligible again, even if the booking is later completed - paying
             * it out would credit an owner for money that was returned.
             */
            if ((int) $row['owner_net_minor'] - (int) $row['reversed_minor'] <= 0) {
                $reason = 'fully_reversed';
                $nextStatus = 'reversed';
            } elseif ((string) $row['booking_status'] === 'disputed') {
                $reason = 'booking_disputed';
                $nextStatus = 'on_hold';
            } elseif ((int) $row['reversed_minor'] > 0) {
                $reason = 'partially_reversed';
                $nextStatus = 'on_hold';
            } elseif ((string) $row['booking_status'] !== 'completed') {
                $reason = 'booking_not_completed';
                $nextStatus = 'pending_session';
            } elseif ((string) $row['payment_state'] !== 'paid') {
                $reason = 'payment_not_confirmed';
                $nextStatus = 'pending_session';
            } else {
                $sessionEndsAt = strtotime((string) $row['end_time']);

                if ($sessionEndsAt === false) {
                    throw new RuntimeException('Booking end_time could not be parsed.');
                }

                $eligibleAtTimestamp = $sessionEndsAt
                    + ((int) ps_payment_config('payout_eligibility_delay_hours') * 3600);

                if (time() < $eligibleAtTimestamp) {
                    $reason = 'inside_eligibility_delay';
                    $nextStatus = 'pending_delay';
                    $eligibleAt = gmdate('Y-m-d H:i:s', $eligibleAtTimestamp);
                } else {
                    $eligibleAt = gmdate('Y-m-d H:i:s', $eligibleAtTimestamp);
                }
            }

            /*
             * Never move an earning BACKWARDS out of a state it was deliberately
             * put into.
             *
             * 'paid' and 'reversed' are terminal. 'on_hold' is sticky for a
             * different reason: it is set deliberately by placeOnHold() for a
             * dispute or an administrative review, and a routine re-evaluation
             * must not quietly release it. Without this, calling
             * evaluateForOwner() after a hold was applied would undo the hold
             * and could make money payable while it was still being reviewed.
             */
            $current = (string) $row['status'];

            if (in_array($current, ['paid', 'reversed'], true)) {
                $nextStatus = $current;
                $reason = $current === 'paid' ? 'already_paid' : 'fully_reversed';
                $eligibleAt = $row['eligible_at'];
            } elseif ($current === 'on_hold') {
                $nextStatus = 'on_hold';
                $reason = 'on_hold';
                $eligibleAt = $row['eligible_at'];
            }

            $this->pdo->prepare('
                UPDATE owner_payouts
                SET status = ?,
                    eligible_at = COALESCE(?, eligible_at),
                    hold_reason = ?
                WHERE booking_id = ? AND status NOT IN (?, ?)
            ')->execute([
                $nextStatus,
                $eligibleAt ?? null,
                in_array($nextStatus, ['on_hold', 'reversed'], true) ? $reason : null,
                $bookingId,
                'paid',
                'reversed',
            ]);

            $refreshed = $this->pdo->prepare(
                'SELECT status, eligible_at, owner_net_minor, reversed_minor
                 FROM owner_payouts WHERE booking_id = ? LIMIT 1'
            );
            $refreshed->execute([$bookingId]);
            $final = $refreshed->fetch();

            return [
                'booking_id' => $bookingId,
                'status' => (string) $final['status'],
                'eligible' => (string) $final['status'] === 'eligible',
                'reason' => $reason,
                'owner_net_minor' => (int) $final['owner_net_minor'],
                'reversed_minor' => (int) $final['reversed_minor'],
                'payable_minor' => max(
                    0,
                    (int) $final['owner_net_minor'] - (int) $final['reversed_minor']
                ),
                'eligible_at' => $final['eligible_at'],
            ];
        }

        /**
         * Move an earning onto hold, e.g. after a dispute is opened.
         */
        public function placeOnHold(int $bookingId, string $reason): void
        {
            $this->pdo->prepare(
                "UPDATE owner_payouts
                 SET status = 'on_hold', hold_reason = ?
                 WHERE booking_id = ? AND status IN ('pending_session', 'pending_delay', 'eligible')"
            )->execute([substr($reason, 0, 255), $bookingId]);
        }

        public function releaseDuplicatePaymentHold(int $bookingId): void
        {
            $this->pdo->prepare("
                UPDATE owner_payouts
                SET status = 'pending_session', hold_reason = NULL
                WHERE booking_id = ? AND status = 'on_hold'
                  AND hold_reason = 'duplicate_payment_requires_refund'
            ")->execute([$bookingId]);
            $this->evaluateBooking($bookingId);
        }

        /**
         * Re-evaluate every earning for an owner. Used after a booking is
         * completed or a dispute is raised.
         */
        public function evaluateForOwner(int $ownerId): array
        {
            $statement = $this->pdo->prepare(
                'SELECT booking_id FROM owner_payouts WHERE owner_id = ?'
            );
            $statement->execute([$ownerId]);

            $results = [];

            foreach ($statement->fetchAll() as $row) {
                $results[] = $this->evaluateBooking((int) $row['booking_id']);
            }

            return $results;
        }

        /**
         * An owner's earning position, derived from the ledger.
         *
         * Sums the CONFIRMED movements only. Nothing here is a promise to pay.
         */
        public function summaryForOwner(int $ownerId): array
        {
            $ledgerTotals = $this->pdo->prepare('
                SELECT
                    COALESCE(SUM(CASE WHEN entry_type = ? THEN amount_minor ELSE 0 END), 0) AS earned,
                    COALESCE(SUM(CASE WHEN entry_type = ? THEN amount_minor ELSE 0 END), 0) AS reversed,
                    COALESCE(SUM(CASE WHEN entry_type = ? THEN amount_minor ELSE 0 END), 0) AS paid_out,
                    COALESCE(SUM(CASE WHEN entry_type = ? THEN amount_minor ELSE 0 END), 0) AS payout_reversed
                FROM financial_ledger
                WHERE party = ? AND party_user_id = ?
            ');
            $ledgerTotals->execute([
                'owner_earning',
                'owner_earning_reversed',
                'payout',
                'payout_reversed',
                'owner',
                $ownerId,
            ]);
            $totals = $ledgerTotals->fetch();

            $earned = (int) $totals['earned'];
            $reversed = (int) $totals['reversed'];

            $byStatus = $this->pdo->prepare(
                'SELECT status, COALESCE(SUM(owner_net_minor - reversed_minor), 0) AS amount
                 FROM owner_payouts
                 WHERE owner_id = ?
                 GROUP BY status'
            );
            $byStatus->execute([$ownerId]);

            $statusTotals = [];

            foreach ($byStatus->fetchAll() as $row) {
                $statusTotals[(string) $row['status']] = (int) $row['amount'];
            }

            return [
                'owner_id' => $ownerId,
                'currency' => (string) ps_payment_config('currency'),
                'total_earned_minor' => $earned,
                'total_reversed_minor' => $reversed,
                'total_paid_out_minor' => max(0, (int) $totals['paid_out'] - (int) $totals['payout_reversed']),
                'eligible_minor' => $statusTotals['eligible'] ?? 0,
                'pending_minor' => ($statusTotals['pending_session'] ?? 0)
                    + ($statusTotals['pending_delay'] ?? 0),
                'on_hold_minor' => $statusTotals['on_hold'] ?? 0,
                'reversed_minor' => $statusTotals['reversed'] ?? 0,
                'available_minor' => $statusTotals['eligible'] ?? 0,
                'total_earned_display' => ps_money_format(
                    $earned,
                    (string) ps_payment_config('currency')
                ),
                'available_display' => ps_money_format(
                    $statusTotals['eligible'] ?? 0,
                    (string) ps_payment_config('currency')
                ),
                'notice' => 'Eligible earnings require an explicit owner transfer request.',
            ];
        }

        /**
         * Earnings that may be released right now.
         */
        public function eligibleEarnings(int $ownerId): array
        {
            $statement = $this->pdo->prepare(
                'SELECT booking_id, currency, owner_net_minor, reversed_minor, eligible_at
                 FROM owner_payouts
                 WHERE owner_id = ? AND status = ?
                 ORDER BY eligible_at ASC'
            );
            $statement->execute([$ownerId, 'eligible']);

            $rows = [];

            foreach ($statement->fetchAll() as $row) {
                $rows[] = [
                    'booking_id' => (int) $row['booking_id'],
                    'net_minor' => max(
                        0,
                        (int) $row['owner_net_minor'] - (int) $row['reversed_minor']
                    ),
                    'eligible_at' => $row['eligible_at'],
                ];
            }

            return $rows;
        }
    }
}
