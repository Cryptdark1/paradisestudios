<?php

/**
 * Paradise Studios — verify your email.
 *
 * Where a newly registered account lands. The account exists but cannot log in
 * until the address is proven, so this screen is reachable WITHOUT a session.
 *
 * It follows the same structure as login.php and register.php: the same shell,
 * the same stylesheets in the same order, the same theme bootstrap.
 *
 * WHAT THIS PAGE NEVER DOES
 * ------------------------
 * It does not decide whether an account is verified. It collects a code and
 * hands it to POST /api/email-verify.php, which asks the server. The success
 * state below is rendered from that server's answer and from nothing else.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/icons.php';

/*
 * Required for EmailVerification::CODE_LENGTH, which decides how many boxes are
 * rendered. Without it the page fatals part-way through the template: the
 * address chip appears and then the output stops, which looks like a styling
 * bug rather than a fatal.
 */
require_once __DIR__ . '/includes/email-verification.php';

if (!defined('PS_BASE_URL')) {
    define('PS_BASE_URL', '/paradisestudios');
}

/*
 * ps_csrf_token() both starts the session and returns the token. That is what
 * lets an unauthenticated visitor still make a CSRF-protected POST, and it is
 * the reason no session is required here.
 */
$csrfToken = ps_csrf_token();

/*
 * The address is remembered from registration in the session, so the common
 * path needs no query string. A query parameter is accepted as a fallback for
 * someone returning to this page later, or following a link.
 *
 * The ADDRESS may travel this way; the CODE never does. The code is the secret.
 */
$email = '';

if (isset($_SESSION['ps_verification_email']) && is_string($_SESSION['ps_verification_email'])) {
    $email = trim($_SESSION['ps_verification_email']);
}

if (isset($_GET['email']) && is_string($_GET['email'])) {
    $candidate = trim($_GET['email']);

    if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
        $email = $candidate;
    }
}

/*
 * A cooldown carried over from a resend, so the timer is already correct if the
 * page is reloaded instead of showing a misleading "resend now".
 */
$cooldown = isset($_SESSION['ps_verification_resend_after'])
    && is_int($_SESSION['ps_verification_resend_after'])
    ? max(0, $_SESSION['ps_verification_resend_after'] - time())
    : 0;

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Verify your email · Paradise Studios</title>

    <?php /* Read by public/js/api.js and sent as X-CSRF-Token on every POST. */ ?>
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">

    <script>
    /* Same pre-paint theme resolution as login.php and register.php, so
       arriving here from registration never flashes the wrong theme. */
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
            /* Private browsing: fall back to light. */
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

    <!-- The OTP component only. -->
    <link rel="stylesheet" href="<?= e(PS_BASE_URL) ?>/public/css/verify-email.css?v=<?= e((string) filemtime(__DIR__ . '/public/css/verify-email.css')) ?>">
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

            <!--
                One live region for the whole outcome. Screen readers announce
                the result once the server has answered, rather than the page
                swapping content silently.
            -->
            <div data-verify-root role="status" aria-live="polite">

                <h1 class="ps-auth__title" data-verify-title>Verify your email</h1>

                <p class="ps-auth__lead" data-verify-lead>
                    We&rsquo;ve sent a verification code to your email address
                </p>

                <?php if ($email !== ''): ?>
                    <p class="ps-verify-chip" data-verify-email><?= e($email) ?></p>
                <?php endif; ?>

                <?php if ($email === ''): ?>
                    <?php /*
                        No address in session or query. Rather than render six
                        boxes that cannot possibly work, ask for the address.
                    */ ?>
                    <div class="ps-auth__alert ps-auth__alert--info" role="alert">
                        <?= ps_icon('info', 16) ?>
                        <span>We could not tell which address to verify. Enter it below.</span>
                    </div>

                    <form method="GET" action="<?= e(PS_BASE_URL) ?>/verify-email.php" novalidate>
                        <div class="ps-field">
                            <label class="ps-field__label" for="verify-email-fallback">Email address</label>
                            <input type="email" id="verify-email-fallback" name="email"
                                   class="ps-input" required autocomplete="email"
                                   value="<?= e($email) ?>"
                                   placeholder="you@example.com">
                        </div>
                        <button type="submit" class="ps-btn ps-btn--primary ps-btn--lg ps-btn--block">
                            Continue
                        </button>
                    </form>
                <?php else: ?>
                    <form data-verify-form novalidate>
                        <?php /*
                            The boxes are inputs, not divs, so a mobile keyboard,
                            a password manager and screen readers all behave.
                            inputmode="numeric" opens the number pad.
                        */ ?>
                        <div class="ps-otp" data-otp
                             data-length="<?= EmailVerification::CODE_LENGTH ?>">
                            <?php for ($i = 0; $i < EmailVerification::CODE_LENGTH; $i++): ?>
                                <input class="ps-otp__box"
                                       type="text"
                                       inputmode="numeric"
                                       autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>"
                                       maxlength="1"
                                       pattern="[0-9]*"
                                       aria-label="<?= $i === 0 ? 'Verification code, 6 digits' : 'Digit ' . ($i + 1) ?>"
                                       data-otp-box>
                            <?php endfor; ?>
                        </div>

                        <div data-verify-alert></div>

                        <button type="submit"
                                class="ps-btn ps-btn--primary ps-btn--lg ps-btn--block"
                                data-verify-submit
                                data-loading-label="Verifying…">
                            Verify email
                        </button>
                    </form>

                    <div class="ps-verify-foot">
                        <span class="ps-verify-foot__item is-waiting"
                              data-resend
                              data-cooldown="<?= (int) $cooldown ?>">
                            <?= ps_icon('clock', 15) ?>
                            <span data-resend-text>Resend code<?= $cooldown > 0 ? ' in ' . gmdate('i:s', max(0, $cooldown - ($cooldown % 60)) + ($cooldown % 60 === 0 ? 0 : 0)) : '' ?></span>
                        </span>

                        <a class="ps-verify-link" href="<?= e(PS_BASE_URL) ?>/login.php">Back to login</a>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </main>

    <script src="<?= e(PS_BASE_URL) ?>/public/js/theme.js?v=<?= e((string) filemtime(__DIR__ . '/public/js/theme.js')) ?>"></script>
    <script src="<?= e(PS_BASE_URL) ?>/public/js/api.js?v=<?= e((string) filemtime(__DIR__ . '/public/js/api.js')) ?>"></script>
    <script src="<?= e(PS_BASE_URL) ?>/public/js/verify-email.js?v=<?= e((string) filemtime(__DIR__ . '/public/js/verify-email.js')) ?>"></script>

</body>
</html>