-- =============================================================================
-- Paradise Studios - migration 002: mock gateway persistence
-- =============================================================================
-- Run with:  mysql -u root paradisestudios < sql/migrations/002_mock_gateway.sql
-- Roll back with sql/migrations/002_mock_gateway.down.sql
--
-- WHY THIS IS NEEDED
-- ------------------
-- The mock provider originally kept its transactions in a PHP static array.
-- That works for the automated suite, which runs in a single process, but the
-- real flow spans several requests:
--
--     POST /api/payment-initialize.php   -> mock creates a transaction
--     POST /api/payment-verify.php       -> mock must FIND that transaction
--     POST /api/payment-webhook.php      -> mock must find it again
--
-- Each of those is a separate PHP process, so the static array is empty and
-- verification fails with "the provider does not recognise that transaction".
-- The local payment flow was therefore impossible to exercise.
--
-- A real payment gateway keeps server-side state; the mock now does the same,
-- in a table that exists only for local development.
--
-- SAFETY
--   * Additive and self-contained. Nothing existing is altered.
--   * This table holds NO real money and is never read by the Paystack adapter.
--   * It must be emptied when switching PAYMENT_PROVIDER to paystack, so a
--     simulated transaction can never be mistaken for a real one.
-- =============================================================================

CREATE TABLE IF NOT EXISTS mock_gateway_transactions (
    id                  INT NOT NULL AUTO_INCREMENT,

    -- The gateway's own transaction id, which verify() is called with.
    provider_reference  VARCHAR(191) NOT NULL,

    -- The reference WE supplied at initialisation.
    our_reference       VARCHAR(64) NOT NULL,

    status              ENUM(
        'success',
        'failed',
        'abandoned',
        'partially_refunded',
        'refunded'
    ) NOT NULL DEFAULT 'success',

    amount_minor        BIGINT UNSIGNED NOT NULL,
    currency            CHAR(3) NOT NULL,

    -- The simulated processing fee, reported only on verify() as a real
    -- gateway would. Recorded verbatim into the ledger when confirmed.
    fee_minor           BIGINT UNSIGNED NOT NULL DEFAULT 0,

    channel             VARCHAR(32) NOT NULL DEFAULT 'mock',
    email               VARCHAR(191) NULL,
    metadata            LONGTEXT NULL,
    paid_at             DATETIME NULL,

    refunded_minor      BIGINT UNSIGNED NOT NULL DEFAULT 0,

    -- What the gateway REPORTS on verify(), held separately so a test can make
    -- the provider report a mismatched reference, amount or currency without
    -- destroying the ability to look the transaction up at all.
    reported_reference     VARCHAR(64) NULL,
    reported_amount_minor  BIGINT UNSIGNED NULL,
    reported_currency      CHAR(3) NULL,

    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_mock_provider_reference (provider_reference),
    KEY idx_mock_our_reference (our_reference),

    CONSTRAINT chk_mock_amount_positive
        CHECK (amount_minor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Local development only. Simulated gateway state for the mock provider. Never real money.';
