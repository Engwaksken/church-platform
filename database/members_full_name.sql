-- members.full_name replaces first_name/last_name as the member's name.
-- config/db.php applies this automatically on the first request; kept here for manual runs.
ALTER TABLE members ADD COLUMN full_name VARCHAR(200) NOT NULL DEFAULT '' AFTER member_no;
UPDATE members SET full_name = TRIM(CONCAT_WS(' ', first_name, last_name));
