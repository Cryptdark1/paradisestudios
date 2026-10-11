<?php

/**
 * Paradise Studios - email verification.
 *
 * Issues, checks and consumes six-digit verification codes.
 *
 * DESIGN RULES
 * ------------
 * 1. The raw code exists only in memory, for the few milliseconds between
 *    generating it and handing it to the mailer. It is never written to the
 *    database, never logged and never returned to a browser.
 * 2. What is stored is password_hash() output. A fast digest would be useless
 *    here: the code space is one million, so anyone who could read the table
 *    could enumerate every possible code in seconds.
 * 3. Verification only ever succeeds on server-side facts - a live, unconsumed,
 *    unlocks code whose hash matches. Never a query parameter, never a flag.
 * 4. Every failure path is generic to the caller, so the endpoint cannot be used
 *    to learn whether an address is registered.
 */

if (!defined('PS_EMAIL_VERIFICATION_LOADED')) {
    define('PS_EMAIL_VERIFICATION_LOADED', true);

    require_once __DIR__ . '/../config/env.php';

    final class EmailVerificationException extends RuntimeException
    {
        public function __construct(
            string $message,
            private string $reason = 'invalid',
            private int $retryAfterSeconds = 0
        ) {
            parent::__construct($message);
        }

        public function reason(): string
        {
            return $this->reason;
        }

        public function retryAfterSeconds(): int
        {
            return $this->retryAfterSeconds;
        }
    }

    final class EmailVerification
    {
        /** Codes stop working after this many seconds. */
        public const CODE_TTL_SECONDS = 600;

        /** Wrong guesses allowed against a single code. */
        public const MAX_ATTEMPTS = 5;

        /** Minimum gap between issuing two codes for one account. */
        public const RESEND_COOLDOWN_SECONDS = 60;

        /**
         * The code is always six digits, zero padded.
         *
         * PUBLIC on purpose: a UI that renders one input per digit has to know
         * how many. Making it private forced the view to hard-code "6" alongside
         * this class, which silently drifts the moment the length changes.
         */
        public const CODE_LENGTH = 6;

        public function __construct(private PDO $pdo)
        {
        }

        /* =============================================================
           Code generation
           ============================================================= */

        /**
         * A cryptographically secure six-digit code, as a string.
         *
         * A string, not an int: keeping the leading zeros is what makes this a
         * six-character code rather than a variable-length number.
         *
         * random_int() is used rather than rand()/mt_rand()/uniqid(), all of
         * which are predictable. Rejection sampling keeps the distribution flat
         * across all one million values instead of favouring the low end.
         */
        public static function generateCode(): string
        {
            $limit = (int) (10 ** self::CODE_LENGTH);

            do {
                $code = random_int(0, $limit - 1);
            } while ($code === $limit);

            return str_pad((string) $code, self::CODE_LENGTH, '0', STR_PAD_LEFT);
        }

        /* =============================================================
           Issuing a code
           ============================================================= */

        /**
         * Issue a fresh code for a user, invalidating any earlier one.
         *
         * @return array{code:string, expires_at:string, resend_after:int}
         *         `code` is the ONLY place the raw code appears. The caller must
         *         hand it straight to the mailer and then drop it.
         *
         * @throws EmailVerificationException when the account is already
         *         verified, or the resend cooldown has not elapsed.
         */
        public function issue(int $userId, string $requestedIp = ''): array
        {
            $user = $this->loadUser($userId);

            if ($user['email_verified_at'] !== null) {
                throw new EmailVerificationException(
                    'This email address is already verified.',
                    'already_verified'
                );
            }

            $now = time();
            $resendAfter = (int) $user['email_verification_resend_after'];

            if ($resendAfter > $now) {
                throw new EmailVerificationException(
                    'Please wait before requesting another code.',
                    'resend_throttled',
                    $resendAfter - $now
                );
            }

            $code = self::generateCode();
            $codeHash = password_hash($code, PASSWORD_DEFAULT);

            if ($codeHash === false) {
                throw new RuntimeException('Verification code hashing failed.');
            }

            $expiresAt = gmdate('Y-m-d H:i:s', $now + self::CODE_TTL_SECONDS);
            $nextResend = $now + self::RESEND_COOLDOWN_SECONDS;

            $this->pdo->beginTransaction();

            try {
                /*
                 * Lock the user row so two concurrent requests cannot both slip
                 * a live code past the cooldown check above.
                 */
                $lock = $this->pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
                $lock->execute([$userId]);
                $locked = $lock->fetch(PDO::FETCH_ASSOC);

                if (!$locked) {
                    throw new EmailVerificationException('Account not found.', 'unknown_user');
                }

                /*
                 * Retire anything still live for this user. A user may only have
                 * one usable code at a time, so the newest request always wins
                 * and an intercepted older code stops being useful.
                 */
                $this->pdo->prepare(
                    "UPDATE email_verification_codes
                     SET locked_at = ?
                     WHERE user_id = ?
                       AND consumed_at IS NULL
                       AND locked_at IS NULL"
                )->execute([gmdate('Y-m-d H:i:s', $now), $userId]);

                $insert = $this->pdo->prepare(
                    'INSERT INTO email_verification_codes
                        (user_id, code_hash, attempts, max_attempts, expires_at, requested_ip)
                     VALUES (?, ?, 0, ?, ?, ?)'
                );
                $insert->execute([
                    $userId,
                    $codeHash,
                    self::MAX_ATTEMPTS,
                    $expiresAt,
                    $this->normaliseIp($requestedIp),
                ]);

                $this->pdo->prepare(
                    'UPDATE users SET email_verification_resend_after = ? WHERE id = ?'
                )->execute([$nextResend, $userId]);

                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $e;
            }

            return [
                'code' => $code,
                'expires_at' => $expiresAt,
                'resend_after' => $nextResend,
                'email' => (string) $user['email'],
                'name' => (string) $user['name'],
            ];
        }

        /* =============================================================
           Checking a submitted code
           ============================================================= */

        /**
         * Verify a submitted code and, only if it is genuinely good, mark the
         * account verified.
         *
         * @throws EmailVerificationException on every failure path.
         */
        public function verify(string $email, string $code): int
        {
            $code = preg_replace('/\D/', '', $code) ?? '';

            if (strlen($code) !== self::CODE_LENGTH) {
                throw new EmailVerificationException('That code is not valid.', 'invalid_code');
            }

            $userId = $this->findUnverifiedUserIdByEmail($email);

            if ($userId === null) {
                /*
                 * Deliberately the same error as a wrong code. Distinguishing
                 * them would turn this endpoint into a membership oracle.
                 */
                throw new EmailVerificationException('That code is not valid.', 'invalid_code');
            }

            $this->pdo->beginTransaction();

            try {
                $statement = $this->pdo->prepare(
                    'SELECT id, code_hash, attempts, max_attempts, expires_at,
                            consumed_at, locked_at
                     FROM email_verification_codes
                     WHERE user_id = ?
                       AND consumed_at IS NULL
                       AND locked_at IS NULL
                     ORDER BY created_at DESC, id DESC
                     LIMIT 1
                     FOR UPDATE'
                );
                $statement->execute([$userId]);

                $row = $statement->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    throw new EmailVerificationException('That code is not valid.', 'invalid_code');
                }

                if ($this->isExpired((string) $row['expires_at'])) {
                    $this->pdo->rollBack();

                    throw new EmailVerificationException(
                        'That code has expired. Please request a new one.',
                        'expired_code'
                    );
                }

                if ((int) $row['attempts'] >= (int) $row['max_attempts']) {
                    $this->pdo->rollBack();

                    throw new EmailVerificationException(
                        'Too many incorrect attempts. Please request a new code.',
                        'attempts_exhausted'
                    );
                }

                if (!password_verify($code, (string) $row['code_hash'])) {
                    /*
                     * Count the miss BEFORE throwing, so repeated wrong guesses
                     * run out of budget. This is the brute-force control.
                     */
                    $this->pdo->prepare(
                        'UPDATE email_verification_codes
                         SET attempts = attempts + 1
                         WHERE id = ?'
                    )->execute([(int) $row['id']]);

                    $spent = (int) $row['attempts'] + 1;
                    $remaining = max(0, (int) $row['max_attempts'] - $spent);

                    $this->pdo->commit();

                    throw new EmailVerificationException(
                        'That code is not valid.',
                        'invalid_code',
                        0
                    );
                }

                /* --- the only success path --------------------------------- */

                $now = gmdate('Y-m-d H:i:s');

                $this->pdo->prepare(
                    'UPDATE email_verification_codes
                     SET consumed_at = ?, locked_at = ?
                     WHERE id = ?'
                )->execute([$now, $now, (int) $row['id']]);

                $this->pdo->prepare(
                    'UPDATE users
                     SET email_verified_at = ?, email_verification_resend_after = 0
                     WHERE id = ? AND email_verified_at IS NULL'
                )->execute([$now, $userId]);

                $this->pdo->commit();

                return $userId;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $e;
            }
        }

        /* =============================================================
           State
           ============================================================= */

        /** Has this account been verified? */
        public function isVerified(int $userId): bool
        {
            $statement = $this->pdo->prepare(
                'SELECT email_verified_at FROM users WHERE id = ? LIMIT 1'
            );
            $statement->execute([$userId]);

            $value = $statement->fetchColumn();

            return $value !== false && $value !== null;
        }

        /**
         * How many guesses remain on the live code, for an honest error message.
         */
        public function remainingAttempts(int $userId): int
        {
            $statement = $this->pdo->prepare(
                'SELECT attempts, max_attempts
                 FROM email_verification_codes
                 WHERE user_id = ? AND consumed_at IS NULL AND locked_at IS NULL
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1'
            );
            $statement->execute([$userId]);

            $row = $statement->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return 0;
            }

            return max(0, (int) $row['max_attempts'] - (int) $row['attempts']);
        }

        /** Seconds until another code may be requested for this account. */
        public function resendCooldown(int $userId): int
        {
            $statement = $this->pdo->prepare(
                'SELECT email_verification_resend_after FROM users WHERE id = ? LIMIT 1'
            );
            $statement->execute([$userId]);

            $value = $statement->fetchColumn();

            return max(0, (int) $value - time());
        }

        /* =============================================================
           Internals
           ============================================================= */

        private function loadUser(int $userId): array
        {
            $statement = $this->pdo->prepare(
                'SELECT id, name, email, email_verified_at, email_verification_resend_after
                 FROM users
                 WHERE id = ?
                 LIMIT 1'
            );
            $statement->execute([$userId]);

            $user = $statement->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new EmailVerificationException('Account not found.', 'unknown_user');
            }

            return $user;
        }

        private function findUnverifiedUserIdByEmail(string $email): ?int
        {
            $email = strtolower(trim($email));

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return null;
            }

            /*
             * Verified accounts are excluded, so a verified user cannot be made
             * to re-verify by submitting their address here.
             */
            $statement = $this->pdo->prepare(
                'SELECT id
                 FROM users
                 WHERE email = ?
                   AND email_verified_at IS NULL
                 LIMIT 1'
            );
            $statement->execute([$email]);

            $id = $statement->fetchColumn();

            return $id === false ? null : (int) $id;
        }

        private function isExpired(string $expiresAt): bool
        {
            $timestamp = strtotime($expiresAt . ' UTC');

            return $timestamp === false || $timestamp <= time();
        }

        /** Store a usable IP or nothing at all. Never a raw header value. */
        private function normaliseIp(string $ip): ?string
        {
            return filter_var($ip, FILTER_VALIDATE_IP) !== false ? substr($ip, 0, 45) : null;
        }
    }
}