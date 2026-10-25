-- pledges.pledge_no now holds the member's own pledge number (members.pledge_no) instead of an
-- auto-generated PLG-... value, so one member's pledges share the same number.
-- Back up the database first, then run this file once. It is safe to re-run.

-- 1. If pledges.pledge_no has a UNIQUE index, replace it with a normal index so a member can have several pledges.
SET @idx := (SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pledges' AND COLUMN_NAME = 'pledge_no' AND NON_UNIQUE = 0
             LIMIT 1);
SET @sql := IF(@idx IS NULL, 'SELECT 1',
               CONCAT('ALTER TABLE pledges DROP INDEX `', @idx, '`, ADD INDEX idx_pledges_pledge_no (pledge_no)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Optional: replace the old generated numbers on existing pledges with each member's pledge number.
--    Pledges whose member has no pledge number keep their old value.
-- UPDATE pledges p JOIN members m ON m.id = p.member_id
--    SET p.pledge_no = m.pledge_no
--  WHERE TRIM(COALESCE(m.pledge_no, '')) <> '';
