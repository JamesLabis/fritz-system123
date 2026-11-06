<?php
/*
 * Cancels unpaid orders that have stayed Pending too long and returns their reserved stock.
 * Run from the command line on a schedule, e.g. every 30 minutes:
 *   Windows Task Scheduler:  C:\xampp\php\php.exe C:\xampp\htdocs\BuildSmart\cron\release_stale_orders.php
 *   Linux cron:              0,30 * * * * php /var/www/BuildSmart/cron/release_stale_orders.php
 * (If this is not scheduled, the site runs the same cleanup itself at most once an hour while people use it.)
 * The hold time is the setting "pending_order_hours" (default 24).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
$_SERVER['REMOTE_ADDR'] = 'cron';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/maintenance.php';
echo "Cancelled " . buildsmart_run_cleanup($pdo) . " stale order(s).\n";
