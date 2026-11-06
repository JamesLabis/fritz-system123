<?php
require_once __DIR__.'/config/config.php'; require_once __DIR__.'/includes/functions.php'; require_login();
if(!current_user_id()) redirect('/login.php');
const BUY_NOW_TTL = 900; // a Buy Now selection is good for 15 minutes
$buyNow = $_SESSION['buildsmart_buy_now'] ?? null;
if ($buyNow !== null) {
 $fresh = is_array($buyNow) && !empty($buyNow['product_id']) && !empty($buyNow['quantity']) && (time() - (int)($buyNow['created_at'] ?? 0)) <= BUY_NOW_TTL;
 if (!$fresh) {
  $backTo = is_array($buyNow) && !empty($buyNow['product_id']) ? '/product.php?id='.(int)$buyNow['product_id'] : '/';
  unset($_SESSION['buildsmart_buy_now']);
  flash('Your Buy Now selection expired. Please choose the product again.','error'); redirect($backTo);
 }
}
$mode = $buyNow ? 'buy_now' : 'cart';
if (empty($_SESSION['checkout_submission_token'])) $_SESSION['checkout_submission_token']=bin2hex(random_bytes(32));
$checkoutToken=$_SESSION['checkout_submission_token'];
$cartId=get_or_create_cart($pdo,current_user_id());

/** The items being ordered, with current stock. Called again after a stock problem so the page shows fresh numbers. */
$loadItems = function() use ($pdo,&$buyNow,$cartId): array {
 if ($buyNow) {
  $s=$pdo->prepare("SELECT p.product_id,p.product_name,p.price,p.stock_quantity,p.image,p.category_id,COALESCE(c.category_name,'Hardware') AS category_name,? AS quantity FROM products p LEFT JOIN categories c ON c.category_id=p.category_id WHERE p.product_id=?");
  $s->execute([(int)$buyNow['quantity'],(int)$buyNow['product_id']]);
 } else {
  $s=$pdo->prepare("SELECT ci.quantity,p.product_id,p.product_name,p.price,p.stock_quantity,p.image,p.category_id,COALESCE(c.category_name,'Hardware') AS category_name FROM cart_items ci JOIN products p ON p.product_id=ci.product_id LEFT JOIN categories c ON c.category_id=p.category_id WHERE ci.cart_id=?");
  $s->execute([$cartId]);
 }
 return $s->fetchAll();
};
/** Items whose quantity is more than what is in stock. */
$findShortages = function(array $items): array {
 $out=[]; foreach($items as $i){ if((int)$i['stock_quantity']<(int)$i['quantity']) $out[]=['product_id'=>(int)$i['product_id'],'name'=>(string)$i['product_name'],'available'=>max(0,(int)$i['stock_quantity']),'requested'=>(int)$i['quantity']]; }
 return $out;
};

