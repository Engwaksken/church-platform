<?php
declare(strict_types=1);

// Run weekly using cron, for example:
// 0 8 * * MON /usr/bin/php /path/to/cron/weekly_pledge_reminders.php

$_POST['action'] = 'send_reminders';
$_REQUEST['action'] = 'send_reminders';

ob_start();
require_once __DIR__ . '/../pledges/process-pledges.php';
$output = ob_get_clean();

echo $output . PHP_EOL;
