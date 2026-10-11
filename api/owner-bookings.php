<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

requireRole('owner');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

$ownerId = currentUserId();

try {

    /*
    |--------------------------------------------------------------------------
    | Get bookings belonging to this owner's studios
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            b.id,
            b.studio_id,
            s.name AS studio_name,
            s.location,
            b.artist_id,
            u.name AS artist_name,
            u.email AS artist_email,
            b.start_time,
            b.end_time,
            b.total_amount,
            b.status,
            b.payment_state,
            b.created_at
        FROM bookings b

        INNER JOIN studios s
            ON s.id = b.studio_id

        INNER JOIN users u
            ON u.id = b.artist_id

        WHERE s.owner_id = ?

        ORDER BY b.start_time ASC
    ");

    $stmt->execute([$ownerId]);

    $bookings = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'count' => count($bookings),
        'data' => $bookings
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to retrieve owner bookings.'
    ]);
}