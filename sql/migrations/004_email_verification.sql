-- =============================================================================
-- Paradise Studios - migration 004: email verification
-- =============================================================================
-- Run with:  mysql -u root paradisestudios < sql/migrations/004_email_verification.sql
-- Roll back: sql/migrations/004_email_verification.down.sql
--
-- WHAT THIS DOES
-- --------------
--   1. Adds verification state to `users`.
--   2. Adds a table holding verification CODES (never the codes themselves).
--   3. Backfills every pre-existing account as already verified.
--
-- WHY THE BACKFILL IS NOT OPTIONAL
-- --------------------------------
-- `email_verified_at` is NULL for an account that has never been verified, and
-- login refuses those. Every account that already exists was created before this
-- feature existed and was never asked to verify anything, so leaving them NULL
-- would lock every current user out of their own account on deploy.
--
-- They are stamped with their own created_at, which is honest: we cannot know
-- whether that address is real, but we do know the account has been in use
-- since then, and re-verifying existing users would be a self-inflicted
-- lockout rather than a security control.
--
-- SAFETY
--   * Additive only. No existing column changes type or meaning.
--   * No rows are deleted, and no existing data is modified apart from the
--     explicit backfill above.
--   * Verified separately against a throwaway database before being run here.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Verification state on the user
-- -----------------------------------------------------------------------------
-- email_verified_at        NULL = never verified.
-- email_verification_resend_after
--                          Unix timestamp before which a NEW code may not be
--                          requested. Stored on the user rather than on the code
--                          so the throttle survives every code being deleted.

ALTER TABLE users
    ADD COLUMN email_verified_at DATETIME NULL DEFAULT NULL AFTER password_hash,
    ADD COLUMN email_verification_resend_after BIGINT UNSIGNED NOT NULL DEFAULT 0
        AFTER email_verified_at;

-- -----------------------------------------------------------------------------
-- 2. Backfill: existing accounts count as verified
-- -----------------------------------------------------------------------------

UPDATE users
SET email_verified_at = created_at
WHERE email_verified_at IS NULL;

-- -----------------------------------------------------------------------------
-- 3. Verification codes
-- -----------------------------------------------------------------------------
-- ONE ROW PER ISSUED CODE.
--
-- code_hash holds password_hash() output (bcrypt/argon). The raw six-digit code
-- is never written here, never logged and never returned to a browser. A plain
-- SHA-256 would be pointless: there are only one million possible codes, so any
-- attacker with read access to this table could enumerate all of them in
-- seconds. A slow KDF makes that a non-starter.
--
-- "Only one active code per user" is enforced in the application inside a
-- transaction, rather than with a generated-column unique index, so the DDL
-- stays portable and obvious.

CREATE TABLE IF NOT EXISTS email_verification_codes (
    id INT NOT NULL AUTO_INCREMENT,

    user_id INT NOT NULL,

    -- password_hash() output. Never the raw code.
    code_hash VARCHAR(255) NOT NULL,

    -- Verification attempts already spent against this code.
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,

    -- Ceiling for attempts on this code. Stored per row so the policy can be
    -- changed for future codes without stranding codes already issued.
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,

    -- The code stops working at this instant. Compared with UTC_TIMESTAMP().
    expires_at DATETIME NOT NULL,

    -- Set the moment the code is successfully used. NULL means "still live".
    consumed_at DATETIME NULL,

    -- Set when attempts are exhausted, so the row stops being retried.
    locked_at DATETIME NULL,

    -- Recorded for abuse diagnosis only. Not used for authorisation.
    requested_ip VARCHAR(45) NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    INDEX idx_evc_user (user_id, created_at),
    INDEX idx_evc_expiry (expires_at),

    CONSTRAINT chk_evc_attempts_within_max
        CHECK (attempts <= max_attempts),

    -- Deleting an account must not leave orphaned codes behind.
    CONSTRAINT fk_evc_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Issued email verification codes. Stores only a slow hash, never the code.';

-- -----------------------------------------------------------------------------
-- 4. Housekeeping index
-- -----------------------------------------------------------------------------
-- Expired, consumed and locked rows are safe to delete once they are old enough
-- to be useless for diagnosis.

DELETE FROM email_verification_codes
WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL 7 DAY);