<?php

/**
 * Paradise Studios - payment webhook receiver.
 *
 * Called by the payment provider. NOT called by a browser, and it requires no
 * session: the provider has no user.
 *
 * SECURITY
 *   - The HMAC-SHA512 signature is checked against the EXACT raw request body
 *     before anything else happens. An unsigned or wrongly signed request is
 *     rejected with 401 and changes nothing.
 *   - Rate limited per IP, because an endpoint reachable from the internet
 *     without a session must not be cheap to hammer.
 *   - Responses are generic. Nothing about internal state is disclosed.
 *   - The secret key is never written to the response or the log.
 *
 * HTTP STATUS CODES
 *   200  processed, ignored (unsupported event), or duplicate. Acknowledged so
 *        the provider stops retrying; retrying would not change the outcome.
 *   400  the request was structurally unusable.
 *   401  signature invalid.
 *   429  rate limited.
 *   500  an unexpected internal error. Deliberately a failure so the provider
 *        RETRIES, which is safe because processing is idempotent.
 *
 * LOCALHOST
 *   A provider cannot reach http://localhost. Until a public HTTPS URL exists,
 *   use a local simulator that produces correctly signed events on demand.
 *   See README-payment.md.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/payments.php';
require_once __DIR__ . '/../includes/payments/ProviderFactory.php';
require_once __DIR__ . '/../includes/payments/FinancialLedger.php';
require_once __DIR__ . '/../includes/payments/PaymentService.php';
require_once __DIR__ . '/../includes/payments/WebhookProcessor.php';

header('Content-Type: application/json; charset=utf-8');

/*
 * Rate limiting reuses the auth-security helpers added for login/register, so
 * there is exactly one rate-limiting implementation in the project.
 */
require_once __DIR__ . '/../includes/auth-security.php';

/**
 * Respond and stop.
 */
function ps_webhook_respond(int $status, string $payload): never
{
    http_response_code($status);
    echo $payload;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ps_webhook_respond(405, json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ]));
}

/*
 * Rate limit BEFORE reading the body, so a flood of large requests is cheap to
 * reject. Window and cooldown match the login throttle's shape.
 */
try {
    $blockedFor = ps_auth_rate_limit_consume($pdo, 'payment_webhook', 120, 60, 300);
} catch (Throwable $e) {
    error_log('[paradisestudios] webhook rate limit unavailable: ' . $e->getMessage());
    $blockedFor = 0;
}

if ($blockedFor > 0) {
    header('Retry-After: ' . $blockedFor);
    ps_webhook_respond(429, json_encode([
        'success' => false,
        'message' => 'Too many requests.',
    ]));
}

/*
 * The raw body, read verbatim.
 *
 * php://input is used rather than file_get_contents on the body so no decoding
 * or re-encoding can alter the bytes the signature was computed over. Re-encoding
 * JSON changes "/" to "\/" and would make a legitimate event fail to verify.
 */
$rawBody = file_get_contents('php://input');

if ($rawBody === false || $rawBody === '') {
    ps_webhook_respond(400, json_encode([
        'success' => false,
        'message' => 'Empty request body.',
    ]));
}

$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? null;
$signature = is_string($signature) ? $signature : null;

try {
    $processor = new PaymentWebhookProcessor(
        $pdo,
        new PaymentService($pdo, new FinancialLedger($pdo))
    );

    $result = $processor->handle($rawBody, $signature);

    ps_webhook_respond(
        $result['http_status'],
        json_encode([
            'success' => $result['http_status'] < 400,
            'message' => 'Event received.',
        ])
    );
} catch (Throwable $e) {
    /*
     * Log the class and message but never the body, the signature or any
     * configured secret.
     */
    error_log(
        '[paradisestudios] webhook endpoint error: '
        . get_class($e) . ' ' . $e->getMessage()
    );

    ps_webhook_respond(500, json_encode([
        'success' => false,
        'message' => 'Unable to process the event.',
    ]));
}
