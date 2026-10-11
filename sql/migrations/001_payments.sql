-- =============================================================================
-- Paradise Studios - migration 001: payment data model
-- =============================================================================
-- Target: MariaDB 10.4+ / MySQL 5.7+
-- Run with:  mysql -u root paradisestudios < sql/migrations/001_payments.sql
--
-- SAFETY PROPERTIES
--   * Every statement is ADDITIVE. No existing column is dropped or retyped,
--     no row is deleted, and no existing query is invalidated.
--   * Two columns are added to `bookings` (total_amount_minor, payment_state).
--     Both are nullable/defaulted, so every current row stays valid.
--   * Historical bookings are marked legacy_unverified: they are never treated
--     as paid, and no money is invented for them.
--   * Rollback is in 001_payments.down.sql and only touches objects this file
--     creates.
--   * The script is IDEMPOTENT: re-running it is a no-op. Columns use
--     ADD COLUMN IF NOT EXISTS, tables use CREATE TABLE IF NOT EXISTS, and every
--     backfill UPDATE is guarded so it cannot overwrite work already done.
--
-- COLUMN TYPES
--   All primary and foreign key columns are SIGNED INT to match the existing
--   schema. users.id, studios.id and bookings.id are all `int(11)` signed, and
--   MySQL/MariaDB require a foreign key column type to match its target
--   exactly - INT UNSIGNED against a signed INT fails with errno 150. The
--   existing tables use signed ints throughout, so matching them avoids a
--   silent type mismatch.
--
-- MONEY REPRESENTATION
--   Every monetary column is BIGINT UNSIGNED MINOR UNITS (kobo for NGN). No
--   DECIMAL and no FLOAT is used for a financial total. `bookings.total_amount`
--   stays DECIMAL(10,2) because the existing frontends read and format it;
--   `bookings.total_amount_minor` is the authoritative integer used by the
--   payment layer, backfilled below by pure string surgery.
--
-- DELETE BEHAVIOUR
--   Existing tables use ON DELETE CASCADE throughout. That is unsafe for money:
--   deleting a user would silently cascade away bookings and destroy the ledger
--   history proving what was paid. All financial tables therefore reference
--   users/bookings with ON DELETE RESTRICT.
-- =============================================================================

SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 1. bookings: authoritative minor-unit amount + payment state
-- -----------------------------------------------------------------------------
-- payment_state is deliberately SEPARATE from `status`.
--   status        = the lifecycle of the SESSION (pending / escrow_held / ...)
--   payment_state = the lifecycle of the MONEY (unpaid / pending / paid / ...)
--
-- The two move independently and are never conflated. A booking counts as paid
-- only when payment_state says so AND a captured payment row exists. The
-- `status` value 'escrow_held' is retained for backward compatibility and is
-- documented as meaning "payment confirmed by the provider" - it does NOT mean
-- a regulated third party is holding funds.

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS total_amount_minor BIGINT UNSIGNED NULL
        COMMENT 'Authoritative amount in minor units (kobo). Backfilled from total_amount.'
        AFTER total_amount,
    ADD COLUMN IF NOT EXISTS payment_state ENUM(
        'unpaid',              -- no payment attempted yet
        'pending',             -- payment initialised, awaiting confirmation
        'paid',                -- provider confirmed capture; ledger credited
        'failed',              -- provider reported failure
        'expired',             -- payment window elapsed without confirmation
        'partially_refunded',  -- some, not all, of the captured amount returned
        'refunded',            -- the captured amount has been fully returned
        'legacy_unverified'    -- predates the payment system; NOT proof of payment
    ) NOT NULL DEFAULT 'unpaid'
        COMMENT 'Payment lifecycle, independent of the session status.'
        AFTER total_amount_minor,
    ADD COLUMN IF NOT EXISTS payment_expires_at DATETIME NULL
        COMMENT 'After this, an unconfirmed payment stops holding the studio slot.'
        AFTER payment_state;

