<?php

/**
 * Paradise Studios - financial ledger.
 *
 * The single write path for every financial event. Nothing else in the
 * application inserts into financial_ledger, so the journal has exactly one
 * shape and one meaning.
 *
 * DESIGN RULES
 *   * APPEND-ONLY. There is deliberately no update and no delete method.
 *   * NO STORED BALANCES. A balance is always SUM() over entries, so a mistake
 *     in one place cannot corrupt a running total held elsewhere.
 *   * THE RATE IS STORED ON EVERY ENTRY. A later change to the commission
 *     setting must never recalculate history, so each row records the rate
 *     that was actually in force when it was written.
 *   * INTEGER MINOR UNITS ONLY. Callers pass and receive integers; this class
 *     never accepts a float.
 *
 * A capture writes three entries that must reconcile exactly:
 *
 *     payment_captured     gross            (party: provider)
 *     platform_commission  commission       (party: platform)
 *     owner_earning        gross - commission(party: owner)
 *
 * gross === commission + ownerNet is asserted, not assumed.
 */

require_once __DIR__ . '/../money.php';
require_once __DIR__ . '/../../config/payments.php';

if (!defined('PS_LEDGER_LOADED')) {
    define('PS_LEDGER_LOADED', true);

    final class FinancialLedger
    {
        public function __construct(private PDO $pdo)
        {
        }

        /**
         * Append one entry.
         *
         * @param string     $entryType   one of the financial_ledger.entry_type values
         * @param string     $party       provider | platform | owner | artist
         * @param int        $amountMinor positive magnitude in minor units
         * @param array      $fields      booking_id, payment_id, refund_id,
         *                                 payout_id, party_user_id, commission_bps,
         *                                 description, metadata
         */
        public function record(
            string $entryType,
            string $party,
            int $amountMinor,
            array $fields = []
        ): int {
            if ($amountMinor < 0) {
                throw new InvalidArgumentException(
                    'Ledger amounts are magnitudes and must not be negative. '
                    . 'Use entry_type to express direction.'
                );
            }

            $commissionBps = $fields['commission_bps'] ?? null;

            if ($commissionBps !== null) {
                ps_money_validate_bps((int) $commissionBps);
            }

            $statement = $this->pdo->prepare('
                INSERT INTO financial_ledger
                    (party, party_user_id, entry_type, booking_id, payment_id,
                     refund_id, payout_id, currency, amount_minor,
                     commission_bps, description, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');

            $statement->execute([
                $party,
                $fields['party_user_id'] ?? null,
                $entryType,
                $fields['booking_id'] ?? null,
                $fields['payment_id'] ?? null,
                $fields['refund_id'] ?? null,
                $fields['payout_id'] ?? null,
                $fields['currency'] ?? (string) ps_payment_config('currency'),
                $amountMinor,
                $commissionBps,
                $fields['description'] ?? null,
                isset($fields['metadata'])
                    ? json_encode($fields['metadata'], JSON_UNESCAPED_SLASHES)
                    : null,
            ]);

            return (int) $this->pdo->lastInsertId();
        }

        /**
         * Write the three entries that represent a captured payment.
         *
         * All three are written inside the caller's transaction, so a failure
         * part-way through cannot leave a capture without its split, or an
         * earning without a capture.
         *
         * @param array $split result of ps_money_split()
         *
         * @return int[] the ledger entry ids, in write order
         */
        public function recordCapture(
            array $split,
            int $bookingId,
            int $paymentId,
            int $ownerId,
            int $artistId,
            ?int $providerFeeMinor = null
        ): array {
            $commissionBps = (int) $split['commission_bps'];
            $currency = (string) ps_payment_config('currency');

            // Defence in depth: never write a split that does not reconcile.
            if (
                (int) $split['commission_minor'] + (int) $split['owner_net_minor']
                !== (int) $split['gross_minor']
            ) {
                throw new RuntimeException(
                    'Refusing to write a capture whose commission and owner net do not '
                    . 'reconcile to the gross amount.'
                );
            }

            $ids = [];

            $ids[] = $this->record('payment_captured', 'provider', (int) $split['gross_minor'], [
                'booking_id' => $bookingId,
                'payment_id' => $paymentId,
                'commission_bps' => $commissionBps,
                'description' => 'Payment captured',
                'metadata' => ['artist_id' => $artistId, 'owner_id' => $ownerId],
            ]);

            /*
             * The processing fee is written only when the provider actually
             * reported one. It is never estimated from a published rate, because
             * a wrong fee silently changes the owner's net.
             */
            if ($providerFeeMinor !== null && $providerFeeMinor > 0) {
                $ids[] = $this->record('provider_fee', 'provider', $providerFeeMinor, [
                    'booking_id' => $bookingId,
                    'payment_id' => $paymentId,
                    'description' => 'Payment processing fee reported by provider',
                ]);
            }

            $ids[] = $this->record('platform_commission', 'platform', (int) $split['commission_minor'], [
                'booking_id' => $bookingId,
                'payment_id' => $paymentId,
                'commission_bps' => $commissionBps,
                'description' => 'Platform commission',
            ]);

            $ids[] = $this->record('owner_earning', 'owner', (int) $split['owner_net_minor'], [
                'booking_id' => $bookingId,
                'payment_id' => $paymentId,
                'party_user_id' => $ownerId,
                'commission_bps' => $commissionBps,
                'description' => 'Owner earning',
            ]);

            return $ids;
        }

        /**
         * Sum amounts for one booking, optionally by entry type.
         *
         * SUM() over BIGINT UNSIGNED in MariaDB returns a DECIMAL, so the
         * result is cast rather than passed through a float.
         *
         * @return int
         */
        public function sumForBooking(int $bookingId, ?string $entryType = null, ?string $party = null): int
        {
            $sql = 'SELECT COALESCE(SUM(amount_minor), 0)
                    FROM financial_ledger
                    WHERE booking_id = ?';
            $parameters = [$bookingId];

            if ($entryType !== null) {
                $sql .= ' AND entry_type = ?';
                $parameters[] = $entryType;
            }

            if ($party !== null) {
                $sql .= ' AND party = ?';
                $parameters[] = $party;
            }

            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);

            return (int) $statement->fetchColumn();
        }

        /**
         * Total captured for a payment.
         */
        public function capturedForPayment(int $paymentId): int
        {
            $statement = $this->pdo->prepare(
                'SELECT COALESCE(SUM(amount_minor), 0)
                 FROM financial_ledger
                 WHERE payment_id = ?
                   AND entry_type = ?'
            );
            $statement->execute([$paymentId, 'payment_captured']);

            return (int) $statement->fetchColumn();
        }

        /**
         * Total successfully refunded for a payment.
         *
         * Only 'refund' entries count, and only those whose refund row reached
         * status 'succeeded'. A refund that was requested but not confirmed has
         * moved no money and must not reduce the refundable balance.
         */
        public function refundedForPayment(int $paymentId): int
        {
            $statement = $this->pdo->prepare('
                SELECT COALESCE(SUM(l.amount_minor), 0)
                FROM financial_ledger l
                INNER JOIN refunds r ON r.id = l.refund_id
                WHERE l.payment_id = ?
                  AND l.entry_type = ?
                  AND r.status = ?
            ');
            $statement->execute([$paymentId, 'refund', 'succeeded']);

            return (int) $statement->fetchColumn();
        }

        /**
         * Money still available to refund for a payment.
         *
         * This is the cap every refund must respect, and it is derived from
         * CONFIRMED movements only - never from a booking's total_amount, which
         * says nothing about whether money arrived.
         */
        public function refundableForPayment(int $paymentId): int
        {
            return max(
                0,
                $this->capturedForPayment($paymentId) - $this->refundedForPayment($paymentId)
            );
        }

        /**
         * Every entry for a booking, newest first. Diagnostics and tests.
         */
        public function entriesForBooking(int $bookingId): array
        {
            $statement = $this->pdo->prepare(
                'SELECT id, party, party_user_id, entry_type, payment_id, refund_id,
                        payout_id, currency, amount_minor, commission_bps, description, created_at
                 FROM financial_ledger
                 WHERE booking_id = ?
                 ORDER BY id ASC'
            );
            $statement->execute([$bookingId]);

            return $statement->fetchAll();
        }

        /**
         * Count of entries of a type for a payment.
         *
         * Used to prove idempotency: confirming the same payment twice must
         * leave exactly one capture entry.
         */
        public function countEntries(int $paymentId, string $entryType): int
        {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM financial_ledger WHERE payment_id = ? AND entry_type = ?'
            );
            $statement->execute([$paymentId, $entryType]);

            return (int) $statement->fetchColumn();
        }
    }
}
