<?php
require_once __DIR__.'/config/config.php'; require_once __DIR__.'/includes/functions.php';
$error='';$errField='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 $name=clean_text($_POST['full_name']??''); $email=trim($_POST['email']??''); $phone=clean_text($_POST['phone']??''); $password=$_POST['password']??'';
 $ve=validate_customer_profile($name,$phone);
 if(!csrf_ok()){$error='This page was open for too long. Your details are still here, please press Create account again.';}
 elseif($ve!==null){$error=$ve['message'];$errField=$ve['field'];}
 elseif(!filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($email)>EMAIL_MAX){$error='Enter a valid email address (up to '.EMAIL_MAX.' characters).';$errField='email';}
 elseif(strlen($password)<8){$error='Your password must be at least 8 characters.';$errField='password';}
 else {try{$s=$pdo->prepare('INSERT INTO users(full_name,email,password,phone) VALUES(?,?,?,?)');$s->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$phone]);flash('Account created. Please sign in.');redirect('/login.php');}catch(PDOException $e){if($e->getCode()==='23000'){$error='That email address is already registered.';$errField='email';}else{error_log('BuildSmart registration failed: '.$e->getMessage());$error=GENERIC_ERROR;}}}
}
$logo = '<svg viewBox="0 0 40 40" aria-hidden="true"><path d="M20 2l16 9v18l-16 9L4 29V11z" fill="#ffc20e"/><path d="M20 10l9 5v10l-9 5-9-5V15z" fill="#14181f"/><circle cx="20" cy="20" r="4" fill="#ffc20e"/></svg>';
$old=['full_name'=>$_POST['full_name']??'','email'=>$_POST['email']??'','phone'=>$_POST['phone']??''];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create account | BuildSmart</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body>
<div class="login-page">
 <section class="login-brand-panel" aria-label="BuildSmart benefits">
  <a class="brand" href="<?= BASE_URL ?>/"><?= $logo ?>Build<span>Smart</span></a>
  <div class="login-brand-copy">
   <div class="login-kicker">BuildSmart · Customer account</div>
   <h1>Everything for your build, one account.</h1>
   <p>Order cement, steel, paint, wiring, plumbing and tools online, then follow every delivery.</p>
   <ul class="login-features">
    <li><span class="login-feature-icon"><?= icon("box") ?></span> Browse live stock before you order</li>
    <li><span class="login-feature-icon"><?= icon("truck") ?></span> Track every order, step by step</li>
    <li><span class="login-feature-icon"><?= icon("clipboard-list") ?></span> Keep digital receipts in one place</li>
   </ul>
  </div>
 </section>
 <main class="login-main">
  <section class="login-card wide">
   <h2>Create your account</h2><p class="login-subtitle">Register to browse and order hardware products.</p>
   <?php if($error): ?><div id="form-error" class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
   <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <label for="reg-name">Full name</label>
    <input id="reg-name"<?= field_attrs('full_name',$errField) ?> type="text" name="full_name" required maxlength="120" autocomplete="name" placeholder="Enter your full name" value="<?= e($old['full_name']) ?>">
    <label for="reg-email">Email address</label>
    <input id="reg-email"<?= field_attrs('email',$errField) ?> type="email" name="email" required maxlength="150" autocomplete="email" placeholder="Enter your email address" value="<?= e($old['email']) ?>">
    <label for="reg-phone">Phone (required for delivery)</label>
    <input id="reg-phone"<?= field_attrs('phone',$errField) ?> type="tel" required maxlength="20" pattern="[0-9+\(\)\-\s]{7,20}" title="7 to 20 characters: numbers, spaces, + ( ) -" name="phone" autocomplete="tel" placeholder="Enter your phone number" value="<?= e($old['phone']) ?>">
    <p class="small muted">You will enter your delivery address when you check out.</p>
    <label for="reg-password">Password</label>
    <input id="reg-password"<?= field_attrs('password',$errField) ?> type="password" name="password" minlength="8" required autocomplete="new-password" placeholder="At least 8 characters" aria-describedby="password-strength"><small id="password-strength" class="password-strength" aria-live="polite">Use 8 or more characters, including letters and numbers.</small>
    <button class="login-submit" type="submit">Create account</button>
   </form>
   <p class="login-register">Already registered? <a href="<?= BASE_URL ?>/login.php">Sign in</a></p>
  </section>
 </main>
</div>
<script>
(()=>{const p=document.getElementById('reg-password'),hint=document.getElementById('password-strength');p?.addEventListener('input',()=>{const v=p.value;let score=0;if(v.length>=8)score++;if(/[A-Z]/.test(v)&&/[a-z]/.test(v))score++;if(/[0-9]/.test(v))score++;if(/[^A-Za-z0-9]/.test(v))score++;hint.textContent=!v?'Use 8 or more characters, including letters and numbers.':score<=1?'Weak password — add length, numbers, or symbols.':score<=2?'Fair password — add another character type.':'Strong password.';hint.dataset.strength=score<=1?'weak':score<=2?'fair':'strong';});})();
</script>
</body></html>
