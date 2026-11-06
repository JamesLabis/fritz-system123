<?php
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/includes/functions.php';
$id=(int)($_GET['id']??0);
$s=$pdo->prepare('SELECT p.*, c.category_name FROM products p LEFT JOIN categories c ON c.category_id=p.category_id WHERE p.product_id=?');$s->execute([$id]);$p=$s->fetch();
if(!$p){http_response_code(404);$pageTitle='Not found';

require __DIR__.'/includes/header.php';echo '<div class="card empty">Product not found. <a href="'.BASE_URL.'/">Back to products</a></div>';require __DIR__.'/includes/footer.php';exit;}
$s=$pdo->prepare('SELECT p.*, c.category_name FROM products p LEFT JOIN categories c ON c.category_id=p.category_id WHERE p.category_id<=>? AND p.product_id<>? ORDER BY p.product_name LIMIT 3');$s->execute([$p['category_id'],$id]);$related=$s->fetchAll();
$stock=(int)$p['stock_quantity'];$pageTitle=$p['product_name'];
$breadcrumbs=[['Home',BASE_URL.'/'],['Products',BASE_URL.'/']];if($p['category_id'])$breadcrumbs[]=[$p['category_name'],BASE_URL.'/?category='.(int)$p['category_id']];$breadcrumbs[]=[$p['product_name'],null];

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='review' && current_user_id()){verify_csrf();$oid=(int)($_POST['order_id']??0);$rating=max(1,min(5,(int)($_POST['rating']??5)));$body=trim($_POST['review_text']??'');$check=$pdo->prepare("SELECT 1 FROM orders o JOIN order_items oi ON oi.order_id=o.order_id WHERE o.order_id=? AND o.user_id=? AND oi.product_id=? AND o.order_status='Completed'");$check->execute([$oid,current_user_id(),$id]);if($check->fetchColumn()){$ins=$pdo->prepare('INSERT INTO product_reviews(user_id,product_id,order_id,rating,review_text) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating),review_text=VALUES(review_text),created_at=CURRENT_TIMESTAMP');$ins->execute([current_user_id(),$id,$oid,$rating,$body]);flash('Thank you for reviewing this product.');}else flash('A valid purchase is required before reviewing.','error');redirect('/product.php?id='.$id.'#reviews');}
require __DIR__.'/includes/header.php';
?>
<div class="pdp">
  <div class="pdp-gallery"><div class="pdp-img"><?= product_visual($p) ?></div><?php $detailImageUrl=product_image_url($p, 'detail_image'); if($detailImageUrl): ?><div class="pdp-secondary"><img src="<?= e($detailImageUrl) ?>" alt="<?= e($p['detail_image_alt'] ?: ($p['product_name'].', second view')) ?>" loading="lazy"></div><?php endif; ?></div>
  <div class="card">
    <span class="eyebrow"><?= e($p['category_name'] ?? 'Uncategorized') ?></span>
    <h1 class="u-mt-10"><?= e($p['product_name']) ?></h1>
    <div class="price"><?= money($p['price']) ?> <span class="unit">/ <?= e($p['unit']) ?></span></div>
    <p class="lead"><?= e($p['description'] ?: 'No description provided.') ?></p>
    <?= stock_badge($stock) ?>
    <table class="spec"><tbody>
      <tr><th>Product code</th><td><?= e($p['sku'] ?: ('BS-'.str_pad((string)$p['product_id'],6,'0',STR_PAD_LEFT))) ?></td></tr>
      <tr><th>Category</th><td><?= e($p['category_name'] ?? 'Uncategorized') ?></td></tr>
      <tr><th>Sold per</th><td><?= e($p['unit']) ?></td></tr>
      <tr><th>Unit price</th><td><?= money($p['price']) ?></td></tr>
      <tr><th>Available stock</th><td><?= $stock ?> <?= e($p['unit']) ?>(s)</td></tr>
      <tr><th>Listed</th><td><?= e(substr((string)$p['date_added'],0,10)) ?></td></tr>
    </tbody></table>
    <?php if(current_user_id() && $stock>0): ?>
      <form method="post" action="<?= BASE_URL ?>/cart.php" class="qty ajax-add-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?= $p['product_id'] ?>"><div class="quantity-control"><button type="button" class="qty-minus" aria-label="Decrease quantity">−</button><input id="product-quantity" type="number" name="quantity" min="1" max="<?= $stock ?>" value="1"><button type="button" class="qty-plus" aria-label="Increase quantity">+</button></div><button class="btn btn-primary u-grow">Add to Cart</button></form><form method="post" action="<?= BASE_URL ?>/cart.php" class="buy-now-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="buy_now"><input type="hidden" name="product_id" value="<?= $p['product_id'] ?>"><input type="hidden" name="quantity" value="1" id="buy-now-quantity"><button class="btn btn-outline btn-block" type="submit">Buy Now · Proceed to Checkout</button></form><p class="line-total" id="line-total">1 × <?=money($p['price'])?> = <strong><?=money($p['price'])?></strong></p>
    <?php elseif(!is_logged_in()): ?><a class="btn btn-primary btn-block" href="<?= BASE_URL ?>/login.php">Login to order</a>
    <?php elseif(is_admin()): ?><a class="btn btn-outline btn-block" href="<?= BASE_URL ?>/admin/products.php?edit=<?= $p['product_id'] ?>">Edit this product</a>
    <?php else: ?><button class="btn btn-outline btn-block" disabled>Out of stock</button><?php endif; ?>
    <?php if(current_user_id()): $w=$pdo->prepare('SELECT 1 FROM wishlists WHERE user_id=? AND product_id=?');$w->execute([current_user_id(),$id]);$saved=(bool)$w->fetchColumn(); ?>
    <form method="post" action="<?=BASE_URL?>/wishlist.php" class="u-mt-10"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="<?=$saved?'remove':'add'?>"><button class="btn btn-outline btn-block"><?=$saved?'♥ Saved to Wishlist':'♡ Add to Wishlist'?></button></form>
    <?php endif; ?>
    <div class="perks"><div>✔ Live stock count</div><div>✔ COD or online payment</div><div>✔ Digital receipt</div></div>
  </div>
