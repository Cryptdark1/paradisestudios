<?php

/**
 * Paradise Studios — sign in.
 *
 * The presentation and role-aware destination remain unchanged.
 *
 * The `?expired` notice is used by public/js/ui.js when a session ends
 * mid-request.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/icons.php';

if (!defined('PS_BASE_URL')) {
    define('PS_BASE_URL', '/paradisestudios');
}

$error = '';
$csrfToken = ps_csrf_token();
$formStatus = 200;

$notice = '';

if (isset($_GET['expired'])) {
    $notice = 'Your session has ended. Please sign in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ps_validate_csrf_token($_POST['_csrf_token'] ?? null)) {
        $formStatus = 403;
        $error = 'This form has expired. Please try again.';
        $csrfToken = ps_rotate_csrf_token();
    } else {
        $emailValue = $_POST['email'] ?? null;
        $passwordValue = $_POST['password'] ?? null;
        $email = is_string($emailValue) ? trim($emailValue) : '';
        $password = is_string($passwordValue) ? $passwordValue : '';

        try {
            $retryAfter = ps_auth_rate_limit_status($pdo, 'login');

            if ($retryAfter > 0) {
                $formStatus = 429;
                header('Retry-After: ' . $retryAfter);
                $error = 'Too many sign-in attempts. Please try again later.';
            } elseif ($email === '' || $password === '') {
                ps_auth_rate_limit_consume($pdo, 'login', 5, 900, 900);
                $error = 'Please enter your email and password.';
            } elseif (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                ps_auth_rate_limit_consume($pdo, 'login', 5, 900, 900);
                $error = 'Please enter a valid email address.';
            } elseif (strlen($password) > 4096) {
                ps_auth_rate_limit_consume($pdo, 'login', 5, 900, 900);
                $error = 'Invalid email or password.';
            } else {
                $stmt = $pdo->prepare(
                    'SELECT id, name, email, password_hash, role, email_verified_at
                     FROM users
                     WHERE email = ?
                     LIMIT 1'
                );
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                /*
                 * EMAIL VERIFICATION GATE
                 *
                 * Placed AFTER the password check and BEFORE any session is
                 * created, so a correct password alone can never grant access
                 * to an unverified account.
                 *
                 * The wording deliberately does not confirm whether the address
                 * is registered, but this branch is only reachable when the
                 * password was already correct, so nothing is disclosed that the
                 * caller did not already possess.
                 *
                 * An empty/null email_verified_at means "never verified". The
                 * migration backfilled every pre-existing account, so this
                 * cannot fire for accounts that predate the feature.
                 */
                $passwordIsCorrect = $user
                    && is_string($user['password_hash'] ?? null)
                    && password_verify($password, $user['password_hash']);

                if (
                    $passwordIsCorrect
                    && ($user['email_verified_at'] ?? null) === null
                ) {
                    /*
                     * A terminal branch of its own, NOT a fall-through to the
                     * generic failure below: the generic branch would overwrite
                     * this message AND tell the user their password was wrong,
                     * which is both untrue and unhelpful.
                     *
                     * The rate limit is still consumed, because this is still a
                     * login attempt and must not become a free probing oracle.
                     */
                    ps_auth_rate_limit_consume($pdo, 'login', 5, 900, 900);

                    $error = 'Please verify your email address before logging in. '
                        . 'Check your inbox for a verification code, or request a new one.';

                    /*
                     * Carry the address to the verification screen in the
                     * session, so it does not have to travel in a URL. The code
                     * never does.
                     */
                    if (is_string($user['email'] ?? null)) {
                        $_SESSION['ps_verification_email'] = (string) $user['email'];
                    }
                } elseif (
                    $passwordIsCorrect
                    && in_array($user['role'] ?? null, ['artist', 'owner', 'admin'], true)
                ) {
                    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                        $newHash = password_hash($password, PASSWORD_DEFAULT);

                        if ($newHash === false) {
                            throw new RuntimeException('Password hash upgrade failed.');
                        }

                        $rehash = $pdo->prepare(
                            'UPDATE users SET password_hash = ? WHERE id = ?'
                        );
                        $rehash->execute([$newHash, (int) $user['id']]);
                    }

                    ps_auth_rate_limit_clear($pdo, 'login');

                    if (!session_regenerate_id(true)) {
                        throw new RuntimeException('Session ID regeneration failed.');
                    }

                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['user_name'] = (string) $user['name'];
                    $_SESSION['user_role'] = (string) $user['role'];
                    $_SESSION['last_activity'] = time();
                    $csrfToken = ps_rotate_csrf_token();

                    $homeForRole = [
                        'owner'  => PS_BASE_URL . '/pages/owner/dashboard.php',
                        'artist' => PS_BASE_URL . '/pages/artist/dashboard.php'
                    ];

                    redirect($homeForRole[$user['role']] ?? PS_BASE_URL . '/dashboard.php');
                } else {
                    ps_auth_rate_limit_consume($pdo, 'login', 5, 900, 900);
                    $error = 'Invalid email or password.';
                }
            }
        } catch (Throwable $e) {
            error_log('Login processing failed.');
            $formStatus = 503;
            $error = 'Unable to sign in right now. Please try again later.';
        }
    }
}

if ($formStatus !== 200) {
    http_response_code($formStatus);
}

