<?php

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

try {

    $studioId = $_GET['id'] ?? '';

    // Validate studio ID
    if (!filter_var($studioId, FILTER_VALIDATE_INT)) {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'A valid studio ID is required.'
        ]);

        exit;
    }

    // Get studio details
    $stmt = $pdo->prepare("
        SELECT
            s.id,
            s.name,
            s.location,
            s.address,
            s.hourly_rate,
            s.description,
            u.name AS owner_name
        FROM studios s
        INNER JOIN users u
            ON s.owner_id = u.id
        WHERE s.id = ?
        LIMIT 1
    ");

    $stmt->execute([$studioId]);

    $studio = $stmt->fetch();

    // Studio doesn't exist
    if (!$studio) {

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Studio not found.'
        ]);

        exit;
    }

    // Get equipment
    $stmt = $pdo->prepare("
        SELECT
            e.id,
            e.name,
            e.category
        FROM studio_equipment se
        INNER JOIN equipment e
            ON se.equipment_id = e.id
        WHERE se.studio_id = ?
        ORDER BY e.name
    ");

    $stmt->execute([$studioId]);

    $studio['equipment'] = $stmt->fetchAll();

    /*
    |--------------------------------------------------------------------------
    | Optional: active booked slots
    |--------------------------------------------------------------------------
    |
    | Only returned when the caller explicitly asks with
    | ?include_availability=1. The default response is unchanged, so existing
    | consumers of this endpoint are unaffected.
    |
    | Cancelled and completed bookings are excluded, matching the overlap
    | check in api/bookings.php. Only start/end times are exposed — no artist
    | identity and no booking IDs.
    |
    */
    if (filter_var($_GET['include_availability'] ?? '', FILTER_VALIDATE_BOOLEAN)) {

        $stmt = $pdo->prepare(
            "SELECT start_time, end_time
             FROM bookings
             WHERE studio_id = ?
             AND status NOT IN ('cancelled', 'completed')
             ORDER BY start_time ASC"
        );

        $stmt->execute([$studioId]);

        $slots = [];

        foreach ($stmt->fetchAll() as $row) {
            $slots[] = [
                'start_time' => $row['start_time'],
                'end_time'   => $row['end_time']
            ];
        }

        $studio['booked_slots'] = $slots;
    }

    echo json_encode([
        'success' => true,
        'data' => $studio
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to load studio details.'
    ]);
}