$items=$loadItems();
if(!$items){
 if($buyNow){ unset($_SESSION['buildsmart_buy_now']); flash('The selected product is no longer available.','error'); redirect('/'); }
 flash('Your cart is empty.','error'); redirect('/cart.php');
}
$cfg=[];foreach($pdo->query('SELECT setting_key,setting_value FROM buildsmart_settings')->fetchAll() as $sr)$cfg[$sr['setting_key']]=$sr['setting_value'];
$deliveryRate=(float)($cfg['delivery_fee']??100);$freeAt=(float)($cfg['free_delivery_minimum']??5000);
$u=$pdo->prepare('SELECT full_name,phone,address FROM users WHERE user_id=?');$u->execute([current_user_id()]);$acct=$u->fetch() ?: [];
$savedList=user_addresses($pdo,current_user_id());$defaultAddr=$savedList[0] ?? null;
$error='';$notice='';$errField='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 $act=(string)($_POST['action']??'');
 $place=resolve_delivery_input($pdo,$_POST);$addrIn=$place['values'];$address=$place['address'];$zone=$place['zone'];$method=(string)($_POST['payment_method']??'Cash on Delivery');$reference=trim((string)($_POST['transaction_reference']??''));
 if(!csrf_ok()){
  $error='This page was open for too long, so nothing was submitted. Your details are still here, please try again.';
 } elseif(($_POST['order_mode']??'')!==$mode){
  // e.g. Buy Now was cleared by another tab; never place an order different from the one on screen.
  $error='Your order changed in another tab or window. Please review the order summary and try again.';
 } elseif($act==='fix_stock'){
  // Bring every short line down to what is in stock; sold-out lines are removed.
  try{
   foreach($findShortages($items) as $sh){
    if($buyNow){
     if($sh['available']<1){ unset($_SESSION['buildsmart_buy_now']); flash($sh['name'].' is now out of stock.','error'); redirect('/'); }
     $_SESSION['buildsmart_buy_now']['quantity']=$sh['available']; $buyNow=$_SESSION['buildsmart_buy_now'];
    } elseif($sh['available']<1){
     $pdo->prepare('DELETE FROM cart_items WHERE cart_id=? AND product_id=?')->execute([$cartId,$sh['product_id']]);
    } else {
     $pdo->prepare('UPDATE cart_items SET quantity=? WHERE cart_id=? AND product_id=?')->execute([$sh['available'],$cartId,$sh['product_id']]);
    }
   }
   $items=$loadItems();
   if(!$items){ flash('Everything in your cart is now out of stock.','error'); redirect('/cart.php'); }
   $notice='Your order was updated to what is in stock. Review the summary, then place your order.';
  }catch(Throwable $e){ $error=user_error_message($e,'checkout stock update'); }
 } else {
  $postedToken=(string)($_POST['checkout_token']??'');
  if(!$postedToken || !hash_equals((string)($_SESSION['checkout_submission_token']??''),$postedToken)){$error='This checkout was already submitted or has expired. Please review your cart and try again.';}
  elseif($place['error']!==null){$error=$place['error']['message'];$errField=$place['error']['field'];}
  elseif(!in_array($method,['Cash on Delivery','Online Payment'],true)){$error='Please choose a valid payment method.';}
  elseif(mb_strlen($reference)>REFERENCE_MAX){$error='The payment reference can be at most '.REFERENCE_MAX.' characters.';$errField='transaction_reference';}
  elseif($findShortages($items)){ /* the stock banner below explains it and offers the fix */ }
  else {
   try{$pdo->beginTransaction();
    if ($buyNow) {
     $lock=$pdo->prepare('SELECT product_id,product_name,price,stock_quantity,? AS quantity FROM products WHERE product_id=? FOR UPDATE');
     $lock->execute([(int)$buyNow['quantity'],(int)$buyNow['product_id']]);$locked=$lock->fetchAll();
    } else {
     $lock=$pdo->prepare('SELECT ci.quantity,p.product_id,p.product_name,p.price,p.stock_quantity FROM cart_items ci JOIN products p ON p.product_id=ci.product_id WHERE ci.cart_id=? FOR UPDATE');
     $lock->execute([$cartId]);$locked=$lock->fetchAll();
    }
    if(!$locked)throw new UserFacingException($buyNow ? 'The selected product is no longer available.' : 'Your cart is empty.');
    $grand=0;foreach($locked as $li){if((int)$li['stock_quantity']<(int)$li['quantity'])throw new InsufficientStockException((string)$li['product_name'],(int)$li['product_id'],max(0,(int)$li['stock_quantity']),(int)$li['quantity']);$grand+=(float)$li['price']*(int)$li['quantity'];}
    $rate=$zone?(float)$zone['fee']:$deliveryRate;$fee=($grand >= $freeAt && $freeAt>0) ? 0 : $rate;$grandWithDelivery=$grand+$fee;
    $s=$pdo->prepare('INSERT INTO orders(user_id,total_amount,delivery_fee,delivery_address,delivery_province,delivery_city,delivery_barangay,delivery_house_no,delivery_street,delivery_landmark,recipient_name,recipient_phone,delivery_zone) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');$s->execute([current_user_id(),$grandWithDelivery,$fee,$address,$addrIn['province'],$addrIn['city'],$addrIn['barangay'],$addrIn['house_no']!==''?$addrIn['house_no']:null,$addrIn['street'],$addrIn['landmark']!==''?$addrIn['landmark']:null,$addrIn['recipient_name'],$addrIn['recipient_phone'],$zone['zone_name']??null]);$orderId=(int)$pdo->lastInsertId();
    $oi=$pdo->prepare('INSERT INTO order_items(order_id,product_id,product_name,quantity,unit_price,subtotal) VALUES(?,?,?,?,?,?)');
    $up=$pdo->prepare('UPDATE products SET stock_quantity=stock_quantity-? WHERE product_id=?');
    $inv=$pdo->prepare("INSERT INTO inventory_transactions(product_id,transaction_type,quantity_changed,remarks) VALUES(?,'Stock Out',?,'Reserved for pending order')");
    foreach($locked as $li){$qty=(int)$li['quantity'];$sub=(float)$li['price']*$qty;$oi->execute([$orderId,$li['product_id'],$li['product_name'],$qty,$li['price'],$sub]);$up->execute([$qty,$li['product_id']]);$inv->execute([$li['product_id'],-$qty]);}
    $payStatus='Pending';
    $pay=$pdo->prepare('INSERT INTO payments(order_id,payment_method,amount,payment_status,transaction_reference) VALUES(?,?,?,?,?)');$pay->execute([$orderId,$method,$grandWithDelivery,$payStatus,$reference ?: null]);
    $hist=$pdo->prepare("INSERT INTO order_status_history(order_id,status,remarks) VALUES(?,'Pending','Order placed by customer')");$hist->execute([$orderId]);
    $receiptNo='BS-'.date('Ymd').'-'.str_pad((string)$orderId,6,'0',STR_PAD_LEFT);
    $r=$pdo->prepare('INSERT INTO receipts(order_id,receipt_number,total_amount) VALUES(?,?,?)');$r->execute([$orderId,$receiptNo,$grandWithDelivery]);
    if (!$buyNow) {
     $del=$pdo->prepare('DELETE FROM cart_items WHERE cart_id=?');$del->execute([$cartId]);
    }
    $pdo->prepare('INSERT INTO customer_notifications(user_id,order_id,title,message) VALUES(?,?,?,?)')->execute([current_user_id(),$orderId,'Order received','Your order #'.$orderId.' has been received and is Pending.']);
    $pdo->commit();
    unset($_SESSION['checkout_submission_token']);
    if ($buyNow) unset($_SESSION['buildsmart_buy_now']);
    // Saving the address is optional and happens after the order is safe; a problem here must never undo the order.
    if(!empty($_POST['save_address'])){try{save_user_address($pdo,current_user_id(),$addrIn,(string)($_POST['address_label']??''));flash('Order placed. The address was saved to your profile.');}catch(Throwable $se){flash('Order placed, but the address could not be saved: '.user_error_message($se,'checkout save address'),'error');}}
    redirect('/receipt.php?order_id='.$orderId);
   }catch(Throwable $e){
    safe_rollback($pdo);
    if($e instanceof InsufficientStockException){
     // Stock changed after the page loaded: show fresh numbers and the way forward instead of a dead end.
     $items=$loadItems();
     if(!$findShortages($items)) $error='Stock changed while your order was being placed. Please review it and try again.';
    } else {
     $error=user_error_message($e,'checkout');
    }
   }
  }
 }
}
$shortages=$findShortages($items);
$total=0;foreach($items as $i){$total+=(float)$i['price']*(int)$i['quantity'];}
if($_SERVER['REQUEST_METHOD']==='POST'){$shownCity=$place['values']['city'];$shownProv=$place['values']['province'];}else{$shownCity=(string)($defaultAddr['city']??'');$shownProv=(string)($defaultAddr['province']??'');}
$shownRate=$deliveryRate;if($shownCity!==''){$pd=place_data($pdo);foreach(($pd[$shownProv]??[]) as $cn=>$cv){if(strcasecmp($cn,$shownCity)===0||strcasecmp($cn,$shownCity.' City')===0){if($cv['f']!==null)$shownRate=(float)$cv['f'];break;}}}
$deliveryFee=($total >= $freeAt && $freeAt>0) ? 0 : $shownRate;
$pageTitle='Checkout';require __DIR__.'/includes/header.php';
?>
<div class="checkout-steps"><span class="step done">✓ <b>Cart</b></span><i></i><span class="step active">2 <b>Details</b></span><i></i><span class="step">3 <b>Receipt</b></span></div>
<div class="checkout-layout"><section class="card checkout-form"><h1>Checkout</h1><p class="muted">Delivery details and payment preference.</p><?php if($error):?><div id="form-error" class="alert alert-error" role="alert"><?=e($error)?></div><?php endif;?><?php if($notice):?><div class="alert alert-info" role="status"><?=e($notice)?></div><?php endif;?><form method="post" id="checkout-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="checkout_token" value="<?=e($checkoutToken)?>"><input type="hidden" name="order_mode" value="<?=e($mode)?>">
<?php if($shortages):?><div class="alert alert-error" role="alert"><b>Not enough stock for part of your order.</b><ul><?php foreach($shortages as $sh):?><li><?=e($sh['name'])?>: <?=$sh['available']>0?'only '.(int)$sh['available'].' left in stock (you asked for '.(int)$sh['requested'].')':'out of stock'?></li><?php endforeach;?></ul><p class="small">Update your order to what is available (sold-out items are removed). Your delivery details below are kept.</p><button type="submit" name="action" value="fix_stock" class="btn btn-primary" formnovalidate>Update my order to what is in stock</button> <?php if(!$buyNow):?><a class="btn btn-outline" href="<?=BASE_URL?>/cart.php">Back to cart</a><?php endif;?></div><?php endif;?>
<?php
 $fv=$_SERVER['REQUEST_METHOD']==='POST' ? $addrIn : ($defaultAddr ?: ['recipient_name'=>(string)($acct['full_name']??''),'recipient_phone'=>(string)($acct['phone']??''),'province'=>DEFAULT_PROVINCE]);
 $saveChecked=$_SERVER['REQUEST_METHOD']==='POST' ? !empty($_POST['save_address']) : !$savedList;
