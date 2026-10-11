<?php

/**
 * Paradise Studios — owner profile update.
 *
 * PUT /api/owner-update-profile.php
 * Body: { "name": "...", "email": "..." }
 *
 * Why this endpoint exists
 * -----------------------
 * No profile update endpoint existed. The Profile page must either do nothing
 * or call a real API — it must not pretend to save. This is the smallest
 * secure addition that follows the existing authentication architecture:
 * session-based auth, role check, JSON in / JSON out, no password handling.
 *
 * Security notes:
 *   - The user is always taken from the session; no user ID is accepted.
 *   - Only `name` and `email` can be changed. Role and password are not
 *     touched, so this endpoint cannot be used for privilege escalation.
 *   - The password hash is never read or written.
 *   - Uniqueness of the email is enforced by the database and reported as a
 *     friendly validation error.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

requireLogin();

if (currentUserRole() !== 'owner') {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Only studio owners can update this profile.'
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

/*
|--------------------------------------------------------------------------
| Read JSON request body
|--------------------------------------------------------------------------
*/
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

$ownerId = currentUserId();

try {

    /*
    |--------------------------------------------------------------------------
    | Reject an email already used by a different account
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare(
        'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1'
    );

    $stmt->execute([$email, $ownerId]);

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
    | Update the signed-in user's profile
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare(
        'UPDATE users SET name = ?, email = ? WHERE id = ?'
    );

    $stmt->execute([$name, $email, $ownerId]);

    /*
    |--------------------------------------------------------------------------
    | Keep the session in step with the new values
    |--------------------------------------------------------------------------
    */
    $_SESSION['user_name'] = $name;

    /*
    |--------------------------------------------------------------------------
    | Return the saved profile
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare(
        'SELECT id, name, email, role, created_at FROM users WHERE id = ? LIMIT 1'
    );

    $stmt->execute([$ownerId]);

    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully.',
        'data' => $stmt->fetch()
    ]);

} catch (PDOException $e) {

    /* Duplicate key — the pre-check above normally catches this, but a race
       could still land here. */
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