-- Backfill: convert every existing DECIMAL amount to kobo.
--
-- Pure string surgery, so no float ever participates. `total_amount * 100`
-- would be the obvious way and is exactly what must not be used: it routes the
-- value through a binary float. DECIMAL(10,2) renders with its two fractional
-- digits, and the CASE also copes with a value lacking a decimal point.
UPDATE bookings
SET total_amount_minor = CASE
        WHEN total_amount IS NULL OR total_amount = '' THEN NULL
        WHEN INSTR(total_amount, '.') = 0 THEN
            CAST(CONCAT(total_amount, '00') AS UNSIGNED)
        ELSE
            CAST(
                CONCAT(
                    SUBSTRING_INDEX(total_amount, '.', 1),
                    LPAD(SUBSTRING_INDEX(total_amount, '.', -1), 2, '0')
                ) AS UNSIGNED
            )
    END
WHERE total_amount_minor IS NULL;

-- Existing bookings were created with 'escrow_held' and no money ever moved.
-- They must NOT be treated as paid, but must keep their current status so the
-- existing history renders unchanged.
UPDATE bookings
SET payment_state = 'legacy_unverified'
WHERE total_amount_minor IS NOT NULL
  AND status <> 'cancelled'
  AND payment_state = 'unpaid';

-- A cancelled legacy booking had no money either; record it honestly.
UPDATE bookings
SET payment_state = 'failed'
WHERE total_amount_minor IS NOT NULL
  AND status = 'cancelled'
  AND payment_state = 'unpaid';

-- -----------------------------------------------------------------------------
-- 2. payments
-- -----------------------------------------------------------------------------
-- One row per payment ATTEMPT. A booking may have several (a failed attempt
-- followed by a successful one), so this is deliberately not unique on booking.

