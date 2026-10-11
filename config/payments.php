<?php

/**
 * Paradise Studios - payment configuration.
 *
 * Single source of truth for everything the payment layer needs to know. All
 * values come from the environment (see config/env.php) so nothing sensitive is
 * committed, and every value has a documented default so the application runs
 * unchanged when no .env file exists.
 *
 * Defaults are chosen for LOCAL DEVELOPMENT with no payment provider:
 *   - the mock adapter, which moves no money;
 *   - PAYMENT_LIVE = false, which makes the Paystack adapter refuse to start and
 *     makes the mock adapter refuse to pretend to be a real gateway.
 *
 * There is deliberately no "fake Paystack key" fallback anywhere. If
 * PAYMENT_PROVIDER is paystack and no secret key is configured, the adapter
 * throws a configuration error rather than inventing credentials.
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/../includes/money.php';

if (!defined('PS_PAYMENTS_CONFIG_LOADED')) {
    define('PS_PAYMENTS_CONFIG_LOADED', true);

    /**
     * Read the configuration.
     *
     * Built once per request. It is rebuilt automatically whenever the
     * environment is re-read, so nothing has to remember to invalidate it -
     * and ps_payment_config_reset() exists only for the rare case where a
     * value is changed without touching the env cache.
     */
    function ps_payment_config(?string $key = null): mixed
    {
        static $config = null;
        static $generation = -1;

        $current = ps_env_generation();

        if ($config === null || $generation !== $current) {
            $config = ps_payment_config_build();
            $generation = $current;
        }

        if ($key === null) {
            return $config;
        }

        return $config[$key] ?? null;
    }

    /**
     * Discard the cached configuration.
     *
     * Only clears - it deliberately does not rebuild, so calling it while the
     * environment is invalid does not throw here.
     */
    function ps_payment_config_reset(): void
    {
        ps_env_bump_generation();
    }

    /**
     * Build and validate the configuration set.
     *
     * @throws RuntimeException if a value is present but unusable, e.g. a
     *                          non-numeric commission rate.
     */
    function ps_payment_config_build(): array
    {
        $provider = strtolower((string) ps_env('PAYMENT_PROVIDER', 'mock'));
        $live = ps_env_bool('PAYMENT_LIVE', false);
        $currency = ps_money_currency((string) ps_env('CURRENCY', 'NGN'));

        if (!in_array($provider, ['mock', 'paystack'], true)) {
            throw new RuntimeException(
                'PAYMENT_PROVIDER must be "mock" or "paystack", got "' . $provider . '".'
            );
        }

        $commissionBps = ps_env_int('PLATFORM_COMMISSION_BPS', 1000);
        ps_money_validate_bps((int) $commissionBps);

        $fullRefundBps = ps_env_int('FULL_REFUND_BPS', 10000);
        ps_money_validate_bps((int) $fullRefundBps);

        $lateRefundBps = ps_env_int('LATE_REFUND_BPS', 5000);
        ps_money_validate_bps((int) $lateRefundBps);

        $returnSecret = (string) ps_env('PAYMENT_RETURN_SECRET', '');

        return [
            'provider' => $provider,
            'live' => $live,

            'currency' => $currency,
            'currency_exponent' => ps_money_exponent($currency),

            // Applied to NEW captures only. Recorded per ledger entry so a later
            // rate change never rewrites history.
            'commission_bps' => (int) $commissionBps,

            'paystack_secret_key' => trim((string) ps_env('PAYSTACK_SECRET_KEY', '')),
            'paystack_public_key' => trim((string) ps_env('PAYSTACK_PUBLIC_KEY', '')),
            'paystack_api_base' => rtrim(
                trim((string) ps_env('PAYSTACK_API_BASE', 'https://api.paystack.co')),
                '/'
            ),

            /*
             * Secret the local mock signs simulated webhooks with.
             *
             * The mock implements HMAC-SHA512 webhook signing for real so the
             * whole pipeline - signature, replay, idempotency - can be tested on
             * a machine with no gateway account, which is how this project
             * currently stands. It is a development value and carries no
             * meaning outside the mock.
             */
            'mock_webhook_secret' => trim((string) ps_env('MOCK_WEBHOOK_SECRET', '')),

            'success_url' => trim((string) ps_env('PAYMENT_SUCCESS_URL', '')),
            'cancel_url' => trim((string) ps_env('PAYMENT_CANCEL_URL', '')),

            /*
             * Signs the query string we hand back to the browser on return. A
             * redirect is user-controllable, so the success page may NEVER mark
             * a booking paid on its own - this HMAC only proves the redirect
             * came from us. The authoritative check is a server-to-server
             * verification with the provider.
             */
            'return_secret' => $returnSecret,

            'full_refund_window_hours' => ps_env_int('FULL_REFUND_WINDOW_HOURS', 24),
            'full_refund_bps' => (int) $fullRefundBps,
            'late_refund_bps' => (int) $lateRefundBps,

            'payment_expiry_minutes' => ps_env_int('PAYMENT_EXPIRY_MINUTES', 30),

            'payout_eligibility_delay_hours' => ps_env_int(
                'PAYOUT_ELIGIBILITY_DELAY_HOURS',
                24
            ),
            'block_payout_on_dispute' => ps_env_bool('BLOCK_PAYOUT_ON_DISPUTE', true),
        ];
    }

    /**
     * True when real money may move.
     *
     * Callers must check this before any provider call that could charge
     * someone. While false the mock provider is allowed to run, because local
     * simulation is exactly what development needs.
     */
    function ps_payment_live_enabled(): bool
    {
        return (bool) ps_payment_config('live');
    }

    /**
     * Human-readable description of the current configuration, safe to log.
     *
     * Never includes a secret key - only whether one is present.
     */
    function ps_payment_config_summary(): array
    {
        $config = ps_payment_config();

        return [
            'provider' => $config['provider'],
            'live' => $config['live'],
            'currency' => $config['currency'],
            'commission_bps' => $config['commission_bps'],
            'paystack_secret_configured' => $config['paystack_secret_key'] !== '',
            'paystack_public_configured' => $config['paystack_public_key'] !== '',
            'return_secret_configured' => $config['return_secret'] !== '',
            'payout_eligibility_delay_hours' => $config['payout_eligibility_delay_hours'],
            'payment_expiry_minutes' => $config['payment_expiry_minutes'],
        ];
    }
}
