<?php

/**
 * Paradise Studios - payment provider exceptions.
 *
 * Distinct exception types so the API layer can decide what to tell a caller
 * without ever leaking the underlying reason.
 */

/**
 * The adapter is not usable: missing keys, wrong provider name, live mode
 * required but not enabled. This is an operator error, never a customer error,
 * so it must not be reported to the user as a failed payment.
 */
class PaymentConfigurationException extends RuntimeException
{
}

/**
 * The provider rejected or could not complete a request. Carries a short,
 * SAFE reason code. Never contains a secret key, a card number or a raw
 * provider payload that might contain either.
 */
class PaymentProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        private string $safeCode = 'provider_error',
        private int $httpStatus = 502
    ) {
        parent::__construct($message);
    }

    public function safeCode(): string
    {
        return $this->safeCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

/**
 * The provider said the transaction did not succeed, or said it succeeded for
 * an amount, currency or reference that does not match what we expect.
 *
 * This is the exception that must stop a payment from being treated as paid.
 * It is never converted into a success anywhere in the codebase.
 */
class PaymentVerificationException extends RuntimeException
{
    public function __construct(
        string $message,
        private string $reason = 'verification_failed',
        private array $detail = []
    ) {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * Safe diagnostic detail: our own reference, expected vs received amount,
     * statuses. Never a secret or card data.
     */
    public function detail(): array
    {
        return $this->detail;
    }
}

/**
 * A request failed local validation before the provider was contacted.
 */
class PaymentValidationException extends InvalidArgumentException
{
}

/**
 * The provider has NOT finished deciding yet.
 *
 * Deliberately NOT a PaymentVerificationException, because the two mean opposite
 * things to whoever is waiting:
 *
 *   PaymentVerificationException -> verification failed. The caller cannot
 *                                  infer whether money moved from this alone.
 *   PaymentPendingException      -> the provider said "not yet". The customer may
 *                                  or may not have been charged. Nothing decided.
 *
 * A payment in this state must NOT be recorded as failed. Marking it failed is
 * irreversible: the retry path refuses to confirm a failed payment, so a
 * transaction that was merely still settling would become permanently unpayable
 * and the customer could be charged twice if they tried again.
 *
 * Kept outside the PaymentVerificationException hierarchy on purpose, so the API
 * layer cannot catch it as "no money has been taken" - a statement that is not
 * true for a transaction still in flight.
 */
class PaymentPendingException extends RuntimeException
{
    public function __construct(
        string $message,
        private string $providerStatus = 'processing',
        private ?int $paymentId = null
    ) {
        parent::__construct($message);
    }

    /** The provider's own status word, e.g. "processing", "queued". */
    public function providerStatus(): string
    {
        return $this->providerStatus;
    }

    public function paymentId(): ?int
    {
        return $this->paymentId;
    }
}
