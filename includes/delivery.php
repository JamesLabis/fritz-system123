<?php
/*
 * BuildSmart — order status rules, delivery workflow and audit log helpers.
 *
 * Every order-status change in the system goes through change_order_status(),
 * so stock returns, payment updates, status history, customer notifications and
 * the audit log always stay consistent.
 */

/** All order statuses in workflow order. */
function order_statuses(): array {
    return ['Pending', 'Confirmed', 'Preparing', 'Ready for Delivery', 'Out for Delivery', 'Completed', 'Cancelled'];
}

/** Which status an order may move to from each status. */
function order_transitions(): array {
    return [
        'Pending'            => ['Confirmed', 'Cancelled'],
        'Confirmed'          => ['Preparing', 'Cancelled'],
        'Preparing'          => ['Ready for Delivery', 'Cancelled'],
        'Ready for Delivery' => ['Out for Delivery', 'Cancelled'],
        // A failed delivery sends the order back to "Ready for Delivery" to be rescheduled.
        'Out for Delivery'   => ['Completed', 'Ready for Delivery', 'Cancelled'],
        'Completed'          => [],
        'Cancelled'          => [],
    ];
}

/**
 * Statuses an admin can pick by hand on the Orders page. The delivery steps
 * (Ready / Out for Delivery / Completed) are done from Delivery Management,
 * because they need a driver, a date and proof of delivery.
 */
function manual_status_options(string $current): array {
    $allowed = order_transitions()[$current] ?? [];
    return array_values(array_intersect($allowed, ['Confirmed', 'Preparing', 'Cancelled']));
}

