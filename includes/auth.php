<?php

if (!function_exists('ps_session_cookie_path')) {
    function ps_session_cookie_path(): string
    {
        $scriptName = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])
            ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME'])
            : '/';

        if (preg_match('~^(.*?)/(?:api|pages)(?:/|$)~', $scriptName, $matches)) {
            $basePath = $matches[1];
        } else {
            $basePath = dirname($scriptName);
        }

        $basePath = '/' . trim($basePath, '/');

        return $basePath === '/'
            ? '/'
            : $basePath . '/';
    }
}

if (!function_exists('ps_is_api_request')) {
    function ps_is_api_request(): bool
    {
        $scriptName = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])
            ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME'])
            : '';

        return preg_match('~(?:^|/)api(?:/|$)~i', $scriptName) === 1;
    }
}

if (session_status() === PHP_SESSION_DISABLED) {
    throw new RuntimeException('Sessions are required for authentication.');
}

if (session_status() === PHP_SESSION_NONE) {
    foreach ([
        'session.use_cookies' => '1',
        'session.use_only_cookies' => '1',
        'session.use_trans_sid' => '0',
        'session.use_strict_mode' => '1'
    ] as $setting => $value) {
        if (ini_set($setting, $value) === false) {
            throw new RuntimeException('Unable to configure secure session handling.');
        }
    }

    $https = isset($_SERVER['HTTPS'])
        && is_string($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== ''
        && strtolower($_SERVER['HTTPS']) !== 'off'
        && $_SERVER['HTTPS'] !== '0';

    if (!session_set_cookie_params([
        'lifetime' => 0,
        'path' => ps_session_cookie_path(),
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax'
    ])) {
        throw new RuntimeException('Unable to configure secure session cookies.');
    }

    if (!session_start()) {
        throw new RuntimeException('Unable to start the authentication session.');
    }
}

if (!defined('PS_SESSION_IDLE_TIMEOUT')) {
    define('PS_SESSION_IDLE_TIMEOUT', 1800);
}

if (!function_exists('ps_auth_idle_timeout')) {
    function ps_auth_idle_timeout(): int
    {
        $timeout = constant('PS_SESSION_IDLE_TIMEOUT');

        if (is_string($timeout) && ctype_digit($timeout)) {
            $timeout = (int) $timeout;
        }

        if (!is_int($timeout) || $timeout < 1) {
            throw new UnexpectedValueException('PS_SESSION_IDLE_TIMEOUT must be a positive integer.');
        }

        return $timeout;
    }
}

ps_auth_idle_timeout();

if (!function_exists('ps_clear_authentication_state')) {
    function ps_clear_authentication_state(): void
    {
        unset(
            $_SESSION['user_id'],
            $_SESSION['user_name'],
            $_SESSION['user_role'],
            $_SESSION['last_activity']
        );
    }
}

if (!function_exists('ps_auth_session_expired_this_request')) {
    function ps_auth_session_expired_this_request(): bool
    {
        return !empty($GLOBALS['ps_auth_session_expired_this_request']);
    }
}

if (!function_exists('ps_auth_database_failure_this_request')) {
    function ps_auth_database_failure_this_request(): bool
    {
        return !empty($GLOBALS['ps_auth_database_failure_this_request']);
    }
}

if (!function_exists('ps_refresh_authenticated_user')) {
    function ps_refresh_authenticated_user(int $userId): bool
    {
        if (
            isset($GLOBALS['ps_authenticated_user_id'])
            && $GLOBALS['ps_authenticated_user_id'] === $userId
            && array_key_exists('ps_authenticated_user_status', $GLOBALS)
        ) {
            return $GLOBALS['ps_authenticated_user_status'] === true;
        }

        $GLOBALS['ps_authenticated_user_id'] = $userId;

        global $pdo;

        if (!isset($pdo) || !$pdo instanceof PDO) {
            $GLOBALS['ps_auth_database_failure_this_request'] = true;
            $GLOBALS['ps_authenticated_user_status'] = null;
            return false;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT id, name, role
                 FROM users
                 WHERE id = ?
                 LIMIT 1'
            );
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                !$user
                || !is_string($user['name'] ?? null)
                || !in_array($user['role'] ?? null, ['artist', 'owner', 'admin'], true)
            ) {
                ps_clear_authentication_state();
                $GLOBALS['ps_authenticated_user_status'] = false;
                return false;
            }

            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            $GLOBALS['ps_authenticated_user_status'] = true;

            return true;
        } catch (Throwable $e) {
            error_log('Authenticated user lookup failed.');
            $GLOBALS['ps_auth_database_failure_this_request'] = true;
            $GLOBALS['ps_authenticated_user_status'] = null;
            return false;
        }
    }
}

