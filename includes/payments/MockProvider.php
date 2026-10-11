<?php

/**
 * Paradise Studios - local mock payment provider.
 *
 * Lets the whole payment stack be exercised with no gateway account, no API
 * keys and no network access. This is what the automated test suite runs
 * against, and what local development should use.
 *
 * IT DOES NOT MOVE MONEY. It simulates a gateway so the application logic
 * around it can be proven correct before a real provider exists.
 *
 * SERVER-SIDE STATE
 * -----------------
 * The mock keeps its transactions in `mock_gateway_transactions`, not in a PHP
 * static, because the real flow spans separate requests:
 *
 *     POST /api/payment-initialize.php   -> creates a transaction
 *     POST /api/payment-verify.php       -> must find it
 *     POST /api/payment-webhook.php      -> must find it again
 *
 * Each is a distinct PHP process, so in-memory state was empty by the time
 * verification ran and the local flow was impossible to exercise. A real gateway
 * has server-side state, and so does this one.
 *
 * That table is for local development only. It holds no real money and is never
 * read by the Paystack adapter.
 *
 * SIGNS WEBHOOKS FOR REAL
 * -----------------------
 * HMAC-SHA512 over the exact raw body, the same algorithm Paystack uses, so the
 * entire webhook pipeline - signature, replay, idempotency - is testable without
 * an account.
 *
 * REFUSES TO RUN LIVE
 * -------------------
 * initialize/verify/refund all throw while PAYMENT_LIVE is true, so the mock
 * can never be mistaken for a real gateway in a live deployment.
 */

require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/PayoutProviderInterface.php';
require_once __DIR__ . '/exceptions.php';

