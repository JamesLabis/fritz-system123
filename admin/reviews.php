<?php
require_once __DIR__.'/../config/config.php';require_once __DIR__.'/../includes/functions.php';require_admin();
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$rid=(int)($_POST['review_id']??0);$act=(string)($_POST['action']??'');
 $s=$pdo->prepare('SELECT r.review_id,r.is_hidden,p.product_name FROM product_reviews r JOIN products p ON p.product_id=r.product_id WHERE r.review_id=?');$s->execute([$rid]);$r=$s->fetch();
 if(!$r){flash('That review no longer exists.','error');}
 elseif($act==='hide'||$act==='show'){
  $pdo->prepare('UPDATE product_reviews SET is_hidden=? WHERE review_id=?')->execute([$act==='hide'?1:0,$rid]);
  log_activity($pdo,$act==='hide'?'review_hide':'review_show','review',$rid,'Review on '.$r['product_name']);
  flash($act==='hide'?'Review hidden from the product page.':'Review is visible again.');
 }
 elseif($act==='delete'){
  $pdo->prepare('DELETE FROM product_reviews WHERE review_id=?')->execute([$rid]);
  log_activity($pdo,'review_delete','review',$rid,'Review on '.$r['product_name']);
  flash('Review deleted.');
 }
 redirect('/admin/reviews.php'.(isset($_GET['filter'])?'?filter='.urlencode((string)$_GET['filter']):''));
}
$filter=($_GET['filter']??'')==='hidden'?'hidden':'all';
$sql='SELECT r.*,u.full_name,p.product_name FROM product_reviews r JOIN users u ON u.user_id=r.user_id JOIN products p ON p.product_id=r.product_id'.($filter==='hidden'?' WHERE r.is_hidden=1':'').' ORDER BY r.created_at DESC LIMIT 200';
$reviews=$pdo->query($sql)->fetchAll();
$pageTitle='Review Moderation';require __DIR__.'/../includes/header.php';
?>
<div class="page-head"><div><h1>Review Moderation</h1><p class="muted">Hide or delete reviews that are abusive or off-topic. Every review here comes from a customer with a completed order for that product.</p></div></div>
<div class="order-tabs"><a class="<?= $filter==='all'?'active':'' ?>" href="?">All reviews</a><a class="<?= $filter==='hidden'?'active':'' ?>" href="?filter=hidden">Hidden only</a></div>
<div class="card"><div class="table-wrap"><table><thead><tr><th>Product</th><th>Customer</th><th>Rating</th><th>Review</th><th>Date</th><th>Actions</th></tr></thead><tbody>
<?php foreach($reviews as $r): ?><tr<?= $r['is_hidden']?' class="low-stock-row"':'' ?>>
<td><a href="<?= BASE_URL ?>/product.php?id=<?= (int)$r['product_id'] ?>#reviews"><?= e($r['product_name']) ?></a></td>
<td><?= e($r['full_name']) ?></td>
<td><?= (int)$r['rating'] ?>/5</td>
<td><?= nl2br(e(mb_strimwidth((string)$r['review_text'],0,300,'…'))) ?><?php if($r['is_hidden']): ?><div class="small text-danger">Hidden from customers</div><?php endif; ?></td>
<td><?= e(substr((string)$r['created_at'],0,10)) ?></td>
<td><form method="post" class="inline-form" onsubmit="return this.action.value!=='delete'||confirm('Delete this review permanently?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="review_id" value="<?= (int)$r['review_id'] ?>">
<button class="btn btn-outline btn-sm" name="action" value="<?= $r['is_hidden']?'show':'hide' ?>"><?= $r['is_hidden']?'Show':'Hide' ?></button>
<button class="btn btn-danger btn-sm" name="action" value="delete">Delete</button></form></td></tr>
<?php endforeach; ?>
<?php if(!$reviews): ?><tr><td colspan="6" class="muted">No reviews<?= $filter==='hidden'?' are hidden':' yet' ?>.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require __DIR__.'/../includes/footer.php'; ?>
