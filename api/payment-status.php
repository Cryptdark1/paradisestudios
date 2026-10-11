<?php

/**
 * Paradise Studios - payment status for a booking.
 *
 * GET /api/payment-status.php?booking_id=12
 *
 * Read-only, so no CSRF token is required - a GET changes nothing. It does
 * require a session and an ownership check.
 *
 * The response is facts about state. It never asserts that funds are held
 * anywhere: money is captured into the platform's merchant account, and the
 * booking's payment_state says whether that happened for THIS booking.
 */

require_once __DIR__ . '/payment-helpers.php';

requireLogin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    ps_pay_error(405, 'Method not allowed.');
}

ps_pay_rate_limit('payment_status', 120, 60, 60);

$bookingId = $_GET['booking_id'] ?? null;

if (
    !is_string($bookingId)
    || preg_match('/^\d+$/', $bookingId) !== 1
    || (int) $bookingId < 1
) {
    ps_pay_error(400, 'A valid booking_id is required.');
}

try {
    $service = new PaymentService($pdo, new FinancialLedger($pdo));

    $status = $service->statusForBooking((int) $bookingId, (int) currentUserId());

    ps_pay_ok($status);
} catch (Throwable $e) {
    ps_pay_fail($e);
}
