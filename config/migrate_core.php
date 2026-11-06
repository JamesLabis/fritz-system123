<?php
/* One-time, non-destructive upgrades (carts, product image columns, login lockout, account flags). */
function buildsmart_migrate_core(PDO $pdo): void {
    // Bump the version string whenever you add a step below.
    $version = 'core_v7';
    try {
        $done = $pdo->query("SELECT setting_value FROM buildsmart_settings WHERE setting_key='schema_core'")->fetchColumn();
        if ($done === $version) return;
    } catch (PDOException $e) { /* settings table is created by migrate_delivery on very old installs */ }

    // Ensure customer cart tables exist on older installations too. This is
    // intentionally non-destructive: existing carts and cart items are kept.
    $pdo->exec("CREATE TABLE IF NOT EXISTS carts (
        cart_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_carts_user (user_id),
        CONSTRAINT fk_carts_user_upgrade FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cart_items (
        cart_item_id INT AUTO_INCREMENT PRIMARY KEY,
        cart_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        UNIQUE KEY uq_cart_product (cart_id, product_id),
        CONSTRAINT fk_cartitems_cart_upgrade FOREIGN KEY (cart_id) REFERENCES carts(cart_id) ON DELETE CASCADE,
        CONSTRAINT fk_cartitems_product_upgrade FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");
    // Merge legacy duplicate carts into the oldest cart for each customer.
    $duplicateUsers = $pdo->query("SELECT user_id, MIN(cart_id) keep_id FROM carts GROUP BY user_id HAVING COUNT(*) > 1")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($duplicateUsers as $dupe) {
        $userId = (int)$dupe['user_id']; $keepId = (int)$dupe['keep_id'];
        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare('SELECT cart_id FROM carts WHERE user_id=? AND cart_id<>? FOR UPDATE');
            $find->execute([$userId, $keepId]);
            foreach ($find->fetchAll(PDO::FETCH_COLUMN) as $oldCartId) {
                $merge = $pdo->prepare('INSERT INTO cart_items(cart_id,product_id,quantity) SELECT ?,product_id,quantity FROM cart_items WHERE cart_id=? ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)');
                $merge->execute([$keepId, (int)$oldCartId]);
                $del = $pdo->prepare('DELETE FROM carts WHERE cart_id=?'); $del->execute([(int)$oldCartId]);
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    if (!$pdo->query("SHOW INDEX FROM carts WHERE Key_name='uq_carts_user'")->fetch()) {
        $pdo->exec('ALTER TABLE carts ADD UNIQUE KEY uq_carts_user (user_id)');
    }
    // Auto-upgrade older databases: add the product image column if missing.
    if (!$pdo->query("SHOW COLUMNS FROM products LIKE 'image'")->fetch()) {
        $pdo->exec('ALTER TABLE products ADD image VARCHAR(255) NULL AFTER unit');
    }
    // Upgrade existing installations for accessible image descriptions and an optional second view.
    if (!$pdo->query("SHOW COLUMNS FROM products LIKE 'image_alt'")->fetch()) {
        $pdo->exec('ALTER TABLE products ADD image_alt VARCHAR(255) NULL AFTER image');
    }
    if (!$pdo->query("SHOW COLUMNS FROM products LIKE 'detail_image'")->fetch()) {
        $pdo->exec('ALTER TABLE products ADD detail_image VARCHAR(255) NULL AFTER image_alt');
    }
    if (!$pdo->query("SHOW COLUMNS FROM products LIKE 'detail_image_alt'")->fetch()) {
        $pdo->exec('ALTER TABLE products ADD detail_image_alt VARCHAR(255) NULL AFTER detail_image');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        attempt_id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(150) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_la_email (email, attempted_at),
        INDEX idx_la_ip (ip, attempted_at)
    ) ENGINE=InnoDB");
    if (!$pdo->query("SHOW COLUMNS FROM administrators LIKE 'must_change_password'")->fetch()) {
        // Existing admins that still use the shipped default password are forced to change it.
        $pdo->exec('ALTER TABLE administrators ADD must_change_password TINYINT(1) NOT NULL DEFAULT 0');
        foreach ($pdo->query('SELECT admin_id,password FROM administrators')->fetchAll() as $a) {
            if (password_verify('admin123', $a['password'])) $pdo->prepare('UPDATE administrators SET must_change_password=1 WHERE admin_id=?')->execute([$a['admin_id']]);
        }
    }
    if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'is_active'")->fetch()) {
        $pdo->exec('ALTER TABLE users ADD is_active TINYINT(1) NOT NULL DEFAULT 1');
    }
    // Structured delivery address (street / barangay / city). users.address stays as the combined text used on orders.
    foreach ([['street','VARCHAR(150)'],['barangay','VARCHAR(100)'],['city','VARCHAR(100)']] as [$col,$def]) {
        if (!$pdo->query("SHOW COLUMNS FROM users LIKE '$col'")->fetch()) $pdo->exec("ALTER TABLE users ADD $col $def NULL");
    }
    // core_v4: province, saved addresses, per-order recipient / landmark, city and barangay lists, delivery zones.
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_zones (
        zone_id INT AUTO_INCREMENT PRIMARY KEY,
        zone_name VARCHAR(80) NOT NULL UNIQUE,
        fee DECIMAL(10,2) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cities (
        city_id INT AUTO_INCREMENT PRIMARY KEY,
        province VARCHAR(60) NOT NULL,
        city VARCHAR(100) NOT NULL,
        zone_id INT NULL,
        UNIQUE KEY uq_city (province, city),
        CONSTRAINT fk_cities_zone FOREIGN KEY (zone_id) REFERENCES delivery_zones(zone_id) ON DELETE SET NULL
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS barangays (
        barangay_id INT AUTO_INCREMENT PRIMARY KEY,
        city_id INT NOT NULL,
        barangay VARCHAR(100) NOT NULL,
        UNIQUE KEY uq_barangay (city_id, barangay),
        CONSTRAINT fk_barangays_city FOREIGN KEY (city_id) REFERENCES cities(city_id) ON DELETE CASCADE
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_addresses (
        address_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        label VARCHAR(40) NULL,
        recipient_name VARCHAR(120) NOT NULL,
        recipient_phone VARCHAR(20) NOT NULL,
        province VARCHAR(60) NOT NULL,
        city VARCHAR(100) NOT NULL,
        barangay VARCHAR(100) NOT NULL,
        house_no VARCHAR(30) NULL,
        street VARCHAR(150) NOT NULL,
        landmark VARCHAR(200) NULL,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ua_user (user_id),
        CONSTRAINT fk_ua_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) $cs");
    if (!$pdo->query("SHOW COLUMNS FROM user_addresses LIKE 'house_no'")->fetch()) $pdo->exec('ALTER TABLE user_addresses ADD house_no VARCHAR(30) NULL AFTER barangay');
    foreach ([['delivery_province','VARCHAR(60)'],['delivery_house_no','VARCHAR(30)'],['delivery_city','VARCHAR(100)'],['delivery_barangay','VARCHAR(100)'],['delivery_street','VARCHAR(150)'],
              ['delivery_landmark','VARCHAR(200)'],['recipient_name','VARCHAR(120)'],['recipient_phone','VARCHAR(20)'],['delivery_zone','VARCHAR(80)']] as [$col,$def]) {
        if (!$pdo->query("SHOW COLUMNS FROM orders LIKE '$col'")->fetch()) $pdo->exec("ALTER TABLE orders ADD $col $def NULL");
    }
    // First time only: starter city / barangay lists and one delivery zone priced like the standard fee (nothing changes until you edit it).
    if ((int)$pdo->query('SELECT COUNT(*) FROM cities')->fetchColumn() === 0) {
        $seed = require __DIR__ . '/places_seed.php';
        $pdo->beginTransaction();
        try {
        $std = $pdo->query("SELECT setting_value FROM buildsmart_settings WHERE setting_key='delivery_fee'")->fetchColumn();
        $pdo->prepare('INSERT INTO delivery_zones(zone_name,fee) VALUES(?,?)')->execute(['Metro Cebu', $std !== false ? (float)$std : 100]);
        $zoneId = (int)$pdo->lastInsertId();
        $insCity = $pdo->prepare('INSERT INTO cities(province,city,zone_id) VALUES(?,?,?)');
        $cityIds = [];
        foreach ($seed['cities'] as $c) { $insCity->execute([$seed['province'], $c, in_array($c, $seed['metro_zone'], true) ? $zoneId : null]); $cityIds[$c] = (int)$pdo->lastInsertId(); }
        $insB = $pdo->prepare('INSERT IGNORE INTO barangays(city_id,barangay) VALUES(?,?)');
        foreach ($seed['barangays'] as $c => $list) foreach ($list as $b) $insB->execute([$cityIds[$c], $b]);
        $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    // Customers who already had a structured address (core_v3) keep it as their saved default. Province was not asked before: assume Cebu.
    $pdo->exec("INSERT INTO user_addresses(user_id,label,recipient_name,recipient_phone,province,city,barangay,street,is_default)
                SELECT u.user_id,'Home',u.full_name,LEFT(COALESCE(u.phone,''),20),'Cebu',u.city,u.barangay,u.street,1
                FROM users u WHERE u.street IS NOT NULL AND u.street<>'' AND u.city IS NOT NULL AND u.barangay IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM user_addresses a WHERE a.user_id=u.user_id)");
    foreach ([['orders','idx_orders_user_date','(user_id, order_date)'],['orders','idx_orders_status','(order_status)'],['order_items','idx_oi_order','(order_id)']] as [$t,$i,$c]) {
        if (!$pdo->query("SHOW INDEX FROM $t WHERE Key_name='$i'")->fetch()) $pdo->exec("ALTER TABLE $t ADD INDEX $i $c");
    }
    // core_v6: review moderation, and indexes that the foreign keys do not already provide.
    if ($pdo->query("SHOW TABLES LIKE 'product_reviews'")->fetch() && !$pdo->query("SHOW COLUMNS FROM product_reviews LIKE 'is_hidden'")->fetch()) {
        $pdo->exec('ALTER TABLE product_reviews ADD is_hidden TINYINT(1) NOT NULL DEFAULT 0');
    }
    foreach ([['products','category_id','idx_products_category'],['inventory_transactions','product_id','idx_inv_product']] as [$t,$col,$i]) {
        // InnoDB already builds an index for a foreign key column, so only add one when none starts with this column.
        if (!$pdo->query("SHOW INDEX FROM $t WHERE Column_name='$col' AND Seq_in_index=1")->fetch()) $pdo->exec("ALTER TABLE $t ADD INDEX $i ($col)");
    }
    // core_v7: refund records (partial refunds) and an optional product cost price for profit reports.
    $pdo->exec("CREATE TABLE IF NOT EXISTS refunds (
        refund_id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        admin_id INT NULL,
        amount DECIMAL(12,2) NOT NULL,
        method VARCHAR(40) NOT NULL,
        reference VARCHAR(150) NULL,
        reason VARCHAR(255) NULL,
        refunded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_refunds_order (order_id),
        CONSTRAINT fk_refunds_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
        CONSTRAINT fk_refunds_admin FOREIGN KEY (admin_id) REFERENCES administrators(admin_id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
    if (!$pdo->query("SHOW COLUMNS FROM products LIKE 'cost_price'")->fetch()) {
        $pdo->exec('ALTER TABLE products ADD cost_price DECIMAL(12,2) NULL AFTER price');
    }
    // Orders that were already cancelled with a full refund get a matching refund record, so totals add up.
    $pdo->exec("INSERT INTO refunds(order_id,amount,method,reason)
                SELECT p.order_id,p.amount,'Not recorded','Refund marked before refund records existed'
                FROM payments p WHERE p.payment_status='Refunded' AND NOT EXISTS (SELECT 1 FROM refunds r WHERE r.order_id=p.order_id)");
    $pdo->prepare('INSERT INTO buildsmart_settings(setting_key,setting_value) VALUES(\'schema_core\',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$version]);
}
