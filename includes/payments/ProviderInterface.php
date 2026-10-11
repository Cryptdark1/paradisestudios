<?php

/**
 * Paradise Studios - payment provider contract.
 *
 * Business logic talks to this interface only. Nothing in the service layer
 * knows whether a payment went through Paystack or the local simulator, which
 * is what keeps the provider decision in one place.
 *
 * Two rules hold for every implementation:
 *
 *  1. verify() must confirm with the PROVIDER, never with anything the browser
 *     sent. A redirect back to a success page is not evidence of payment.
 *
 *  2. No implementation may report a payment as successful unless the
 *     provider itself said so over a trusted channel.
 */

if (!defined('PS_PAYMENT_PROVIDER_LOADED')) {
    define('PS_PAYMENT_PROVIDER_LOADED', true);

    interface PaymentProviderInterface
    {
        /**
         * Short provider identifier: 'mock' or 'paystack'.
         */
        public function name(): string;

        /**
         * True when the adapter is usable right now.
         *
         * Callers should check this before attempting an operation so a
         * misconfiguration surfaces as a clear error rather than a confusing
         * provider failure.
         */
        public function isConfigured(): bool;

        /**
         * Start a payment.
         *
         * Returns a token for the provider's checkout plus whatever URL the
         * customer should be sent to. Nothing here confirms that money moved.
         *
         * @param string $reference      our unique reference for this payment.
         *                                 A STRING, not an integer: gateway
         *                                 references are opaque identifiers and
         *                                 may contain letters and separators.
         * @param int    $amountMinor    integer minor units
         * @param string $currency       ISO 4217 code
         * @param string $email          for the provider's receipt
         * @param array  $metadata       safe extra fields (booking id, etc.)
         *
         * @return array{authorization_url:string, access_code:string, raw:array}
         */
        public function initialize(
            string $reference,
            int $amountMinor,
            string $currency,
            string $email,
            array $metadata = []
        ): array;

        /**
         * Ask the provider, server to server, what actually happened.
         *
         * This is the ONLY trusted source of payment truth.
         *
         * @return array{
         *   reference:string|null,
         *   provider_reference:string|null,
         *   status:string,
         *   amount_minor:int|null,
         *   currency:string|null,
         *   fee_minor:int|null,
         *   channel:string|null,
         *   paid_at:string|null,
         *   raw:array
         * }
         */
        public function verify(string $providerReference): array;

        /**
         * Ask the provider to return money.
         *
         * Returns the provider's own reference and status. A return of
         * 'requested' means the request was accepted, NOT that money has moved.
         *
         * @return array{provider_reference:string|null, status:string, raw:array}
         */
        public function refund(string $providerReference, int $amountMinor, string $currency): array;

        /**
         * Verify an inbound webhook signature over the EXACT raw request body.
         *
         * Declared on the interface so the endpoint never has to know which
         * provider it is talking to in order to authenticate it. The mock
         * implements it for real, which is what makes the entire webhook flow
         * - signature, replay, idempotency - testable on a machine with no
         * gateway account.
         */
        public function verifySignature(string $rawBody, ?string $signatureHeader): bool;
    }
}
