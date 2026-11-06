<?php
require_once __DIR__.'/config/config.php'; require_once __DIR__.'/includes/functions.php'; require_login();
if(!current_user_id()) redirect('/admin/settings.php');
$uid=current_user_id();$error='';$errField='';$errSection='';
$posted=null;$postedAddr=null;
$editId=(int)($_GET['edit']??0);
if($_SERVER['REQUEST_METHOD']==='POST'){
 $action=(string)($_POST['action']??'profile');
 if($action==='profile'){$posted=['full_name'=>clean_text($_POST['full_name']??''),'phone'=>clean_text($_POST['phone']??'')];}
 if(!csrf_ok()){$error='This page was open for too long, so nothing was saved. Please press the button again.';}
 elseif($action==='profile'){
  $ve=validate_customer_profile($posted['full_name'],$posted['phone']);
  if($ve!==null){$error=$ve['message'];$errField=$ve['field'];$errSection='profile';}
  else{
   try{$s=$pdo->prepare('UPDATE users SET full_name=?,phone=? WHERE user_id=?');$s->execute([$posted['full_name'],$posted['phone'],$uid]);$_SESSION['display_name']=$posted['full_name'];flash(msg('saved','Profile'));redirect('/account.php');}
   catch(PDOException $e){error_log('BuildSmart profile update failed: '.$e->getMessage());$error=GENERIC_ERROR;}
  }
 }
 elseif($action==='save_address'){
  $editId=(int)($_POST['address_id']??0);$errSection='address';
  $place=resolve_delivery_input($pdo,$_POST);$postedAddr=$place['values']+['label'=>clean_text($_POST['address_label']??'')];
  if($place['error']!==null){$error=$place['error']['message'];$errField=$place['error']['field'];}
  else{
   try{save_user_address($pdo,$uid,$place['values'],(string)($_POST['address_label']??''),!empty($_POST['make_default']),$editId);flash(msg('saved','Address'));redirect('/account.php');}
   catch(UserFacingException $e){$error=$e->getMessage();}
   catch(PDOException $e){error_log('BuildSmart address save failed: '.$e->getMessage());$error=GENERIC_ERROR;}
  }
 }
 elseif($action==='default_address'||$action==='delete_address'){
  try{
   $aid=(int)($_POST['address_id']??0);
   if($action==='delete_address'){delete_user_address($pdo,$uid,$aid);flash('Address deleted.');}
   else{set_default_address($pdo,$uid,$aid);flash('Default address updated.');}
  }catch(PDOException $e){error_log('BuildSmart address action failed: '.$e->getMessage());flash(GENERIC_ERROR,'error');}
  redirect('/account.php');
 }
 elseif($action==='password'){
  $cur=$_POST['current_password']??'';$new=$_POST['new_password']??'';$conf=$_POST['confirm_password']??'';
  $s=$pdo->prepare('SELECT password FROM users WHERE user_id=?');$s->execute([$uid]);$hash=(string)$s->fetchColumn();
  if(!password_verify($cur,$hash)){$error='Your current password is incorrect.';}
  elseif(strlen($new)<8){$error='New password must be at least 8 characters.';}
  elseif($new!==$conf){$error='New password and confirmation do not match.';}
  else{
   try{$s=$pdo->prepare('UPDATE users SET password=? WHERE user_id=?');$s->execute([password_hash($new,PASSWORD_DEFAULT),$uid]);flash('Password changed.');redirect('/account.php');}
   catch(PDOException $e){error_log('BuildSmart password change failed: '.$e->getMessage());$error=GENERIC_ERROR;}
  }
 }
}
$s=$pdo->prepare('SELECT * FROM users WHERE user_id=?');$s->execute([$uid]);$u=$s->fetch();
if(!$u){session_destroy();redirect('/login.php');}
$form=$posted??['full_name'=>(string)$u['full_name'],'phone'=>(string)($u['phone']??'')];
$addresses=user_addresses($pdo,$uid);
// The address form: a new address, or the one being edited.
$editing=null;if($editId){foreach($addresses as $a){if((int)$a['address_id']===$editId){$editing=$a;}}}
if($postedAddr!==null)$addrForm=$postedAddr;
elseif($editing)$addrForm=['recipient_name'=>$editing['recipient_name'],'recipient_phone'=>$editing['recipient_phone'],'province'=>$editing['province'],'city'=>$editing['city'],'barangay'=>$editing['barangay'],'house_no'=>(string)$editing['house_no'],'street'=>$editing['street'],'landmark'=>(string)$editing['landmark'],'label'=>(string)$editing['label']];
else $addrForm=['recipient_name'=>(string)$u['full_name'],'recipient_phone'=>(string)($u['phone']??''),'province'=>DEFAULT_PROVINCE,'label'=>''];
$formOpen=$editing!==null||$errSection==='address';
$pageTitle='My Profile';require __DIR__.'/includes/header.php';
?>
<div class="page-head"><div><h1>My Profile</h1><p class="muted">Update your details, saved delivery addresses and password.</p></div><a class="btn btn-outline" href="<?= BASE_URL ?>/my_orders.php">My Orders</a></div>
<?php if($error): ?><div id="form-error" class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="grid u-cols-2-start">
 <div class="card"><h2>Profile</h2>
  <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="profile">
   <label for="acc-name">Full name</label><input id="acc-name"<?= field_attrs('full_name',$errSection==='profile'?$errField:'') ?> name="full_name" value="<?= e($form['full_name']) ?>" required maxlength="120">
   <label for="acc-email">Email</label><input id="acc-email" value="<?= e($u['email']) ?>" disabled>
   <label for="acc-phone">Phone</label><input id="acc-phone"<?= field_attrs('phone',$errSection==='profile'?$errField:'') ?> type="tel" name="phone" value="<?= e($form['phone']) ?>" required maxlength="20" pattern="[0-9+\(\)\-\s]{7,20}" title="7 to 20 characters: numbers, spaces, + ( ) -" autocomplete="tel">
   <p class="small muted">You only need a delivery address when you check out.</p>
   <div class="form-actions"><button class="btn btn-primary">Save changes</button></div>
  </form></div>
 <div class="card"><h2>Change password</h2>
  <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="password">
   <label for="pw-cur">Current password</label><input id="pw-cur" type="password" name="current_password" required autocomplete="current-password">
   <label for="pw-new">New password</label><input id="pw-new" type="password" name="new_password" minlength="8" required autocomplete="new-password">
   <label for="pw-conf">Confirm new password</label><input id="pw-conf" type="password" name="confirm_password" minlength="8" required autocomplete="new-password">
   <div class="form-actions"><button class="btn btn-primary">Update password</button></div>
  </form></div>
