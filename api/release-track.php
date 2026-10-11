<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

requireLogin();

if (currentUserRole() !== 'artist') {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Only artists can release tracks.'
    ]);

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);

    exit;
}

ps_require_api_csrf_token();

$trackId = $_GET['id'] ?? '';

if (!filter_var($trackId, FILTER_VALIDATE_INT)) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'A valid track ID is required.'
    ]);

    exit;
}

$artistId = currentUserId();

try {

    $pdo->beginTransaction();

    /*
     * Lock the track while we check and release it.
     */
    $stmt = $pdo->prepare("
        SELECT
            id,
            artist_id,
            studio_id,
            title,
            status,
            clout_earned
        FROM tracks
        WHERE id = ?
        AND artist_id = ?
        FOR UPDATE
    ");

    $stmt->execute([
        $trackId,
        $artistId
    ]);

    $track = $stmt->fetch();

    if (!$track) {

        $pdo->rollBack();

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Track not found.'
        ]);

        exit;
    }

    /*
     * Prevent releasing the same track twice.
     */
    if ($track['status'] === 'released') {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'Track has already been released.'
        ]);

        exit;
    }

    /*
     * Only recording tracks can be released.
     */
    if ($track['status'] !== 'recording') {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'Track cannot be released in its current state.'
        ]);

        exit;
    }

    /*
     * XP awarded for releasing a track.
     */
    $xpReward = 50;

    /*
     * Mark the track as released.
     */
    $stmt = $pdo->prepare("
        UPDATE tracks
        SET
            status = 'released',
            clout_earned = ?,
            released_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

    $stmt->execute([
        $xpReward,
        $trackId
    ]);

    /*
     * Add the XP to the artist's Buzz Meter.
     */
    $stmt = $pdo->prepare("
        UPDATE users
        SET buzz_level = buzz_level + ?
        WHERE id = ?
    ");

    $stmt->execute([
        $xpReward,
        $artistId
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Track released successfully.',
        'data' => [
            'track_id' => (int) $track['id'],
            'title' => $track['title'],
            'status' => 'released',
            'xp_earned' => $xpReward
        ]
    ]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to release track.'
    ]);
}