<?php
require_once __DIR__.'/../config/config.php';require_once __DIR__.'/../includes/functions.php';require_admin();
$q=trim($_GET['q']??'');$products=$orders=$users=[];
if($q!==''){
 $like="%$q%";
 $s=$pdo->prepare('SELECT product_id,product_name,price,stock_quantity,unit FROM products WHERE product_name LIKE ? OR description LIKE ? ORDER BY product_name LIMIT 10');$s->execute([$like,$like]);$products=$s->fetchAll();
 $id=ctype_digit(ltrim($q,'#'))?(int)ltrim($q,'#'):0;
 $s=$pdo->prepare('SELECT o.order_id,o.order_date,o.total_amount,o.order_status,u.full_name FROM orders o JOIN users u ON u.user_id=o.user_id WHERE o.order_id=? OR u.full_name LIKE ? OR u.email LIKE ? ORDER BY o.order_date DESC LIMIT 10');$s->execute([$id,$like,$like]);$orders=$s->fetchAll();
 $s=$pdo->prepare('SELECT user_id,full_name,email,phone FROM users WHERE full_name LIKE ? OR email LIKE ? OR phone LIKE ? ORDER BY full_name LIMIT 10');$s->execute([$like,$like,$like]);$users=$s->fetchAll();
}
$pageTitle='Search results';require __DIR__.'/../includes/header.php';
?>
<div class="page-head"><div><h1>Search results</h1><p class="muted"><?= $q===''?'Type something in the search bar above.':'Results for “'.e($q).'”' ?></p></div></div>
<?php if($q!==''): ?>
<div class="card u-mb-18"><h2>Products <span class="pill pill-warn"><?= count($products) ?></span></h2><div class="table-wrap"><table><thead><tr><th>Product</th><th>Price</th><th>Stock</th><th></th></tr></thead><tbody>
<?php foreach($products as $p): ?><tr><td><?= e($p['product_name']) ?></td><td><?= money($p['price']) ?></td><td><?= (int)$p['stock_quantity'] ?> <?= e($p['unit']) ?></td><td><a href="<?= BASE_URL ?>/admin/products.php?edit=<?= (int)$p['product_id'] ?>">Edit</a></td></tr><?php endforeach; ?>
<?php if(!$products): ?><tr><td colspan="4" class="muted">No matching products.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="card u-mb-18"><h2>Orders <span class="pill pill-warn"><?= count($orders) ?></span></h2><div class="table-wrap"><table><thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Total</th><th>Status</th></tr></thead><tbody>
<?php foreach($orders as $o): ?><tr><td><a href="<?= BASE_URL ?>/admin/orders.php?id=<?= (int)$o['order_id'] ?>">#<?= (int)$o['order_id'] ?></a></td><td><?= e($o['full_name']) ?></td><td><?= e($o['order_date']) ?></td><td><?= money($o['total_amount']) ?></td><td><span class="pill <?= pill_class($o['order_status']) ?>"><?= e($o['order_status']) ?></span></td></tr><?php endforeach; ?>
<?php if(!$orders): ?><tr><td colspan="5" class="muted">No matching orders.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="card"><h2>Customers <span class="pill pill-warn"><?= count($users) ?></span></h2><div class="table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Phone</th></tr></thead><tbody>
<?php foreach($users as $u): ?><tr><td><a href="<?= BASE_URL ?>/admin/users.php?q=<?= urlencode($u['email']) ?>"><?= e($u['full_name']) ?></a></td><td><?= e($u['email']) ?></td><td><?= e($u['phone']??'—') ?></td></tr><?php endforeach; ?>
<?php if(!$users): ?><tr><td colspan="3" class="muted">No matching customers.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php require __DIR__.'/../includes/footer.php'; ?>
