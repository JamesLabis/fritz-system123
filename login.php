<?php
require_once __DIR__.'/config/config.php'; require_once __DIR__.'/includes/functions.php';
$error='';
/** Minutes until a lockout on column $col ('email' or 'ip') eases: when the oldest attempt that keeps the count over $limit leaves the 15-minute window. */
function lockout_wait_minutes(PDO $pdo,string $col,string $val,int $limit):int{
 $s=$pdo->prepare("SELECT TIMESTAMPDIFF(SECOND,NOW(),attempted_at + INTERVAL 15 MINUTE) FROM login_attempts WHERE $col=? AND attempted_at > NOW() - INTERVAL 15 MINUTE ORDER BY attempted_at ASC");
 $s->execute([$val]);$secs=$s->fetchAll(PDO::FETCH_COLUMN);$i=count($secs)-$limit;
 return isset($secs[$i])?max(1,(int)ceil(((int)$secs[$i])/60)):15;
}
/** Same as lockout_wait_minutes, for the email + IP pair. */
function lockout_wait_minutes_pair(PDO $pdo,string $email,string $ip,int $limit):int{
 $s=$pdo->prepare("SELECT TIMESTAMPDIFF(SECOND,NOW(),attempted_at + INTERVAL 15 MINUTE) FROM login_attempts WHERE email=? AND ip=? AND attempted_at > NOW() - INTERVAL 15 MINUTE ORDER BY attempted_at ASC");
 $s->execute([$email,$ip]);$secs=$s->fetchAll(PDO::FETCH_COLUMN);$i=count($secs)-$limit;
 return isset($secs[$i])?max(1,(int)ceil(((int)$secs[$i])/60)):15;
}
$next=(string)($_GET['next']??$_POST['next']??'');
// Show why we are here (e.g. "Your session expired" or "Account created"); this page does not use the shared header.
$loginFlash=$_SESSION['flash']??null; unset($_SESSION['flash']);
if($_SERVER['REQUEST_METHOD']==='POST'){
 $email=trim($_POST['email']??''); $password=$_POST['password']??''; $ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,45);
 if(!csrf_ok()){ $error='This page was open for too long. Please press Sign in again.'; }
 else {
 // Lockout within 15 minutes: 5 failures from the same email AND the same IP (so a stranger cannot lock the real owner out
 // from another address), 20 failures against one email from anywhere (distributed guessing), or 20 failures from one IP.
 $c=$pdo->prepare('SELECT SUM(email=?) pair, COUNT(*) i FROM login_attempts WHERE ip=? AND attempted_at > NOW() - INTERVAL 15 MINUTE'); $c->execute([$email,$ip]); $cnt=$c->fetch();
 $c2=$pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email=? AND attempted_at > NOW() - INTERVAL 15 MINUTE'); $c2->execute([$email]);
 $pairLocked=(int)($cnt['pair']??0)>=5;$emailLocked=(int)$c2->fetchColumn()>=20;$ipLocked=(int)($cnt['i']??0)>=20;
 if($pairLocked || $emailLocked || $ipLocked){
  $wait=max($pairLocked?lockout_wait_minutes_pair($pdo,$email,$ip,5):0,$emailLocked?lockout_wait_minutes($pdo,'email',$email,20):0,$ipLocked?lockout_wait_minutes($pdo,'ip',$ip,20):0);
  $error='Too many failed attempts. Please try again in about '.$wait.' minute'.($wait===1?'':'s').'.';
 }
 else {
 // Resolve the account type automatically so the simplified login still supports admins and customers.
 $s=$pdo->prepare('SELECT * FROM administrators WHERE email=?');$s->execute([$email]);$a=$s->fetch();
 if($a && password_verify($password,$a['password'])){session_regenerate_id(true);unset($_SESSION['user_id']);$_SESSION['admin_id']=(int)$a['admin_id'];$_SESSION['display_name']=$a['admin_name'];if(!empty($a['must_change_password']))$_SESSION['must_change_password']=1;$pdo->prepare('DELETE FROM login_attempts WHERE email=?')->execute([$email]);log_activity($pdo,'login','admin',(int)$a['admin_id'],'Administrator signed in',(int)$a['admin_id'],$a['admin_name']);redirect(!empty($a['must_change_password'])?'/admin/settings.php':post_login_target($next,true,'/admin/dashboard.php'));}
 if($a){log_activity($pdo,'login_failed','admin',(int)$a['admin_id'],'Wrong password for administrator account',null,$a['admin_name']);}
 $s=$pdo->prepare('SELECT * FROM users WHERE email=?');$s->execute([$email]);$u=$s->fetch();
 if($u && password_verify($password,$u['password'])){
  if(isset($u['is_active']) && !(int)$u['is_active']){$error='This account has been deactivated. Please contact the store.';}
  else{session_regenerate_id(true);unset($_SESSION['admin_id'],$_SESSION['must_change_password']);$_SESSION['user_id']=(int)$u['user_id'];$_SESSION['display_name']=$u['full_name'];$pdo->prepare('DELETE FROM login_attempts WHERE email=?')->execute([$email]);redirect(post_login_target($next,false,'/'));}
 } else {
  $pdo->prepare('INSERT INTO login_attempts(email,ip) VALUES(?,?)')->execute([mb_substr($email,0,150),$ip]);
  if($u){log_activity($pdo,'login_failed','customer',(int)$u['user_id'],'Wrong password for customer account',null,$u['full_name']);}
  $error='Invalid email or password.';
 }
 }
 }
}
$logo = '<svg viewBox="0 0 40 40" aria-hidden="true"><path d="M20 2l16 9v18l-16 9L4 29V11z" fill="#ffc20e"/><path d="M20 10l9 5v10l-9 5-9-5V15z" fill="#14181f"/><circle cx="20" cy="20" r="4" fill="#ffc20e"/></svg>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in | BuildSmart</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body>
<div class="login-page">
 <section class="login-brand-panel" aria-label="BuildSmart features">
  <a class="brand" href="<?= BASE_URL ?>/"><?= $logo ?>Build<span>Smart</span></a>
  <div class="login-brand-copy">
   <div class="login-kicker">BuildSmart · Sign in</div>
   <h1>Everything for your build, in one place.</h1>
   <p>Order cement, steel, paint, wiring, plumbing and tools — and follow every delivery. Store staff sign in here too.</p>
   <ul class="login-features">
    <li><span class="login-feature-icon"><?= icon("box") ?></span> Live stock before you order</li>
    <li><span class="login-feature-icon"><?= icon("truck") ?></span> Track every order, step by step</li>
    <li><span class="login-feature-icon"><?= icon("clipboard-list") ?></span> Digital receipts in one place</li>
   </ul>
  </div>
 </section>
 <main class="login-main">
  <section class="login-card">
   <h2>Welcome back</h2><p class="login-subtitle">Sign in to continue to your account.</p>
   <?php if($loginFlash && !$error): ?><div id="form-error" class="alert <?= ($loginFlash['type']??'')==='success'?'alert-info':'alert-error' ?>" role="alert"><?= e($loginFlash['message']) ?></div><?php endif; ?>
   <?php if($error): ?><div id="form-error" class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
   <form method="post" id="login-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="next" value="<?= e(safe_next($next)??'') ?>">
    <label for="login-email">Email or username</label>
    <input id="login-email" type="text" name="email" maxlength="150" required<?= $error?' aria-invalid="true" aria-describedby="form-error"':'' ?> autocomplete="username" placeholder="Email address or admin username" value="<?= e($_POST['email']??'') ?>">
    <label for="login-password">Password</label>
    <div class="password-field"><input id="login-password" type="password" name="password" required<?= $error?' aria-invalid="true" aria-describedby="form-error"':'' ?> autocomplete="current-password" placeholder="Enter your password"><button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false" aria-controls="login-password" id="toggle-password">Show</button></div>
    <div class="login-options">
     <label class="remember-label"><input type="checkbox" id="remember-login"> Remember me</label>
     <a class="forgot-link" href="#forgot-password" id="forgot-password-link">Forgot password?</a>
    </div>
    <div class="login-help" id="forgot-password-help">Customers: contact the store and ask for a password reset. Staff will give you a temporary password, and you can change it under My Profile after signing in. Staff accounts: ask another administrator.</div>
    <button class="login-submit" type="submit">Sign in</button>
   </form>
   <p class="login-register">New customer? <a href="<?= BASE_URL ?>/register.php">Create an account</a></p>
  </section>
 </main>
</div>
<script>
(()=>{const password=document.getElementById('login-password'),toggle=document.getElementById('toggle-password');toggle?.addEventListener('click',()=>{const show=password.type==='password';password.type=show?'text':'password';toggle.textContent=show?'Hide':'Show';toggle.setAttribute('aria-label',show?'Hide password':'Show password');toggle.setAttribute('aria-pressed',show?'true':'false');});const user=document.getElementById('login-email'),remember=document.getElementById('remember-login'),key='buildsmart_remembered_login';try{const saved=localStorage.getItem(key);if(saved){user.value=saved;remember.checked=true;}}catch(e){}document.getElementById('login-form').addEventListener('submit',()=>{try{if(remember.checked)localStorage.setItem(key,user.value.trim());else localStorage.removeItem(key);}catch(e){}});document.getElementById('forgot-password-link').addEventListener('click',e=>{e.preventDefault();document.getElementById('forgot-password-help').classList.toggle('show');});})();
</script>
</body></html>
