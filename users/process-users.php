<?php
declare(strict_types=1);

/*
 * Compatibility bridge.
 * The users API now lives in users/index.php so it works consistently on
 * installations where .htaccess removes .php extensions.
 */
$_GET['users_api'] = '1';

if (!isset($_REQUEST['action']) && isset($_GET['action'])) {
    $_REQUEST['action'] = $_GET['action'];
}

require __DIR__ . '/index.php';
