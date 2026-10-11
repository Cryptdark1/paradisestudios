<?php

/**
 * Paradise Studios - Paystack adapter.
 *
 * Test-mode requests are allowed only with a Paystack test key while
 * PAYMENT_LIVE=false. Live keys are rejected in that mode. It does NOT:
 *   - fall back to the mock provider,
 *   - accept a placeholder key,
 *   - report an unverified payment as successful,
 *   - guess at a base URL or a key format.
 *
 * That is deliberate. A payment backend that quietly substitutes a fake
 * gateway is far more dangerous than one that refuses to start.
 *
 * Test credentials stay in the external environment file, never in the
 * repository. Live transactions remain disabled unless separately enabled.
 */

require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/PayoutProviderInterface.php';
require_once __DIR__ . '/exceptions.php';

if (!defined('PS_PAYSTACK_PROVIDER_LOADED')) {
    define('PS_PAYSTACK_PROVIDER_LOADED', true);

    final class PaystackPaymentProvider implements PaymentProviderInterface, PayoutProviderInterface
    {
        /**
         * Paystack reports amounts in the currency's SUBUNIT (kobo for NGN),
         * which is exactly the integer minor-unit representation this whole
         * codebase uses. No conversion or scaling is required, which is one
         * reason integer minor units were chosen.
         */
        private const SUBUNIT_MULTIPLIER = 1;

        public function name(): string
        {
            return 'paystack';
        }

        public function isConfigured(): bool
        {
            return $this->secretKey() !== '';
        }

        private function secretKey(): string
        {
            return (string) ps_payment_config('paystack_secret_key');
        }

        /**
         * Guard used by every operation.
         */
        private function assertConfigured(): void
        {
            if ($this->secretKey() === '') {
                throw new PaymentConfigurationException(
                    'Paystack is selected as the payment provider but no secret key is configured. '
                    . 'Set PAYSTACK_SECRET_KEY in your environment file (sk_test_ for test mode). '
                    . 'No payment has been attempted.'
                );
            }
        }

        /**
         * Refuse to run in live mode without a live key, and refuse to run a
         * live key in test mode.
         *
         * This is the single most important safety check in the adapter: a
         * live key must never be exercised against a development booking.
         */
        private function assertKeyMatchesLiveMode(string $key, bool $live): void
        {
            $isLiveKey = str_starts_with($key, 'sk_live_');
            $isTestKey = str_starts_with($key, 'sk_test_');

            if (!$isLiveKey && !$isTestKey) {
                throw new PaymentConfigurationException(
                    'The configured Paystack secret key does not look like a Paystack key '
                    . '(expected a sk_test_ or sk_live_ prefix).'
                );
            }

            if ($live && !$isLiveKey) {
                throw new PaymentConfigurationException(
                    'PAYMENT_LIVE is true but the configured Paystack key is a TEST key. '
                    . 'Real payments cannot be taken with a test key.'
                );
            }

            if (!$live && $isLiveKey) {
                throw new PaymentConfigurationException(
                    'A LIVE Paystack key is configured while PAYMENT_LIVE is false. '
                    . 'Refusing to run so a real key is never exercised against development data.'
                );
            }
        }

        public function initialize(
            string $reference,
            int $amountMinor,
            string $currency,
            string $email,
            array $metadata = []
        ): array {
            $this->assertConfigured();
            $this->assertKeyMatchesLiveMode($this->secretKey(), (bool) ps_payment_config('live'));

            if ($amountMinor <= 0) {
                throw new PaymentValidationException('Amount must be greater than zero.');
            }

            $successUrl = (string) ps_payment_config('success_url');
            $cancelUrl = (string) ps_payment_config('cancel_url');

            if ($successUrl === '' || $cancelUrl === '') {
                throw new PaymentConfigurationException(
                    'PAYMENT_SUCCESS_URL and PAYMENT_CANCEL_URL must both be configured '
                    . 'before payments can be initialised.'
                );
            }

            $payload = [
                'email' => $email,
                'amount' => $amountMinor * self::SUBUNIT_MULTIPLIER,
                'currency' => strtoupper($currency),
                'reference' => (string) $reference,
                'callback_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $metadata,
            ];

            $response = $this->request('POST', '/transaction/initialize', $payload);
            $data = $response['data'] ?? null;
            $data = is_array($data) ? $data : [];

            if (!isset($data['authorization_url'])) {
                throw new PaymentProviderException(
                    'Paystack did not return an authorization URL.',
                    'initialize_failed'
                );
            }

            return [
                'authorization_url' => (string) $data['authorization_url'],
                'access_code' => (string) ($data['access_code'] ?? ''),
                'raw' => $response,
            ];
        }

        public function verify(string $providerReference): array
        {
            $this->assertConfigured();
            $this->assertKeyMatchesLiveMode($this->secretKey(), (bool) ps_payment_config('live'));

            $response = $this->request(
                'GET',
                '/transaction/verify/' . rawurlencode($providerReference)
            );

            $data = $response['data'] ?? null;
            $data = is_array($data) ? $data : [];

            /*
             * Paystack's own status vocabulary includes success, failed,
             * abandoned, processing, queued and reversed. Only 'success'
             * means money was captured. Unknown states are kept intact so the
             * service can leave the payment retryable rather than guessing.
             */
            $rawStatus = $data['status'] ?? null;
            $status = is_string($rawStatus) && trim($rawStatus) !== ''
                ? trim($rawStatus)
                : 'unknown';

            $amountMinor = null;
            $rawAmount = $data['amount'] ?? null;
            if (
                is_int($rawAmount)
                || (is_string($rawAmount) && preg_match('/^(0|[1-9][0-9]*)$/D', $rawAmount) === 1)
            ) {
                $parsedAmount = filter_var($rawAmount, FILTER_VALIDATE_INT);

                if ($parsedAmount !== false && $parsedAmount >= 0) {
                    $amountMinor = (int) $parsedAmount * self::SUBUNIT_MULTIPLIER;
                }
            }

            $feeMinor = null;
            if (isset($data['fees']) && is_numeric($data['fees'])) {
                $feeMinor = (int) $data['fees'];
            }

            return [
                'reference' => isset($data['reference']) && is_string($data['reference'])
                    && trim($data['reference']) !== ''
                    ? $data['reference']
                    : null,
                'provider_reference' => isset($data['id'])
                    && (is_string($data['id']) || is_int($data['id']))
                    ? (string) $data['id']
                    : null,
                'status' => $status,
                'amount_minor' => $amountMinor,
                'currency' => isset($data['currency']) && is_string($data['currency'])
                    && trim($data['currency']) !== ''
                    ? strtoupper($data['currency'])
                    : null,
                'fee_minor' => $feeMinor,
                'channel' => isset($data['channel']) ? (string) $data['channel'] : null,
                'paid_at' => isset($data['paid_at']) && is_string($data['paid_at'])
                    ? $data['paid_at']
                    : null,
                'raw' => $response,
            ];
        }

        public function refund(string $providerReference, int $amountMinor, string $currency): array
        {
            $this->assertConfigured();
            $this->assertKeyMatchesLiveMode($this->secretKey(), (bool) ps_payment_config('live'));

            if ($amountMinor <= 0) {
                throw new PaymentValidationException('Refund amount must be greater than zero.');
            }

            /*
             * Paystack refunds are keyed by the ORIGINAL TRANSACTION REFERENCE,
             * not by a gateway transaction id. Returning the caller's value
             * keeps the interface uniform; the caller always holds our own
             * reference for the payment.
             */
            $response = $this->request('POST', '/refund', [
                'transaction' => $providerReference,
                'amount' => $amountMinor * self::SUBUNIT_MULTIPLIER,
                'currency' => strtoupper($currency),
            ]);

            $data = $response['data'] ?? null;

            return [
                'provider_reference' => is_array($data) && isset($data['reference'])
                    ? (string) $data['reference']
                    : null,
                'status' => isset($data['status']) ? (string) $data['status'] : 'unknown',
                'raw' => $response,
            ];
        }

        public function banks(string $currency): array
        {
            $this->assertConfigured();
            $this->assertKeyMatchesLiveMode($this->secretKey(), (bool) ps_payment_config('live'));
            $response = $this->request(
                'GET',
                '/bank?' . http_build_query(['currency' => strtoupper($currency)])
            );
            $data = $response['data'] ?? [];

            if (!is_array($data)) {
                throw new PaymentProviderException('Paystack returned invalid bank data.', 'invalid_response', 502);
            }

            return array_values(array_filter($data, static function ($bank): bool {
                return is_array($bank)
                    && isset($bank['name'], $bank['code'])
                    && is_string($bank['name'])
                    && is_string($bank['code']);
            }));
        }

        public function resolveBankAccount(
            string $accountNumber,
            string $bankCode,
            string $currency
        ): array {
            $this->assertConfigured();
            $this->assertKeyMatchesLiveMode($this->secretKey(), (bool) ps_payment_config('live'));
            $query = http_build_query([
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
                'currency' => strtoupper($currency),
            ]);
            $response = $this->request('GET', '/bank/resolve?' . $query);
            $data = $response['data'] ?? null;

            if (
                !is_array($data)
                || !is_string($data['account_name'] ?? null)
                || !is_string($data['account_number'] ?? null)
            ) {
                throw new PaymentProviderException('Paystack could not verify that bank account.', 'account_unverified', 422);
            }

            return [
                'account_name' => trim($data['account_name']),
                'account_number' => trim($data['account_number']),
            ];
        }

        public function createTransferRecipient(
            string $name,
            string $accountNumber,
            string $bankCode,
            string $currency
        ): array {
            $this->assertConfigured();
            $this->assertKeyMatchesLiveMode($this->secretKey(), (bool) ps_payment_config('live'));
            $response = $this->request('POST', '/transferrecipient', [
                'type' => 'nuban',
                'name' => $name,
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
                'currency' => strtoupper($currency),
            ]);
            $data = $response['data'] ?? null;

            if (!is_array($data) || !is_string($data['recipient_code'] ?? null) || $data['recipient_code'] === '') {
                throw new PaymentProviderException('Paystack did not confirm a transfer recipient.', 'recipient_unconfirmed', 502);
            }

            return [
                'recipient_code' => $data['recipient_code'],
                'name' => is_string($data['name'] ?? null) ? $data['name'] : $name,
            ];
        }

        public function initiateTransfer(
            string $reference,
            int $amountMinor,
            string $currency,
            string $recipientCode,
            string $reason
        ): array {
            $this->assertConfigured();
            $this->assertKeyMatchesLiveMode($this->secretKey(), (bool) ps_payment_config('live'));
            $response = $this->request('POST', '/transfer', [
                'source' => 'balance',
                'amount' => $amountMinor * self::SUBUNIT_MULTIPLIER,
                'currency' => strtoupper($currency),
                'reference' => $reference,
                'recipient' => $recipientCode,
                'reason' => $reason,
            ]);
            return $this->normaliseTransfer($response);
        }

        public function verifyTransfer(string $reference): array
        {
            $this->assertConfigured();
            $this->assertKeyMatchesLiveMode($this->secretKey(), (bool) ps_payment_config('live'));
            $response = $this->request(
                'GET',
                '/transfer/verify/' . rawurlencode($reference)
            );
            return $this->normaliseTransfer($response);
        }

        private function normaliseTransfer(array $response): array
        {
            $data = $response['data'] ?? null;

            if (!is_array($data) || !is_string($data['status'] ?? null)) {
                throw new PaymentProviderException('Paystack returned an unrecognized transfer response.', 'invalid_response', 502);
            }

            $amount = $data['amount'] ?? null;
            $amountMinor = is_int($amount) || (is_string($amount) && ctype_digit($amount))
                ? filter_var($amount, FILTER_VALIDATE_INT)
                : false;

            return [
                'reference' => is_string($data['reference'] ?? null) ? $data['reference'] : null,
                'provider_reference' => isset($data['id']) && (is_string($data['id']) || is_int($data['id']))
                    ? (string) $data['id']
                    : null,
                'status' => strtolower(trim($data['status'])),
                'amount_minor' => $amountMinor === false ? null : (int) $amountMinor,
                'currency' => is_string($data['currency'] ?? null) ? strtoupper($data['currency']) : null,
                'recipient_code' => is_string($data['recipient']['recipient_code'] ?? null)
                    ? $data['recipient']['recipient_code']
                    : (is_string($data['recipient_code'] ?? null) ? $data['recipient_code'] : null),
                'raw_status' => is_string($data['status']) ? strtolower(trim($data['status'])) : 'unknown',
            ];
        }

        /**
         * Minimal HTTP client.
         *
         * Deliberately hand-rolled rather than adding a dependency: one POST,
         * one GET, JSON in and out. Timeouts are short so a hung gateway
         * cannot hold a database transaction open.
         *
         * The secret key is sent in the Authorization header and is never
         * written to a log or included in an exception message.
         */
        private function request(string $method, string $path, array $payload = []): array
        {
            $url = $this->baseUrl() . $path;

            $curl = curl_init($url);

            if ($curl === false) {
                throw new PaymentProviderException(
                    'Unable to initialise an HTTP client.',
                    'transport_unavailable'
                );
            }

            $headers = [
                'Authorization: Bearer ' . $this->secretKey(),
                'Accept: application/json',
                'Content-Type: application/json',
            ];

            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ]);

            if ($method === 'POST') {
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
            }

            $body = curl_exec($curl);
            $errorNumber = curl_errno($curl);
            $errorMessage = curl_error($curl);
            $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

            curl_close($curl);

            if ($errorNumber !== 0 || $body === false) {
                // The transport error may contain a URL but never the key.
                throw new PaymentProviderException(
                    'Could not reach the payment provider: ' . $errorMessage,
                    'transport_error'
                );
            }

            $decoded = json_decode((string) $body, true);

            if (!is_array($decoded)) {
                throw new PaymentProviderException(
                    'The payment provider returned a response that was not JSON.',
                    'invalid_response',
                    502
                );
            }

            /*
             * Paystack answers 200 with {"status": false, "message": "..."} for
             * application-level failures, so the body has to be inspected even
             * on a 200.
             */
            if ($httpStatus >= 400 || empty($decoded['status'])) {
                throw new PaymentProviderException(
                    'Paystack rejected the request: '
                        . (string) ($decoded['message'] ?? ('HTTP ' . $httpStatus)),
                    'provider_rejected',
                    $httpStatus >= 400 ? $httpStatus : 422
                );
            }

            return $decoded;
        }

        private function baseUrl(): string
        {
            $base = (string) ps_payment_config('paystack_api_base');

            if ($base === '') {
                throw new PaymentConfigurationException(
                    'PAYSTACK_API_BASE is not configured.'
                );
            }

            return $base;
        }

        /**
         * Verify an inbound webhook signature.
         *
         * HMAC-SHA512 of the EXACT raw request body, compared in constant time.
         * Paystack sends the digest in the X-Paystack-Signature header.
         *
         * The raw body matters: re-encoding the decoded JSON escapes "/" as
         * "\/" and normalises whitespace, which produces a different digest and
         * would reject legitimate events.
         *
         * Declared as an instance method because PaymentProviderInterface
         * requires it - PHP does not allow a static and an instance method to
         * share a name.
         */
        public function verifySignature(string $rawBody, ?string $signatureHeader): bool
        {
            $secret = (string) ps_payment_config('paystack_secret_key');

            if ($secret === '' || $signatureHeader === null || $signatureHeader === '') {
                return false;
            }

            $expected = hash_hmac('sha512', $rawBody, $secret);

            return hash_equals($expected, $signatureHeader);
        }
    }
}
