<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/log-maintenance.php';

$db = auth_db();
$settings = log_maintenance_settings($db);
log_maintenance_run($db, $settings);
