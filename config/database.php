<?php

/**
 * Paradise Studios - database connection.
 *
 * The connection handle stays a global named $pdo. Every existing page and API
 * includes this file and then uses $pdo directly, so changing that would mean
 * touching every file for no benefit. New payment code may use the same $pdo or
 * be handed it explicitly.
 *
 * Credentials now come from the environment with the previous hard-coded XAMPP
 * values as defaults, so a project with no .env file behaves exactly as before.
 */

require_once __DIR__ . '/env.php';

$host     = (string) ps_env('DB_HOST', '127.0.0.1');
$port     = (string) ps_env('DB_PORT', '3306');
$dbname   = (string) ps_env('DB_NAME', 'paradisestudios');
$username = (string) ps_env('DB_USER', 'root');
$password = (string) ps_env('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            /*
             * Use real server-side prepared statements instead of PDO's client
             * side emulation. Emulation sends the statement text and the
             * parameters separately and interpolates in PHP; with it off,
             * MySQL parses and caches the statement and the bound values never
             * become part of the SQL string. This also makes integer binding
             * behave as a genuine integer, which matters for the minor-unit
             * amounts the payment layer binds.
             */
            PDO::ATTR_EMULATE_PREPARES => false,

            // Exceptions, so every caller can rely on try/catch.
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            /*
             * DECIMAL columns arrive as PHP strings, not floats, so money can
             * be parsed without ever passing through a binary float.
             */
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]
    );

    /*
     * Safety net for any code path that reconnects later and forgets the
     * emulate-prepares flag. Financial correctness depends on real prepared
     * statements, so it is asserted rather than assumed.
     *
     * The value comes back as int(0) rather than bool(false) from the MySQL
     * driver, hence the cast before the comparison - a strict check here would
     * raise a false alarm on every single request.
     */
    if ((bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) !== false) {
        error_log('[paradisestudios] PDO emulate_prepares is unexpectedly enabled.');
    }

} catch (PDOException $e) {
    /*
     * Connection details are deliberately not echoed. The previous behaviour
     * printed $e->getMessage(), which can disclose the DSN and sometimes the
     * username to whoever triggered the failure.
     */
    error_log('[paradisestudios] Database connection failed: ' . $e->getMessage());

    http_response_code(503);

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Service temporarily unavailable.',
        ]);
        exit;
    }

    echo 'Service temporarily unavailable.';
    exit;
}