?><fieldset class="address-fieldset"><legend>Deliver to</legend>
<?php if($savedList):?><label for="saved-address">Use a saved address</label><select id="saved-address"><option value="">Enter a different address</option><?php foreach($savedList as $sa):?><option value="<?=(int)$sa['address_id']?>" data-address="<?=e(json_encode(['recipient_name'=>$sa['recipient_name'],'recipient_phone'=>$sa['recipient_phone'],'province'=>$sa['province'],'city'=>$sa['city'],'barangay'=>$sa['barangay'],'house_no'=>(string)$sa['house_no'],'street'=>$sa['street'],'landmark'=>(string)$sa['landmark']],JSON_UNESCAPED_UNICODE))?>"><?=e(($sa['label']?$sa['label'].' – ':'').trim($sa['house_no'].' '.$sa['street']).', '.$sa['city'])?><?=$sa['is_default']?' (default)':''?></option><?php endforeach;?></select><?php endif;?>
<?= address_fields($fv,$errField,"checkout",$pdo) ?>
<?php if(!$savedList && !empty($acct["address"])): ?><p class="small muted">Old address on file: <?= e($acct["address"]) ?>. Please enter it in the boxes above.</p><?php endif; ?>
<label class="u-check-row"><input class="u-check-input" type="checkbox" name="save_address" value="1" id="save-address" <?=$saveChecked?'checked':''?>> <span>Save this address to my profile</span></label>
<div id="label-wrap"><label for="address-label">Label (optional)</label><input id="address-label" name="address_label" maxlength="<?=LABEL_MAX?>" value="<?=e((string)($_POST['address_label']??''))?>" placeholder="e.g. Home, Job site, Warehouse"></div>
<p class="small muted">Your saved addresses are never changed by an order unless you tick the box. Manage them in My Profile.</p>
</fieldset><label>Payment method</label><div class="payment-cards"><label class="payment-card"><input type="radio" name="payment_method" value="Cash on Delivery" <?=($_POST['payment_method']??'Cash on Delivery')==='Cash on Delivery'?'checked':''?>><span class="payment-icon"><?=icon('cash')?></span><b>Cash on Delivery</b><small>Pay when your order arrives</small></label><label class="payment-card"><input type="radio" name="payment_method" value="Online Payment" <?=($_POST['payment_method']??'')==='Online Payment'?'checked':''?>><span class="payment-icon"><?=icon('card')?></span><b>Online Payment</b><small>Record your transaction reference</small></label></div><div id="reference-field" class="reference-field"><label>Online payment reference (optional)</label><input name="transaction_reference"<?=field_attrs('transaction_reference',$errField)?> maxlength="150" value="<?=e($_POST['transaction_reference']??'')?>" placeholder="Enter reference if already paid"></div><p class="small muted">Online payment is recorded as Pending until an administrator verifies it. No bank or wallet is charged by this demo system. Stock is reserved when the order is placed.</p><button class="btn btn-primary btn-block" <?=$shortages?'disabled title="Update your order to what is in stock first"':''?>>Place Order</button></form></section><aside class="card checkout-summary"><h2>Order Summary</h2><?php foreach($items as $i):?><div class="checkout-item"><div class="checkout-thumb"><?=product_visual($i)?></div><div class="checkout-item-name"><b><?=e($i['product_name'])?></b><small><?= (int)$i['quantity']?> × <?=money($i['price'])?></small></div><strong><?=money((float)$i['price']*(int)$i['quantity'])?></strong></div><?php endforeach;?><div id="checkout-totals" data-subtotal="<?=e((string)$total)?>" data-rate="<?=e((string)$deliveryRate)?>" data-free="<?=e((string)$freeAt)?>"><div class="summary-total">Subtotal <strong><?=money($total)?></strong></div><p>Delivery fee <strong id="sum-fee"><?=money($deliveryFee)?></strong></p><p class="small muted" id="sum-free" <?=$deliveryFee===0?'':'hidden'?>>Free delivery applied.</p><p class="small muted">The delivery fee depends on your city.</p><div class="summary-total">Total <strong id="sum-total"><?=money($total+$deliveryFee)?></strong></div></div></aside></div>
<?= place_picker_script($pdo) ?>
<script>
(()=>{const form=document.getElementById('checkout-form');if(!form)return;
const money=n=>'₱'+n.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
const box=document.getElementById('checkout-totals');
form.addEventListener('placechange',e=>{const sub=parseFloat(box.dataset.subtotal),rate=parseFloat(box.dataset.rate),free=parseFloat(box.dataset.free);const r=(e.detail.fee===null||e.detail.fee===undefined)?rate:e.detail.fee;const fee=(free>0&&sub>=free)?0:r;document.getElementById('sum-fee').textContent=money(fee);document.getElementById('sum-total').textContent=money(sub+fee);document.getElementById('sum-free').hidden=fee!==0;});
const sel=document.getElementById('saved-address');
if(sel)sel.addEventListener('change',()=>{const o=sel.selectedOptions[0];const d=o&&o.dataset.address?JSON.parse(o.dataset.address):{recipient_name:form.recipient_name.defaultValue,recipient_phone:form.recipient_phone.defaultValue,province:'<?=e(DEFAULT_PROVINCE)?>',city:'',barangay:'',house_no:'',street:'',landmark:''};
 ['recipient_name','recipient_phone','house_no','street','landmark'].forEach(k=>{form.elements[k].value=d[k]||'';});form.querySelector('[data-addr-group]').setPlace(d);if(o&&o.dataset.address)document.getElementById('save-address').checked=false;sync();});
const chk=document.getElementById('save-address'),lw=document.getElementById('label-wrap');function sync(){lw.hidden=!chk.checked;}chk.addEventListener('change',sync);sync();})();
</script>
<div class="bs-modal" id="orderConfirmModal" aria-hidden="true">
 <div class="bs-modal-backdrop" data-order-cancel></div>
 <section class="bs-modal-card" role="dialog" aria-modal="true" aria-labelledby="orderConfirmTitle" tabindex="-1">
  <div class="bs-modal-icon yellow"><span>🛒</span></div><h2 id="orderConfirmTitle">Confirm your order</h2>
  <p>Please review your delivery details and order summary. Do you want to place this order?</p>
  <div class="bs-modal-actions"><button type="button" class="bs-modal-secondary" data-order-cancel>Go back</button><button type="button" class="bs-modal-primary" id="confirm-place-order">Confirm order</button></div>
 </section>
