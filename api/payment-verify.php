<?php

/**
 * Paradise Studios - confirm a payment, server side.
 *
 * POST /api/payment-verify.php
 * Body: { "reference": "ps_12_2026...", "booking_id": 12 }
 *
 * This is what the success page calls when the customer returns from the
 * provider. CRITICAL: arriving back on a success URL is NOT evidence of
 * payment. This endpoint ignores everything the browser says and re-asks the
 * provider. If the provider has not confirmed the transaction, nothing is
 * credited and the response says so plainly.
 *
 * It is idempotent, so calling it repeatedly - by an impatient customer, a
 * double click, or a retry - cannot credit the booking twice.
 */

require_once __DIR__ . '/payment-helpers.php';

requireLogin();

if (currentUserRole() !== 'artist') {
    ps_pay_error(403, 'Only artists can confirm their own payments.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ps_pay_error(405, 'Method not allowed.');
}

ps_require_api_csrf_token();

ps_pay_rate_limit('payment_verify', 40, 60, 300);

$input = ps_pay_json_body();

$reference = $input['reference'] ?? null;

if (!is_string($reference) || trim($reference) === '' || strlen($reference) > 64) {
    ps_pay_error(400, 'A valid payment reference is required.');
}

$bookingId = ps_pay_int($input, 'booking_id', false);

try {
    $service = new PaymentService($pdo, new FinancialLedger($pdo));

    /*
     * Ownership is checked inside confirm(): the reference must resolve to a
     * payment belonging to THIS artist. Passing someone else's reference is
     * treated as "not found" rather than "forbidden", so the endpoint cannot be
     * used to probe which references exist.
     */
    $payment = $service->findPaymentByReference(trim($reference));

    if ((int) $payment['artist_id'] !== (int) currentUserId()) {
        ps_pay_error(404, 'Payment not found.');
    }

    if ($bookingId !== null && (int) $payment['booking_id'] !== $bookingId) {
        ps_pay_error(404, 'Payment not found.');
    }

    $result = $service->confirm(trim($reference), $bookingId === null ? null : (string) $bookingId);

    ps_pay_ok(
        [
            'confirmed' => $result['confirmed'],
            'already_confirmed' => $result['already_confirmed'],
            'payment_state' => 'paid',
            'booking_id' => $result['booking_id'],
            'amount_minor' => $result['amount_minor'],
            'currency' => $result['currency'],
            'amount_display' => ps_money_format($result['amount_minor'], $result['currency']),
        ],
        $result['already_confirmed']
            ? 'This payment was already confirmed.'
            : 'Payment confirmed.'
    );
} catch (PaymentVerificationException $e) {
    /*
     * A verification mismatch means nothing can be credited, but it does not
     * prove whether the provider moved money. Keep the response neutral.
     */
    ps_pay_error(
        409,
        'This payment could not be confirmed. Check your bookings before trying again.'
    );
} catch (Throwable $e) {
    ps_pay_fail($e);
}
