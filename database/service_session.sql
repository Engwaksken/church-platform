-- SERVICE (first, second, ...) for offertory and Good Samaritan collections.
-- Adds offertory.service_session, and good_samaritan.service_type and service_session.
-- Run once after database/good_samaritan.sql. It is safe to re-run.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'offertory' AND COLUMN_NAME = 'service_session') > 0,
  'SELECT 1', 'ALTER TABLE offertory ADD COLUMN service_session VARCHAR(40) NULL AFTER service_type');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'good_samaritan' AND COLUMN_NAME = 'service_type') > 0,
  'SELECT 1', 'ALTER TABLE good_samaritan ADD COLUMN service_type VARCHAR(60) NULL AFTER given_on');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'good_samaritan' AND COLUMN_NAME = 'service_session') > 0,
  'SELECT 1', 'ALTER TABLE good_samaritan ADD COLUMN service_session VARCHAR(40) NULL AFTER service_type');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
