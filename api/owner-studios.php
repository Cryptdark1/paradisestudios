<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

requireLogin();

if (currentUserRole() !== 'owner') {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Only studio owners can access this endpoint.'
    ]);

    exit;
}

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
     * Only return studios belonging to
     * the currently logged-in owner.
     */
    $stmt = $pdo->prepare("
        SELECT
            id,
            name,
            location,
            address,
            hourly_rate,
            description,
            created_at
        FROM studios
        WHERE owner_id = ?
        ORDER BY created_at DESC
    ");

    $stmt->execute([
        $ownerId
    ]);

    $studios = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'count' => count($studios),
        'data' => $studios
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to retrieve studios.'
    ]);
}