CREATE TABLE IF NOT EXISTS payments (
    id                  INT NOT NULL AUTO_INCREMENT,

    booking_id          INT NOT NULL,

    -- Identity snapshotted at payment time. studios.owner_id is resolved by a
    -- JOIN at read time today, which means an owner reassigning a studio would
    -- silently move historical earnings. Snapshotting the payee here and in the
    -- ledger freezes that history at capture time.
    artist_id           INT NOT NULL,
    studio_id           INT NOT NULL,
    owner_id            INT NOT NULL COMMENT 'Payee, snapshotted at capture',

    provider            ENUM('mock', 'paystack') NOT NULL,

    -- OUR reference, sent to the provider and echoed back on the webhook.
    -- Unique so a reference can never be reused across payments.
    reference           VARCHAR(64) NOT NULL,

    -- The provider's own transaction id. Nullable until confirmed, and unique
    -- per provider when present, so one gateway transaction can never be
    -- attached to two payments.
    provider_reference  VARCHAR(191) NULL,
    provider_channel    VARCHAR(64) NULL,

    currency            CHAR(3) NOT NULL,

    -- What we asked for.
    amount_minor        BIGINT UNSIGNED NOT NULL COMMENT 'Minor units (kobo for NGN)',

    -- What the provider said it received. NULL until known. Never trusted in
    -- place of amount_minor: verification compares the two.
    provider_amount_minor BIGINT UNSIGNED NULL,
    provider_currency     CHAR(3) NULL,

    -- Provider processing fee, only once the provider reports it. Assumed
    -- unknown before confirmation; never estimated.
    provider_fee_minor  BIGINT UNSIGNED NULL,

    status              ENUM(
        'initialized',  -- we created the payment, provider not yet contacted
        'pending',      -- provider has an authorization/session open
        'processing',   -- provider is capturing
        'succeeded',    -- capture verified server-to-server
        'failed',       -- provider reported failure
        'abandoned',    -- never confirmed, window elapsed
        'partially_refunded',
        'refunded'
    ) NOT NULL DEFAULT 'initialized',

    -- Commission rate actually applied to THIS payment, in basis points.
    -- Stored per payment so a later rate change never rewrites history.
    commission_bps      SMALLINT UNSIGNED NOT NULL,

    failure_reason      VARCHAR(255) NULL,
    metadata            LONGTEXT NULL COMMENT 'Sanitised provider subset. No secrets, no PAN.',

    paid_at             DATETIME NULL,
    expires_at          DATETIME NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- A reference identifies exactly one payment, globally.
    UNIQUE KEY uq_payments_reference (reference),

    -- The same gateway transaction cannot be recorded against two payments.
    -- MariaDB/MySQL permit many NULLs in a UNIQUE index, which is what we want:
    -- unconfirmed payments have no provider_reference yet.
    UNIQUE KEY uq_payments_provider_reference (provider, provider_reference),

    KEY idx_payments_booking (booking_id),
    KEY idx_payments_status (status),
    KEY idx_payments_owner_status (owner_id, status),
    KEY idx_payments_paid_at (paid_at),

    CONSTRAINT fk_payments_booking
        FOREIGN KEY (booking_id) REFERENCES bookings (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT fk_payments_artist
        FOREIGN KEY (artist_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT fk_payments_studio
        FOREIGN KEY (studio_id) REFERENCES studios (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT fk_payments_owner
        FOREIGN KEY (owner_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT chk_payments_amount_positive
        CHECK (amount_minor > 0),

    CONSTRAINT chk_payments_commission_range
        CHECK (commission_bps BETWEEN 0 AND 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Payment attempts against a booking. Authoritative record of money in.';

-- -----------------------------------------------------------------------------
-- 3. financial_ledger
-- -----------------------------------------------------------------------------
-- APPEND-ONLY. There is deliberately no UPDATE or DELETE path in the
-- application and no stored running balance: balances are derived with SUM, so
-- a mistake in one place cannot corrupt a total held elsewhere.
--
-- Every row records the rate that was applied, so historical entries are never
-- recomputed with a newly changed commission rate.

CREATE TABLE IF NOT EXISTS financial_ledger (
    id                BIGINT NOT NULL AUTO_INCREMENT,

    -- Who the money moves for.
    party             ENUM('provider', 'platform', 'owner', 'artist') NOT NULL,
    party_user_id     INT NULL COMMENT 'Set for owner and artist parties',

    entry_type        ENUM(
        'payment_captured',        -- gross captured from the customer
        'platform_commission',     -- platform fee on a capture
        'provider_fee',            -- gateway processing fee, once reported
        'owner_earning',           -- owner's net share of a capture
        'owner_earning_reversed',  -- earning clawed back (refund, dispute)
        'commission_reversed',     -- platform commission clawed back
        'refund',                  -- money returned to the customer
        'payout',                  -- confirmed owner transfer (enabled by migration 003)
        'adjustment'               -- manual correction, always with a reason
    ) NOT NULL,

    booking_id        INT NULL,
    payment_id        INT NULL,
    refund_id         INT NULL,
    payout_id         INT NULL,

    currency          CHAR(3) NOT NULL,
    amount_minor      BIGINT UNSIGNED NOT NULL
        COMMENT 'Positive magnitude; direction comes from entry_type.',

    -- Rate in force when this entry was written. Stored so history is stable.
    commission_bps    SMALLINT UNSIGNED NULL,

    description       VARCHAR(255) NULL,
    metadata          LONGTEXT NULL COMMENT 'Safe diagnostic detail only.',

    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_ledger_booking (booking_id),
    KEY idx_ledger_payment (payment_id),
    KEY idx_ledger_party (party, party_user_id),
    KEY idx_ledger_type (entry_type),
    KEY idx_ledger_created (created_at),

    CONSTRAINT fk_ledger_booking
        FOREIGN KEY (booking_id) REFERENCES bookings (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT fk_ledger_payment
        FOREIGN KEY (payment_id) REFERENCES payments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT fk_ledger_party_user
        FOREIGN KEY (party_user_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Append-only financial journal. Balances are derived with SUM, never stored.';

-- -----------------------------------------------------------------------------
-- 4. refunds
-- -----------------------------------------------------------------------------
-- A refund is a REQUEST plus its outcome. Requesting one does not move money;
-- only a provider-confirmed success does. That distinction is what stops the
-- application ever claiming an unverified refund was returned to a customer.

CREATE TABLE IF NOT EXISTS refunds (
    id                  INT NOT NULL AUTO_INCREMENT,

    payment_id          INT NOT NULL,
    booking_id          INT NOT NULL,

    provider            ENUM('mock', 'paystack') NOT NULL,
    provider_reference  VARCHAR(191) NULL,

    currency            CHAR(3) NOT NULL,
    amount_minor        BIGINT UNSIGNED NOT NULL COMMENT 'Requested, in minor units.',

    -- Policy actually applied, so a later policy change cannot rewrite history.
    refund_type         ENUM('cancellation', 'manual', 'adjustment') NOT NULL DEFAULT 'cancellation',
    policy_bps          SMALLINT UNSIGNED NOT NULL COMMENT 'Cancellation policy rate used.',

    reason              VARCHAR(255) NULL,
    requested_by        INT NULL COMMENT 'users.id; NULL for system-initiated.',

    -- Supplied by the caller to make a retried request safe. A repeat with the
    -- same key returns the original refund instead of creating a second one,
    -- which is what prevents double refunding.
    idempotency_key     VARCHAR(80) NOT NULL,

    status              ENUM(
        'requested',   -- recorded, not yet sent to the provider
        'processing',  -- sent, awaiting provider result
        'succeeded',   -- provider confirmed the money was returned
        'failed',      -- provider rejected or the attempt failed
        'cancelled'    -- abandoned before completion
    ) NOT NULL DEFAULT 'requested',

    failure_reason      VARCHAR(255) NULL,
    provider_response   LONGTEXT NULL COMMENT 'Sanitised. No secrets, no PAN.',

    requested_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at        DATETIME NULL,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- The same idempotency key can only ever produce one refund.
    UNIQUE KEY uq_refunds_idempotency (idempotency_key),

    -- The same provider refund must not be applied twice.
    UNIQUE KEY uq_refunds_provider_reference (provider, provider_reference),

    KEY idx_refunds_payment (payment_id),
    KEY idx_refunds_booking (booking_id),
    KEY idx_refunds_status (status),

    CONSTRAINT fk_refunds_payment
        FOREIGN KEY (payment_id) REFERENCES payments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT fk_refunds_booking
        FOREIGN KEY (booking_id) REFERENCES bookings (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT fk_refunds_requested_by
        FOREIGN KEY (requested_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT chk_refunds_amount_positive
        CHECK (amount_minor > 0),

    CONSTRAINT chk_refunds_policy_range
        CHECK (policy_bps BETWEEN 0 AND 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Refund requests and outcomes. A request is not a completed refund.';

-- -----------------------------------------------------------------------------
-- 5. payment_webhook_events
-- -----------------------------------------------------------------------------
-- Every inbound callback is recorded before it is acted on, including rejected
-- ones. `fingerprint` is a SHA-256 of the exact raw body, so a byte-identical
-- replay is recognised even if the provider omitted or reused an event id.

CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id                BIGINT NOT NULL AUTO_INCREMENT,

    provider          ENUM('mock', 'paystack') NOT NULL,
    event_id          VARCHAR(191) NULL COMMENT 'Provider event identifier, when supplied.',
    event_type        VARCHAR(64) NULL,

    -- SHA-256 of the raw request body, hex encoded. Unique.
    fingerprint       CHAR(64) NOT NULL,

    signature_valid   TINYINT(1) NOT NULL DEFAULT 0,

    status            ENUM(
        'received',
        'processing',
        'processed',
        'ignored',             -- unsupported type, or stale ordering
        'duplicate',           -- already handled
        'invalid_signature',
        'failed'               -- verification or ledger write failed
    ) NOT NULL DEFAULT 'received',

    payment_id        INT NULL,
    booking_id        INT NULL,

    -- Sanitised diagnostic detail ONLY: event ids, statuses, amounts, our own
    -- references. Never a secret key, never a card number, never a CVV.
    payload           LONGTEXT NULL,
    error_message     VARCHAR(500) NULL,
    http_status       SMALLINT UNSIGNED NULL,

    received_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at      DATETIME NULL,

    PRIMARY KEY (id),

    -- A byte-identical replay is caught here.
    UNIQUE KEY uq_webhook_fingerprint (fingerprint),

    -- An event id may only be processed once per provider.
    UNIQUE KEY uq_webhook_event (provider, event_id),

    KEY idx_webhook_status (status),
    KEY idx_webhook_received (received_at),
    KEY idx_webhook_payment (payment_id),

    CONSTRAINT fk_webhook_payment
        FOREIGN KEY (payment_id) REFERENCES payments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT fk_webhook_booking
        FOREIGN KEY (booking_id) REFERENCES bookings (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Inbound provider callbacks, including rejected ones. Idempotency anchor.';

-- -----------------------------------------------------------------------------
-- 6. owner_payouts
-- -----------------------------------------------------------------------------
-- ELIGIBILITY ONLY. This table records that an owner has earned money and when
-- it becomes available. Nothing in this codebase ever creates a bank recipient
-- or initiates a transfer, and no payment provider holds funds on an owner's
-- behalf after a session.
--
-- One row per completed booking earning, so an earning can never be credited
-- twice. A future payout operation would group rows whose status is 'eligible'.

CREATE TABLE IF NOT EXISTS owner_payouts (
    id                  INT NOT NULL AUTO_INCREMENT,

    -- Unique: one earning per booking. This constraint is what makes
    -- double-crediting an owner impossible at the database level.
    booking_id          INT NOT NULL,
    payment_id          INT NOT NULL,
    owner_id            INT NOT NULL COMMENT 'Snapshotted payee.',

    currency            CHAR(3) NOT NULL,

    gross_minor         BIGINT UNSIGNED NOT NULL,
    commission_minor    BIGINT UNSIGNED NOT NULL,
    provider_fee_minor  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    owner_net_minor     BIGINT UNSIGNED NOT NULL,

    -- Amount already clawed back from this earning by refunds.
    reversed_minor      BIGINT UNSIGNED NOT NULL DEFAULT 0,

    commission_bps      SMALLINT UNSIGNED NOT NULL COMMENT 'Rate applied when earned.',

    status              ENUM(
        'pending_session',   -- session not finished yet
        'pending_delay',     -- finished, inside the eligibility window
        'eligible',          -- available to be paid out
        'on_hold',           -- dispute or administrative review
        'paid',              -- a payout was executed (never by this codebase)
        'failed',
        'reversed'           -- refunded away before payout
    ) NOT NULL DEFAULT 'pending_session',

    -- When the session ends, and when the delay expires.
    session_ends_at     DATETIME NULL,
    eligible_at         DATETIME NULL,

    -- Set if the earning is ever clawed back or placed on hold.
    hold_reason         VARCHAR(255) NULL,

    provider            ENUM('mock', 'paystack') NULL,
    provider_reference  VARCHAR(191) NULL,
    paid_at             DATETIME NULL,

    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_payouts_booking (booking_id),

    KEY idx_payouts_owner_status (owner_id, status),
    KEY idx_payouts_eligible_at (eligible_at),

    CONSTRAINT fk_payouts_booking
        FOREIGN KEY (booking_id) REFERENCES bookings (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT fk_payouts_payment
        FOREIGN KEY (payment_id) REFERENCES payments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT fk_payouts_owner
        FOREIGN KEY (owner_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    -- The split must always reconcile, exactly as in the ledger.
    CONSTRAINT chk_payouts_reconciles
        CHECK (commission_minor + owner_net_minor = gross_minor),

    CONSTRAINT chk_payouts_non_negative
        CHECK (owner_net_minor >= 0 AND reversed_minor <= gross_minor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Owner earnings and payout ELIGIBILITY. No transfer is ever initiated here.';

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

-- =============================================================================
-- Post-migration verification
-- =============================================================================
SELECT 'payments'              AS table_name, COUNT(*) AS row_count FROM payments
UNION ALL SELECT 'financial_ledger',       COUNT(*) FROM financial_ledger
UNION ALL SELECT 'refunds',                COUNT(*) FROM refunds
UNION ALL SELECT 'payment_webhook_events', COUNT(*) FROM payment_webhook_events
UNION ALL SELECT 'owner_payouts',          COUNT(*) FROM owner_payouts;

-- Existing bookings must be legacy_unverified (or failed when cancelled) and
-- must carry a backfilled integer amount.
SELECT id, status, total_amount, total_amount_minor, payment_state
FROM bookings ORDER BY id;
