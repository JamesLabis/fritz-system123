<?php
/*
 * Stale-order cleanup shared by the scheduled job (cron/release_stale_orders.php)
 * and the automatic fallback below, so cleanup still happens if the scheduler is not set up.
 */

/** Cancels unpaid Pending orders older than "pending_order_hours" (stock is returned) and tidies old rows. */
function buildsmart_run_cleanup(PDO $pdo): int {
    $hours = (int)($pdo->query("SELECT setting_value FROM buildsmart_settings WHERE setting_key='pending_order_hours'")->fetchColumn() ?: 24);
    $s = $pdo->prepare("SELECT o.order_id FROM orders o JOIN payments p ON p.order_id=o.order_id
                        WHERE o.order_status='Pending' AND p.payment_status<>'Paid' AND o.order_date < NOW() - INTERVAL ? HOUR");
    $s->execute([$hours]);
    $n = 0;
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try {
            change_order_status($pdo, (int)$id, 'Cancelled', "Auto-cancelled: not confirmed within $hours hours",
                ['message' => "Your order #$id was cancelled because it was not confirmed in time."]);
            $n++;
        } catch (Throwable $e) { error_log("release_stale_orders #$id: " . $e->getMessage()); }
    }
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
    $pdo->exec('DELETE FROM customer_notifications WHERE is_read=1 AND created_at < NOW() - INTERVAL 365 DAY');
    $pdo->exec('DELETE FROM activity_logs WHERE created_at < NOW() - INTERVAL 365 DAY');
    $pdo->prepare("INSERT INTO buildsmart_settings(setting_key,setting_value) VALUES('cron_last_run',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
        ->execute([date('Y-m-d H:i:s')]);
    return $n;
}

/**
 * Fallback for installs where the scheduler is not set up: at most once per hour, the first web
 * request runs the cleanup. The UPDATE is the lock, so two simultaneous visitors cannot both run it.
 */
function buildsmart_maybe_run_cleanup(PDO $pdo): void {
    try {
        $last = $pdo->query("SELECT setting_value FROM buildsmart_settings WHERE setting_key='cron_last_run'")->fetchColumn();
        if ($last !== false && strtotime((string)$last) > time() - 3600) return; // ran recently: one cheap SELECT and done
        $pdo->exec("INSERT IGNORE INTO buildsmart_settings(setting_key,setting_value) VALUES('cron_last_run','1970-01-01 00:00:00')");
        $claim = $pdo->prepare("UPDATE buildsmart_settings SET setting_value=? WHERE setting_key='cron_last_run' AND setting_value < ?");
        $claim->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() - 3600)]);
        if ($claim->rowCount() === 1) buildsmart_run_cleanup($pdo);
    } catch (Throwable $e) {
        error_log('buildsmart auto cleanup: ' . $e->getMessage());
    }
}
