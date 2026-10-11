<?php

/**
 * Paradise Studios — registration.
 *
 * The existing presentation and artist/owner registration policy are retained.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/email-verification.php';
require_once __DIR__ . '/includes/verification-mailer.php';

if (!defined('PS_BASE_URL')) {
    define('PS_BASE_URL', '/paradisestudios');
}

if (isLoggedIn()) {
    redirect(PS_BASE_URL . '/dashboard.php');
}

$error = '';
$success = '';
$verifyLink = '';
$csrfToken = ps_csrf_token();
$formStatus = 200;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ps_validate_csrf_token($_POST['_csrf_token'] ?? null)) {
        $formStatus = 403;
        $error = 'This form has expired. Please try again.';
        $csrfToken = ps_rotate_csrf_token();
    } else {
        try {
            $retryAfter = ps_auth_rate_limit_consume($pdo, 'register', 5, 3600, 3600);

            if ($retryAfter > 0) {
                $formStatus = 429;
                header('Retry-After: ' . $retryAfter);
                $error = 'Too many registration attempts. Please try again later.';
            } else {
                $nameValue = $_POST['name'] ?? null;
                $emailValue = $_POST['email'] ?? null;
                $passwordValue = $_POST['password'] ?? null;
                $confirmationValue = $_POST['confirm_password'] ?? null;
                $roleValue = $_POST['role'] ?? null;

                $name = is_string($nameValue) ? trim($nameValue) : '';
                $email = is_string($emailValue) ? trim($emailValue) : '';
                $password = is_string($passwordValue) ? $passwordValue : '';
                $confirmation = is_string($confirmationValue) ? $confirmationValue : '';
                $role = is_string($roleValue) ? $roleValue : '';

                $nameLength = preg_match_all('/[\s\S]/u', $name);

                if ($name === '' || $email === '' || $password === '' || $confirmation === '' || $role === '') {
                    $error = 'All fields are required.';
                } elseif ($nameLength === false || $nameLength > 100) {
                    $error = 'Name must be 100 characters or fewer.';
                } elseif (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Please enter a valid email address.';
                } elseif (!in_array($role, ['artist', 'owner'], true)) {
                    $error = 'Invalid account type.';
                } elseif (strlen($password) < 8) {
                    $error = 'Password must be at least 8 characters.';
                } elseif (strlen($password) > 72) {
                    $error = 'Password must be no more than 72 bytes.';
                } elseif ($password !== $confirmation) {
                    $error = 'Passwords do not match.';
                } else {
                    $stmt = $pdo->prepare(
                        'SELECT id FROM users WHERE email = ? LIMIT 1'
                    );
                    $stmt->execute([$email]);

                    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                        $error = 'An account with this email already exists.';
                    } else {
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                        if ($passwordHash === false) {
                            throw new RuntimeException('Password hashing failed.');
                        }

                        $stmt = $pdo->prepare(
                            'INSERT INTO users
                                (name, email, password_hash, role)
                             VALUES (?, ?, ?, ?)'
                        );
                        $stmt->execute([$name, $email, $passwordHash, $role]);

                        $newUserId = (int) $pdo->lastInsertId();

                        /*
                         * EMAIL VERIFICATION
                         * ------------------
                         * The account exists but cannot log in until the
                         * address is proven, so a code is issued and sent here.
                         *
                         * A delivery failure is reported honestly. Telling the
                         * user "check your email" when nothing was sent would
                         * leave them locked out with no way to tell why - and
                         * the only escape would be asking an operator.
                         *
                         * The account is deliberately NOT rolled back on
                         * failure: the user exists, they simply need a working
                         * code, and "request a new one" is the route out.
                         */
                        $verificationIssue = null;
                        $mailDelivered = false;
                        $mailProblem = '';
                        $resendAfter = time() + EmailVerification::RESEND_COOLDOWN_SECONDS;

                        if (!Mailer::isConfigured()) {
                            $mailProblem = 'Email delivery is not configured on this server.';

                            error_log(
                                '[paradisestudios] registration created user ' . $newUserId
                                . ' but MAIL_FROM_ADDRESS is not configured.'
                            );
                        } else {
                            try {
                                $service = new EmailVerification($pdo);

                                $verificationIssue = $service->issue(
                                    $newUserId,
                                    is_string($_SERVER['REMOTE_ADDR'] ?? null)
                                        ? (string) $_SERVER['REMOTE_ADDR']
                                        : ''
                                );

                                $delivery = VerificationMailer::sendCode(
                                    (string) $verificationIssue['email'],
                                    (string) $verificationIssue['name'],
                                    (string) $verificationIssue['code'],
                                    (int) (EmailVerification::CODE_TTL_SECONDS / 60)
                                );

                                /*
                                 * "Delivered" here means the code reached a
                                 * provider that will try to deliver it. When the
                                 * local dev sink is in use it reached a FILE, so
                                 * the page must not claim an email was sent.
                                 */
                                $mailEmailed = VerificationMailer::wasEmailed($delivery);
                                $mailDelivered = (bool) ($delivery['sent'] ?? false);

                                /*
                                 * Copied OUT before the array is dropped below.
                                 * Reading it afterwards would get null, because
                                 * dropping the raw code must not be skippable.
                                 */
                                $resendAfter = (int) ($verificationIssue['resend_after'] ?? $resendAfter);

                                if (!$mailDelivered) {
                                    $mailProblem = 'The verification email could not be sent.';

                                    error_log(
                                        '[paradisestudios] verification email for user '
                                        . $newUserId . ' was not delivered.'
                                    );
                                }
                            } catch (Throwable $verificationError) {
                                $mailProblem = 'The verification email could not be prepared.';

                                error_log(
                                    '[paradisestudios] verification setup failed for user '
                                    . $newUserId . '.'
                                );
                            } finally {
                                /* Drop the raw code immediately. */
                                $verificationIssue = null;
                            }
                        }

                        if ($mailDelivered) {
                                /*
                                 * Hand the address to the verification screen in
                                 * the session so it does not have to travel in
                                 * a query string. The code still goes only by
                                 * email.
                                 */
                                $_SESSION['ps_verification_email'] = $email;
                                $_SESSION['ps_verification_resend_after'] = $resendAfter;

                                if ($mailEmailed) {
                                    $success = 'Account created. We have sent a verification '
                                        . 'code to your email address. Enter it on the next '
                                        . 'screen to finish setting up your account.';
                                } else {
                                    /*
                                     * The dev sink is in use. The code reached a
                                     * local file, so the copy must NOT claim an
                                     * email was sent.
                                     *
                                     * This branch is only ever reachable with
                                     * MAIL_DEV_FILE set, which is a local-only
                                     * development mode.
                                     */
                                    $success = 'Account created. The verification code was '
                                        . 'written to the local development mailbox '
                                        . '(no email is being sent in this mode).';
                                }

                                $verifyLink = PS_BASE_URL . '/verify-email.php';
                            } else {
                                $formStatus = 503;

                                $error = 'Your account was created, but we could not email you a '
                                    . 'verification code. ' . $mailProblem
                                    . ' Try again in a moment, or contact support if this '
                                    . 'keeps happening.';
                            }
                        }
                }
            }
        } catch (PDOException $e) {
            $mysqlErrorCode = $e->errorInfo[1] ?? null;

            if ($e->getCode() === '23000' && (int) $mysqlErrorCode === 1062) {
                $error = 'An account with this email already exists.';
            } else {
                error_log('Registration database operation failed.');
                $formStatus = 503;
                $error = 'Unable to create an account right now. Please try again later.';
            }
        } catch (Throwable $e) {
            error_log('Registration processing failed.');
            $formStatus = 503;
            $error = 'Unable to create an account right now. Please try again later.';
        }
    }
}

