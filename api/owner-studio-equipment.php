<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

requireRole('owner');

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

ps_require_api_csrf_token();

$studioId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$studioId || $studioId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'A valid studio ID is required.'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input) || !isset($input['equipment_ids']) || !is_array($input['equipment_ids'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'equipment_ids must be an array.'
    ]);
    exit;
}

$equipmentIds = $input['equipment_ids'];

/*
|--------------------------------------------------------------------------
| Validate equipment IDs
|--------------------------------------------------------------------------
*/

$equipmentIds = array_map('intval', $equipmentIds);
$equipmentIds = array_values(array_unique($equipmentIds));

foreach ($equipmentIds as $equipmentId) {
    if ($equipmentId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'All equipment IDs must be positive integers.'
        ]);
        exit;
    }
}

$ownerId = currentUserId();

try {

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | Lock studio and verify ownership
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT id, name
        FROM studios
        WHERE id = ?
        AND owner_id = ?
        FOR UPDATE
    ");

    $stmt->execute([$studioId, $ownerId]);

    $studio = $stmt->fetch();

    if (!$studio) {
        $pdo->rollBack();

        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Studio not found or you do not own this studio.'
        ]);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Verify all equipment exists
    |--------------------------------------------------------------------------
    */

    if (count($equipmentIds) > 0) {

        $placeholders = implode(',', array_fill(0, count($equipmentIds), '?'));

        $stmt = $pdo->prepare("
            SELECT id
            FROM equipment
            WHERE id IN ($placeholders)
        ");

        $stmt->execute($equipmentIds);

        $validEquipmentIds = array_map(
            'intval',
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );

        sort($validEquipmentIds);
        $requestedEquipmentIds = $equipmentIds;
        sort($requestedEquipmentIds);

        if ($validEquipmentIds !== $requestedEquipmentIds) {
            $pdo->rollBack();

            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'One or more equipment IDs do not exist.'
            ]);
            exit;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Replace existing equipment
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        DELETE FROM studio_equipment
        WHERE studio_id = ?
    ");

    $stmt->execute([$studioId]);

    /*
    |--------------------------------------------------------------------------
    | Add new equipment
    |--------------------------------------------------------------------------
    */

    if (count($equipmentIds) > 0) {

        $stmt = $pdo->prepare("
            INSERT INTO studio_equipment (studio_id, equipment_id)
            VALUES (?, ?)
        ");

        foreach ($equipmentIds as $equipmentId) {
            $stmt->execute([
                $studioId,
                $equipmentId
            ]);
        }
    }

    $pdo->commit();

    /*
    |--------------------------------------------------------------------------
    | Return updated equipment
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            e.id,
            e.name,
            e.category
        FROM studio_equipment se
        INNER JOIN equipment e
            ON e.id = se.equipment_id
        WHERE se.studio_id = ?
        ORDER BY e.id
    ");

    $stmt->execute([$studioId]);

    $equipment = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'message' => 'Studio equipment updated successfully.',
        'data' => [
            'studio_id' => $studioId,
            'studio_name' => $studio['name'],
            'equipment' => $equipment
        ]
    ]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to update studio equipment.'
    ]);
}