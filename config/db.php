<?php
// Configure these values in the server environment; never commit live credentials.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'cou_church');
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("<div style='padding:20px;color:red;font-family:sans-serif;'><strong>DB Error:</strong> ".$conn->connect_error."</div>");
}
$conn->set_charset("utf8mb4");

// members.full_name holds the member's name (first_name/last_name are legacy); add and backfill it once.
$fullNameCol = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='members' AND COLUMN_NAME='full_name'");
$hasMembers  = $conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='members'");
if ($fullNameCol && !$fullNameCol->num_rows && $hasMembers && $hasMembers->num_rows) {
    try {
        $conn->query("ALTER TABLE members ADD COLUMN full_name VARCHAR(200) NOT NULL DEFAULT '' AFTER member_no");
        $middle = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='members' AND COLUMN_NAME='middle_name'");
        $parts  = $middle && $middle->num_rows ? 'first_name,middle_name,last_name' : 'first_name,last_name';
        $conn->query("UPDATE members SET full_name=TRIM(REPLACE(CONCAT_WS(' ',$parts),'  ',' '))");
        unset($middle, $parts);
    } catch (Throwable $e) { error_log('Could not add members.full_name: ' . $e->getMessage()); }
}
unset($fullNameCol, $hasMembers);