if (!function_exists('isLoggedIn')) {
    function isLoggedIn(): bool
    {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        $userIdValue = $_SESSION['user_id'];
        $userId = is_int($userIdValue) || is_string($userIdValue)
            ? filter_var(
                $userIdValue,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            )
            : false;
        $userRole = $_SESSION['user_role'] ?? null;
        $userName = $_SESSION['user_name'] ?? null;

        if (
            $userId === false
            || !is_string($userRole)
            || !in_array($userRole, ['artist', 'owner', 'admin'], true)
            || !is_string($userName)
        ) {
            ps_clear_authentication_state();
            return false;
        }

        $now = time();

        if (array_key_exists('last_activity', $_SESSION)) {
            $lastActivityValue = $_SESSION['last_activity'];
            $lastActivity = is_int($lastActivityValue) || is_string($lastActivityValue)
                ? filter_var(
                    $lastActivityValue,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                )
                : false;

            if ($lastActivity === false || $lastActivity > $now || $now - $lastActivity >= ps_auth_idle_timeout()) {
                ps_clear_authentication_state();
                $GLOBALS['ps_auth_session_expired_this_request'] = true;
                return false;
            }
        }

        if (!ps_refresh_authenticated_user((int) $userId)) {
            return false;
        }

        $_SESSION['last_activity'] = $now;

        return true;
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin(): void
    {
        if (isLoggedIn()) {
            return;
        }

        if (ps_auth_database_failure_this_request()) {
            http_response_code(503);

            if (ps_is_api_request()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => 'Authentication service temporarily unavailable.'
                ]);
            } else {
                exit('Authentication service temporarily unavailable.');
            }

            exit;
        }

        if (ps_is_api_request()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');

            echo json_encode([
                'success' => false,
                'message' => ps_auth_session_expired_this_request()
                    ? 'Your session has expired.'
                    : 'Authentication required.'
            ]);
            exit;
        }

        $basePath = rtrim(ps_session_cookie_path(), '/');
        $loginUrl = ($basePath === '' ? '' : $basePath) . '/login.php';

        if (ps_auth_session_expired_this_request()) {
            $loginUrl .= '?expired=1';
        }

        header('Location: ' . $loginUrl);
        exit;
    }
}

if (!function_exists('currentUserId')) {
    function currentUserId(): ?int
    {
        return isLoggedIn()
            ? (int) $_SESSION['user_id']
            : null;
    }
}

if (!function_exists('currentUserRole')) {
    function currentUserRole(): ?string
    {
        return isLoggedIn()
            ? $_SESSION['user_role']
            : null;
    }
}

if (!function_exists('requireRole')) {
    function requireRole(string $role): void
    {
        requireLogin();

        if (currentUserRole() === $role) {
            return;
        }

        http_response_code(403);

        if (ps_is_api_request()) {
            header('Content-Type: application/json; charset=utf-8');

            echo json_encode([
                'success' => false,
                'message' => 'Access denied.'
            ]);
            exit;
        }

        exit('Access denied.');
    }
}

require_once __DIR__ . '/auth-security.php';
