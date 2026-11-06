# BuildSmart — Online Hardware Sales and Order Management System

A student-project PHP + MySQL application for XAMPP.

## Requirements
- XAMPP with Apache and MySQL
- PHP 8.0+
- A modern browser

## Installation
1. Copy the `BuildSmart` folder into `C:\xampp\htdocs\` (Windows) or the XAMPP `htdocs` directory.
2. Start **Apache** and **MySQL** in XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin`.
4. Import `database/buildsmart.sql`.
5. Edit `config/config.php` if your MySQL username/password differs from the XAMPP defaults.
6. The SQL file already creates the administrator:
   - Account type: **Administrator**
   - Username: `admin`
   - Password: `admin123`
7. Sign in at `http://localhost/BuildSmart/login.php`.
8. You are forced to change the default password on first login.
9. Register a customer account from the Register page to test customer ordering.

## Main URLs
- Home / catalog: `http://localhost/BuildSmart/`
- Login: `http://localhost/BuildSmart/login.php`
- Register: `http://localhost/BuildSmart/register.php`
- Admin dashboard: `http://localhost/BuildSmart/admin/dashboard.php`

## Included functions
- Customer registration and login
- Administrator login and dashboard
- Product categories and product management
- Shopping cart and checkout
- COD and online-payment reference recording
- Orders and order-item snapshots
- Order status history
- Stock-in / stock-out transaction history
- Automatic stock deduction when an order is placed
- Digital receipt page
- Daily and monthly sales reports

## Notes
- The "Online" payment option records the selected method, reference, and payment status. It does not charge a bank card or wallet. Connect a real payment gateway (and verify its server-side webhook) before using this for real transactions.
- Live GPS tracking and physical stock audits are outside the project scope. Delivery assignment (drivers, dispatch, proof of delivery) is included — see `docs/DELIVERY_WORKFLOW.txt`.
- This is a learning/demo application. Change default credentials, use HTTPS, configure backups, and review security before public deployment.

## Restyle & product images
- New "Site Yard" theme (charcoal + safety yellow) across customer and admin pages.
- Every product shows a detailed illustration by category, or a real photo if the admin uploads one (Admin > Products > image field, JPG/PNG/WEBP up to 2 MB, saved in `uploads/`).
- New product detail page (`product.php`) with specs, quantity selector and related items.
- Existing databases are upgraded automatically (an `image` column is added on first load).

## Product photos in `img/product`

The storefront checks the product's saved image filename first, then looks for a
matching product-name or product-ID filename in `img/product/`. Example names:
`Portland-Cement-40kg.jpg`, `12.jpg`, or `product-12.jpg`. JPG, JPEG, PNG,
WebP, and GIF are supported. Admin-uploaded images in `uploads/` continue to
work as before. 


## Cart, Buy Now, inventory, and payment rules

- Add to Cart submits the product form through the footer AJAX handler (`ajax-add-form`).
- Buy Now checks out only the selected product and quantity. It does not add to, replace, or clear the customer's existing cart.
- A customer has one cart (`uq_carts_user`). The startup upgrade merges any legacy duplicate carts before adding the unique index; simultaneous cart creation handles duplicate-key races.
- Zero-stock cart items cannot be assigned a quantity. The customer receives a clear out-of-stock message and can remove the item.
- Stock is reserved (deducted) transactionally when an order is placed, including an online-payment order. Online payments remain Pending until verified; this demo does not process a real payment. Completing a COD order marks it Paid. Completing an online order does not automatically mark it Paid.
- Cancelling a pending order restores reserved stock once. Already-cancelled and completed orders cannot be cancelled again through the admin status flow. Paid orders require refund handling before cancellation.


## Final feature update (November 2026)

The current `database/buildsmart.sql` now includes the feature tables and columns for a fresh installation. If you already have a BuildSmart database with customer/order data, **do not re-import `buildsmart.sql`** because that original setup script recreates tables. Instead, back up your database and import `database/buildsmart_features.sql` once in phpMyAdmin.

### Added features
- Customer wishlist and remove-from-wishlist controls.
- Product ratings and reviews restricted to customers with a completed purchase.
- Configurable low-stock thresholds, dashboard/header alerts, and per-product threshold values.
- Customer notifications when an order is placed or its status is changed by an administrator.
- Existing order status history is retained for customer order tracking.
- Daily, weekly, and monthly sales reporting; CSV exports open in Excel.
- Browser Print / Save as PDF for sales reports.
- Product search by name, description, or SKU; category, stock availability, price range, and sorting filters.
- Delivery fees with free-delivery minimum, configurable under Admin > Feature Settings.
- Unique product SKU codes. Blank SKUs are generated automatically as `BS-000001`, etc.

