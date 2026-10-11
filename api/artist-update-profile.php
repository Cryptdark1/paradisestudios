<?php

/**
 * Paradise Studios — artist profile update.
 *
 * PUT /api/artist-update-profile.php
 * Body: { "name": "...", "email": "..." }
 *
 * Why this is a separate endpoint
 * ------------------------------
 * api/owner-update-profile.php deliberately rejects any role other than
 * 'owner', so it is not appropriate for an artist to call. Rather than
 * loosening that endpoint's authorization (which would widen an owner-only
 * surface), artists get their own equivalent that follows the same rules:
 *
 *   - session authentication, no user ID accepted from the request
 *   - only `name` and `email` are writable
 *   - role, XP (buzz_level) and password are never touched, so this cannot
 *     be used for privilege escalation
 *   - the password hash is never selected or written
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

requireLogin();

if (currentUserRole() !== 'artist') {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Only artists can update this profile.'
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

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON request.'
    ]);

    exit;
}

$name  = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');

/*
|--------------------------------------------------------------------------
| Validate name
|--------------------------------------------------------------------------
*/
if ($name === '') {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Name is required.'
    ]);

    exit;
}

if (strlen($name) > 100) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Name cannot exceed 100 characters.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Validate email
|--------------------------------------------------------------------------
*/
if ($email === '') {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Email address is required.'
    ]);

    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Please enter a valid email address.'
    ]);

    exit;
}

$artistId = currentUserId();

try {

    /*
    |--------------------------------------------------------------------------
    | Reject an email already used by a different account
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare(
        'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1'
    );

    $stmt->execute([$email, $artistId]);

    if ($stmt->fetch()) {

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'That email address is already in use by another account.'
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Update the signed-in artist
    |--------------------------------------------------------------------------
    |
    | Only name and email are in the SET list. buzz_level (XP) and role are
    | deliberately absent so a crafted request cannot grant XP or change role.
    |
    */
    $stmt = $pdo->prepare(
        'UPDATE users SET name = ?, email = ? WHERE id = ? AND role = \'artist\''
    );

    $stmt->execute([$name, $email, $artistId]);

    if ($stmt->rowCount() === 0) {

        /* The values were unchanged, or the account is no longer an artist. */
        $stmt = $pdo->prepare(
            'SELECT id FROM users WHERE id = ? AND role = \'artist\' LIMIT 1'
        );

        $stmt->execute([$artistId]);

        if (!$stmt->fetch()) {

            http_response_code(404);

            echo json_encode([
                'success' => false,
                'message' => 'Artist not found.'
            ]);

            exit;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Keep the session in step
    |--------------------------------------------------------------------------
    */
    $_SESSION['user_name'] = $name;

    $stmt = $pdo->prepare(
        'SELECT id, name, email, role, buzz_level, created_at
         FROM users
         WHERE id = ?
         LIMIT 1'
    );

    $stmt->execute([$artistId]);

    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully.',
        'data' => $stmt->fetch()
    ]);

} catch (PDOException $e) {

    if ($e->getCode() === '23000') {

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'That email address is already in use by another account.'
        ]);

        exit;
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to update profile.'
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to update profile.'
    ]);
}