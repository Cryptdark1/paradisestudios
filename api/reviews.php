<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

requireLogin();

if (currentUserRole() !== 'artist') {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Only artists can submit reviews.'
    ]);

    exit;
}

/*
 * GET  -> list the signed-in artist's own reviews (with booking + studio)
 * POST -> submit a review (existing behaviour continues below, unchanged)
 *
 * The GET branch is additive; the POST submit flow is untouched.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $artistId = currentUserId();

    try {

        /*
         * Scoped to the signed-in artist. This lets the Buzz & Reviews page
         * show what the artist has already written, and lets the dashboard
         * mark which completed bookings are still reviewable.
         */
        $stmt = $pdo->prepare(
            "SELECT
                r.id,
                r.booking_id,
                r.rating,
                r.comment,
                r.created_at,
                s.name AS studio_name,
                b.start_time
             FROM reviews r

             INNER JOIN studios s
                 ON r.studio_id = s.id

             INNER JOIN bookings b
                 ON r.booking_id = b.id

             WHERE r.artist_id = ?

             ORDER BY r.created_at DESC, r.id DESC"
        );

        $stmt->execute([$artistId]);

        $reviews = $stmt->fetchAll();

        foreach ($reviews as &$review) {
            $review['id'] = (int) $review['id'];
            $review['booking_id'] = (int) $review['booking_id'];
            $review['rating'] = (int) $review['rating'];
        }

        unset($review);

        echo json_encode([
            'success' => true,
            'count' => count($reviews),
            'data' => $reviews
        ]);

    } catch (PDOException $e) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Unable to load reviews.'
        ]);
    }

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);

    exit;
}

ps_require_api_csrf_token();

/*
 * Read JSON request body.
 */
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON request.'
    ]);

    exit;
}

$bookingId = $input['booking_id'] ?? '';
$rating = $input['rating'] ?? '';
$comment = trim($input['comment'] ?? '');

if (!filter_var($bookingId, FILTER_VALIDATE_INT)) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'A valid booking ID is required.'
    ]);

    exit;
}

/*
 * Rating must be an integer from 1 to 5.
 */
if (
    filter_var($rating, FILTER_VALIDATE_INT) === false ||
    (int) $rating < 1 ||
    (int) $rating > 5
) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Rating must be an integer between 1 and 5.'
    ]);

    exit;
}

$rating = (int) $rating;

if (strlen($comment) > 1000) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Comment cannot exceed 1000 characters.'
    ]);

    exit;
}

$artistId = currentUserId();

try {

    $pdo->beginTransaction();

    /*
     * Lock the booking while checking whether
     * it can be reviewed.
     */
    $stmt = $pdo->prepare("
        SELECT
            b.id,
            b.artist_id,
            b.studio_id,
            b.status,
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
     * Reviews are only allowed after the session
     * has been completed.
     */
    if ($booking['status'] !== 'completed') {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'Only completed bookings can be reviewed.'
        ]);

        exit;
    }

    /*
     * Check whether this booking already has
     * a review.
     */
    $stmt = $pdo->prepare("
        SELECT id
        FROM reviews
        WHERE booking_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $bookingId
    ]);

    if ($stmt->fetch()) {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'This booking has already been reviewed.'
        ]);

        exit;
    }

    /*
     * Create the review.
     */
    $stmt = $pdo->prepare("
        INSERT INTO reviews (
            booking_id,
            artist_id,
            studio_id,
            rating,
            comment
        )
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $bookingId,
        $artistId,
        $booking['studio_id'],
        $rating,
        $comment !== '' ? $comment : null
    ]);

    $reviewId = $pdo->lastInsertId();

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Review submitted successfully.',
        'data' => [
            'review_id' => (int) $reviewId,
            'booking_id' => (int) $bookingId,
            'studio_id' => (int) $booking['studio_id'],
            'studio_name' => $booking['studio_name'],
            'rating' => $rating,
            'comment' => $comment
        ]
    ]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to submit review.'
    ]);
}