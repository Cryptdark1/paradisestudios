<?php

if (!function_exists('ps_csrf_token')) {
    function ps_csrf_token(): string
    {
        if (
            !isset($_SESSION['csrf_token'])
            || !is_string($_SESSION['csrf_token'])
            || preg_match('/^[a-f0-9]{64}$/D', $_SESSION['csrf_token']) !== 1
        ) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('ps_validate_csrf_token')) {
    function ps_validate_csrf_token($submittedToken): bool
    {
        return is_string($submittedToken)
            && strlen($submittedToken) === 64
            && preg_match('/^[a-f0-9]{64}$/D', $submittedToken) === 1
            && isset($_SESSION['csrf_token'])
            && is_string($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $submittedToken);
    }
}

if (!function_exists('ps_require_api_csrf_token')) {
    function ps_require_api_csrf_token(): void
    {
        $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf_token'] ?? null);

        if (ps_validate_csrf_token($submittedToken)) {
            return;
        }

        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or missing request token.'
        ]);
        exit;
    }
}

if (!function_exists('ps_rotate_csrf_token')) {
    function ps_rotate_csrf_token(): string
    {
        unset($_SESSION['csrf_token']);

        return ps_csrf_token();
    }
}

if (!function_exists('ps_auth_rate_limit_bucket_hash')) {
    function ps_auth_rate_limit_bucket_hash(string $action): string
    {
        $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';

        if (!is_string($remoteAddress) || filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
            $remoteAddress = 'unknown';
        } else {
            $packedAddress = inet_pton($remoteAddress);

            if ($packedAddress === false) {
                $remoteAddress = 'unknown';
            } elseif (strlen($packedAddress) === 16) {
                $ipv4MappedPrefix = str_repeat("\0", 10) . "\xff\xff";

                $remoteAddress = substr($packedAddress, 0, 12) === $ipv4MappedPrefix
                    ? bin2hex(substr($packedAddress, 12, 4))
                    : bin2hex(substr($packedAddress, 0, 8)) . '/64';
            } else {
                $remoteAddress = bin2hex($packedAddress);
            }
        }

        return hash('sha256', $action . "\0" . $remoteAddress);
    }
}

if (!function_exists('ps_auth_rate_limit_cleanup')) {
    function ps_auth_rate_limit_cleanup(PDO $pdo): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }

        $cutoff = time() - (30 * 24 * 60 * 60);
        $stmt = $pdo->prepare(
            'DELETE FROM auth_rate_limits
             WHERE last_seen_at < ?
             LIMIT 100'
        );
        $stmt->execute([$cutoff]);
    }
}

if (!function_exists('ps_auth_rate_limit_status')) {
    function ps_auth_rate_limit_status(PDO $pdo, string $action): int
    {
        ps_auth_rate_limit_cleanup($pdo);

        $stmt = $pdo->prepare(
            'SELECT blocked_until
             FROM auth_rate_limits
             WHERE bucket_hash = ?
             LIMIT 1'
        );
        $stmt->execute([ps_auth_rate_limit_bucket_hash($action)]);
        $bucket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bucket) {
            return 0;
        }

        return max(0, (int) $bucket['blocked_until'] - time());
    }
}

if (!function_exists('ps_auth_rate_limit_consume')) {
    function ps_auth_rate_limit_consume(
        PDO $pdo,
        string $action,
        int $limit,
        int $windowSeconds,
        int $cooldownSeconds
    ): int {
        ps_auth_rate_limit_cleanup($pdo);

        $now = time();
        $bucketHash = ps_auth_rate_limit_bucket_hash($action);
        $pdo->beginTransaction();

        try {
            $insert = $pdo->prepare(
                'INSERT IGNORE INTO auth_rate_limits
                    (bucket_hash, attempts, window_started_at, blocked_until, last_seen_at)
                 VALUES (?, 0, ?, 0, ?)'
            );
            $insert->execute([$bucketHash, $now, $now]);

            $select = $pdo->prepare(
                'SELECT attempts, window_started_at, blocked_until
                 FROM auth_rate_limits
                 WHERE bucket_hash = ?
                 FOR UPDATE'
            );
            $select->execute([$bucketHash]);
            $bucket = $select->fetch(PDO::FETCH_ASSOC);

            if (!$bucket) {
                throw new RuntimeException('Rate limit bucket could not be initialized.');
            }

            $blockedUntil = (int) $bucket['blocked_until'];

            if ($blockedUntil > $now) {
                $pdo->commit();
                return $blockedUntil - $now;
            }

            $windowStartedAt = (int) $bucket['window_started_at'];
            $attempts = (int) $bucket['attempts'];

            if (
                $windowStartedAt > $now
                || $now - $windowStartedAt >= $windowSeconds
                || $attempts >= $limit
            ) {
                $windowStartedAt = $now;
                $attempts = 0;
            }

            $attempts++;
            $blockedUntil = $attempts >= $limit
                ? $now + $cooldownSeconds
                : 0;

            $update = $pdo->prepare(
                'UPDATE auth_rate_limits
                 SET attempts = ?, window_started_at = ?, blocked_until = ?, last_seen_at = ?
                 WHERE bucket_hash = ?'
            );
            $update->execute([
                $attempts,
                $windowStartedAt,
                $blockedUntil,
                $now,
                $bucketHash
            ]);

            $pdo->commit();
            return 0;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}

if (!function_exists('ps_auth_rate_limit_clear')) {
    function ps_auth_rate_limit_clear(PDO $pdo, string $action): void
    {
        $stmt = $pdo->prepare(
            'DELETE FROM auth_rate_limits
             WHERE bucket_hash = ?'
        );
        $stmt->execute([ps_auth_rate_limit_bucket_hash($action)]);
    }
}
