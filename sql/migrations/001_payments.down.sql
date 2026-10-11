-- =============================================================================
-- Paradise Studios - migration 001 rollback
-- =============================================================================
--  mysql -u root paradisestudios < sql/migrations/001_payments.down.sql
--
-- This reverses sql/migrations/001_payments.sql.
--
--   * Removes the five tables 001 created.
--   * Removes the three columns 001 added to `bookings`.
--   * Does NOT touch any pre-existing table, column or row.
--   * `bookings.total_amount` (DECIMAL) is left exactly as it was, so the
--     existing frontends keep working after a rollback.
--
-- ORDER MATTERS: the added bookings columns are dropped first because the new
-- tables reference bookings. child tables are dropped before payments.
--
-- WARNING: dropping these tables DESTROYS payment history. Take a backup first
-- (mysqldump). This is only appropriate before any real payment has been
-- captured.
-- =============================================================================

SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

-- Financial tables, child-first.
DROP TABLE IF EXISTS owner_payouts;
DROP TABLE IF EXISTS payment_webhook_events;
DROP TABLE IF EXISTS refunds;
DROP TABLE IF EXISTS financial_ledger;
DROP TABLE IF EXISTS payments;

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

-- Columns added to bookings by 001.
ALTER TABLE bookings
    DROP COLUMN IF EXISTS payment_expires_at,
    DROP COLUMN IF EXISTS payment_state,
    DROP COLUMN IF EXISTS total_amount_minor;

-- Confirmation: bookings is back to its original shape.
DESCRIBE bookings;
