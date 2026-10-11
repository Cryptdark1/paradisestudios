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

    // Get filters from the URL
    $location = trim($_GET['location'] ?? '');
    $maxPrice = $_GET['max_price'] ?? '';
    $equipment = trim($_GET['equipment'] ?? '');

    // Base query
    $sql = "
        SELECT
            s.id,
            s.name,
            s.location,
            s.address,
            s.hourly_rate,
            s.description,
            GROUP_CONCAT(
                DISTINCT e.name
                ORDER BY e.name
                SEPARATOR ', '
            ) AS equipment
        FROM studios s

        LEFT JOIN studio_equipment se
            ON s.id = se.studio_id

        LEFT JOIN equipment e
            ON se.equipment_id = e.id

        WHERE 1 = 1
    ";

    $params = [];

    // Location filter
    if ($location !== '') {
        $sql .= " AND s.location LIKE ?";
        $params[] = '%' . $location . '%';
    }

    // Maximum price filter
    if ($maxPrice !== '' && is_numeric($maxPrice)) {
        $sql .= " AND s.hourly_rate <= ?";
        $params[] = (float) $maxPrice;
    }

    /*
     * Equipment filter
     *
     * Example:
     * ?equipment=1,4
     *
     * This means the studio must have
     * equipment ID 1 AND equipment ID 4.
     */
    if ($equipment !== '') {

        $equipmentIds = array_filter(
            array_map('intval', explode(',', $equipment))
        );

        foreach ($equipmentIds as $equipmentId) {

            $sql .= "
                AND EXISTS (
                    SELECT 1
                    FROM studio_equipment se_filter
                    WHERE se_filter.studio_id = s.id
                    AND se_filter.equipment_id = ?
                )
            ";

            $params[] = $equipmentId;
        }
    }

    $sql .= "
        GROUP BY
            s.id,
            s.name,
            s.location,
            s.address,
            s.hourly_rate,
            s.description

        ORDER BY s.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $studios = $stmt->fetchAll();

    // Convert equipment string into an array
    foreach ($studios as &$studio) {

        if ($studio['equipment'] !== null) {
            $studio['equipment'] = explode(
                ', ',
                $studio['equipment']
            );
        } else {
            $studio['equipment'] = [];
        }
    }

    echo json_encode([
        'success' => true,
        'count' => count($studios),
        'data' => $studios
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to load studios.'
    ]);
}