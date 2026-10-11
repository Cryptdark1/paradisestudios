<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/payments.php';
require_once __DIR__ . '/../includes/money.php';
require_once __DIR__ . '/../includes/payments/ProviderFactory.php';

header('Content-Type: application/json');

// Only logged-in users can create bookings
requireLogin();

// Only artists can create bookings
if (currentUserRole() !== 'artist') {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Only artists can create bookings.'
    ]);

    exit;
}

// This endpoint currently handles POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);

    exit;
}

ps_require_api_csrf_token();

// Read JSON request body
$input = json_decode(
    file_get_contents('php://input'),
    true
);

// Make sure valid JSON was provided
if (!is_array($input)) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON request body.'
    ]);

    exit;
}

// Get booking data
$studioId = $input['studio_id'] ?? null;
$startTime = $input['start_time'] ?? null;
$endTime = $input['end_time'] ?? null;

// Validate required fields
if (
    !$studioId ||
    !$startTime ||
    !$endTime
) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'studio_id, start_time and end_time are required.'
    ]);

    exit;
}

// Validate studio ID
if (!filter_var($studioId, FILTER_VALIDATE_INT)) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid studio ID.'
    ]);

    exit;
}

// Validate date/time format
$start = DateTime::createFromFormat(
    'Y-m-d H:i:s',
    $startTime
);

$end = DateTime::createFromFormat(
    'Y-m-d H:i:s',
    $endTime
);

if (
    !$start ||
    !$end ||
    $start->format('Y-m-d H:i:s') !== $startTime ||
    $end->format('Y-m-d H:i:s') !== $endTime
) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid date/time format. Use YYYY-MM-DD HH:MM:SS.'
    ]);

    exit;
}

// End time must be after start time
if ($end <= $start) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'End time must be after start time.'
    ]);

    exit;
}

// Calculate duration in hours
$durationSeconds = $end->getTimestamp() - $start->getTimestamp();

$durationHours = $durationSeconds / 3600;

// For this MVP, sessions must be at least 1 hour
if ($durationHours < 1) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Booking must be at least 1 hour.'
    ]);

    exit;
}

// Make sure duration is in whole hours
if (fmod($durationHours, 1) !== 0.0) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Booking duration must be in whole hours.'
    ]);

    exit;
}

// Get the studio and its hourly rate
$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        hourly_rate
    FROM studios
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$studioId]);

$studio = $stmt->fetch();

// Studio does not exist
if (!$studio) {

    http_response_code(404);

    echo json_encode([
        'success' => false,
        'message' => 'Studio not found.'
    ]);

    exit;
}

// Calculate total booking cost
$hourlyRate = (float) $studio['hourly_rate'];

$totalAmount = $hourlyRate * $durationHours;

// Start a database transaction
$pdo->beginTransaction();

try {

    /*
     * Lock the studio row.
     *
     * This prevents two booking requests for the same studio
     * from performing the availability check simultaneously.
     */
    $stmt = $pdo->prepare("
        SELECT
            id,
            name,
            hourly_rate
        FROM studios
        WHERE id = ?
        FOR UPDATE
    ");

    $stmt->execute([$studioId]);

    $studio = $stmt->fetch();

    // Studio does not exist
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
     * Check for an overlapping active booking.
     *
     * Overlap exists when:
     *
     * existing start < requested end
     * AND
     * existing end > requested start
     *
     * PAYMENT-AWARE. The previous query treated any non-cancelled,
     * non-completed booking as occupying the slot. With payments a booking sits
     * in 'pending' until the customer actually pays, so an abandoned checkout
     * would have frozen a studio's calendar indefinitely.
     *
     * A booking is only treated as occupying the slot when it is still live
     * with respect to money:
     *   paid / partially_refunded  - a real confirmed commitment
     *   legacy_unverified          - a pre-payments booking, treated as active
     *   pending                    - holds the slot only until payment_expires_at
     *   unpaid / failed / expired  - never holds the slot
     */
    $stmt = $pdo->prepare("
        SELECT id
        FROM bookings
        WHERE studio_id = ?
        AND status NOT IN ('cancelled', 'completed')
        AND (
            payment_state IN ('paid', 'partially_refunded', 'legacy_unverified')
            OR (
                payment_state = 'pending'
                AND (payment_expires_at IS NULL OR payment_expires_at > UTC_TIMESTAMP())
            )
        )
        AND start_time < ?
        AND end_time > ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $studioId,
        $endTime,
        $startTime
    ]);

    $existingBooking = $stmt->fetch();

    if ($existingBooking) {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'This studio is already booked during the selected time.'
        ]);

        exit;
    }

    /*
     * Calculate the booking cost in integer minor units.
     *
     * The float multiplication below is what the payment layer exists to
     * eliminate, so it is replaced. The DECIMAL column is still written for the
     * existing frontends, but derived from the integer, never the other way
     * round.
     */
    $currency = (string) ps_payment_config('currency');

    $hourlyRateMinor = ps_money_to_minor((string) $studio['hourly_rate'], $currency);
    $totalAmountMinor = $hourlyRateMinor * $durationHours;

    $totalAmount = (float) ps_money_to_decimal($totalAmountMinor, $currency);
    $hourlyRate = (float) ps_money_to_decimal($hourlyRateMinor, $currency);

    /*
     * Create the booking as 'pending'.
     *
     * BEHAVIOUR CHANGE, DOCUMENTED: this previously wrote 'escrow_held', the
     * same status used before payments existed. 'escrow_held' is now assigned
     * ONLY when a payment provider confirms money arrived, so writing it at
     * creation would assert that funds were held when none were.
     *
     * A new booking therefore starts unpaid and does not occupy the studio's
     * calendar until payment is confirmed (see the overlap query above).
     */
    $stmt = $pdo->prepare("
        INSERT INTO bookings (
            studio_id,
            artist_id,
            start_time,
            end_time,
            total_amount,
            total_amount_minor,
            status,
            payment_state
        )
        VALUES (?, ?, ?, ?, ?, ?, 'pending', 'unpaid')
    ");

    $stmt->execute([
        $studioId,
        currentUserId(),
        $startTime,
        $endTime,
        $totalAmount,
        $totalAmountMinor
    ]);

    $bookingId = $pdo->lastInsertId();

    // Everything succeeded
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Booking created successfully.',
        'data' => [
            'booking_id' => (int) $bookingId,
            'studio_id' => (int) $studio['id'],
            'studio_name' => $studio['name'],
            'artist_id' => currentUserId(),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration_hours' => $durationHours,
            'hourly_rate' => $hourlyRate,
            'total_amount' => $totalAmount,

            /*
             * CHANGED from 'escrow_held' to 'pending'.
             *
             * The current frontends key their action buttons off this value,
             * so a new booking correctly shows "View" only until payment is
             * confirmed. That is a display change only - no frontend code was
             * edited - and it is the honest state: no money has been taken.
             */
            'status' => 'pending',

            /* --- added by the payment layer --- */
            'payment_state' => 'unpaid',
            'total_amount_minor' => $totalAmountMinor,
            'payment_required' => true,
            'notice' => 'This booking is not paid for yet. Start a payment to confirm it.'
        ]
    ]);

} catch (Throwable $e) {

    // Undo anything that happened inside the transaction
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to create booking.'
    ]);
}