$initialRole = isset($_POST['role']) && is_string($_POST['role'])
    ? $_POST['role']
    : 'artist';
$formName = isset($_POST['name']) && is_string($_POST['name'])
    ? $_POST['name']
    : '';
$formEmail = isset($_POST['email']) && is_string($_POST['email'])
    ? $_POST['email']
    : '';

if ($formStatus !== 200) {
    http_response_code($formStatus);
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Register · Paradise Studios</title>

    <script>
    /* Same pre-paint theme resolution as the rest of the product. */
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

    <button type="button"
            class="ps-theme-toggle ps-auth__theme"
            data-theme-toggle
            data-next-theme="dark"
            aria-label="Switch theme"
            title="Switch theme">
        &#127769;
    </button>

    <main class="ps-auth__panel">

        <!-- Decorative photograph panel, matching login.php. -->
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

            <h1 class="ps-auth__title">Create your account</h1>
            <p class="ps-auth__lead">It takes less than a minute.</p>

            <?php if ($error): ?>
                <div class="ps-auth__alert ps-auth__alert--error" role="alert">
                    <?= ps_icon('alert', 16) ?>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="ps-auth__alert ps-auth__alert--info" role="status">
                    <?= ps_icon('check-circle', 16) ?>
                    <span><?= e($success) ?></span>
                </div>

                <a class="ps-btn ps-btn--primary ps-btn--lg ps-btn--block"
                   href="<?= e($verifyLink !== '' ? $verifyLink : PS_BASE_URL . '/verify-email.php') ?>">
                    Enter verification code
                </a>

                <p class="ps-auth__switch">
                    Already verified?
                    <a href="<?= e(PS_BASE_URL) ?>/login.php">Log in here</a>
                </p>
            <?php else: ?>

                <form method="POST" novalidate data-register-form>
                    <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">

                    <div class="ps-field">
                        <label class="ps-field__label" for="name">Full name</label>
                        <input type="text" id="name" name="name" class="ps-input"
                               maxlength="100" required autocomplete="name"
                               value="<?= e($formName) ?>"
                               aria-describedby="name-error">
                        <span class="ps-field__error" id="name-error" data-field-error></span>
                    </div>

                    <div class="ps-field">
                        <label class="ps-field__label" for="email">Email</label>
                        <input type="email" id="email" name="email" class="ps-input"
                               required autocomplete="email"
                               value="<?= e($formEmail) ?>"
                               aria-describedby="email-error">
                        <span class="ps-field__error" id="email-error" data-field-error></span>
                    </div>

                    <div class="ps-field">
                        <label class="ps-field__label" for="password">Password</label>
                        <div class="ps-password">
                            <input type="password" id="password" name="password"
                                   class="ps-input" required minlength="8"
                                   autocomplete="new-password"
                                   data-strength-source
                                   aria-describedby="password-hint password-error">
                            <button type="button" class="ps-password__toggle"
                                    data-password-toggle data-password-target="password"
                                    aria-label="Show password" aria-pressed="false"
                                    title="Show password">
                                <?= ps_icon('profile', 17) ?>
                            </button>
                        </div>

                        <div class="ps-strength" data-strength aria-label="">
                            <span class="ps-strength__bars">
                                <span class="ps-strength__bar"></span>
                                <span class="ps-strength__bar"></span>
                                <span class="ps-strength__bar"></span>
                                <span class="ps-strength__bar"></span>
                            </span>
                            <span class="ps-strength__label" data-strength-label></span>
                        </div>

                        <span class="ps-field__hint" id="password-hint">
                            At least 8 characters and no more than 72 bytes.
                        </span>
                        <span class="ps-field__error" id="password-error" data-field-error></span>
                    </div>

                    <div class="ps-field">
                        <label class="ps-field__label" for="confirm_password">Confirm password</label>
                        <div class="ps-password">
                            <input type="password" id="confirm_password"
                                   name="confirm_password" class="ps-input" required
                                   autocomplete="new-password"
                                   aria-describedby="confirm_password-error">
                            <button type="button" class="ps-password__toggle"
                                    data-password-toggle data-password-target="confirm_password"
                                    aria-label="Show password" aria-pressed="false"
                                    title="Show password">
                                <?= ps_icon('profile', 17) ?>
                            </button>
                        </div>
                        <span class="ps-field__error" id="confirm_password-error" data-field-error></span>
                    </div>

                    <?php /*
                        Only the two roles the backend accepts are offered.
                        Administrator is not available at any point.
                    */ ?>
                    <div class="ps-field">
                        <span class="ps-field__label" id="role-label">
                            Account type
                        </span>

                        <div class="ps-roles" role="radiogroup" aria-labelledby="role-label">

                            <label class="ps-role">
                                <input type="radio" name="role" value="artist"
                                    <?= $initialRole === 'artist' ? 'checked' : '' ?>>
                                <span class="ps-role__box" aria-hidden="true"></span>
                                <span class="ps-role__icon" aria-hidden="true"><?= ps_icon('music', 16) ?></span>
                                <span class="ps-role__text">
                                    <span class="ps-role__name">Artist</span>
                                    <span class="ps-role__hint">Book &amp; upload tracks</span>
                                </span>
                            </label>

                            <label class="ps-role">
                                <input type="radio" name="role" value="owner"
                                    <?= $initialRole === 'owner' ? 'checked' : '' ?>>
                                <span class="ps-role__box" aria-hidden="true"></span>
                                <span class="ps-role__icon" aria-hidden="true"><?= ps_icon('studios', 16) ?></span>
                                <span class="ps-role__text">
                                    <span class="ps-role__name">Studio owner</span>
                                    <span class="ps-role__hint">List &amp; manage studios</span>
                                </span>
                            </label>

                        </div>

                        <span class="ps-field__error" id="role-error" data-field-error></span>
                    </div>

                    <button type="submit" class="ps-btn ps-btn--primary ps-btn--lg ps-btn--block"
                            data-auth-submit>
                        Create account
                    </button>

                    <p class="ps-auth__switch">
                        Already have an account?
                        <a href="<?= e(PS_BASE_URL) ?>/login.php">Log in here</a>
                    </p>
                </form>

            <?php endif; ?>
        </div>
    </main>

    <script src="<?= e(PS_BASE_URL) ?>/public/js/theme.js?v=<?= e((string) filemtime(__DIR__ . '/public/js/theme.js')) ?>"></script>
    <script src="<?= e(PS_BASE_URL) ?>/public/js/auth.js?v=<?= e((string) filemtime(__DIR__ . '/public/js/auth.js')) ?>"></script>

</body>
</html>