<?php

/**
 * Shared helpers for the email verification endpoints.
 *
 * Reuses the existing authentication, CSRF and rate limiting rather than
 * introducing a second system:
 *   - includes/auth.php            session handling
 *   - includes/auth-security.php   ps_require_api_csrf_token(), rate limits
 *   - includes/email-verification.php
 *
 * NOT-A-JSON-BROWSER-REQUEST
 * --------------------------
 * Verification happens BEFORE login, so there is no authenticated session to
 * identify the user. The caller proves which account they are by supplying the
 * address they registered with. That is why every failure here returns the SAME
 * generic message: distinguishing "no such account" from "wrong code" would turn
 * this endpoint into a membership oracle.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth-security.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/email-verification.php';

header('Content-Type: application/json; charset=utf-8');

/** Send a JSON error and stop. */
function ps_verify_error(int $status, string $message, array $extra = []): never
{
    http_response_code($status);

    echo json_encode(array_merge([
        'success' => false,
        'message' => $message,
    ], $extra));

    exit;
}

/** Send JSON success and stop. */
function ps_verify_ok(array $data = [], string $message = 'OK'): never
{
    http_response_code(200);

    echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);

    exit;
}

/** Require a POST request. */
function ps_verify_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        ps_verify_error(405, 'Method not allowed.');
    }
}

/** Read the JSON request body. */
function ps_verify_json_body(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        ps_verify_error(400, 'A JSON request body is required.');
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        ps_verify_error(400, 'Invalid JSON request body.');
    }

    return $decoded;
}

/**
 * Read a trimmed string from the body.
 *
 * Rejects anything non-string rather than coercing it, so an array or object
 * can never reach a query or a regex.
 */
function ps_verify_string(array $input, string $key, int $maxLength = 255): string
{
    if (!array_key_exists($key, $input)) {
        ps_verify_error(400, $key . ' is required.');
    }

    if (!is_string($input[$key])) {
        ps_verify_error(400, $key . ' must be text.');
    }

    $value = trim($input[$key]);

    if (strlen($value) > $maxLength) {
        ps_verify_error(400, $key . ' is too long.');
    }

    return $value;
}

/** The caller's IP, for abuse tracking only. Never authorised against. */
function ps_verify_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    return is_string($ip) ? $ip : '';
}

/**
 * Rate limit by IP.
 *
 * Both verification endpoints are reachable without a session, so the IP bucket
 * is the only cheap defence against someone grinding through codes.
 */
function ps_verify_rate_limit(string $action, int $limit, int $window, int $cooldown): void
{
    global $pdo;

    $blockedFor = ps_auth_rate_limit_consume($pdo, $action, $limit, $window, $cooldown);

    if ($blockedFor > 0) {
        header('Retry-After: ' . $blockedFor);
        ps_verify_error(429, 'Too many attempts. Please wait before trying again.', [
            'data' => ['retry_after_seconds' => $blockedFor],
        ]);
    }
}