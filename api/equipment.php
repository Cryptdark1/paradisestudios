<?php

/**
 * Paradise Studios — equipment catalogue.
 *
 * GET /api/equipment.php
 *
 * Why this endpoint exists
 * -----------------------
 * GET /api/studios.php supports an `equipment` filter, but it takes equipment
 * IDs (?equipment=1,4), not names. There is no endpoint that lists what those
 * IDs are, so the Discover Studios filter UI has nothing to build its checkbox
 * list from.
 *
 * This endpoint returns the catalogue only. It is deliberately narrow:
 *   - no studio data
 *   - no user data
 *   - no file paths
 *
 * It follows the same conventions as the rest of the project: JSON out, and a
 * 405 for anything other than GET.
 */

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

    $stmt = $pdo->query(
        "SELECT id, name, category
         FROM equipment
         ORDER BY category ASC, name ASC"
    );

    $equipment = $stmt->fetchAll();

    /*
     * Group by category so the frontend does not have to re-sort. The flat
     * list is kept as well because the studios.php filter works on IDs.
     */
    $grouped = [];

    foreach ($equipment as $item) {

        $category = $item['category'];

        if (!isset($grouped[$category])) {
            $grouped[$category] = [];
        }

        $grouped[$category][] = [
            'id'   => (int) $item['id'],
            'name' => $item['name']
        ];
    }

    echo json_encode([
        'success' => true,
        'count'   => count($equipment),
        'data'    => $equipment,
        'grouped' => $grouped
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to retrieve equipment.'
    ]);
}