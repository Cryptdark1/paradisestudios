<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

/*
 * Payment integration. The POST handler below is otherwise unchanged: same
 * endpoint, same method, same status codes, same response keys.
 */
require_once __DIR__ . '/../includes/payments/ProviderFactory.php';
require_once __DIR__ . '/../includes/payments/FinancialLedger.php';
require_once __DIR__ . '/../includes/payments/BookingLifecycle.php';

header('Content-Type: application/json');

requireLogin();

if (currentUserRole() !== 'artist') {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Only artists can cancel bookings.'
    ]);

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);

    exit;
}

ps_require_api_csrf_token();

$bookingId = $_GET['id'] ?? '';

if (!filter_var($bookingId, FILTER_VALIDATE_INT)) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'A valid booking ID is required.'
    ]);

    exit;
}

$artistId = currentUserId();

try {

    $pdo->beginTransaction();

    /*
     * Lock the booking while checking and cancelling it.
     */
    $stmt = $pdo->prepare("
        SELECT
            b.id,
            b.artist_id,
            b.studio_id,
            b.start_time,
            b.end_time,
            b.total_amount,
            b.total_amount_minor,
            b.status,
            b.payment_state,
            s.name AS studio_name
        FROM bookings b

        INNER JOIN studios s
            ON b.studio_id = s.id

        WHERE b.id = ?
        AND b.artist_id = ?

        FOR UPDATE
    ");

    $stmt->execute([
        $bookingId,
        $artistId
    ]);

    $booking = $stmt->fetch();

    if (!$booking) {

        $pdo->rollBack();

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Booking not found.'
        ]);

        exit;
    }

    /*
     * A booking can be cancelled when a payment has been confirmed, or when it
     * was never paid for at all (payment_state 'unpaid' or 'failed').
     *
     * This widens the previous `escrow_held`-only gate deliberately: with
     * payments, a booking sits in 'pending' until the customer actually pays,
     * and an unpaid booking must still be cancellable or a customer could be
     * locked into a session they never bought. `escrow_held` remains accepted
     * for backward compatibility with existing rows.
     */
    $cancellableStates = ['escrow_held', 'pending', 'confirmed'];

    $paymentState = (string) ($booking['payment_state'] ?? '');

    $isCancellable =
        $booking['status'] === 'escrow_held'
        || ($paymentState !== '' && in_array($paymentState, ['unpaid', 'failed', 'expired'], true));

    if (!$isCancellable) {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'This booking cannot be cancelled in its current state.'
        ]);

        exit;
    }

    /*
     * Compare the session start time with the
     * current server time.
     */
    $now = new DateTime();
    $startTime = new DateTime($booking['start_time']);

    $secondsUntilStart =
        $startTime->getTimestamp() - $now->getTimestamp();

    /*
     * Session has already started.
     */
    if ($secondsUntilStart <= 0) {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'A booking cannot be cancelled after the session has started.'
        ]);

        exit;
    }

    /*
     * Refund policy, in integer basis points.
     *
     * The previous implementation computed this with floats against
     * bookings.total_amount. The figure is now derived from what was actually
     * CAPTURED, in whole kobo, so it can never disagree with the ledger.
     *
     * The response keys the frontends already read are preserved with the same
     * names and meaning.
     */
    $fullRefundWindowSeconds = 24 * 60 * 60;

    $refundPolicyBps =
        $secondsUntilStart >= $fullRefundWindowSeconds ? 10000 : 5000;

    $totalAmountMinor = (int) ($booking['total_amount_minor'] ?? 0);

    $lifecycle = new BookingLifecycle($pdo, new FinancialLedger($pdo));

    $refundPlan = $lifecycle->cancellationRefundPlan($booking);

    /*
     * Mark the booking cancelled.
     */
    $stmt = $pdo->prepare("
        UPDATE bookings
        SET status = 'cancelled'
        WHERE id = ?
    ");

    $stmt->execute([
        $bookingId
    ]);

    $pdo->commit();

    /*
     * The gateway call happens AFTER the commit, deliberately. Holding the
     * booking's row lock across a network call would block every other
     * operation on this booking for the duration, and a crash mid-call would
     * roll back a cancellation the customer has already been told succeeded.
     *
     * The cancellation is durable regardless of what the gateway does; the
     * refund outcome is reported separately and honestly.
     */
    $refundOutcome = $lifecycle->requestCancellationRefund((int) $bookingId, $artistId);

    $totalAmount = (float) $booking['total_amount'];

    /*
     * Legacy display values. refund_amount stays a float in major units
     * because the current frontends format it that way, but it is now derived
     * from the captured minor amount rather than recomputed with floats.
     */
    $refundAmount = ps_money_to_decimal(
        (int) $refundPlan['calculated_minor'],
        (string) ps_payment_config('currency')
    );

    echo json_encode([
        'success' => true,
        'message' => 'Booking cancelled successfully.',
        'data' => [
            'booking_id' => (int) $booking['id'],
            'studio_name' => $booking['studio_name'],
            'status' => 'cancelled',
            'total_amount' => $totalAmount,
            'refund_percentage' => (int) ($refundPolicyBps / 100),
            'refund_amount' => (float) $refundAmount,

            /* --- added by the payment layer; existing keys above are unchanged --- */
            'refund_policy_bps' => (int) $refundPolicyBps,
            'refund_amount_minor' => (int) $refundPlan['calculated_minor'],
            'captured_amount_minor' => (int) $refundPlan['captured_minor'],
            'refund_processed' => (bool) ($refundOutcome['refund_attempted'] ?? false)
                && ($refundOutcome['status'] ?? '') === 'succeeded',
            'refund_status' => $refundOutcome['status'] ?? 'not_applicable',
            'refund_message' => $refundOutcome['message']
                ?? $refundPlan['notice']
                ?? 'This booking was not paid for, so no refund was due.',
            'notice' => 'A refund is only returned once the payment provider confirms it.'
        ]
    ]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to cancel booking.'
    ]);
}