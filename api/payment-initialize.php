<?php

/**
 * Paradise Studios - start a payment for a booking.
 *
 * POST /api/payment-initialize.php
 * Body: { "booking_id": 12 }
 *
 * Returns the amount to be charged and a URL to send the customer to. It does
 * NOT mark anything paid: a booking is only paid once the provider confirms,
 * which happens through the webhook or a subsequent verify call.
 *
 * The amount is never taken from the request. It is read from the booking,
 * which the server itself derived from the studio's hourly rate.
 */

require_once __DIR__ . '/payment-helpers.php';

requireLogin();

if (currentUserRole() !== 'artist') {
    ps_pay_error(403, 'Only artists can pay for bookings.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ps_pay_error(405, 'Method not allowed.');
}

/*
 * Reuses the project's existing CSRF implementation. A browser-driven state
 * change must carry the session token.
 */
ps_require_api_csrf_token();

ps_pay_rate_limit('payment_initialize', 20, 60, 300);

$input = ps_pay_json_body();
$bookingId = ps_pay_int($input, 'booking_id');

try {
    $service = new PaymentService($pdo, new FinancialLedger($pdo));

    $result = $service->initialize((int) $bookingId, (int) currentUserId());

    ps_pay_ok($result, 'Payment started.');
} catch (Throwable $e) {
    ps_pay_fail($e);
}
