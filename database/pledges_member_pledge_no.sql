-- Each member has one pledge number (members.pledge_no), and all of that member's pledges use it
-- instead of an auto-generated PLG-... value. A member may still make several pledges.
-- Back up the database first, then run this file once. It is safe to re-run.

-- 1. Tidy member pledge numbers: trim spaces and store blanks as NULL (NULLs never clash in a unique index).
UPDATE members SET pledge_no = NULLIF(TRIM(pledge_no), '') WHERE pledge_no IS NOT NULL;

-- 2. Pledge numbers shared by more than one member. If this returns rows, give those members
--    different numbers (Members screen) and run the file again; step 3 fails until they are fixed.
SELECT pledge_no, COUNT(*) AS members_sharing, GROUP_CONCAT(CONCAT(member_no, ' ', full_name) SEPARATOR '; ') AS members
  FROM members WHERE pledge_no IS NOT NULL
 GROUP BY pledge_no HAVING COUNT(*) > 1;

-- 3. Enforce one member per pledge number.
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND INDEX_NAME = 'uq_members_pledge_no');
SET @sql := IF(@has > 0, 'SELECT 1', 'ALTER TABLE members ADD UNIQUE INDEX uq_members_pledge_no (pledge_no)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. If pledges.pledge_no has a UNIQUE index, replace it with a normal index so a member's
--    several pledges can share their pledge number.
SET @idx := (SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pledges' AND COLUMN_NAME = 'pledge_no' AND NON_UNIQUE = 0
             LIMIT 1);
SET @sql := IF(@idx IS NULL, 'SELECT 1',
               CONCAT('ALTER TABLE pledges DROP INDEX `', @idx, '`, ADD INDEX idx_pledges_pledge_no (pledge_no)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Put existing pledges on their member's pledge number, replacing old generated PLG-... values.
--    Pledges whose member has no pledge number yet keep their old value.
UPDATE pledges p JOIN members m ON m.id = p.member_id
   SET p.pledge_no = m.pledge_no
 WHERE m.pledge_no IS NOT NULL AND p.pledge_no <> m.pledge_no;
