<?php

/**
 * Paradise Studios - money primitives.
 *
 * All financial arithmetic in the payment layer happens in INTEGER MINOR UNITS
 * (kobo for NGN). Floating point is never used for a financial total: binary
 * floats cannot represent 0.1 exactly, so repeated multiplication and division
 * drift, and `0.1 + 0.2 !== 0.3` becomes an accounting discrepancy.
 *
 * Existing endpoints in this project DO cast to float
 * (api/bookings.php, api/reschedule-booking.php, api/cancel-booking.php).
 * Those are left as they are for backward compatibility in this stage; the
 * payment layer below never trusts them and always re-derives amounts from the
 * authoritative minor-unit value.
 *
 * Rates are also integers, expressed in BASIS POINTS (bps): 10000 bps = 100%.
 * A percentage would reintroduce floats at exactly the point where money is
 * split between two parties.
 *
 * This file is dependency-free so it can be unit-tested without a database.
 */

if (!defined('PS_MONEY_LOADED')) {
    define('PS_MONEY_LOADED', true);

    /**
     * Currencies are identified by ISO 4217 code. The exponent is the number of
     * decimal places in the minor unit. Only currencies we can actually reason
     * about are listed; anything else must be added deliberately rather than
     * silently defaulting to a wrong number of decimal places.
     */
    function ps_money_exponent(string $currency): int
    {
        $currency = strtoupper(trim($currency));

        $exponents = [
            'NGN' => 2, // kobo
            'USD' => 2, // cents
            'EUR' => 2,
            'GBP' => 2,
            'GHS' => 2, // pesewas
            'ZAR' => 2, // cents
            'KES' => 2, // cents
            'JPY' => 0, // yen has no minor unit
            'KRW' => 0,
        ];

        if (!isset($exponents[$currency])) {
            throw new InvalidArgumentException(
                'Unsupported currency: ' . $currency
            );
        }

        return $exponents[$currency];
    }

    /**
     * Validate and normalise a currency code.
     */
    function ps_money_currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        // Calling the exponent table is the validation.
        ps_money_exponent($currency);

        return $currency;
    }

    /**
     * Convert a DECIMAL string (as returned by PDO for a DECIMAL column) into
     * integer minor units.
     *
     * Accepts a string or an int. Floats are rejected on purpose: passing one
     * means the value has already lost precision before it reached us.
     *
     * Digits beyond the currency's exponent are truncated, not rounded, so a
     * stored value can never appear to contain more precision than it has.
     */
    function ps_money_to_minor(string|int $amount, string $currency = 'NGN'): int
    {
        if (is_int($amount)) {
            $amount = (string) $amount;
        }

        $currency = ps_money_currency($currency);
        $exponent = ps_money_exponent($currency);

        $amount = trim($amount);

        if ($amount === '') {
            throw new InvalidArgumentException('Amount is empty.');
        }

        if (!preg_match('/^(-?)(\d{1,15})(?:\.(\d*))?$/', $amount, $m)) {
            throw new InvalidArgumentException(
                'Amount is not a valid decimal number: ' . $amount
            );
        }

        $sign = $m[1] === '-' ? '-' : '';
        $whole = $m[2];
        $fraction = $m[3] ?? '';

        if ($exponent === 0) {
            $fraction = '';
        } else {
            // Pad to exactly $exponent digits, then truncate any extras.
            $fraction = substr(str_pad($fraction, $exponent, '0'), 0, $exponent);
        }

        /*
         * 15 integer digits + 2 fraction digits = 17 digits, which is inside
         * the 19-digit signed 64-bit range PHP_INT_MAX provides. The guard
         * below is therefore belt-and-braces rather than a real limit.
         */
        $minorString = $sign . $whole . $fraction;

        if (!preg_match('/^-?\d+$/', $minorString)) {
            throw new InvalidArgumentException('Amount could not be converted.');
        }

        return (int) $minorString;
    }

    /**
     * Convert integer minor units back to a DECIMAL string for display.
     *
     * Always returns exactly $exponent decimal places so amounts line up in
     * tables and never render as a bare integer.
     */
    function ps_money_to_decimal(int $minor, string $currency = 'NGN'): string
    {
        $currency = ps_money_currency($currency);
        $exponent = ps_money_exponent($currency);

        $negative = $minor < 0;
        $absolute = (string) abs($minor);

        if ($exponent === 0) {
            return ($negative ? '-' : '') . $absolute;
        }

        $absolute = str_pad($absolute, $exponent + 1, '0', STR_PAD_LEFT);

        $whole = substr($absolute, 0, strlen($absolute) - $exponent);
        $fraction = substr($absolute, strlen($absolute) - $exponent);

        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }

    /**
     * Human-readable amount, e.g. 1500000 minor NGN -> "NGN 15,000.00".
     */
    function ps_money_format(int $minor, string $currency = 'NGN'): string
    {
        $decimal = ps_money_to_decimal($minor, $currency);
        $exponent = ps_money_exponent($currency);
        $negative = str_starts_with($decimal, '-');

        if ($negative) {
            $decimal = substr($decimal, 1);
        }

        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');

        $grouped = number_format((float) $whole, 0, '.', ',');

        $out = $currency . ' ' . $grouped;

        if ($exponent > 0) {
            $out .= '.' . $fraction;
        }

        return ($negative ? '-' : '') . $out;
    }

    /**
     * Apply a basis-point rate to an amount, rounding half away from zero.
     *
     * intdiv() truncates toward zero, so the half-unit is added on the
     * magnitude and the sign reapplied afterwards. This keeps
     * apply(1000) + apply(-1000) === 0.
     */
    function ps_money_apply_bps(int $amountMinor, int $bps): int
    {
        if ($bps < 0) {
            throw new InvalidArgumentException('Basis points must not be negative.');
        }

        $sign = $amountMinor < 0 ? -1 : 1;
        $absolute = abs($amountMinor);

        // denominator 10000, half of it is 5000
        $result = intdiv($absolute * $bps + 5000, 10000);

        return $sign * $result;
    }

    /**
     * Split a captured gross amount into the platform's commission and the
     * owner's net earning.
     *
     * The owner figure is DERIVED as gross - commission rather than computed
     * independently, so the three values can never fail to reconcile. The
     * ledger relies on this: captured == commission + ownerNet, exactly.
     */
    function ps_money_split(int $grossMinor, int $commissionBps): array
    {
        if ($grossMinor < 0) {
            throw new InvalidArgumentException('Gross amount must not be negative.');
        }

        $commission = ps_money_apply_bps($grossMinor, $commissionBps);

        if ($commission > $grossMinor) {
            throw new InvalidArgumentException(
                'Commission rate exceeds 100% of the captured amount.'
            );
        }

        return [
            'gross_minor' => $grossMinor,
            'commission_minor' => $commission,
            'owner_net_minor' => $grossMinor - $commission,
            'commission_bps' => $commissionBps,
        ];
    }

    /**
     * Validate a basis-point rate.
     */
    function ps_money_validate_bps(int $bps, int $max = 10000): int
    {
        if ($bps < 0 || $bps > $max) {
            throw new InvalidArgumentException(
                'Basis points must be between 0 and ' . $max . '.'
            );
        }

        return $bps;
    }
}
