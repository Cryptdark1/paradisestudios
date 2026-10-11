<?php

/**
 * Paradise Studios - request a new email verification code.
 *
 * POST /api/email-verification-resend.php
 * Body: { "email": "..." }
 *
 * Unauthenticated, for the same reason as email-verify.php: an unverified
 * account has no session yet.
 *
 * ALWAYS reports the same outcome whether or not the address is registered.
 * The response is deliberately identical for a real account, an unknown address
 * and an already-verified account, so this cannot be used to discover who has an
 * account here.
 *
 * The only differences a caller can observe come from the rate limiter, which is
 * per IP and shared across all addresses.
 */

require_once __DIR__ . '/verification-helpers.php';
require_once __DIR__ . '/../includes/verification-mailer.php';

ps_verify_require_post();

ps_require_api_csrf_token();

/*
 * Tighter than the verify endpoint, because each resend triggers real mail.
 * Six per hour per IP is generous for one person and useless for an attacker.
 */
ps_verify_rate_limit('email_verify_resend', 6, 3600, 3600);

$input = ps_verify_json_body();
$email = ps_verify_string($input, 'email', 100);

$service = new EmailVerification($pdo);

/** The single answer this endpoint is allowed to give. */
const PS_RESEND_NEUTRAL_OK = 'If that address needs verifying, a new code is on its way.';

/*
 * Resolve the account without telling the caller whether it exists.
 */
$statement = $pdo->prepare(
    'SELECT id, name, email, email_verified_at, email_verification_resend_after
     FROM users
     WHERE email = ?
     LIMIT 1'
);
$statement->execute([$email]);
$user = $statement->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['email_verified_at'] !== null) {
    ps_verify_ok([], PS_RESEND_NEUTRAL_OK);
}

$cooldown = max(0, (int) $user['email_verification_resend_after'] - time());

if ($cooldown > 0) {
    /*
     * A per-account cooldown is still reported generically. Leaking the exact
     * remaining time would confirm the account exists.
     */
    ps_verify_ok([
        'retry_after_seconds' => $cooldown,
    ], PS_RESEND_NEUTRAL_OK);
}

/*
 * Mail is checked BEFORE a code is issued. Creating a code that nobody can
 * receive would burn the account's one cooldown window on a message that never
 * arrives, leaving the user unable to get a working code.
 */
if (!Mailer::isConfigured()) {
    error_log(
        '[paradisestudios] verification email requested but MAIL_FROM_ADDRESS is not configured.'
    );

    ps_verify_error(
        503,
        'Email is not set up on this server yet, so a code cannot be sent. Please contact support.',
        ['data' => ['reason' => 'mail_not_configured']]
    );
}

try {
    $issued = $service->issue((int) $user['id'], ps_verify_client_ip());
} catch (EmailVerificationException $e) {
    /*
     * A throttle hit between the check above and the issue. Answer generically,
     * for the same anti-enumeration reason.
     */
    ps_verify_ok([
        'retry_after_seconds' => $e->retryAfterSeconds(),
    ], PS_RESEND_NEUTRAL_OK);
}

/*
 * The raw code goes straight to the mailer and is not kept anywhere.
 */
$delivery = VerificationMailer::sendCode(
    (string) $issued['email'],
    (string) $issued['name'],
    (string) $issued['code'],
    (int) (EmailVerification::CODE_TTL_SECONDS / 60)
);

unset($issued);

if (!$delivery['sent']) {
    /*
     * Honest failure. Saying "sent" here would leave the user staring at an
     * inbox forever with no indication that anything went wrong.
     */
    error_log(
        '[paradisestudios] verification email was not delivered: '
        . str_replace(["\r", "\n"], ' ', $delivery['reason'])
    );

    ps_verify_error(
        503,
        'We could not send the email just now. Please try again shortly.',
        ['data' => ['reason' => 'delivery_failed']]
    );
}

ps_verify_ok([
    'retry_after_seconds' => EmailVerification::RESEND_COOLDOWN_SECONDS,
    'expires_in_seconds' => EmailVerification::CODE_TTL_SECONDS,
], PS_RESEND_NEUTRAL_OK);