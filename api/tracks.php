<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

requireLogin();

if (currentUserRole() !== 'artist') {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Only artists can upload tracks.'
    ]);

    exit;
}

/*
 * GET  -> list the signed-in artist's own tracks
 * POST -> upload a new track (existing behaviour continues below, unchanged)
 *
 * The GET branch is additive, so the existing upload flow — including its
 * test-track-upload.php consumer — is untouched.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $artistId = currentUserId();

    try {

        /*
         * Only the signed-in artist's own tracks are returned. No artist_id is
         * accepted from the request, so one artist can never list another's.
         */
        $stmt = $pdo->prepare(
            "SELECT
                t.id,
                t.studio_id,
                s.name AS studio_name,
                t.title,
                t.status,
                t.clout_earned,
                t.released_at,
                t.created_at
             FROM tracks t

             LEFT JOIN studios s
                 ON t.studio_id = s.id

             WHERE t.artist_id = ?

             ORDER BY t.created_at DESC, t.id DESC"
        );

        $stmt->execute([$artistId]);

        $tracks = $stmt->fetchAll();

        /*
         * file_path is deliberately NOT selected: it is an internal server path
         * and must never be exposed to the browser.
         */
        foreach ($tracks as &$track) {
            $track['id'] = (int) $track['id'];
            $track['studio_id'] = (int) $track['studio_id'];
            $track['clout_earned'] = (int) $track['clout_earned'];
        }

        unset($track);

        echo json_encode([
            'success' => true,
            'count' => count($tracks),
            'data' => $tracks
        ]);

    } catch (PDOException $e) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Unable to load tracks.'
        ]);
    }

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
 * Required fields:
 * booking_id
 * title
 * audio file
 */

$bookingId = $_POST['booking_id'] ?? '';
$title = trim($_POST['title'] ?? '');

if (!filter_var($bookingId, FILTER_VALIDATE_INT)) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'A valid booking ID is required.'
    ]);

    exit;
}

if ($title === '') {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Track title is required.'
    ]);

    exit;
}

if (strlen($title) > 100) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Track title cannot exceed 100 characters.'
    ]);

    exit;
}

if (!isset($_FILES['audio'])) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Audio file is required.'
    ]);

    exit;
}

$file = $_FILES['audio'];

if ($file['error'] !== UPLOAD_ERR_OK) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Audio upload failed.'
    ]);

    exit;
}

/*
 * Maximum file size: 20 MB
 */
$maxFileSize = 20 * 1024 * 1024;

if ($file['size'] > $maxFileSize) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Audio file cannot exceed 20 MB.'
    ]);

    exit;
}

/*
 * Validate the actual MIME type instead of
 * trusting the filename extension.
 */
$finfo = new finfo(FILEINFO_MIME_TYPE);

$mimeType = $finfo->file($file['tmp_name']);

$allowedTypes = [
    'audio/mpeg' => 'mp3',
    'audio/wav' => 'wav',
    'audio/x-wav' => 'wav',
    'audio/mp4' => 'm4a',
    'audio/x-m4a' => 'm4a',
    'audio/ogg' => 'ogg'
];

if (!isset($allowedTypes[$mimeType])) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid audio format. Allowed formats: MP3, WAV, M4A and OGG.'
    ]);

    exit;
}

$artistId = currentUserId();

try {

    /*
     * Verify that the booking belongs to the
     * logged-in artist and has been completed.
     */
    $stmt = $pdo->prepare("
        SELECT
            b.id,
            b.artist_id,
            b.studio_id,
            b.status,
            s.name AS studio_name
        FROM bookings b

        INNER JOIN studios s
            ON b.studio_id = s.id

        WHERE b.id = ?
        AND b.artist_id = ?

        FOR UPDATE
    ");

    $stmt->execute([
        $bookingId,
        $artistId
    ]);

    $booking = $stmt->fetch();

    if (!$booking) {

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Booking not found.'
        ]);

        exit;
    }

    if ($booking['status'] !== 'completed') {

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'A track can only be uploaded for a completed booking.'
        ]);

        exit;
    }

    /*
     * Create a unique filename.
     */
    $extension = $allowedTypes[$mimeType];

    $filename = bin2hex(random_bytes(16)) . '.' . $extension;

    $uploadDirectory = __DIR__ . '/../public/uploads/';

    if (!is_dir($uploadDirectory)) {
        mkdir($uploadDirectory, 0755, true);
    }

    $destination = $uploadDirectory . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Unable to save uploaded audio file.'
        ]);

        exit;
    }

    /*
     * Store the relative path in the database.
     */
    $filePath = 'public/uploads/' . $filename;

    $stmt = $pdo->prepare("
        INSERT INTO tracks (
            artist_id,
            studio_id,
            title,
            file_path,
            status
        )
        VALUES (?, ?, ?, ?, 'recording')
    ");

    $stmt->execute([
        $artistId,
        $booking['studio_id'],
        $title,
        $filePath
    ]);

    $trackId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Track uploaded successfully.',
        'data' => [
            'track_id' => (int) $trackId,
            'title' => $title,
            'studio_id' => (int) $booking['studio_id'],
            'studio_name' => $booking['studio_name'],
            'status' => 'recording',
            'file_path' => $filePath
        ]
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to upload track.'
    ]);
}