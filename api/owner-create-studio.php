<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

requireLogin();

if (currentUserRole() !== 'owner') {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Only studio owners can create studios.'
    ]);

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

$name = trim($input['name'] ?? '');
$location = trim($input['location'] ?? '');
$address = trim($input['address'] ?? '');
$hourlyRate = $input['hourly_rate'] ?? '';
$description = trim($input['description'] ?? '');

/*
 * Validate studio name.
 */
if ($name === '') {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Studio name is required.'
    ]);

    exit;
}

if (strlen($name) > 100) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Studio name cannot exceed 100 characters.'
    ]);

    exit;
}

/*
 * Validate location.
 */
if ($location === '') {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Studio location is required.'
    ]);

    exit;
}

if (strlen($location) > 100) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Studio location cannot exceed 100 characters.'
    ]);

    exit;
}

/*
 * Validate hourly rate.
 */
if (
    !is_numeric($hourlyRate) ||
    (float) $hourlyRate <= 0
) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Hourly rate must be greater than zero.'
    ]);

    exit;
}

$hourlyRate = (float) $hourlyRate;

/*
 * Validate optional address.
 */
if (strlen($address) > 1000) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Address cannot exceed 1000 characters.'
    ]);

    exit;
}

/*
 * Validate optional description.
 */
if (strlen($description) > 5000) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Description cannot exceed 5000 characters.'
    ]);

    exit;
}

$ownerId = currentUserId();

try {

    /*
     * Create the studio using the logged-in
     * owner's ID from the session.
     */
    $stmt = $pdo->prepare("
        INSERT INTO studios (
            owner_id,
            name,
            location,
            address,
            hourly_rate,
            description
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $ownerId,
        $name,
        $location,
        $address !== '' ? $address : null,
        $hourlyRate,
        $description !== '' ? $description : null
    ]);

    $studioId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Studio created successfully.',
        'data' => [
            'studio_id' => (int) $studioId,
            'owner_id' => (int) $ownerId,
            'name' => $name,
            'location' => $location,
            'address' => $address,
            'hourly_rate' => $hourlyRate,
            'description' => $description
        ]
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to create studio.'
    ]);
}