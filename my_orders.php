<?php
require_once __DIR__.'/config/config.php';require_once __DIR__.'/includes/functions.php';require_login();if(!current_user_id())redirect('/login.php');
$s=$pdo->prepare('SELECT o.*,p.payment_method,p.payment_status,r.receipt_number FROM orders o LEFT JOIN payments p ON p.order_id=o.order_id LEFT JOIN receipts r ON r.order_id=o.order_id WHERE o.user_id=? ORDER BY o.order_date DESC');$s->execute([current_user_id()]);$orders=$s->fetchAll();
$pageTitle='My Orders';require __DIR__.'/includes/header.php';
?>
<div class="page-head"><div><h1>My Orders</h1><p class="muted">View your orders and fulfillment progress.</p></div></div>
<?php if(!$orders): ?><div class="card empty state-empty"><?=empty_art('No orders yet')?><h2>No orders yet</h2><p>Your placed orders and tracking updates will appear here.</p><a class="btn btn-primary" href="<?=BASE_URL?>/">Start shopping</a></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Order</th><th>Date</th><th>Total</th><th>Payment</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($orders as $o): ?><tr><td>#<?= $o['order_id'] ?></td><td><?= e($o['order_date']) ?></td><td><?= money($o['total_amount']) ?></td><td><?= e($o['payment_method']) ?><div class="small muted"><?= e($o['payment_status']) ?></div></td><td><span class="pill <?= pill_class($o['order_status']) ?>"><?= e($o['order_status']) ?></span></td><td><a href="<?= BASE_URL ?>/order_detail.php?id=<?= $o['order_id'] ?>">View details</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php require __DIR__.'/includes/footer.php'; ?>
