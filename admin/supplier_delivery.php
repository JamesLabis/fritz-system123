<?php
// Old navigation target. Delivery management now lives in deliveries.php.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();
redirect('/admin/deliveries.php');
