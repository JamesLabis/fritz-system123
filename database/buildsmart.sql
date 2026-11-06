CREATE DATABASE IF NOT EXISTS buildsmart CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE buildsmart;

SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS user_addresses, barangays, cities, delivery_zones, activity_logs, deliveries, drivers, customer_notifications, product_reviews, wishlists, buildsmart_settings, receipts, inventory_transactions, order_status_history, payments, order_items, orders, cart_items, carts, products, categories, administrators, users;
SET FOREIGN_KEY_CHECKS=1;

-- Province / city / barangay lists, delivery zones, saved addresses and the extra orders columns (recipient, landmark,
-- province...) are created automatically by config/migrate_core.php (core_v4) the first time any page loads.
CREATE TABLE users (
 user_id INT AUTO_INCREMENT PRIMARY KEY,
 full_name VARCHAR(120) NOT NULL,
 email VARCHAR(150) NOT NULL UNIQUE,
 password VARCHAR(255) NOT NULL,
 phone VARCHAR(30),
 address TEXT,
 street VARCHAR(150) NULL,
 barangay VARCHAR(100) NULL,
 city VARCHAR(100) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE administrators (
 admin_id INT AUTO_INCREMENT PRIMARY KEY,
 admin_name VARCHAR(120) NOT NULL,
 email VARCHAR(150) NOT NULL UNIQUE,
 password VARCHAR(255) NOT NULL,
 contact_number VARCHAR(30),
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE categories (
 category_id INT AUTO_INCREMENT PRIMARY KEY,
 category_name VARCHAR(100) NOT NULL UNIQUE,
 description TEXT
) ENGINE=InnoDB;

CREATE TABLE products (
 product_id INT AUTO_INCREMENT PRIMARY KEY,
 admin_id INT NULL,
 category_id INT NULL,
 product_name VARCHAR(160) NOT NULL,
 sku VARCHAR(50) NULL,
 description TEXT,
 price DECIMAL(12,2) NOT NULL DEFAULT 0,
 stock_quantity INT NOT NULL DEFAULT 0,
 low_stock_threshold INT NOT NULL DEFAULT 5,
 unit VARCHAR(40) NOT NULL DEFAULT 'piece',
 image VARCHAR(255) NULL,
 image_alt VARCHAR(255) NULL,
 detail_image VARCHAR(255) NULL,
 detail_image_alt VARCHAR(255) NULL,
 date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_products_sku(sku),
 CONSTRAINT fk_products_admin FOREIGN KEY(admin_id) REFERENCES administrators(admin_id) ON DELETE SET NULL,
 CONSTRAINT fk_products_category FOREIGN KEY(category_id) REFERENCES categories(category_id) ON DELETE SET NULL,
 CHECK(price >= 0), CHECK(stock_quantity >= 0)
) ENGINE=InnoDB;

CREATE TABLE carts (
 cart_id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_carts_user (user_id),
 CONSTRAINT fk_carts_user FOREIGN KEY(user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE cart_items (
 cart_item_id INT AUTO_INCREMENT PRIMARY KEY,
 cart_id INT NOT NULL,
 product_id INT NOT NULL,
 quantity INT NOT NULL DEFAULT 1,
 UNIQUE KEY uq_cart_product(cart_id, product_id),
 CONSTRAINT fk_cartitems_cart FOREIGN KEY(cart_id) REFERENCES carts(cart_id) ON DELETE CASCADE,
 CONSTRAINT fk_cartitems_product FOREIGN KEY(product_id) REFERENCES products(product_id) ON DELETE RESTRICT,
 CHECK(quantity > 0)
) ENGINE=InnoDB;

CREATE TABLE orders (
 order_id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
 order_status ENUM('Pending','Confirmed','Preparing','Ready for Delivery','Out for Delivery','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
 delivery_address TEXT NOT NULL,
 CONSTRAINT fk_orders_user FOREIGN KEY(user_id) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE order_items (
 order_item_id INT AUTO_INCREMENT PRIMARY KEY,
 order_id INT NOT NULL,
 product_id INT NOT NULL,
 product_name VARCHAR(160) NOT NULL,
 quantity INT NOT NULL,
 unit_price DECIMAL(12,2) NOT NULL,
 subtotal DECIMAL(12,2) NOT NULL,
 CONSTRAINT fk_orderitems_order FOREIGN KEY(order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
 CONSTRAINT fk_orderitems_product FOREIGN KEY(product_id) REFERENCES products(product_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE payments (
 payment_id INT AUTO_INCREMENT PRIMARY KEY,
 order_id INT NOT NULL UNIQUE,
 payment_method ENUM('Cash on Delivery','Online Payment') NOT NULL,
 amount DECIMAL(12,2) NOT NULL,
 payment_status ENUM('Pending','Paid','Failed','Refunded') NOT NULL DEFAULT 'Pending',
 payment_date DATETIME NULL,
 transaction_reference VARCHAR(150),
 CONSTRAINT fk_payments_order FOREIGN KEY(order_id) REFERENCES orders(order_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE order_status_history (
 history_id INT AUTO_INCREMENT PRIMARY KEY,
 order_id INT NOT NULL,
 admin_id INT NULL,
 status VARCHAR(60) NOT NULL,
 status_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 remarks TEXT,
 CONSTRAINT fk_history_order FOREIGN KEY(order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
 CONSTRAINT fk_history_admin FOREIGN KEY(admin_id) REFERENCES administrators(admin_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE inventory_transactions (
 transaction_id INT AUTO_INCREMENT PRIMARY KEY,
 product_id INT NOT NULL,
 admin_id INT NULL,
 transaction_type ENUM('Stock In','Stock Out','Sale','Adjustment') NOT NULL,
 quantity_changed INT NOT NULL,
 transaction_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 remarks VARCHAR(255),
 CONSTRAINT fk_inventory_product FOREIGN KEY(product_id) REFERENCES products(product_id) ON DELETE RESTRICT,
 CONSTRAINT fk_inventory_admin FOREIGN KEY(admin_id) REFERENCES administrators(admin_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE receipts (
 receipt_id INT AUTO_INCREMENT PRIMARY KEY,
 order_id INT NOT NULL UNIQUE,
 receipt_number VARCHAR(50) NOT NULL UNIQUE,
 issued_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 total_amount DECIMAL(12,2) NOT NULL,
 CONSTRAINT fk_receipts_order FOREIGN KEY(order_id) REFERENCES orders(order_id) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE wishlists (
 wishlist_id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 product_id INT NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_wishlist_user_product(user_id,product_id),
 CONSTRAINT fk_wishlist_user FOREIGN KEY(user_id) REFERENCES users(user_id) ON DELETE CASCADE,
 CONSTRAINT fk_wishlist_product FOREIGN KEY(product_id) REFERENCES products(product_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE product_reviews (
 review_id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 product_id INT NOT NULL,
 order_id INT NOT NULL,
 rating TINYINT NOT NULL,
 review_text TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_review_user_product_order(user_id,product_id,order_id),
 CONSTRAINT fk_review_user FOREIGN KEY(user_id) REFERENCES users(user_id) ON DELETE CASCADE,
 CONSTRAINT fk_review_product FOREIGN KEY(product_id) REFERENCES products(product_id) ON DELETE CASCADE,
 CONSTRAINT fk_review_order FOREIGN KEY(order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
 CHECK(rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE customer_notifications (
 notification_id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 order_id INT NULL,
 title VARCHAR(160) NOT NULL,
 message VARCHAR(500) NOT NULL,
 is_read TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_notifications_user_read(user_id,is_read,created_at),
 CONSTRAINT fk_notification_user FOREIGN KEY(user_id) REFERENCES users(user_id) ON DELETE CASCADE,
 CONSTRAINT fk_notification_order FOREIGN KEY(order_id) REFERENCES orders(order_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE buildsmart_settings (
 setting_key VARCHAR(80) PRIMARY KEY,
 setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB;
INSERT INTO buildsmart_settings(setting_key,setting_value) VALUES
 ('default_low_stock_threshold','5'),('delivery_fee','100'),('free_delivery_minimum','5000'),('schema_delivery_v1','1');

-- Delivery management and audit log
CREATE TABLE drivers (
 driver_id INT AUTO_INCREMENT PRIMARY KEY,
 driver_name VARCHAR(120) NOT NULL,
 phone VARCHAR(40) NULL,
 vehicle VARCHAR(80) NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE deliveries (
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
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
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
) ENGINE=InnoDB;

-- Default administrator: username 'admin' / password 'admin123' (change after first login)
INSERT INTO administrators(admin_name,email,password) VALUES
('BuildSmart Administrator','admin','$2y$10$RHPYEKGxDWEo1rXGKEspn.1UIgRljZ6FbSI5dSMS1fsfZDcHQNp1G');

INSERT INTO categories(category_name,description) VALUES
('Cement','Cement and masonry materials'),
('Steel and Metals','Steel bars, wire and metal products'),
('Paints','Interior and exterior paints'),
('Electrical','Electrical supplies and accessories'),
('Plumbing','Pipes, fittings and plumbing supplies'),
('Tools','Hand tools and construction tools');

INSERT INTO products(product_name,category_id,description,price,stock_quantity,unit) VALUES
('Portland Cement 40kg',1,'General purpose Portland cement',265.00,100,'bag'),
('Deformed Bar 10mm',2,'Steel reinforcement bar',185.00,80,'piece'),
('Latex Wall Paint 4L',3,'Interior wall paint',720.00,35,'pail'),
('Electrical Wire 2.0mm',4,'Copper electrical wire',18.00,500,'meter'),
('PVC Pipe 2 inch',5,'PVC plumbing pipe',145.00,60,'length'),
('Claw Hammer',6,'Steel claw hammer with handle',250.00,25,'piece');

UPDATE products SET sku=CONCAT('BS-',LPAD(product_id,6,'0')) WHERE sku IS NULL OR sku='';

-- Connect the supplied product photos to their matching catalog products.
UPDATE products SET image='portland-cement-40kg.png', image_alt='Portland Cement 40kg product photo' WHERE product_name='Portland Cement 40kg';
UPDATE products SET image='deformed-bar-10mm.jpg', image_alt='Deformed Bar 10mm product photo' WHERE product_name='Deformed Bar 10mm';
UPDATE products SET image='latex-wall-paint-4l.jpg', image_alt='Latex Wall Paint 4L product photo' WHERE product_name='Latex Wall Paint 4L';
UPDATE products SET image='electrical-wire-2-0mm.jpg', image_alt='Electrical Wire 2.0mm product photo' WHERE product_name='Electrical Wire 2.0mm';
UPDATE products SET image='pvc-pipe-2-inch.jpg', image_alt='PVC Pipe 2 inch product photo' WHERE product_name='PVC Pipe 2 inch';
UPDATE products SET image='claw-hammer.jpg', image_alt='Claw Hammer product photo' WHERE product_name='Claw Hammer';

