<?php
declare(strict_types=1);


require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/bird-notification-functions.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo "Database connection not found.\n";
    exit(1);
}
$conn->set_charset('utf8mb4');

if (!bird_nf_table_exists($conn, 'bird_allocations')) {
    echo "bird_allocations table not found.\n";
    exit(0);
}

$dueCol = bird_nf_due_col($conn);
$sql = "SELECT id FROM bird_allocations
        WHERE status IN ('Active','Overdue')
          AND `$dueCol` IS NOT NULL
          AND `$dueCol` <> '0000-00-00'
          AND `$dueCol` BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 MONTH)
        ORDER BY `$dueCol` ASC";

$res = $conn->query($sql);
$checked = 0;
$sentEmail = 0;
$sentSms = 0;
$skipped = 0;

while ($res && ($row = $res->fetch_assoc())) {
    $checked++;
    $result = bird_send_due_reminder($conn, (int)$row['id']);
    if (!empty($result['skipped'])) $skipped++;
    if (!empty($result['email'])) $sentEmail++;
    if (!empty($result['sms'])) $sentSms++;
}

echo "Checked: {$checked}; Email sent: {$sentEmail}; SMS sent: {$sentSms}; Skipped: {$skipped}\n";