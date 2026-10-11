<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

requireLogin();

if (currentUserRole() !== 'artist') {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Only artists can view the Buzz Meter.'
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

$artistId = currentUserId();

try {

    $stmt = $pdo->prepare("
        SELECT
            id,
            name,
            buzz_level
        FROM users
        WHERE id = ?
        AND role = 'artist'
    ");

    $stmt->execute([$artistId]);

    $artist = $stmt->fetch();

    if (!$artist) {

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Artist not found.'
        ]);

        exit;
    }

    $xp = (int) $artist['buzz_level'];

    /*
     * Buzz Meter levels:
     *
     * Local: 0 - 499
     * City Star: 500 - 1,999
     * National Icon: 2,000+
     */

    if ($xp < 500) {

        $level = 'Local';
        $nextLevel = 'City Star';
        $nextThreshold = 500;

    } elseif ($xp < 2000) {

        $level = 'City Star';
        $nextLevel = 'National Icon';
        $nextThreshold = 2000;

    } else {

        $level = 'National Icon';
        $nextLevel = null;
        $nextThreshold = null;
    }

    if ($nextThreshold !== null) {

        $xpRemaining = $nextThreshold - $xp;

        $previousThreshold = $level === 'Local' ? 0 : 500;

        $progress = (($xp - $previousThreshold) / ($nextThreshold - $previousThreshold)) * 100;

    } else {

        $xpRemaining = 0;
        $progress = 100;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'artist_id' => (int) $artist['id'],
            'artist_name' => $artist['name'],
            'xp' => $xp,
            'level' => $level,
            'next_level' => $nextLevel,
            'next_threshold' => $nextThreshold,
            'xp_remaining' => $xpRemaining,
            'progress_percent' => round($progress, 2)
        ]
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to load Buzz Meter.'
    ]);
}