$initial = strtoupper(substr((string) ($_SESSION['user_name'] ?? 'P'), 0, 1));
$formEmail = isset($_POST['email']) && is_string($_POST['email'])
    ? $_POST['email']
    : '';

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in · Paradise Studios</title>

    <script>
    /* Same pre-paint theme resolution as the rest of the product, so a visitor
       who chose dark mode never sees a light flash on the auth screen. */
    (function () {
        var theme = 'light';
        try {
            var stored = window.localStorage.getItem('paradise-theme');
            if (stored === 'light' || stored === 'dark') {
                theme = stored;
            } else if (window.matchMedia
                && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                theme = 'dark';
            }
        } catch (e) {
            theme = 'light';
        }
        document.documentElement.setAttribute('data-theme', theme);
    })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap">

    <link rel="preload" as="image"
          href="<?= e(PS_BASE_URL) ?>/public/img/auth-studio.jpg"
          fetchpriority="high">

    <link rel="stylesheet" href="<?= e(PS_BASE_URL) ?>/public/css/theme.css?v=<?= e((string) filemtime(__DIR__ . '/public/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= e(PS_BASE_URL) ?>/public/css/app.css?v=<?= e((string) filemtime(__DIR__ . '/public/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(PS_BASE_URL) ?>/public/css/auth.css?v=<?= e((string) filemtime(__DIR__ . '/public/css/auth.css')) ?>">

    <!-- Artist/owner warm palette, scoped to .ps-artist on <body>. -->
    <link rel="stylesheet" href="<?= e(PS_BASE_URL) ?>/public/css/artist-theme.css?v=<?= e((string) filemtime(__DIR__ . '/public/css/artist-theme.css')) ?>">
</head>

<body class="ps-auth ps-artist">

    <!-- The single theme toggle, same behaviour as every other page -->
    <button type="button"
            class="ps-theme-toggle ps-auth__theme"
            data-theme-toggle
            data-next-theme="dark"
            aria-label="Switch theme"
            title="Switch theme">
        &#127769;
    </button>

    <main class="ps-auth__panel">

        <!--
            Decorative photograph panel (see .ps-auth__aside in auth.css).
            aria-hidden because it carries no information the form does not.
            Preloaded because it is likely the Largest Contentful Paint element.
        -->
        <div class="ps-auth__aside" aria-hidden="true">
            <div class="ps-auth__brand">
                <span class="ps-brand__mark"><?= ps_icon('music', 20) ?></span>
                <span class="ps-brand__name">
                    <span>Paradise</span>
                    <span>Studios</span>
                </span>
            </div>
        </div>

        <div class="ps-auth__form">
            <div class="ps-auth__brand ps-auth__brand--mobile">
                <span class="ps-brand__mark"><?= ps_icon('music', 18) ?></span>
                <span class="ps-brand__name">
                    <span>Paradise</span>
                    <span>Studios</span>
                </span>
            </div>

            <h1 class="ps-auth__title">Login</h1>
            <p class="ps-auth__lead">Enter your email and password to continue.</p>

            <?php if ($notice !== ''): ?>
                <div class="ps-auth__alert ps-auth__alert--info" role="status">
                    <?= ps_icon('info', 16) ?>
                    <span><?= e($notice) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="ps-auth__alert ps-auth__alert--error" role="alert">
                    <?= ps_icon('alert', 16) ?>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error !== '' && isset($_SESSION['ps_verification_email'])): ?>
                <?php /* The way out of an unverified account, offered only when
                       there is genuinely one to get out of. */ ?>
                <p style="text-align:center;margin:-6px 0 20px">
                    <a class="ps-verify-link" style="color:var(--ps-text)"
                       href="<?= e(PS_BASE_URL) ?>/verify-email.php">Enter your verification code</a>
                </p>
            <?php endif; ?>

            <form method="POST" novalidate data-auth-form>
                <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">

                <div class="ps-field">
                    <label class="ps-field__label" for="email">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="ps-input"
                        autocomplete="email"
                        required
                        autofocus
                        value="<?= e($formEmail) ?>"
                        aria-describedby="email-error"
                    >
                    <span class="ps-field__error" id="email-error" data-field-error></span>
                </div>

                <div class="ps-field">
                    <label class="ps-field__label" for="password">Password</label>

                    <div class="ps-password">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="ps-input"
                            autocomplete="current-password"
                            required
                            aria-describedby="password-error"
                        >
                        <button
                            type="button"
                            class="ps-password__toggle"
                            data-password-toggle
                            aria-label="Show password"
                            aria-pressed="false"
                            title="Show password"
                        >
                            <?= ps_icon('profile', 17) ?>
                        </button>
                    </div>

                    <span class="ps-field__error" id="password-error" data-field-error></span>
                </div>

                <button type="submit" class="ps-btn ps-btn--primary ps-btn--lg ps-btn--block"
                        data-auth-submit>
                    Login
                </button>

                <p class="ps-auth__switch">
                    Have an account?
                    <a href="<?= e(PS_BASE_URL) ?>/register.php">Create one here</a>
                </p>
            </form>

            <p class="ps-auth__note">
                <?= ps_icon('info', 13) ?>
                <span>
                    Password reset is not available. Contact an administrator if you
                    have lost access to your account.
                </span>
            </p>
        </div>
    </main>

    <script src="<?= e(PS_BASE_URL) ?>/public/js/theme.js?v=<?= e((string) filemtime(__DIR__ . '/public/js/theme.js')) ?>"></script>
    <script src="<?= e(PS_BASE_URL) ?>/public/js/auth.js?v=<?= e((string) filemtime(__DIR__ . '/public/js/auth.js')) ?>"></script>

</body>
</html>