<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

hzSessionStart();

$_SESSION = [];
session_destroy();
header('Location: ' . BASE . '/login.php');
exit;
