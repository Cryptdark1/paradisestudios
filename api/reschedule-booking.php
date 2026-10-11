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
        'message' => 'Only artists can reschedule bookings.'
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

/*
 * Read JSON request body.
 */
$input = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($input)) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON request.'
    ]);

    exit;
}

$startTime = $input['start_time'] ?? '';
$endTime = $input['end_time'] ?? '';

if ($startTime === '' || $endTime === '') {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Start time and end time are required.'
    ]);

    exit;
}

/*
 * Validate datetime format.
 */
$start = DateTime::createFromFormat(
    'Y-m-d H:i:s',
    $startTime
);

$end = DateTime::createFromFormat(
    'Y-m-d H:i:s',
    $endTime
);

$startErrors = DateTime::getLastErrors();

if (
    $start === false ||
    $end === false ||
    (
        $startErrors !== false &&
        ($startErrors['warning_count'] > 0 ||
         $startErrors['error_count'] > 0)
    )
) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid datetime format. Use YYYY-MM-DD HH:MM:SS.'
    ]);

    exit;
}

if ($end <= $start) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'End time must be after start time.'
    ]);

    exit;
}

/*
 * Calculate duration in hours.
 */
$durationSeconds =
    $end->getTimestamp() - $start->getTimestamp();

$hour = 60 * 60;

if ($durationSeconds < $hour) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Booking must be at least 1 hour.'
    ]);

    exit;
}

if ($durationSeconds % $hour !== 0) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Booking duration must be in whole hours.'
    ]);

    exit;
}

$durationHours = (int) (
    $durationSeconds / $hour
);

$artistId = currentUserId();

try {

    $pdo->beginTransaction();

    /*
     * Lock the booking while rescheduling it.
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
            s.name AS studio_name,
            s.hourly_rate
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
     * Only escrow-held bookings can be rescheduled.
     */
    if ($booking['status'] !== 'escrow_held') {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'This booking cannot be rescheduled in its current state.'
        ]);

        exit;
    }

    /*
     * Do not allow rescheduling after the
     * original session has started.
     */
    $now = new DateTime();

    $originalStart =
        new DateTime($booking['start_time']);

    if ($originalStart <= $now) {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'A booking cannot be rescheduled after the session has started.'
        ]);

        exit;
    }

    /*
     * Lock the studio before checking availability.
     */
    $stmt = $pdo->prepare("
        SELECT id, hourly_rate
        FROM studios
        WHERE id = ?
        FOR UPDATE
    ");

    $stmt->execute([
        $booking['studio_id']
    ]);

    $studio = $stmt->fetch();

    if (!$studio) {

        $pdo->rollBack();

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Studio not found.'
        ]);

        exit;
    }

    /*
     * Check whether another active booking
     * occupies the requested time.
     *
     * The current booking is excluded.
     */
    $stmt = $pdo->prepare("
        SELECT id
        FROM bookings
        WHERE studio_id = ?
        AND id != ?
        AND status NOT IN ('cancelled', 'completed')
        AND start_time < ?
        AND end_time > ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $booking['studio_id'],
        $bookingId,
        $endTime,
        $startTime
    ]);

    $conflict = $stmt->fetch();

    if ($conflict) {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'The requested time overlaps another booking.'
        ]);

        exit;
    }

    /*
     * Recalculate the booking amount using the studio's hourly rate.
     *
     * Computed in integer minor units. The previous float multiplication is
     * what the payment layer exists to eliminate, so it is not carried forward
     * even for the display value.
     */
    $hourlyRateMinor = ps_money_to_minor(
        (string) $studio['hourly_rate'],
        (string) ps_payment_config('currency')
    );

    $totalAmountMinor = $hourlyRateMinor * $durationHours;

    $totalAmount = (float) ps_money_to_decimal(
        $totalAmountMinor,
        (string) ps_payment_config('currency')
    );

    /*
     * A booking money has already been taken against must not change amount.
     *
     * Rescheduling recomputes the total from the studio's CURRENT rate, so for a
     * paid booking it would silently move a figure the customer was already
     * charged at. Neither a top-up nor a partial refund is performed
     * automatically here, so the booking is refused and the artist is told to
     * cancel and rebook.
     */
    $lifecycle = new BookingLifecycle($pdo, new FinancialLedger($pdo));

    $rescheduleCheck = $lifecycle->rescheduleCheck($booking, $totalAmountMinor);

    if (!$rescheduleCheck['allowed']) {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => $rescheduleCheck['reason'],
            'reason_code' => $rescheduleCheck['code']
        ]);

        exit;
    }

    /*
     * Update the booking.
     *
     * total_amount_minor is kept in step with the display column so the two
     * can never drift apart.
     */
    $stmt = $pdo->prepare("
        UPDATE bookings
        SET
            start_time = ?,
            end_time = ?,
            total_amount = ?,
            total_amount_minor = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $startTime,
        $endTime,
        $totalAmount,
        $totalAmountMinor,
        $bookingId
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Booking rescheduled successfully.',
        'data' => [
            'booking_id' => (int) $booking['id'],
            'studio_id' => (int) $booking['studio_id'],
            'studio_name' => $booking['studio_name'],
            'artist_id' => (int) $booking['artist_id'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration_hours' => $durationHours,
            'hourly_rate' => $hourlyRate,
            'total_amount' => $totalAmount,
            'status' => $booking['status'],

            /* --- added by the payment layer; existing keys above are unchanged --- */
            'total_amount_minor' => $totalAmountMinor
        ]
    ]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to reschedule booking.'
    ]);
}