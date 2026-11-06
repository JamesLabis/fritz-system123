<?php
/*
 * One-time, non-destructive upgrade for the delivery workflow.
 * Adds: "Out for Delivery" order status, "Refunded" payment status,
 * and the drivers, deliveries and activity_logs tables.
 * It runs once; afterwards a single settings row ("schema_delivery_v1") marks it as done,
 * so normal page loads only pay for one tiny SELECT.
 */
function buildsmart_migrate_delivery(PDO $pdo): void {
    try {
        $done = $pdo->query("SELECT setting_value FROM buildsmart_settings WHERE setting_key='schema_delivery_v1'")->fetchColumn();
        if ($done) return;
    } catch (PDOException $e) {
        // buildsmart_settings does not exist yet on very old installs; it is created below.
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS buildsmart_settings (
        setting_key VARCHAR(80) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB");

    $colType = function (string $table, string $col) use ($pdo): string {
        $s = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $s->execute([$table, $col]);
        return (string)$s->fetchColumn();
    };

    if (!str_contains($colType('orders', 'order_status'), 'Out for Delivery')) {
        $pdo->exec("ALTER TABLE orders MODIFY order_status ENUM('Pending','Confirmed','Preparing','Ready for Delivery','Out for Delivery','Completed','Cancelled') NOT NULL DEFAULT 'Pending'");
    }
    if (!str_contains($colType('payments', 'payment_status'), 'Refunded')) {
        $pdo->exec("ALTER TABLE payments MODIFY payment_status ENUM('Pending','Paid','Failed','Refunded') NOT NULL DEFAULT 'Pending'");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS drivers (
        driver_id INT AUTO_INCREMENT PRIMARY KEY,
        driver_name VARCHAR(120) NOT NULL,
        phone VARCHAR(40) NULL,
        vehicle VARCHAR(80) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS deliveries (
        delivery_id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        driver_id INT NULL,
        status ENUM('Assigned','Out for Delivery','Delivered','Failed','Cancelled') NOT NULL DEFAULT 'Assigned',
        scheduled_date DATE NOT NULL,
        dispatched_at DATETIME NULL,
        delivered_at DATETIME NULL,
        received_by VARCHAR(120) NULL,
        cod_collected DECIMAL(12,2) NULL,
        failure_reason VARCHAR(255) NULL,
        notes VARCHAR(255) NULL,
        assigned_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_deliveries_order (order_id),
        INDEX idx_deliveries_status_date (status, scheduled_date),
        CONSTRAINT fk_deliveries_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
        CONSTRAINT fk_deliveries_driver FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE SET NULL,
        CONSTRAINT fk_deliveries_admin FOREIGN KEY (assigned_by) REFERENCES administrators(admin_id) ON DELETE SET NULL
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
        log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NULL,
        admin_name VARCHAR(120) NULL,
        action VARCHAR(60) NOT NULL,
        entity VARCHAR(40) NOT NULL,
        entity_id INT NULL,
        details VARCHAR(500) NULL,
        ip VARCHAR(45) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_activity_created (created_at),
        INDEX idx_activity_action (action)
    ) ENGINE=InnoDB");

    $pdo->exec("INSERT INTO buildsmart_settings(setting_key,setting_value) VALUES('schema_delivery_v1','1') ON DUPLICATE KEY UPDATE setting_value='1'");
}
