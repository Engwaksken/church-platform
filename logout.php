<?php
require_once 'config/config.php';
session_destroy();
redirect(BASE_URL . 'index.php');