</div>
<?php if($related): ?><h2 class="u-mt-38">More in <?= e($p['category_name'] ?? 'this category') ?></h2>
<div class="grid"><?php foreach($related as $r): $href=BASE_URL.'/product.php?id='.(int)$r['product_id']; ?>
  <article class="card product-card"><a class="product-art" href="<?= $href ?>"><?= product_visual($r) ?></a><div class="product-info"><h3><a href="<?= $href ?>"><?= e($r['product_name']) ?></a></h3><div class="buy"><div class="price"><?= money($r['price']) ?></div><?= stock_badge((int)$r['stock_quantity']) ?></div></div></article>
<?php endforeach; ?></div><?php endif; ?>
<?php if(current_user_id() && $stock>0): ?><div class="mobile-cart-bar"><span id="mobile-line-total"><?=money($p['price'])?></span><button type="button" class="btn btn-primary" onclick="document.querySelector('form.qty[action*="cart.php"]')?.requestSubmit()">Add to Cart</button></div><?php endif; ?>
<?php
$rv=$pdo->prepare('SELECT r.*,u.full_name FROM product_reviews r JOIN users u ON u.user_id=r.user_id WHERE r.product_id=? AND r.is_hidden=0 ORDER BY r.created_at DESC');$rv->execute([$id]);$reviews=$rv->fetchAll();
$avg=$pdo->prepare('SELECT AVG(rating) FROM product_reviews WHERE product_id=? AND is_hidden=0');$avg->execute([$id]);$average=(float)$avg->fetchColumn();
$eligible=[];if(current_user_id()){$el=$pdo->prepare("SELECT o.order_id FROM orders o JOIN order_items oi ON oi.order_id=o.order_id WHERE o.user_id=? AND oi.product_id=? AND o.order_status='Completed' ORDER BY o.order_date DESC");$el->execute([current_user_id(),$id]);$eligible=$el->fetchAll(PDO::FETCH_COLUMN);}

?>
<section class="card u-mt-24" id="reviews"><h2>Customer Reviews</h2><p class="muted"><?=count($reviews)?> review(s) · <?= $average ? number_format($average,1).'/5 average' : 'No ratings yet' ?></p>
<?php if(current_user_id() && $eligible):?><form class="u-my-16" method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="review"><label>Purchased order</label><select name="order_id" required><?php foreach($eligible as $oid):?><option value="<?=$oid?>">Order #<?=$oid?></option><?php endforeach;?></select><label>Rating</label><select name="rating"><option value="5">5 - Excellent</option><option value="4">4 - Good</option><option value="3">3 - Average</option><option value="2">2 - Poor</option><option value="1">1 - Very poor</option></select><label>Your review</label><textarea name="review_text" rows="3" maxlength="2000" placeholder="Share your experience"></textarea><button class="btn btn-primary u-mt-10">Submit review</button></form><?php endif;?>
<?php foreach($reviews as $r):?><div class="history-entry"><span class="history-dot"></span><div><b><?=e($r['full_name'])?></b> <span class="pill pill-ok">Verified purchase</span> · <?=str_repeat('★',(int)$r['rating'])?><?=str_repeat('☆',5-(int)$r['rating'])?><small><?=e($r['created_at'])?></small><p><?=nl2br(e($r['review_text']??''))?></p></div></div><?php endforeach;?><?php if(!$reviews):?><p class="muted">Be the first to review this product after purchasing it.</p><?php endif;?></section>
<?php require __DIR__.'/includes/footer.php'; ?>