</div>
<div class="card u-mt-18"><h2>Saved addresses</h2>
 <?php if(!$addresses && !empty($u['address'])): ?><p class="small muted">Old address on file: <?= e($u['address']) ?>. Add it below with its province, city and barangay so drivers can find you.</p><?php endif; ?>
 <?php if(!$addresses): ?><p class="muted">No saved addresses yet. Add one here, or tick “Save this address” at checkout.</p><?php endif; ?>
 <?php foreach($addresses as $a): ?>
 <div class="delivery-box"><p><b><?= e($a['label'] ?: 'Address') ?></b> <?php if($a['is_default']): ?><span class="pill s-completed">Default</span><?php endif; ?></p>
  <p><?= e($a['recipient_name']) ?> · <?= e($a['recipient_phone']) ?><br><?= e(compose_address($a['street'],$a['barangay'],$a['city'],$a['province'],(string)$a['house_no'])) ?><?php if($a['landmark']): ?><br><span class="muted small">Landmark / notes: <?= e($a['landmark']) ?></span><?php endif; ?></p>
  <div class="delivery-head-actions">
   <a class="btn btn-outline btn-sm" href="?edit=<?= (int)$a['address_id'] ?>#address-form">Edit</a>
   <?php if(!$a['is_default']): ?><form class="u-inline" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="default_address"><input type="hidden" name="address_id" value="<?= (int)$a['address_id'] ?>"><button class="btn btn-outline btn-sm">Make default</button></form><?php endif; ?>
   <form class="u-inline" method="post" onsubmit="return confirm('Delete this saved address? Your past orders are not affected.')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_address"><input type="hidden" name="address_id" value="<?= (int)$a['address_id'] ?>"><button class="btn btn-outline btn-sm">Delete</button></form>
  </div></div>
 <?php endforeach; ?>
 <details id="address-form" class="u-mt-18"<?= $formOpen?' open':'' ?>><summary><b><?= $editing?'Edit address':'Add an address' ?></b></summary>
  <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_address"><input type="hidden" name="address_id" value="<?= $editing?(int)$editing['address_id']:0 ?>">
   <label for="acc-label">Label (optional)</label><input id="acc-label" name="address_label" maxlength="<?= LABEL_MAX ?>" value="<?= e((string)$addrForm['label']) ?>" placeholder="e.g. Home, Job site, Warehouse">
   <?= address_fields($addrForm,$errSection==='address'?$errField:'','acc',$pdo) ?>
   <label class="u-check-row"><input class="u-check-input" type="checkbox" name="make_default" value="1"> <span>Make this my default address</span></label>
   <div class="form-actions"><button class="btn btn-primary"><?= $editing?'Save address':'Add address' ?></button><?php if($editing): ?> <a class="btn btn-outline" href="<?= BASE_URL ?>/account.php">Cancel</a><?php endif; ?></div>
  </form></details>
</div>
<?= place_picker_script($pdo) ?>
<?php require __DIR__.'/includes/footer.php'; ?>
