-- =============================================================================
-- Paradise Studios - migration 002 rollback
-- =============================================================================
--  mysql -u root paradisestudios < sql/migrations/002_mock_gateway.down.sql
--
-- Removes the mock gateway's local-development state table. Nothing else in
-- the project references it, and no real money is ever stored in it.
--
-- Empty this table whenever switching PAYMENT_PROVIDER from "mock" to
-- "paystack", so a simulated transaction can never be mistaken for a real one.
-- =============================================================================

DROP TABLE IF EXISTS mock_gateway_transactions;
