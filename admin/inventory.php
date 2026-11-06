<?php
require_once __DIR__.'/../config/config.php';require_once __DIR__.'/../includes/functions.php';require_admin();
const INVENTORY_MAX_QTY = 1000000;
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 $pid=(int)($_POST['product_id']??0);
 $rawQty=trim((string)($_POST['quantity']??''));
 $type=(string)($_POST['transaction_type']??'Stock In');
 $remarks=trim((string)($_POST['remarks']??''));
 $pdo_started=false;
 try{
  if($pid<1)throw new UserFacingException('Choose a product.');
  if(!in_array($type,['Stock In','Stock Out','Adjustment'],true))throw new UserFacingException('Choose a valid transaction type.');
  if($rawQty===''||!preg_match('/^\d+$/',$rawQty))throw new UserFacingException('Enter the quantity as a whole number.');
  if(strlen($rawQty)>7||(int)$rawQty>INVENTORY_MAX_QTY)throw new UserFacingException('Quantity can be at most '.number_format(INVENTORY_MAX_QTY).'.');
  $qty=(int)$rawQty;
  // Stock In / Stock Out move stock by an amount, so it must be at least 1.
  // An Adjustment sets stock to the counted value, and a recount of 0 is valid.
  if($qty<1&&$type!=='Adjustment')throw new UserFacingException('Enter a quantity of at least 1 for '.$type.'. Use Adjustment to set stock to 0.');
  if(mb_strlen($remarks)>REMARKS_MAX)throw new UserFacingException('Remarks can be at most '.REMARKS_MAX.' characters.');
  $pdo->beginTransaction();$pdo_started=true;
  $s=$pdo->prepare('SELECT stock_quantity,product_name FROM products WHERE product_id=? FOR UPDATE');$s->execute([$pid]);$row=$s->fetch();
  if(!$row)throw new UserFacingException('That product no longer exists.');
  $current=(int)$row['stock_quantity'];
  $new=$type==='Stock In'?$current+$qty:($type==='Stock Out'?$current-$qty:$qty);
  if($new<0)throw new UserFacingException('Stock cannot become negative: '.$row['product_name'].' has only '.$current.' in stock.');
  if($new>INVENTORY_MAX_QTY*2)throw new UserFacingException('That would put stock above the allowed maximum.');
  $s=$pdo->prepare('UPDATE products SET stock_quantity=? WHERE product_id=?');$s->execute([$new,$pid]);
  $delta=$new-$current;
  $s=$pdo->prepare('INSERT INTO inventory_transactions(product_id,admin_id,transaction_type,quantity_changed,remarks) VALUES(?,?,?,?,?)');$s->execute([$pid,$_SESSION['admin_id'],$type,$delta,$remarks]);
  log_activity($pdo,'stock_'.strtolower(str_replace(' ','_',$type)),'product',$pid,$type.' '.($delta>0?'+':'').$delta.' (now '.$new.')'.($remarks!==''?' - '.$remarks:''));
  $pdo->commit();
  flash($type==='Adjustment'&&$delta===0?'Stock already matched the count ('.$new.'), so nothing changed. The recount was recorded.':'Inventory updated. '.$row['product_name'].' now has '.$new.' in stock.');
 }catch(Throwable $e){
  if($pdo_started)safe_rollback($pdo);
  flash(user_error_message($e,'inventory update'),'error');
 }
 redirect('/admin/inventory.php');
}
$products=$pdo->query('SELECT * FROM products ORDER BY product_name')->fetchAll();$logs=$pdo->query('SELECT t.*,p.product_name,a.admin_name FROM inventory_transactions t JOIN products p ON p.product_id=t.product_id LEFT JOIN administrators a ON a.admin_id=t.admin_id ORDER BY t.transaction_date DESC LIMIT 30')->fetchAll();
$pageTitle='Inventory';require __DIR__.'/../includes/header.php';require __DIR__.'/nav.php';
?>
<div class="page-head"><div><h1>Inventory Management</h1><p class="muted">Record stock-in, stock-out, and quantity adjustments.</p></div></div>
<div class="card u-mb-18"><h2>Update Stock</h2><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="form-row"><div><label>Product</label><select name="product_id"><?php foreach($products as $p): ?><option value="<?= $p['product_id'] ?>" data-stock="<?= (int)$p['stock_quantity'] ?>"><?= e($p['product_name']) ?> (Current: <?= $p['stock_quantity'] ?>)</option><?php endforeach; ?></select></div><div><label>Transaction type</label><select name="transaction_type" id="inv-type"><option>Stock In</option><option>Stock Out</option><option>Adjustment</option></select></div><div><label>Quantity</label><input type="number" min="0" max="1000000" step="1" name="quantity" id="inv-qty" required><small class="muted" id="inv-preview" aria-live="polite"></small><small class="muted" id="inv-qty-hint">Stock In / Stock Out: 1 or more. Adjustment: the counted total (0 is allowed).</small></div><div><label>Remarks</label><input name="remarks" maxlength="255"></div></div><button class="btn btn-primary u-mt-14">Save Inventory Change</button></form></div>
<div class="card u-mb-18"><h2>Current Stock</h2><div class="table-wrap"><table><thead><tr><th>Product / SKU</th><th>Stock</th><th>Alert threshold</th><th>Unit</th></tr></thead><tbody><?php if(!$products): ?><tr><td colspan="4" class="muted">No products yet. Add one under Products.</td></tr><?php endif; ?><?php foreach($products as $p): ?><tr><td><?= e($p['product_name']) ?><div class="small muted"><?=e($p['sku']??'')?></div></td><td><?= $p['stock_quantity'] ?> <?php if((int)$p['stock_quantity']<=(int)($p['low_stock_threshold']??5)):?><span class="pill pill-warn">Low stock</span><?php endif;?></td><td><?= (int)($p['low_stock_threshold']??5) ?></td><td><?= e($p['unit']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="card"><h2>Inventory Transaction History</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Product</th><th>Type</th><th>Quantity change</th><th>Admin</th><th>Remarks</th></tr></thead><tbody><?php if(!$logs): ?><tr><td colspan="6" class="muted">No stock movements recorded yet.</td></tr><?php endif; ?><?php foreach($logs as $l): ?><tr><td><?= e($l['transaction_date']) ?></td><td><?= e($l['product_name']) ?></td><td><?= e($l['transaction_type']) ?></td><td><?= $l['quantity_changed'] ?></td><td><?= e($l['admin_name']??'System') ?></td><td><?= e($l['remarks']??'') ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<script>(()=>{const t=document.getElementById('inv-type'),q=document.getElementById('inv-qty');if(!t||!q)return;const p=document.querySelector('select[name=product_id]'),pv=document.getElementById('inv-preview');const sync=()=>{q.min=t.value==='Adjustment'?'0':'1';if(!p||!pv)return;const cur=parseInt(p.selectedOptions[0]?.dataset.stock||'0',10),n=parseInt(q.value,10);if(q.value===''||isNaN(n)||n<0){pv.textContent='';pv.style.display='none';return;}const next=t.value==='Stock In'?cur+n:t.value==='Stock Out'?cur-n:n;pv.style.display='block';pv.textContent=next<0?'Not possible: only '+cur+' in stock.':'Stock will change from '+cur+' to '+next+' ('+(next-cur>=0?'+':'')+(next-cur)+').';};[t,q,p].forEach(el=>el&&el.addEventListener(el===q?'input':'change',sync));sync();})();</script>
<?php require __DIR__.'/../includes/footer.php'; ?>
