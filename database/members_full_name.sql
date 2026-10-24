-- members.full_name replaces first_name/last_name as the member's name.
-- config/db.php applies the first three statements automatically; kept here for manual runs.
ALTER TABLE members ADD COLUMN full_name VARCHAR(200) NOT NULL DEFAULT '' AFTER member_no;
UPDATE members SET full_name = TRIM(CONCAT_WS(' ', first_name, last_name));
-- The app no longer writes the legacy columns, so they must accept NULL (match your column types).
ALTER TABLE members MODIFY first_name VARCHAR(100) NULL DEFAULT NULL, MODIFY last_name VARCHAR(100) NULL DEFAULT NULL;

-- Once full_name is confirmed in production (take a backup first), drop the legacy columns:
-- ALTER TABLE members DROP COLUMN first_name, DROP COLUMN last_name;
