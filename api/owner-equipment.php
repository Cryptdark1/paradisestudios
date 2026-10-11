<?php

/**
 * Paradise Studios — owner studio equipment (read).
 *
 * GET /api/owner-equipment.php
 * GET /api/owner-equipment.php?studio_id=5
 * GET /api/owner-equipment.php?studio_ids=5,6,7
 *
 * Why this endpoint exists
 * -----------------------
 * The existing project provides:
 *   - PUT /api/owner-studio-equipment.php  -> writes a studio's equipment
 *   - GET /api/studio-details.php?id={id}  -> one studio + its equipment
 *
 * There is no endpoint that returns the full equipment catalogue, and
 * studio-details.php requires one HTTP request per studio. This endpoint is
 * the smallest addition that lets the equipment manager list every option and
 * pre-load the current selection, including for all studios in one call.
 *
 * It follows the existing conventions exactly: session authentication,
 * server-side ownership checks, JSON in / JSON out.
 */

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
    | Equipment catalogue
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->query(
        "SELECT id, name, category
         FROM equipment
         ORDER BY category ASC, name ASC"
    );

    $catalog = $stmt->fetchAll();

    $response = [
        'catalog' => $catalog
    ];

    /*
    |--------------------------------------------------------------------------
    | Optional: equipment assigned to specific studios
    |--------------------------------------------------------------------------
    |
    | Only studios belonging to the signed-in owner are accepted; anything
    | else is silently dropped so no other owner's data can be inferred.
    |
    */
    $studioId = filter_input(INPUT_GET, 'studio_id', FILTER_VALIDATE_INT);
    $studioIdsRaw = trim((string) ($_GET['studio_ids'] ?? ''));

    $requestedIds = [];

    if ($studioId) {
        $requestedIds = [(int) $studioId];
    } elseif ($studioIdsRaw !== '') {
        $requestedIds = array_values(array_unique(array_filter(array_map(
            'intval',
            explode(',', $studioIdsRaw)
        ))));
    }

    if (!empty($requestedIds)) {

        $placeholders = implode(',', array_fill(0, count($requestedIds), '?'));

        /* Ownership filter: only the requester's own studios are resolved. */
        $stmt = $pdo->prepare(
            "SELECT id
             FROM studios
             WHERE owner_id = ?
             AND id IN ($placeholders)"
        );

        $stmt->execute(array_merge([$ownerId], $requestedIds));

        $ownedIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $assignments = [];
        $assignedRows = [];

        foreach ($ownedIds as $id) {
            $assignments[(string) $id] = [];
        }

        if (!empty($ownedIds)) {

            $placeholders = implode(',', array_fill(0, count($ownedIds), '?'));

            $stmt = $pdo->prepare(
                "SELECT se.studio_id, e.id, e.name, e.category
                 FROM studio_equipment se
                 INNER JOIN equipment e
                     ON e.id = se.equipment_id
                 WHERE se.studio_id IN ($placeholders)
                 ORDER BY e.category ASC, e.name ASC"
            );

            $stmt->execute($ownedIds);

            foreach ($stmt->fetchAll() as $row) {

                $studioKey = (string) (int) $row['studio_id'];

                $assignments[$studioKey][] = (int) $row['id'];

                if ($studioId) {
                    $assignedRows[] = [
                        'id'       => (int) $row['id'],
                        'name'     => $row['name'],
                        'category' => $row['category']
                    ];
                }
            }
        }

        if ($studioId) {
            $response['studio_id'] = (int) $studioId;
            $response['assigned'] = $assignedRows;
            $response['assigned_ids'] = $assignments[(string) (int) $studioId] ?? [];
        } else {
            $response['assignments'] = $assignments;
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $response
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to retrieve studio equipment.'
    ]);
}