/** Run $fn inside a transaction (re-uses the caller's transaction if one is open). */
function run_in_transaction(PDO $pdo, callable $fn) {
    if ($pdo->inTransaction()) return $fn();
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Write one row to the audit log. Never throws: logging must not break the action itself. */
function log_activity(PDO $pdo, string $action, string $entity, ?int $entityId, string $details = '', ?int $adminId = null, ?string $adminName = null): void {
    try {
        $adminId   = $adminId   ?? (isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null);
        $adminName = $adminName ?? ($_SESSION['display_name'] ?? null);
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $pdo->prepare('INSERT INTO activity_logs(admin_id,admin_name,action,entity,entity_id,details,ip) VALUES(?,?,?,?,?,?,?)')
            ->execute([$adminId, $adminName, $action, $entity, $entityId, mb_substr($details, 0, 500), $ip]);
    } catch (Throwable $e) {
        error_log('BuildSmart activity log failed: ' . $e->getMessage());
    }
}

function notify_customer(PDO $pdo, int $userId, ?int $orderId, string $title, string $message): void {
    $pdo->prepare('INSERT INTO customer_notifications(user_id,order_id,title,message) VALUES(?,?,?,?)')
        ->execute([$userId, $orderId, $title, mb_substr($message, 0, 500)]);
}

/**
 * The one place an order's status is changed.
 *
 * $opts: 'message' => custom customer notification, 'refund' => true to cancel an already-paid order
 * (marks the payment Refunded). Throws Exception with a readable message when the change is not allowed.
 */
function change_order_status(PDO $pdo, int $orderId, string $newStatus, string $remarks = '', array $opts = []): void {
    $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
    run_in_transaction($pdo, function () use ($pdo, $orderId, $newStatus, $remarks, $opts, $adminId) {
        $s = $pdo->prepare('SELECT o.order_status,o.user_id,p.payment_method,p.payment_status,p.amount pay_amount
                            FROM orders o LEFT JOIN payments p ON p.order_id=o.order_id
                            WHERE o.order_id=? FOR UPDATE');
        $s->execute([$orderId]);
        $cur = $s->fetch();
        if (!$cur) throw new UserFacingException('Order not found.');

        $old = $cur['order_status'];
        if (!in_array($newStatus, order_statuses(), true)) throw new UserFacingException('Unknown order status.');
        if ($old === $newStatus) throw new UserFacingException('The order is already ' . $old . '.');
        if (!in_array($newStatus, order_transitions()[$old] ?? [], true)) {
            throw new UserFacingException('An order that is ' . $old . ' cannot be changed to ' . $newStatus . '.');
        }

        $method = $cur['payment_method'];
        $pay    = $cur['payment_status'];

        // Online payments are only a typed reference, so an admin must verify them before work starts.
        if ($newStatus === 'Preparing' && $method === 'Online Payment' && $pay !== 'Paid') {
            throw new UserFacingException('Verify the online payment before preparing this order.');
        }

        if ($newStatus === 'Cancelled') {
            if ($pay === 'Paid' && empty($opts['refund'])) {
                throw new UserFacingException('This order is already paid. Tick "Mark payment as refunded" to cancel it.');
            }
            // Return the reserved stock exactly once (cancelled orders can never change again).
            $items = $pdo->prepare('SELECT product_id,quantity FROM order_items WHERE order_id=?');
            $items->execute([$orderId]);
            $back = $pdo->prepare('UPDATE products SET stock_quantity=stock_quantity+? WHERE product_id=?');
            $inv  = $pdo->prepare("INSERT INTO inventory_transactions(product_id,admin_id,transaction_type,quantity_changed,remarks) VALUES(?,?,'Adjustment',?,?)");
            foreach ($items->fetchAll() as $it) {
                $back->execute([$it['quantity'], $it['product_id']]);
                $inv->execute([$it['product_id'], $adminId, $it['quantity'], 'Stock returned: order #' . $orderId . ' cancelled']);
            }
            if ($pay === 'Paid') {
                // Record whatever has not been refunded yet, so the refund history always adds up to the payment.
                $done = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM refunds WHERE order_id=?');
                $done->execute([$orderId]);
                $left = round((float)$cur['pay_amount'] - (float)$done->fetchColumn(), 2);
                if ($left > 0) {
                    $pdo->prepare('INSERT INTO refunds(order_id,admin_id,amount,method,reference,reason) VALUES(?,?,?,?,?,?)')
                        ->execute([$orderId, $adminId, $left, 'Not recorded', null, 'Order cancelled: ' . mb_substr($remarks, 0, 200)]);
                }
                $pdo->prepare("UPDATE payments SET payment_status='Refunded' WHERE order_id=?")->execute([$orderId]);
            } else {
                $pdo->prepare("UPDATE payments SET payment_status='Failed' WHERE order_id=? AND payment_status='Pending'")->execute([$orderId]);
            }
            // Close any delivery that is still open for this order.
            $pdo->prepare("UPDATE deliveries SET status='Cancelled', notes=CONCAT(COALESCE(notes,''),' [order cancelled]') WHERE order_id=? AND status IN ('Assigned','Out for Delivery')")->execute([$orderId]);
        }

        if ($newStatus === 'Completed' && $method === 'Cash on Delivery' && $pay === 'Pending') {
            $pdo->prepare("UPDATE payments SET payment_status='Paid',payment_date=NOW() WHERE order_id=? AND payment_status='Pending'")->execute([$orderId]);
        }

        $pdo->prepare('UPDATE orders SET order_status=? WHERE order_id=?')->execute([$newStatus, $orderId]);
        $pdo->prepare('INSERT INTO order_status_history(order_id,admin_id,status,remarks) VALUES(?,?,?,?)')
            ->execute([$orderId, $adminId, $newStatus, $remarks]);

        $defaults = [
            'Confirmed'          => 'Your order #%d has been confirmed.',
            'Preparing'          => 'Your order #%d is being prepared.',
            'Ready for Delivery' => 'Your order #%d is packed and ready for delivery.',
            'Out for Delivery'   => 'Your order #%d is out for delivery.',
            'Completed'          => 'Your order #%d has been delivered. Thank you for shopping with BuildSmart!',
            'Cancelled'          => 'Your order #%d has been cancelled.',
        ];
        $message = $opts['message'] ?? sprintf($defaults[$newStatus], $orderId) . ($remarks !== '' ? ' Note: ' . $remarks : '');
        notify_customer($pdo, (int)$cur['user_id'], $orderId, 'Order status updated', $message);

        log_activity($pdo, 'order_status', 'order', $orderId, $old . ' → ' . $newStatus . ($remarks !== '' ? ' (' . $remarks . ')' : '') . (!empty($opts['refund']) ? ' [payment refunded]' : ''));
    });
}

/** Admin confirms an online payment (the system never charges a card itself). */
function verify_online_payment(PDO $pdo, int $orderId, string $reference): void {
    run_in_transaction($pdo, function () use ($pdo, $orderId, $reference) {
        $s = $pdo->prepare('SELECT o.order_status,o.user_id,p.payment_method,p.payment_status
                            FROM orders o JOIN payments p ON p.order_id=o.order_id WHERE o.order_id=? FOR UPDATE');
        $s->execute([$orderId]);
        $r = $s->fetch();
        if (!$r) throw new UserFacingException('Order not found.');
        if ($r['payment_method'] !== 'Online Payment') throw new UserFacingException('Only online payments need verification.');
        if ($r['payment_status'] !== 'Pending') throw new UserFacingException('This payment is already ' . $r['payment_status'] . '.');
        if (in_array($r['order_status'], ['Cancelled', 'Completed'], true)) throw new UserFacingException('This order is already ' . $r['order_status'] . '.');
        if ($reference === '') throw new UserFacingException('Enter the transaction reference you verified.');

        $pdo->prepare("UPDATE payments SET payment_status='Paid',payment_date=NOW(),transaction_reference=? WHERE order_id=?")->execute([$reference, $orderId]);
        notify_customer($pdo, (int)$r['user_id'], $orderId, 'Payment confirmed', 'We confirmed your online payment for order #' . $orderId . '.');
        log_activity($pdo, 'payment_verified', 'order', $orderId, 'Online payment verified, ref ' . $reference);
    });
}

/* ------------------------------------------------------------------ */
/* Delivery workflow                                                   */
/* ------------------------------------------------------------------ */

function delivery_statuses(): array {
    return ['Assigned', 'Out for Delivery', 'Delivered', 'Failed', 'Cancelled'];
}

function valid_delivery_date(string $date): bool {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date && $date >= date('Y-m-d');
}

function fetch_active_driver(PDO $pdo, int $driverId): array {
    $s = $pdo->prepare('SELECT * FROM drivers WHERE driver_id=? AND is_active=1');
    $s->execute([$driverId]);
    $d = $s->fetch();
    if (!$d) throw new UserFacingException('Choose an active driver.');
    return $d;
}

/** Latest open (Assigned / Out for Delivery) delivery for an order, with driver details. */
function open_delivery(PDO $pdo, int $orderId): ?array {
    $s = $pdo->prepare("SELECT d.*,dr.driver_name,dr.phone driver_phone,dr.vehicle
                        FROM deliveries d LEFT JOIN drivers dr ON dr.driver_id=d.driver_id
                        WHERE d.order_id=? AND d.status IN ('Assigned','Out for Delivery')
                        ORDER BY d.delivery_id DESC LIMIT 1");
    $s->execute([$orderId]);
    return $s->fetch() ?: null;
}

/** Most recent delivery attempt for an order (any status), for display. */
function latest_delivery(PDO $pdo, int $orderId): ?array {
    $s = $pdo->prepare("SELECT d.*,dr.driver_name,dr.phone driver_phone,dr.vehicle
                        FROM deliveries d LEFT JOIN drivers dr ON dr.driver_id=d.driver_id
                        WHERE d.order_id=? AND d.status<>'Cancelled'
                        ORDER BY d.delivery_id DESC LIMIT 1");
    $s->execute([$orderId]);
    return $s->fetch() ?: null;
}

function lock_delivery(PDO $pdo, int $deliveryId): array {
    $s = $pdo->prepare('SELECT * FROM deliveries WHERE delivery_id=? FOR UPDATE');
    $s->execute([$deliveryId]);
    $d = $s->fetch();
    if (!$d) throw new UserFacingException('Delivery not found.');
    return $d;
}

/** Assign a driver and date to an order that is Preparing (or Ready after a failed attempt). */
function assign_delivery(PDO $pdo, int $orderId, int $driverId, string $date, string $notes = ''): int {
    if (!valid_delivery_date($date)) throw new UserFacingException('Choose a delivery date that is today or later.');
    return run_in_transaction($pdo, function () use ($pdo, $orderId, $driverId, $date, $notes) {
        $s = $pdo->prepare('SELECT o.order_status,o.user_id,p.payment_method,p.payment_status
                            FROM orders o LEFT JOIN payments p ON p.order_id=o.order_id WHERE o.order_id=? FOR UPDATE');
        $s->execute([$orderId]);
        $o = $s->fetch();
        if (!$o) throw new UserFacingException('Order not found.');
        if (!in_array($o['order_status'], ['Preparing', 'Ready for Delivery'], true)) {
            throw new UserFacingException('Only orders that are Preparing can be assigned for delivery (this one is ' . $o['order_status'] . ').');
        }
        if ($o['payment_method'] === 'Online Payment' && $o['payment_status'] !== 'Paid') {
            throw new UserFacingException('Verify the online payment before assigning delivery.');
        }
        if (open_delivery($pdo, $orderId)) throw new UserFacingException('This order already has an open delivery.');
        $driver = fetch_active_driver($pdo, $driverId);

        $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
        $pdo->prepare("INSERT INTO deliveries(order_id,driver_id,status,scheduled_date,notes,assigned_by) VALUES(?,?,'Assigned',?,?,?)")
            ->execute([$orderId, $driverId, $date, $notes !== '' ? $notes : null, $adminId]);
        $deliveryId = (int)$pdo->lastInsertId();

        $msg = 'Your order #' . $orderId . ' is scheduled for delivery on ' . date('M j, Y', strtotime($date)) . ' with ' . $driver['driver_name'] . '.';
        if ($o['order_status'] === 'Preparing') {
            change_order_status($pdo, $orderId, 'Ready for Delivery', 'Delivery assigned to ' . $driver['driver_name'] . ' for ' . $date, ['message' => $msg]);
        } else {
            notify_customer($pdo, (int)$o['user_id'], $orderId, 'Delivery rescheduled', $msg);
        }
        log_activity($pdo, 'delivery_assign', 'delivery', $deliveryId, 'Order #' . $orderId . ' → ' . $driver['driver_name'] . ' on ' . $date);
        return $deliveryId;
    });
}

/** Change driver / date / notes while the delivery is still only Assigned. */
function update_delivery(PDO $pdo, int $deliveryId, int $driverId, string $date, string $notes = ''): void {
    if (!valid_delivery_date($date)) throw new UserFacingException('Choose a delivery date that is today or later.');
    run_in_transaction($pdo, function () use ($pdo, $deliveryId, $driverId, $date, $notes) {
        $d = lock_delivery($pdo, $deliveryId);
        if ($d['status'] !== 'Assigned') throw new UserFacingException('Only deliveries that are still Assigned can be edited.');
        $driver = fetch_active_driver($pdo, $driverId);
        $pdo->prepare('UPDATE deliveries SET driver_id=?,scheduled_date=?,notes=? WHERE delivery_id=?')
            ->execute([$driverId, $date, $notes !== '' ? $notes : null, $deliveryId]);
        $u = $pdo->prepare('SELECT user_id FROM orders WHERE order_id=?');
        $u->execute([$d['order_id']]);
        notify_customer($pdo, (int)$u->fetchColumn(), (int)$d['order_id'], 'Delivery updated',
            'Your order #' . $d['order_id'] . ' delivery is now scheduled for ' . date('M j, Y', strtotime($date)) . ' with ' . $driver['driver_name'] . '.');
        log_activity($pdo, 'delivery_update', 'delivery', $deliveryId, 'Order #' . $d['order_id'] . ' → ' . $driver['driver_name'] . ' on ' . $date);
    });
}

/** Driver leaves with the order: delivery and order become "Out for Delivery". */
function dispatch_delivery(PDO $pdo, int $deliveryId): void {
    run_in_transaction($pdo, function () use ($pdo, $deliveryId) {
        $d = lock_delivery($pdo, $deliveryId);
        if ($d['status'] !== 'Assigned') throw new UserFacingException('Only an Assigned delivery can be dispatched.');
        $s = $pdo->prepare('SELECT dr.driver_name,dr.phone FROM drivers dr WHERE dr.driver_id=?');
        $s->execute([$d['driver_id']]);
        $dr = $s->fetch() ?: ['driver_name' => 'our driver', 'phone' => ''];
        $pdo->prepare("UPDATE deliveries SET status='Out for Delivery',dispatched_at=NOW() WHERE delivery_id=?")->execute([$deliveryId]);

        $p = $pdo->prepare('SELECT o.total_amount,p.payment_method,p.payment_status FROM orders o LEFT JOIN payments p ON p.order_id=o.order_id WHERE o.order_id=?');
        $p->execute([$d['order_id']]);
        $info = $p->fetch();
        $msg = 'Your order #' . $d['order_id'] . ' is out for delivery with ' . $dr['driver_name'] . ($dr['phone'] ? ' (' . $dr['phone'] . ')' : '') . '.';
        if ($info && $info['payment_method'] === 'Cash on Delivery' && $info['payment_status'] === 'Pending') {
            $msg .= ' Please prepare ' . money($info['total_amount']) . ' in cash.';
        }
        change_order_status($pdo, (int)$d['order_id'], 'Out for Delivery', 'Dispatched with ' . $dr['driver_name'], ['message' => $msg]);
        log_activity($pdo, 'delivery_dispatch', 'delivery', $deliveryId, 'Order #' . $d['order_id'] . ' out for delivery');
    });
}

/** Proof of delivery: completes the order (and marks Cash on Delivery as paid). */
function complete_delivery(PDO $pdo, int $deliveryId, string $receivedBy, ?float $codCollected): void {
    if ($receivedBy === '') throw new UserFacingException('Enter the name of the person who received the order.');
    run_in_transaction($pdo, function () use ($pdo, $deliveryId, $receivedBy, $codCollected) {
        $d = lock_delivery($pdo, $deliveryId);
        if ($d['status'] !== 'Out for Delivery') throw new UserFacingException('Only a delivery that is Out for Delivery can be marked delivered.');

        $p = $pdo->prepare('SELECT o.total_amount,p.payment_method,p.payment_status FROM orders o LEFT JOIN payments p ON p.order_id=o.order_id WHERE o.order_id=? FOR UPDATE');
        $p->execute([$d['order_id']]);
        $info = $p->fetch();
        $collected = null;
        if ($info && $info['payment_method'] === 'Cash on Delivery' && $info['payment_status'] === 'Pending') {
            if ($codCollected === null || abs($codCollected - (float)$info['total_amount']) > 0.005) {
                throw new UserFacingException('Cash collected must equal the order total of ' . money($info['total_amount']) . '.');
            }
            $collected = $codCollected;
        }
        $pdo->prepare("UPDATE deliveries SET status='Delivered',delivered_at=NOW(),received_by=?,cod_collected=? WHERE delivery_id=?")
            ->execute([$receivedBy, $collected, $deliveryId]);
        change_order_status($pdo, (int)$d['order_id'], 'Completed', 'Delivered. Received by ' . $receivedBy);
        log_activity($pdo, 'delivery_complete', 'delivery', $deliveryId, 'Order #' . $d['order_id'] . ' delivered, received by ' . $receivedBy . ($collected !== null ? ', cash ' . number_format($collected, 2) : ''));
    });
}

/** Delivery attempt failed: the order goes back to "Ready for Delivery" to be rescheduled. */
function fail_delivery(PDO $pdo, int $deliveryId, string $reason): void {
    if ($reason === '') throw new UserFacingException('Enter the reason the delivery failed.');
    run_in_transaction($pdo, function () use ($pdo, $deliveryId, $reason) {
        $d = lock_delivery($pdo, $deliveryId);
        if ($d['status'] !== 'Out for Delivery') throw new UserFacingException('Only a delivery that is Out for Delivery can be marked failed.');
        $pdo->prepare("UPDATE deliveries SET status='Failed',failure_reason=? WHERE delivery_id=?")->execute([mb_substr($reason, 0, 255), $deliveryId]);
        change_order_status($pdo, (int)$d['order_id'], 'Ready for Delivery', 'Delivery attempt failed: ' . $reason,
            ['message' => 'We could not deliver your order #' . $d['order_id'] . ' (' . $reason . '). We will contact you to reschedule.']);
        log_activity($pdo, 'delivery_failed', 'delivery', $deliveryId, 'Order #' . $d['order_id'] . ' failed: ' . $reason);
    });
}

const REFUND_METHODS = ['Cash', 'GCash / e-wallet', 'Bank transfer', 'Original payment method', 'Other'];

/**
 * Record a full or partial refund against a paid order. The payment becomes "Refunded" only when the
 * whole amount has been returned; until then it stays "Paid" and the order page shows what is left.
 */
function record_refund(PDO $pdo, int $orderId, float $amount, string $method, string $reference, string $reason): void {
    $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
    $amount = round($amount, 2);
    if ($amount <= 0) throw new UserFacingException('Enter a refund amount above zero.');
    if (!in_array($method, REFUND_METHODS, true)) throw new UserFacingException('Choose how the money was returned.');
    if (mb_strlen($reference) > 150) throw new UserFacingException('The reference can be at most 150 characters.');
    if ($reason === '' || mb_strlen($reason) > 255) throw new UserFacingException('Give a short reason (up to 255 characters).');
    run_in_transaction($pdo, function () use ($pdo, $orderId, $amount, $method, $reference, $reason, $adminId) {
        $s = $pdo->prepare('SELECT o.user_id,p.payment_status,p.amount FROM orders o JOIN payments p ON p.order_id=o.order_id WHERE o.order_id=? FOR UPDATE');
        $s->execute([$orderId]);
        $row = $s->fetch();
        if (!$row) throw new UserFacingException('Order not found.');
        if ($row['payment_status'] !== 'Paid') throw new UserFacingException('Only a paid order can be refunded (payment is ' . $row['payment_status'] . ').');
        $d = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM refunds WHERE order_id=?');
        $d->execute([$orderId]);
        $left = round((float)$row['amount'] - (float)$d->fetchColumn(), 2);
        if ($amount > $left) throw new UserFacingException('You can refund at most ' . money($left) . ' more on this order.');
        $pdo->prepare('INSERT INTO refunds(order_id,admin_id,amount,method,reference,reason) VALUES(?,?,?,?,?,?)')
            ->execute([$orderId, $adminId, $amount, $method, $reference !== '' ? $reference : null, $reason]);
        $full = abs($left - $amount) < 0.005;
        if ($full) $pdo->prepare("UPDATE payments SET payment_status='Refunded' WHERE order_id=?")->execute([$orderId]);
        notify_customer($pdo, (int)$row['user_id'], $orderId, 'Refund issued', 'A ' . ($full ? 'full' : 'partial') . ' refund of ' . money($amount) . ' was issued for order #' . $orderId . ' (' . $method . ').');
        log_activity($pdo, 'refund', 'order', $orderId, money($amount) . ' via ' . $method . ($reference !== '' ? ' ref ' . $reference : '') . ' - ' . $reason . ($full ? ' [fully refunded]' : ''));
    });
}

/**
 * Change an open order's delivery fee (for example after a corrected address moved it to another zone).
 * The order total, the pending payment amount and the receipt are updated together and the customer is told.
 */
function adjust_delivery_fee(PDO $pdo, int $orderId, float $newFee, string $reason): void {
    $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
    $newFee = round($newFee, 2);
    if ($newFee < 0 || $newFee > 100000) throw new UserFacingException('The delivery fee must be between ₱0.00 and ₱100,000.00.');
    if ($reason === '' || mb_strlen($reason) > 150) throw new UserFacingException('Give a short reason (up to 150 characters).');
    run_in_transaction($pdo, function () use ($pdo, $orderId, $newFee, $reason, $adminId) {
        $s = $pdo->prepare('SELECT o.order_status,o.user_id,o.delivery_fee,o.total_amount,p.payment_status FROM orders o LEFT JOIN payments p ON p.order_id=o.order_id WHERE o.order_id=? FOR UPDATE');
        $s->execute([$orderId]);
        $o = $s->fetch();
        if (!$o) throw new UserFacingException('Order not found.');
        if (in_array($o['order_status'], ['Completed', 'Cancelled'], true)) throw new UserFacingException('A ' . strtolower($o['order_status']) . ' order can no longer be changed.');
        if (($o['payment_status'] ?? 'Pending') !== 'Pending') throw new UserFacingException('This order is already ' . strtolower((string)$o['payment_status']) . '. Record a refund instead of changing the fee.');
        $old = (float)$o['delivery_fee'];
        if (abs($old - $newFee) < 0.005) throw new UserFacingException('The delivery fee is already ' . money($old) . '.');
        $newTotal = round((float)$o['total_amount'] - $old + $newFee, 2);
        $pdo->prepare('UPDATE orders SET delivery_fee=?,total_amount=? WHERE order_id=?')->execute([$newFee, $newTotal, $orderId]);
        $pdo->prepare("UPDATE payments SET amount=? WHERE order_id=? AND payment_status='Pending'")->execute([$newTotal, $orderId]);
        $pdo->prepare('UPDATE receipts SET total_amount=? WHERE order_id=?')->execute([$newTotal, $orderId]);
        $pdo->prepare('INSERT INTO order_status_history(order_id,admin_id,status,remarks) VALUES(?,?,?,?)')
            ->execute([$orderId, $adminId, $o['order_status'], 'Delivery fee changed ' . money($old) . ' → ' . money($newFee) . ': ' . $reason]);
        notify_customer($pdo, (int)$o['user_id'], $orderId, 'Delivery fee updated', 'The delivery fee for order #' . $orderId . ' changed from ' . money($old) . ' to ' . money($newFee) . '. New total: ' . money($newTotal) . '. Reason: ' . $reason);
        log_activity($pdo, 'delivery_fee', 'order', $orderId, money($old) . ' → ' . money($newFee) . ' (' . $reason . ')');
    });
}
