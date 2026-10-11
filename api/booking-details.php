<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// User must be logged in
requireRole('artist');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

try {

    $bookingId = $_GET['id'] ?? '';

    // Validate booking ID
    if (!filter_var($bookingId, FILTER_VALIDATE_INT)) {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'A valid booking ID is required.'
        ]);

        exit;
    }

    $artistId = currentUserId();

    /*
     * IMPORTANT:
     * We check the booking ID AND the logged-in artist ID
     * in the same query.
     *
     * This prevents an artist from viewing another
     * artist's booking by changing ?id=...
     *
     * The added payment columns are subject to exactly the same restriction:
     * they are selected only by a query already scoped to this artist's own
     * booking, so payment information cannot leak across users. There is no
     * admin override on this endpoint and none is introduced here.
     *
     * PAYMENT COLUMNS
     * ---------------
     * Read directly from bookings.payment_state rather than derived from the
     * payments table, because PaymentService maintains the column inside the
     * same transaction as the payment row. A JOIN-derived value could
     * disagree with the authoritative one.
     *
     *   - No payment row  -> the booking's own 'unpaid' state is returned.
     *     Nothing here can infer a paid state from anything.
     *   - payment_state is NOT NULL DEFAULT 'unpaid', so never NULL.
     *   - total_amount_minor and payment_expires_at are nullable and are
     *     returned as NULL rather than fabricated. total_amount below is
     *     NOT NULL and is unchanged as the display value.
     */
    $stmt = $pdo->prepare("
        SELECT
            b.id,
            b.studio_id,
            s.name AS studio_name,
            s.location AS studio_location,
            s.address AS studio_address,
            s.hourly_rate,
            b.artist_id,
            b.start_time,
            b.end_time,
            b.total_amount,
            b.status,
            b.created_at,

            -- Payment state. See the note above: authoritative, not derived.
            b.payment_state,
            b.total_amount_minor,
            b.payment_expires_at

        FROM bookings b

        INNER JOIN studios s
            ON b.studio_id = s.id

        WHERE b.id = ?
        AND b.artist_id = ?

        LIMIT 1
    ");

    $stmt->execute([
        $bookingId,
        $artistId
    ]);

    $booking = $stmt->fetch();

    // Booking doesn't exist or doesn't belong to this artist
    if (!$booking) {

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Booking not found.'
        ]);

        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => $booking
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to load booking details.'
    ]);
}