### Setup for an existing installation
1. Back up your `buildsmart` database.
2. Open phpMyAdmin and select the existing `buildsmart` database.
3. Import `database/buildsmart_features.sql`.
4. Upload/replace the updated project files.
5. Sign in as administrator and open **Feature Settings** to set delivery charges and the default low-stock threshold.
6. In Product Management, set optional custom SKU and per-product low-stock threshold. If SKU is blank, it is generated automatically.

### Reports
Use Admin > Reports. The CSV buttons create Excel-compatible files. For a PDF, choose **Print / Save PDF** and select “Save as PDF” in the browser print dialog.

### Important
The online-payment option remains a record/reference workflow in this project; it does not connect to a real payment gateway. Barcode scanning hardware is not included; SKUs provide unique product codes that can be encoded into barcode labels separately.

## Additional improvements in this update
- Checkout uses a one-time submission token to reject stale/replayed form submissions.
- Customer navigation shows the current unread notification count.
- Wishlist items can be moved directly into the cart, with stock checks and quantity merging.
- Sales Reports now supports a From/To date range and a non-cancelled order summary.

Existing functionality retained: transactional stock deduction, order status timeline, duplicate-review prevention/update, product image lookup with category artwork fallback, and cancellation exclusion from sales.


## Administrator navigation and next development phases

See `ADMIN_DEVELOPMENT_ROADMAP.txt` for the administrator sidebar and the development phases. Delivery Management and Activity Logs are now working modules. Supplier records and purchase orders are still planned.

## Order-to-delivery workflow (new)

Customer places order -> admin confirms (and verifies online payment) -> prepares -> assigns a driver -> dispatches -> records proof of delivery. Failed deliveries can be rescheduled; cancelling returns stock. Full details, rules and screens are in `docs/DELIVERY_WORKFLOW.txt`.

### Upgrading an existing installation (delivery update)
1. Back up your `buildsmart` database and files.
2. Replace the project files with this version.
3. Open any page. The app adds the new order status, the `drivers`, `deliveries` and `activity_logs` tables automatically, once, and keeps all existing data.
   (Alternative: import `database/buildsmart_delivery.sql` once in phpMyAdmin. It is safe to import twice.)
4. Admin > Delivery Management > Manage drivers: add your drivers.
Fresh installations: import `database/buildsmart.sql` as before; it already contains everything.

### Fixed in this update
- **Place Order button stuck on "Placing order…"** — the global double-click guard in `includes/footer.php` locked the button on the first click, before the confirmation popup, so the confirmed submit was blocked. It now ignores submits that another script already cancelled.
- Order statuses can no longer jump (for example Pending -> Completed). Allowed moves are defined in `includes/delivery.php`.
- Online payments must be verified by an admin before the order can be prepared.
- Cancelling a paid order requires marking the payment Refunded; stock is returned once.
- Activity Logs now record logins, failed admin logins, order/payment/delivery actions, driver changes, stock changes, product changes, settings and exports.
- CSV exports neutralise cells that start with `=`, `+`, `-` or `@` (spreadsheet formula injection).

### Still recommended before going live
Change the default `admin` / `admin123` password; add login attempt limiting; use a dedicated MySQL user instead of `root`; enable HTTPS; schedule database and `uploads/` backups.

## Security & reliability update
- Admin login fixed: the sign-in field accepts a username (`admin`) as well as an email.
- Default admin password must be changed at first login (`must_change_password`).
- Login lockout: 5 failed attempts per email (20 per IP) within 15 minutes; customer failures are logged.
- Session cookies are HttpOnly + SameSite=Lax (+ Secure on HTTPS); idle timeout 30 min (admin) / 8 h (customer).
- Logout needs a CSRF token; security headers added; database errors are logged, not shown.
- Schema upgrades moved to `config/migrate_core.php` and run once instead of on every request.
- Customers can cancel their own Pending, unpaid orders; stock is released automatically.
- Admin > Users: reset a customer's password (temporary password shown once) and deactivate/activate accounts.
- Phone number is required at registration (needed for delivery).
- A stale "Buy Now" item can no longer hijack checkout from the cart.
- `cron/release_stale_orders.php` auto-cancels unpaid Pending orders after 24 h (setting `pending_order_hours`).
  Schedule it every 30 minutes with Task Scheduler (Windows) or cron (Linux); see the file header.

## Design update
- Contrast fixes: darker amber for text/hover (`--amber-text`), darker success green, readable placeholders.
- One primary button style everywhere (safety yellow); consistent 12px control / 18px card radii.
- Tokens merged: the `--color-*` set now aliases the main tokens; repeated hard-coded colours replaced with variables.
- Dark mode follows the system setting (force it with `<html data-theme="dark">` or `"light"`).
- Inter web font (falls back to Segoe UI/system fonts when offline).
- New login copy, SVG feature icons, sentence-case labels, and a compact form-first mobile login.
- Admin charts show compact amounts (₱12.5k) with the full value on hover; empty wishlist/notifications states added.
