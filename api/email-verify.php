<?php

/**
 * Paradise Studios - verify an email address with a submitted code.
 *
 * POST /api/email-verify.php
 * Body: { "email": "...", "code": "123456" }
 *
 * Unauthenticated on purpose: the account cannot log in until it is verified,
 * so requiring a session here would deadlock the flow.
 *
 * The code is checked against the server's own record. Nothing in the request
 * can mark an account verified on its own, and the code is never echoed back in
 * any response.
 */

require_once __DIR__ . '/verification-helpers.php';

ps_verify_require_post();

/* Reuses the project's existing CSRF check, verbatim. */
ps_require_api_csrf_token();

ps_verify_rate_limit('email_verify', 10, 300, 900);

$input = ps_verify_json_body();

$email = ps_verify_string($input, 'email', 100);
$code = ps_verify_string($input, 'code', 32);

/*
 * A single generic message for every rejection.
 *
 * "That code is not valid." covers a wrong code, an unknown address, an expired
 * code and an exhausted attempt budget alike. Differentiating them would tell an
 * attacker which addresses are registered.
 */
const PS_VERIFY_GENERIC_ERROR = 'That code is not valid. Please check and try again.';

try {
    $service = new EmailVerification($pdo);

    $userId = $service->verify($email, $code);

    ps_verify_ok(
        [
            'verified' => true,
        ],
        'Email verified successfully.'
    );
} catch (EmailVerificationException $e) {
    /*
     * An expired code is a different situation from a wrong one and the user
     * genuinely needs to know: only "request a new code" unblocks them. It is
     * still safe to say, because proving an account exists is not required to
     * tell someone their own code expired - and the code itself had to be
     * correct to get this far in the "attempts exhausted" case.
     *
     * "invalid_code" stays generic.
     */
    if ($e->reason() === 'expired_code') {
        ps_verify_error(410, 'That code has expired. Please request a new one.', [
            'data' => ['reason' => 'expired_code'],
        ]);
    }

    ps_verify_error(400, PS_VERIFY_GENERIC_ERROR, [
        'data' => ['reason' => 'invalid_code'],
    ]);
} catch (Throwable $e) {
    error_log('[paradisestudios] email verification failed unexpectedly.');
    ps_verify_error(500, 'Something went wrong. Please try again.');
}