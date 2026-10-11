<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/payments/ProviderFactory.php';
require_once __DIR__ . '/../includes/payments/FinancialLedger.php';
require_once __DIR__ . '/../includes/payments/BookingLifecycle.php';

header('Content-Type: application/json');

requireLogin();

if (currentUserRole() !== 'artist') {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Only artists can complete bookings.'
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

    // Start transaction
    $pdo->beginTransaction();

    /*
     * Lock the booking while we process completion.
     *
     * This prevents two completion requests from
     * awarding XP twice.
     */
    $stmt = $pdo->prepare("
        SELECT
            b.id,
            b.artist_id,
            b.studio_id,
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

    // Booking doesn't exist or doesn't belong to artist
    if (!$booking) {

        $pdo->rollBack();

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Booking not found.'
        ]);

        exit;
    }

    $lifecycle = new BookingLifecycle($pdo, new FinancialLedger($pdo));

    /*
     * A session may only be completed once money was actually captured.
     *
     * This REPLACES the previous `escrow_held`-only gate. `escrow_held` never
     * represented held funds - it was set the moment a booking was created, with
     * no money involved - so completing such a booking awarded XP and created an
     * owner earning for a session nobody paid for.
     *
     * PAYMENT IS CHECKED FIRST. An unpaid booking is normally 'pending', so
     * testing the session status first produced the unhelpful "Only paid
     * bookings can be completed" when the real problem was simply that no
     * payment had been made yet. This way the message says what to fix.
     */
    $completionCheck = $lifecycle->completionCheck($booking);

    if (!$completionCheck['allowed']) {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => $completionCheck['reason'],
            'reason_code' => $completionCheck['code']
        ]);

        exit;
    }

    /*
     * The money is confirmed, so the only remaining question is whether the
     * session is in a state that can be completed.
     */
    if ($booking['status'] !== 'escrow_held') {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'Only paid bookings can be completed.'
        ]);

        exit;
    }

    /*
     * Mark booking as completed.
     */
    $stmt = $pdo->prepare("
        UPDATE bookings
        SET status = 'completed'
        WHERE id = ?
    ");

    $stmt->execute([$bookingId]);

    /*
     * Award 100 XP to the artist.
     */
    $stmt = $pdo->prepare("
        UPDATE users
        SET buzz_level = buzz_level + 100
        WHERE id = ?
    ");

    $stmt->execute([$artistId]);

    // Commit everything together
    $pdo->commit();

    /*
     * The owner earning already exists from the payment capture. Completion
     * only moves it from pending_session towards eligibility, after the
     * configured delay. Eligibility is not a transfer: no money is moved here.
     */
    $lifecycle->refreshPayoutEligibility((int) $bookingId);

    echo json_encode([
        'success' => true,
        'message' => 'Booking completed successfully.',
        'data' => [
            'booking_id' => (int) $booking['id'],
            'studio_name' => $booking['studio_name'],
            'total_amount' => (float) $booking['total_amount'],
            'status' => 'completed',
            'xp_earned' => 100,

            /* --- added by the payment layer; existing keys above are unchanged --- */
            'payment_state' => 'paid',
            'payout_status' => 'pending_delay',
            'payout_notice' => 'The owner earning becomes eligible after the configured delay. '
                . 'No transfer has been initiated.'
        ]
    ]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to complete booking.'
    ]);
}