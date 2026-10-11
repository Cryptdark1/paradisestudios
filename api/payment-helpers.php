<?php

/**
 * Paradise Studios - shared helpers for the payment API endpoints.
 *
 * Every payment endpoint reuses the EXISTING authentication, role and CSRF
 * infrastructure rather than introducing a second system:
 *   - includes/auth.php            session, role, API-aware 401/403
 *   - includes/auth-security.php   CSRF token + rate limiting
 *   - ps_require_api_csrf_token()  reused verbatim
 *   - ps_auth_rate_limit_consume() reused verbatim
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/payments.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth-security.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/money.php';
require_once __DIR__ . '/../includes/payments/ProviderFactory.php';
require_once __DIR__ . '/../includes/payments/FinancialLedger.php';
require_once __DIR__ . '/../includes/payments/PaymentService.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Send a JSON error and stop.
 */
function ps_pay_error(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

/**
 * Send JSON success and stop.
 */
function ps_pay_ok(array $data, string $message = 'OK'): never
{
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);
    exit;
}

/**
 * Send a "still deciding" answer and stop.
 *
 * HTTP 202, with `pending: true` at the top level and `confirmed: false` in the
 * data. The two flags exist because callers get this wrong:
 *
 *   `pending`    lets the UI tell "still settling" apart from "we could not
 *                reach the provider", which is a completely different problem.
 *   `confirmed`  is false, so a client that only reads data.confirmed can never
 *                mistake a settling transaction for a captured one.
 *
 * It is deliberately NOT a 409. A verification failure cannot tell the caller
 * whether money moved, and must not be used to describe a transaction still in
 * flight as terminal.
 */
function ps_pay_pending(array $data, string $message = 'Payment is still being processed.'): never
{
    http_response_code(202);

    echo json_encode([
        'success' => true,
        'pending' => true,
        'message' => $message,
        'data' => array_merge(['confirmed' => false], $data),
    ]);

    exit;
}

/**
 * Require a JSON request body.
 */
function ps_pay_json_body(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        ps_pay_error(400, 'A JSON request body is required.');
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        ps_pay_error(400, 'Invalid JSON request body.');
    }

    return $decoded;
}

/**
 * Read a positive integer from JSON input.
 *
 * Rejects anything non-numeric rather than coercing it, so a booking id of
 * "1 OR 1=1" can never reach a query.
 */
function ps_pay_int(array $input, string $key, bool $required = true): ?int
{
    if (!array_key_exists($key, $input)) {
        if ($required) {
            ps_pay_error(400, $key . ' is required.');
        }

        return null;
    }

    $value = $input[$key];

    if (is_int($value)) {
        $parsed = $value;
    } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
        $parsed = (int) $value;
    } else {
        ps_pay_error(400, $key . ' must be a positive integer.');
        return null;
    }

    if ($parsed < 1) {
        ps_pay_error(400, $key . ' must be a positive integer.');
    }

    return $parsed;
}

/**
 * Apply a per-IP rate limit, returning 429 when exceeded.
 */
function ps_pay_rate_limit(string $action, int $limit, int $window, int $cooldown): void
{
    global $pdo;

    try {
        $blockedFor = ps_auth_rate_limit_consume($pdo, $action, $limit, $window, $cooldown);
    } catch (Throwable $e) {
        // Fail open on the limiter itself so a limiter outage cannot take
        // payments offline, but make the failure visible.
        error_log('[paradisestudios] rate limit unavailable for ' . $action . ': ' . $e->getMessage());

        return;
    }

    if ($blockedFor > 0) {
        header('Retry-After: ' . $blockedFor);
        ps_pay_error(429, 'Too many requests. Please wait a moment and try again.');
    }
}

/**
 * Map a payment exception onto an HTTP status and a safe message.
 */
function ps_pay_fail(Throwable $e): never
{
    /*
     * Checked BEFORE PaymentVerificationException, and deliberately a separate
     * class: "still deciding" must never be reported as "no money has been
     * taken".
     */
    if ($e instanceof PaymentPendingException) {
        ps_pay_pending(
            [
                'pending' => true,
                'provider_status' => $e->providerStatus(),
                'payment_state' => 'pending',
            ],
            $e->getMessage()
        );
    }

    if ($e instanceof PaymentConfigurationException) {
        /*
         * Operator problem, not a customer problem. The detail is safe to show
         * because it names configuration settings and never a secret.
         */
        error_log('[paradisestudios] payment configuration error: ' . $e->getMessage());
        ps_pay_error(503, 'Payments are not available right now. Please try again later.');
    }

    if ($e instanceof PaymentVerificationException) {
        // Never says how much was expected or received.
        ps_pay_error(409, 'This payment could not be confirmed. Check your bookings before trying again.');
    }

    if ($e instanceof PaymentValidationException) {
        ps_pay_error(400, $e->getMessage());
    }

    if ($e instanceof PaymentProviderException) {
        $status = $e->httpStatus();
error_log('[paradisestudios] Paystack diagnostic: code='
    . $e->safeCode()
    . ' http=' . $status
    . ' message=' . $e->getMessage());
        ps_pay_error(
            $status >= 400 && $status < 600 ? $status : 502,
            $status === 404 ? 'Booking not found.' : 'Unable to reach the payment provider.'
        );
    }

    error_log('[paradisestudios] payment error: ' . get_class($e) . ' ' . $e->getMessage());
    ps_pay_error(500, 'Something went wrong. Please try again.');
}
