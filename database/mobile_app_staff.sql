-- MOBILE APP FOR ALL USERS (api/member/)
-- Lets every active website user sign in to the app with their username or email (members can still use
-- their membership or pledge number). Users without a member record get a 6-digit code by email, as on the website.
-- Run once after database/member_mobile_api.sql; safe to re-run.

CREATE TABLE IF NOT EXISTS member_api_login_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    challenge_hash CHAR(64) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    device_name VARCHAR(120) NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    last_sent_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    UNIQUE KEY uq_member_api_login_challenge (challenge_hash),
    INDEX idx_member_api_login_codes_user (user_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password reset codes (also in database/member_mobile_api.sql), for sites set up before they were added.
CREATE TABLE IF NOT EXISTS member_api_password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(80) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_id INT NULL,
    code_hash VARCHAR(255) NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    INDEX idx_member_api_resets_identifier (identifier, created_at),
    INDEX idx_member_api_resets_ip (ip_address, created_at),
    INDEX idx_member_api_resets_user (user_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who saved each offertory entry (also database/offertory_recorded_by.sql).
SET @has_recorded_by = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'offertory' AND COLUMN_NAME = 'recorded_by');
SET @has_offertory = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'offertory');
SET @sql = IF(@has_offertory = 1 AND @has_recorded_by = 0, 'ALTER TABLE offertory ADD COLUMN recorded_by INT NULL AFTER witnessed_by', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