</div>
<script>
(()=>{const form=document.getElementById('checkout-form'),modal=document.getElementById('orderConfirmModal');if(!form||!modal)return;let confirmed=false;let opener=null;const close=()=>{modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');document.body.classList.remove('bs-modal-open');if(opener)opener.focus();};form.addEventListener('submit',e=>{if(e.submitter&&e.submitter.value==='fix_stock')return;if(confirmed){confirmed=false;return;}e.preventDefault();if(!form.reportValidity())return;opener=document.activeElement;modal.classList.add('is-open');modal.setAttribute('aria-hidden','false');document.body.classList.add('bs-modal-open');modal.querySelector('.bs-modal-card').focus();},{capture:true});document.getElementById('confirm-place-order').addEventListener('click',()=>{confirmed=true;close();form.requestSubmit();});modal.querySelectorAll('[data-order-cancel]').forEach(el=>el.addEventListener('click',close));document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('is-open'))close();});})();
</script>
<script>document.addEventListener('DOMContentLoaded',()=>{const cards=document.querySelectorAll('.payment-card');const ref=document.getElementById('reference-field');function sync(){const online=document.querySelector('input[name="payment_method"]:checked')?.value==='Online Payment';ref.hidden=!online;cards.forEach(c=>c.classList.toggle('selected',c.querySelector('input').checked));}document.querySelectorAll('input[name="payment_method"]').forEach(r=>r.addEventListener('change',sync));sync();});</script>
<?php require __DIR__.'/includes/footer.php'; ?>
