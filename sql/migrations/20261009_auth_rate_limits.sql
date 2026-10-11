CREATE TABLE IF NOT EXISTS auth_rate_limits (
    bucket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at BIGINT UNSIGNED NOT NULL,
    blocked_until BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_seen_at BIGINT UNSIGNED NOT NULL,

    INDEX idx_auth_rate_limits_last_seen (last_seen_at)
) ENGINE=InnoDB;
