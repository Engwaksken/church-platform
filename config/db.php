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
