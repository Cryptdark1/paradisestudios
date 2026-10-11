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

    // Get the logged-in user's ID from the session
    $artistId = currentUserId();

    /*
     * We deliberately do NOT accept artist_id from the URL.
     *
     * This prevents a user from changing:
     *
     * ?artist_id=5
     *
     * and viewing another artist's bookings.
     */

    /*
     * PAYMENT COLUMNS
     * ---------------
     * payment_state, total_amount_minor and payment_expires_at were already on
     * the bookings table but were never selected, so the frontend could not
     * tell a paid booking from an unpaid one. They are read straight from the
     * column rather than derived from the payments table.
     *
     * The column is the authority because PaymentService maintains it inside
     * the same transaction as the payment row: markPaymentFailed() updates
     * both, confirm() updates both, RefundService updates it. Re-deriving it
     * here from a JOIN could disagree with the authoritative value, and would
     * be a second source of truth for money.
     *
     * Rejections and edges this deliberately handles:
     *   - A booking with NO payment row still reports its own payment_state,
     *     which is 'unpaid'. Nothing here can promote a booking to paid, and
     *     no state is inferred from the absence of a payment.
     *   - payment_state is NOT NULL DEFAULT 'unpaid', so it cannot be NULL
     *     and needs no COALESCE guard.
     *   - total_amount_minor and payment_expires_at ARE nullable, and are
     *     returned as NULL rather than invented. The existing total_amount
     *     column is NOT NULL and remains the display value.
     */
    $stmt = $pdo->prepare("
        SELECT
            b.id,
            b.studio_id,
            s.name AS studio_name,
            s.location AS studio_location,
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

        WHERE b.artist_id = ?

        ORDER BY b.start_time DESC
    ");

    $stmt->execute([$artistId]);

    $bookings = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'count' => count($bookings),
        'data' => $bookings
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to load bookings.'
    ]);
}