<?php

/**
 * Paradise Studios - payment provider factory.
 *
 * The only place in the codebase that decides WHICH gateway is used. Services
 * receive an already-built provider and never read the provider name from the
 * request, the database, or a client-supplied value.
 */

require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/MockProvider.php';
require_once __DIR__ . '/PaystackProvider.php';
require_once __DIR__ . '/exceptions.php';

/*
 * Both adapters read ps_payment_config() - provider choice, live mode, currency,
 * webhook secret. Loading the configuration here means anything that reaches a
 * provider has it, so an endpoint never has to remember.
 *
 * This was a real 500: api/reschedule-booking.php and api/cancel-booking.php
 * reached ps_payment_config() through this factory and fataled because nothing
 * had loaded the configuration in that request.
 */
require_once __DIR__ . '/../../config/payments.php';

if (!defined('PS_PROVIDER_FACTORY_LOADED')) {
    define('PS_PROVIDER_FACTORY_LOADED', true);

    /**
     * Build the configured provider.
     *
     * @throws PaymentConfigurationException when the configured provider is
     *         unknown or, for Paystack, not yet credentialed.
     */
    function ps_payment_provider(): PaymentProviderInterface
    {
        static $provider = null;
        static $generation = -1;

        $current = ps_env_generation();

        if (!($provider instanceof PaymentProviderInterface) || $generation !== $current) {
            $name = (string) ps_payment_config('provider');

            $provider = match ($name) {
                'mock' => new MockPaymentProvider(ps_payment_provider_pdo()),
                'paystack' => new PaystackPaymentProvider(),
                default => throw new PaymentConfigurationException(
                    'Unknown payment provider "' . $name . '". Expected "mock" or "paystack".'
                ),
            };

            $generation = $current;
        }

        return $provider;
    }

    /**
     * Drop the cached provider, e.g. after a test changes the provider.
     */
    function ps_payment_provider_reset(): void
    {
        ps_env_bump_generation();
    }

    /**
     * The project's PDO handle, if one has been established.
     *
     * config/database.php creates a global $pdo. The mock provider needs it to
     * keep its simulated transactions in server-side storage across separate
     * PHP processes - see includes/payments/MockProvider.php.
     */
    function ps_payment_provider_pdo(): ?PDO
    {
        global $pdo;

        return ($pdo instanceof PDO) ? $pdo : null;
    }

    /**
     * True when the configured provider is the local simulator.
     */
    function ps_payment_provider_is_mock(): bool
    {
        return ps_payment_provider()->name() === 'mock';
    }
}
