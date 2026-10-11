-- ============================================
-- PARADISE STUDIOS BOOKING APP
-- Database Schema
-- MariaDB 10.4+
-- ============================================

CREATE DATABASE IF NOT EXISTS paradisestudios;

USE paradisestudios;


-- ============================================
-- 1. USERS
-- ============================================

CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('artist', 'owner', 'admin') NOT NULL,
    buzz_level INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- AUTHENTICATION RATE LIMIT BUCKETS
-- ============================================

CREATE TABLE auth_rate_limits (
    bucket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at BIGINT UNSIGNED NOT NULL,
    blocked_until BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_seen_at BIGINT UNSIGNED NOT NULL,

    INDEX idx_auth_rate_limits_last_seen (last_seen_at)
) ENGINE=InnoDB;


-- ============================================
-- 2. STUDIOS
-- ============================================

CREATE TABLE studios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    owner_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(100) NOT NULL,
    address TEXT,
    hourly_rate DECIMAL(10,2) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_studios_owner
        FOREIGN KEY (owner_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_studios_location (location),
    INDEX idx_studios_owner (owner_id)
);


-- ============================================
-- 3. EQUIPMENT
-- ============================================

CREATE TABLE equipment (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL UNIQUE,
    category ENUM(
        'Mic',
        'Software',
        'Monitoring',
        'Instrument'
    ) NOT NULL
);

-- ============================================
-- 4. STUDIO EQUIPMENT
-- ============================================

CREATE TABLE studio_equipment (
    studio_id INT NOT NULL,
    equipment_id INT NOT NULL,

    PRIMARY KEY (studio_id, equipment_id),

    CONSTRAINT fk_studio_equipment_studio
        FOREIGN KEY (studio_id)
        REFERENCES studios(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_studio_equipment_equipment
        FOREIGN KEY (equipment_id)
        REFERENCES equipment(id)
        ON DELETE CASCADE
);