if (!defined('PS_MOCK_PROVIDER_LOADED')) {
    define('PS_MOCK_PROVIDER_LOADED', true);

    final class MockPaymentProvider implements PaymentProviderInterface, PayoutProviderInterface
    {
        private ?PDO $pdo;

        /**
         * When no database handle is available the mock falls back to an
         * in-memory store, which keeps it usable in a pure unit test with no
         * schema applied. State then lives only for the current process.
         */
        private array $memory = [];

        private static ?string $forcedStatus = null;
        private static ?string $forcedTransferStatus = null;
        private array $mockTransfers = [];

        public function __construct(?PDO $pdo = null)
        {
            $this->pdo = $pdo;
        }

        /**
         * Resolve the PDO the mock should use.
         *
         * global $pdo is the project's established connection handle, created
         * by config/database.php. The mock is constructed inside the payment
         * factory, so it can simply use it.
         */
        private function db(): ?PDO
        {
            if ($this->pdo instanceof PDO) {
                return $this->pdo;
            }

            global $pdo;

            return ($pdo instanceof PDO) ? $pdo : null;
        }

        /**
         * Is server-side storage available and usable?
         */
        private function persistent(): bool
        {
            $pdo = $this->db();

            if ($pdo === null) {
                return false;
            }

            try {
                $pdo->query('SELECT 1 FROM mock_gateway_transactions LIMIT 1');

                return true;
            } catch (Throwable $e) {
                // Migration 002 has not been applied; fall back to memory so
                // the suite still runs on a bare schema.
                return false;
            }
        }

        public function name(): string
        {
            return 'mock';
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public static function forceTransferStatus(?string $status): void
        {
            self::$forcedTransferStatus = $status;
        }

        public function banks(string $currency): array
        {
            $this->assertNotLive();
            return [['name' => 'Mock Test Bank', 'code' => '000000', 'currency' => strtoupper($currency)]];
        }

        public function resolveBankAccount(string $accountNumber, string $bankCode, string $currency): array
        {
            $this->assertNotLive();
            if ($bankCode !== '000000' || preg_match('/^\d{10}$/D', $accountNumber) !== 1) {
                throw new PaymentProviderException('Mock bank account could not be verified.', 'account_unverified', 422);
            }
            return ['account_name' => 'MOCK TEST ACCOUNT', 'account_number' => $accountNumber];
        }

        public function createTransferRecipient(
            string $name,
            string $accountNumber,
            string $bankCode,
            string $currency
        ): array {
            $this->assertNotLive();
            return ['recipient_code' => 'RCP_mock_' . bin2hex(random_bytes(8)), 'name' => $name];
        }

        public function initiateTransfer(
            string $reference,
            int $amountMinor,
            string $currency,
            string $recipientCode,
            string $reason
        ): array {
            $this->assertNotLive();
            $status = self::$forcedTransferStatus ?? 'success';
            $this->mockTransfers[$reference] = [
                'reference' => $reference,
                'provider_reference' => 'mock_transfer_' . bin2hex(random_bytes(6)),
                'status' => $status,
                'amount_minor' => $amountMinor,
                'currency' => strtoupper($currency),
                'recipient_code' => $recipientCode,
                'raw_status' => $status,
                'attempt_id' => null,
            ];
            if ($this->persistent()) {
                $this->db()->prepare('
                    INSERT INTO mock_gateway_transfers
                        (reference, provider_reference, status, amount_minor, currency, recipient_code)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        amount_minor = VALUES(amount_minor),
                        currency = VALUES(currency),
                        recipient_code = VALUES(recipient_code)
                ')->execute([
                    $reference,
                    $this->mockTransfers[$reference]['provider_reference'],
                    $status,
                    $amountMinor,
                    strtoupper($currency),
                    $recipientCode,
                ]);
            }
            return $this->mockTransfers[$reference];
        }

        public function verifyTransfer(string $reference): array
        {
            $this->assertNotLive();
            if (isset($this->mockTransfers[$reference])) {
                $transfer = $this->mockTransfers[$reference];
            } elseif ($this->persistent()) {
                $statement = $this->db()->prepare(
                    'SELECT * FROM mock_gateway_transfers WHERE reference = ? LIMIT 1'
                );
                $statement->execute([$reference]);
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new PaymentProviderException('Mock transfer not found.', 'transfer_not_found', 404);
                }
                $transfer = [
                    'reference' => $row['reference'],
                    'provider_reference' => $row['provider_reference'],
                    'status' => $row['status'],
                    'amount_minor' => (int) $row['amount_minor'],
                    'currency' => $row['currency'],
                    'recipient_code' => $row['recipient_code'],
                    'raw_status' => $row['status'],
                ];
            } else {
                throw new PaymentProviderException('Mock transfer not found.', 'transfer_not_found', 404);
            }
            if (self::$forcedTransferStatus !== null) {
                $transfer['status'] = self::$forcedTransferStatus;
                $transfer['raw_status'] = self::$forcedTransferStatus;
                if ($this->persistent()) {
                    $this->db()->prepare(
                        'UPDATE mock_gateway_transfers SET status = ? WHERE reference = ?'
                    )->execute([self::$forcedTransferStatus, $reference]);
                }
            }
            return $transfer;
        }

        private function assertNotLive(): void
        {
            if ((bool) ps_payment_config('live')) {
                throw new PaymentConfigurationException(
                    'The mock payment provider cannot be used while PAYMENT_LIVE is true. '
                    . 'Set PAYMENT_PROVIDER=paystack and supply real credentials.'
                );
            }
        }

        /* =============================================================
           initialize
           ============================================================= */

        public function initialize(
            string $reference,
            int $amountMinor,
            string $currency,
            string $email,
            array $metadata = []
        ): array {
            $this->assertNotLive();

            if ($amountMinor <= 0) {
                throw new PaymentValidationException(
                    'Amount must be greater than zero.'
                );
            }

            $providerReference = 'mock_txn_' . $reference . '_' . bin2hex(random_bytes(6));
            $paidAt = gmdate('Y-m-d H:i:s');
            $fee = $this->simulatedFee($amountMinor);

            $record = [
                'provider_reference' => $providerReference,
                'our_reference' => $reference,
                'status' => 'success',
                'amount_minor' => $amountMinor,
                'currency' => strtoupper($currency),
                'fee_minor' => $fee,
                'channel' => 'mock',
                'email' => $email,
                'metadata' => $metadata,
                'paid_at' => $paidAt,
                'refunded_minor' => 0,
                'reported_reference' => null,
                'reported_amount_minor' => null,
                'reported_currency' => null,
            ];

            if ($this->persistent()) {
                $this->db()->prepare('
                    INSERT INTO mock_gateway_transactions
                        (provider_reference, our_reference, status, amount_minor, currency,
                         fee_minor, channel, email, metadata, paid_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ')->execute([
                    $providerReference,
                    $reference,
                    'success',
                    $amountMinor,
                    strtoupper($currency),
                    $fee,
                    'mock',
                    $email,
                    (string) json_encode($metadata, JSON_UNESCAPED_SLASHES),
                    $paidAt,
                ]);
            } else {
                $this->memory[$providerReference] = $record;
            }

            return [
                'authorization_url' => '/paradisestudios/payment-return.php?ref=' . urlencode($reference),
                'access_code' => 'mock_access_' . $providerReference,
                'raw' => [
                    'status' => true,
                    'message' => 'Authorization URL created',
                    'data' => [
                        'reference' => $reference,
                        'authorization_url' => '/paradisestudios/payment-return.php?ref=' . urlencode($reference),
                        'access_code' => 'mock_access_' . $providerReference,
                    ],
                ],
            ];
        }

        /* =============================================================
           verify
           ============================================================= */

        public function verify(string $providerReference): array
        {
            $this->assertNotLive();

            $transaction = $this->lookup($providerReference);

            if ($transaction === null) {
                throw new PaymentProviderException(
                    'The provider does not recognise that transaction.',
                    'transaction_not_found',
                    404
                );
            }

            $status = self::$forcedStatus ?? (string) $transaction['status'];

            /*
             * A null override means "report what we actually hold". This is how
             * the mismatch checks in PaymentService are exercised: the gateway
             * is reachable and says "success", but about the wrong amount or
             * reference.
             */
            $reportedReference = $transaction['reported_reference']
                ?? $transaction['our_reference'];
            $reportedAmount = $transaction['reported_amount_minor']
                ?? (int) $transaction['amount_minor'];
            $reportedCurrency = $transaction['reported_currency']
                ?? (string) $transaction['currency'];

            return [
                'reference' => (string) $reportedReference,
                'provider_reference' => (string) $transaction['provider_reference'],
                'status' => $status,
                'amount_minor' => (int) $reportedAmount,
                'currency' => (string) $reportedCurrency,
                'fee_minor' => (int) $transaction['fee_minor'],
                'channel' => (string) $transaction['channel'],
                'paid_at' => $status === 'success' ? $transaction['paid_at'] : null,
                'raw' => [
                    'status' => $status === 'success',
                    'data' => [
                        'id' => (string) $transaction['provider_reference'],
                        'reference' => (string) $reportedReference,
                        'amount' => (int) $reportedAmount,
                        'currency' => (string) $reportedCurrency,
                        'status' => $status,
                        'paid_at' => $status === 'success' ? $transaction['paid_at'] : null,
                        'channel' => (string) $transaction['channel'],
                    ],
                ],
            ];
        }

        /* =============================================================
           refund
           ============================================================= */

        public function refund(string $providerReference, int $amountMinor, string $currency): array
        {
            $this->assertNotLive();

            $transaction = $this->lookup($providerReference);

            if ($transaction === null) {
                throw new PaymentProviderException(
                    'The provider does not recognise that transaction.',
                    'transaction_not_found',
                    404
                );
            }

            $refundable = (int) $transaction['amount_minor'] - (int) $transaction['refunded_minor'];

            /*
             * A real gateway refuses to refund more than it received. The
             * application must never even ask, but the provider is the last line
             * of defence.
             */
            if ($amountMinor <= 0 || $amountMinor > $refundable) {
                throw new PaymentProviderException(
                    'Refund amount exceeds the remaining captured amount.',
                    'refund_amount_invalid',
                    400
                );
            }

            $alreadyRefunded = (int) $transaction['refunded_minor'] + $amountMinor;
            $newStatus = $alreadyRefunded >= (int) $transaction['amount_minor']
                ? 'refunded'
                : 'partially_refunded';

            if ($this->persistent()) {
                $this->db()->prepare('
                    UPDATE mock_gateway_transactions
                    SET refunded_minor = refunded_minor + ?, status = ?
                    WHERE provider_reference = ?
                ')->execute([$amountMinor, $newStatus, $transaction['provider_reference']]);
            } else {
                $this->memory[$transaction['provider_reference']]['refunded_minor'] = $alreadyRefunded;
                $this->memory[$transaction['provider_reference']]['status'] = $newStatus;
            }

            $refundReference = 'mock_ref_' . bin2hex(random_bytes(8));

            return [
                'provider_reference' => $refundReference,
                'status' => 'success',
                'raw' => [
                    'status' => true,
                    'data' => [
                        'reference' => $refundReference,
                        'transaction' => (string) $transaction['provider_reference'],
                        'amount' => $amountMinor,
                        'currency' => strtoupper($currency),
                        'status' => 'success',
                    ],
                ],
            ];
        }

        /* =============================================================
           lookup
           ============================================================= */

        /**
         * Find a transaction by EITHER our reference or the provider's own id.
         *
         * This mirrors a real gateway, whose verify endpoint accepts both.
         * PaymentService relies on it: it prefers the stored provider_reference
         * and falls back to our own reference.
         */
        private function lookup(string $identifier): ?array
        {
            if ($this->persistent()) {
                $statement = $this->db()->prepare(
                    'SELECT * FROM mock_gateway_transactions
                     WHERE provider_reference = ? OR our_reference = ?
                     ORDER BY id DESC LIMIT 1'
                );
                $statement->execute([$identifier, $identifier]);

                $row = $statement->fetch();

                if ($row !== false) {
                    return $row;
                }

                return null;
            }

            if (isset($this->memory[$identifier])) {
                return $this->memory[$identifier];
            }

            foreach ($this->memory as $transaction) {
                if ((string) $transaction['our_reference'] === $identifier) {
                    return $transaction;
                }
            }

            return null;
        }

        /**
         * A plausible non-zero processing fee.
         *
         * Deliberately NOT Paystack's real published schedule. Anything that
         * depends on the exact fee must read it from the provider at
         * confirmation time, which is what PaymentService does.
         */
        private function simulatedFee(int $amountMinor): int
        {
            return ps_money_apply_bps($amountMinor, 150) + 10000; // 1.5% + 100.00
        }

        /* =============================================================
           Webhook signing
           ============================================================= */

        /**
         * The shared secret this mock signs with. Configurable so a test can
         * prove a signature made with a DIFFERENT secret is rejected.
         */
        public static function signingSecret(): string
        {
            $configured = (string) ps_payment_config('mock_webhook_secret');

            return $configured !== '' ? $configured : 'mock_webhook_dev_secret';
        }

        public function verifySignature(string $rawBody, ?string $signatureHeader): bool
        {
            if ($signatureHeader === null || $signatureHeader === '') {
                return false;
            }

            return hash_equals(self::signBody($rawBody), $signatureHeader);
        }

        /**
         * Produce the signature a real gateway would send for this body.
         */
        public static function signBody(string $rawBody): string
        {
            return hash_hmac('sha512', $rawBody, self::signingSecret());
        }

        /* =============================================================
           Test controls
           ============================================================= */

        /**
         * Clear the forced status override used by tests.
         */
        public static function reset(): void
        {
            self::$forcedStatus = null;
            self::$forcedTransferStatus = null;
        }

        /**
         * Force the next verify() to report this status.
         */
        public static function forceStatus(?string $status): void
        {
            self::$forcedStatus = $status;
        }

        /**
         * Make the provider REPORT a different value on verify(), without
         * breaking its ability to find the transaction.
         */
        public static function reportAs(string $identifier, string $field, mixed $value): void
        {
            if (!in_array($field, ['reference', 'amount_minor', 'currency'], true)) {
                throw new InvalidArgumentException('Unsupported report field: ' . $field);
            }

            $column = [
                'reference' => 'reported_reference',
                'amount_minor' => 'reported_amount_minor',
                'currency' => 'reported_currency',
            ][$field];

            global $pdo;

            if (($pdo ?? null) instanceof PDO) {
                try {
                    $pdo->prepare(
                        "UPDATE mock_gateway_transactions
                         SET {$column} = ?
                         WHERE provider_reference = ? OR our_reference = ?"
                    )->execute([$value, $identifier, $identifier]);

                    return;
                } catch (Throwable $e) {
                    // Table absent; fall through to the in-memory path.
                }
            }
        }

        /**
         * The provider transaction id created for one of our references.
         */
        public static function providerReferenceFor(string $ourReference): ?string
        {
            global $pdo;

            if (($pdo ?? null) instanceof PDO) {
                try {
                    $statement = $pdo->prepare(
                        'SELECT provider_reference FROM mock_gateway_transactions
                         WHERE our_reference = ? ORDER BY id DESC LIMIT 1'
                    );
                    $statement->execute([$ourReference]);

                    $value = $statement->fetchColumn();

                    return $value !== false ? (string) $value : null;
                } catch (Throwable $e) {
                    // Table absent.
                }
            }

            return null;
        }
    }
}
