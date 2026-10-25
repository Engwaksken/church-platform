-- PLEDGE TYPES: a pledge is Annual, Project, Harvest Items, Event or Other (specified in pledge_reason).
-- Adds pledges.pledge_type, pledge_year (Annual) and event_id (Event), and types existing pledges:
-- Project when they have a project, otherwise Other. Back up first; it is safe to re-run.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pledges' AND COLUMN_NAME = 'pledge_type') > 0,
  'SELECT 1', 'ALTER TABLE pledges ADD COLUMN pledge_type VARCHAR(30) NULL AFTER member_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pledges' AND COLUMN_NAME = 'pledge_year') > 0,
  'SELECT 1', 'ALTER TABLE pledges ADD COLUMN pledge_year SMALLINT NULL AFTER pledge_type');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pledges' AND COLUMN_NAME = 'event_id') > 0,
  'SELECT 1', 'ALTER TABLE pledges ADD COLUMN event_id INT NULL AFTER project_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE pledges SET pledge_type = IF(project_id IS NOT NULL AND project_id > 0, 'Project', 'Other') WHERE pledge_type IS NULL OR pledge_type = '';
