-- =============================================================================
-- Paradise Studios - migration 004 rollback
-- =============================================================================
--  mysql -u root paradisestudios < sql/migrations/004_email_verification.down.sql
--
-- WARNING - READ BEFORE RUNNING
-- ----------------------------
-- Dropping `email_verification_codes` DESTROYS every outstanding code. Anyone
-- mid-verification would have to request a new one.
--
-- Dropping `email_verified_at` also destroys verification history. Because the
-- column would be gone, the login check has nothing to read, so unverified
-- accounts could sign in again. Only roll back if that is what you intend.
--
-- Take a mysqldump first.
-- =============================================================================

DROP TABLE IF EXISTS email_verification_codes;

ALTER TABLE users
    DROP COLUMN email_verification_resend_after,
    DROP COLUMN email_verified_at;