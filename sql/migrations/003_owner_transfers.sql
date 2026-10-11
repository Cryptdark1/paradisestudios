-- Owner bank destinations and auditable Paystack transfer attempts.
-- Bank account numbers are verified in the request and are never persisted.
-- Apply once after migrations 001 and 002.

ALTER TABLE payments
    ADD COLUMN authorization_url VARCHAR(2048) NULL AFTER provider_channel,
    MODIFY COLUMN status ENUM(
        'initialized', 'pending', 'processing', 'succeeded', 'duplicate_capture',
        'failed', 'abandoned', 'partially_refunded', 'refunded'
    ) NOT NULL DEFAULT 'initialized';

ALTER TABLE financial_ledger
    MODIFY COLUMN entry_type ENUM(
        'payment_captured', 'platform_commission', 'provider_fee', 'owner_earning',
        'owner_earning_reversed', 'commission_reversed', 'refund', 'payout',
        'payout_reversed', 'adjustment'
    ) NOT NULL;

CREATE TABLE IF NOT EXISTS owner_payout_accounts (
    owner_id         INT NOT NULL,
    provider         ENUM('mock', 'paystack') NOT NULL,
    recipient_code   VARCHAR(191) NOT NULL,
    bank_code        VARCHAR(32) NOT NULL,
    bank_name        VARCHAR(128) NOT NULL,
    account_name     VARCHAR(128) NOT NULL,
    account_last4    CHAR(4) NOT NULL,
    currency         CHAR(3) NOT NULL,
    verified_at      DATETIME NOT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (owner_id),
    UNIQUE KEY uq_owner_payout_recipient (provider, recipient_code),
    CONSTRAINT fk_owner_payout_account_user
        FOREIGN KEY (owner_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS owner_transfer_attempts (
    id                   BIGINT NOT NULL AUTO_INCREMENT,
    payout_id             INT NOT NULL,
    owner_id              INT NOT NULL,
    provider              ENUM('mock', 'paystack') NOT NULL,
    reference             VARCHAR(191) NOT NULL,
    recipient_code        VARCHAR(191) NOT NULL,
    provider_reference    VARCHAR(191) NULL,
    amount_minor          BIGINT UNSIGNED NOT NULL,
    currency              CHAR(3) NOT NULL,
    status                ENUM('submitting', 'pending', 'success', 'failed', 'reversed', 'unknown') NOT NULL DEFAULT 'submitting',
    provider_status       VARCHAR(64) NULL,
    failure_reason        VARCHAR(255) NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_owner_transfer_reference (reference),
    UNIQUE KEY uq_owner_transfer_provider_reference (provider, provider_reference),
    KEY idx_owner_transfer_payout_status (payout_id, status),
    KEY idx_owner_transfer_owner_created (owner_id, created_at),
    CONSTRAINT fk_owner_transfer_payout
        FOREIGN KEY (payout_id) REFERENCES owner_payouts (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_owner_transfer_owner
        FOREIGN KEY (owner_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_owner_transfer_amount_positive CHECK (amount_minor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS mock_gateway_transfers (
    reference          VARCHAR(191) NOT NULL,
    provider_reference VARCHAR(191) NOT NULL,
    status             VARCHAR(64) NOT NULL,
    amount_minor       BIGINT UNSIGNED NOT NULL,
    currency           CHAR(3) NOT NULL,
    recipient_code     VARCHAR(191) NOT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (reference),
    UNIQUE KEY uq_mock_transfer_provider_reference (provider_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
