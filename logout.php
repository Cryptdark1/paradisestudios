<?php

require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

if (!ps_validate_csrf_token($_POST['_csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid request token. Please return to the application and try again.');
}

$cookieParams = session_get_cookie_params();
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => $cookieParams['secure'],
        'httponly' => $cookieParams['httponly'],
        'samesite' => $cookieParams['samesite'] ?? 'Lax'
    ]);
}

session_destroy();

$basePath = rtrim(ps_session_cookie_path(), '/');
$loginUrl = ($basePath === '' ? '' : $basePath) . '/login.php';
header('Location: ' . $loginUrl);
exit;
