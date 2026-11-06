<?php
require_once __DIR__.'/../config/config.php';require_once __DIR__.'/../includes/functions.php';require_admin();
$aid=(int)$_SESSION['admin_id'];$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$action=$_POST['action']??'profile';
 if($action==='profile'){
  $name=trim($_POST['admin_name']??'');$contact=trim($_POST['contact_number']??'');
  if($name===''){$error='Please enter a name.';}
  else{$s=$pdo->prepare('UPDATE administrators SET admin_name=?,contact_number=? WHERE admin_id=?');$s->execute([$name,$contact,$aid]);$_SESSION['display_name']=$name;flash('Profile updated.');redirect('/admin/settings.php');}
 }
 if($action==='password'){
  $cur=$_POST['current_password']??'';$new=$_POST['new_password']??'';$conf=$_POST['confirm_password']??'';
  $s=$pdo->prepare('SELECT password FROM administrators WHERE admin_id=?');$s->execute([$aid]);$hash=(string)$s->fetchColumn();
  if(!password_verify($cur,$hash)){$error='Your current password is incorrect.';}
  elseif(strlen($new)<8){$error='New password must be at least 8 characters.';}
  elseif($new==='admin123'){$error='Choose a password different from the default one.';}
  elseif($new!==$conf){$error='New password and confirmation do not match.';}
  else{$s=$pdo->prepare('UPDATE administrators SET password=?,must_change_password=0 WHERE admin_id=?');$s->execute([password_hash($new,PASSWORD_DEFAULT),$aid]);unset($_SESSION['must_change_password']);log_activity($pdo,'password_change','admin',$aid,'Administrator changed own password');flash('Password changed.');redirect('/admin/settings.php');}
 }
}
$s=$pdo->prepare('SELECT * FROM administrators WHERE admin_id=?');$s->execute([$aid]);$a=$s->fetch();
if(!$a){session_destroy();redirect('/login.php');}
$pageTitle='Profile';require __DIR__.'/../includes/header.php';
?>
<div class="page-head"><div><h1>Profile</h1><p class="muted">Manage your administrator profile and password.</p></div></div>
<?php if(!empty($_SESSION['must_change_password'])): ?><div class="alert alert-error">You are using the default password. Please set a new password to continue.</div><?php endif; ?>
<?php if($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<div class="grid u-cols-2-start">
 <div class="card"><h2>Profile</h2>
  <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="profile">
   <label for="st-name">Name</label><input id="st-name" name="admin_name" value="<?= e($a['admin_name']) ?>" required>
   <label for="st-email">Email / username</label><input id="st-email" value="<?= e($a['email']) ?>" disabled>
   <label for="st-contact">Contact number</label><input id="st-contact" name="contact_number" value="<?= e($a['contact_number']) ?>">
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
<?php require __DIR__.'/../includes/footer.php'; ?>
