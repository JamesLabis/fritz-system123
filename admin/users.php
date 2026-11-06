<?php
require_once __DIR__.'/../config/config.php';require_once __DIR__.'/../includes/functions.php';require_admin();
$q=trim($_GET['q']??'');$tempPw=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$uid=(int)($_POST['user_id']??0);$act=$_POST['action']??'';
 $nm=$pdo->prepare('SELECT full_name,is_active FROM users WHERE user_id=?');$nm->execute([$uid]);$tu=$nm->fetch();
 if($tu&&$act==='reset'){$tempPw=substr(str_replace(['+','/','='],'',base64_encode(random_bytes(12))),0,10).'7a';$pdo->prepare('UPDATE users SET password=? WHERE user_id=?')->execute([password_hash($tempPw,PASSWORD_DEFAULT),$uid]);log_activity($pdo,'user_password_reset','user',$uid,$tu['full_name']);$tempFor=$tu['full_name'];}
 if($tu&&$act==='toggle'){$new=(int)$tu['is_active']?0:1;$pdo->prepare('UPDATE users SET is_active=? WHERE user_id=?')->execute([$new,$uid]);log_activity($pdo,$new?'user_activate':'user_deactivate','user',$uid,$tu['full_name']);flash($new?'Account activated.':'Account deactivated.');redirect('/admin/users.php'.($q!==''?'?q='.urlencode($q):''));}
}
$sql="SELECT u.user_id,u.full_name,u.email,u.phone,u.created_at,u.is_active,COUNT(o.order_id) order_count,COALESCE(SUM(CASE WHEN o.order_status<>'Cancelled' THEN o.total_amount END),0) spent FROM users u LEFT JOIN orders o ON o.user_id=u.user_id";$params=[];
if($q!==''){$sql.=' WHERE u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?';$params=["%$q%","%$q%","%$q%"];}
$sql.=' GROUP BY u.user_id ORDER BY u.created_at DESC';$s=$pdo->prepare($sql);$s->execute($params);$users=$s->fetchAll();
$pageTitle='Users';require __DIR__.'/../includes/header.php';
?>
<div class="page-head"><div><h1>Users</h1><p class="muted">Registered customers and their order activity.</p></div></div>
<form class="admin-search" method="get"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by name, email or phone…" aria-label="Search users"><button class="btn btn-primary">Search</button><?php if($q!==''): ?><a class="btn btn-outline" href="users.php">Clear</a><?php endif; ?></form>
<?php if($tempPw): ?><div class="alert alert-success">Temporary password for <b><?= e($tempFor) ?></b>: <code><?= e($tempPw) ?></code> — shown once. Give it to the customer and ask them to change it after signing in.</div><?php endif; ?>
<div class="card"><div class="table-wrap"><table><thead><tr><th>Customer</th><th>Email</th><th>Phone</th><th>Registered</th><th>Orders</th><th>Total spent</th><th>Account</th></tr></thead><tbody>
<?php foreach($users as $u): ?><tr><td><?= e($u['full_name']) ?></td><td><?= e($u['email']) ?></td><td><?= e($u['phone']??'—') ?></td><td><?= e($u['created_at']) ?></td><td><?= (int)$u['order_count'] ?></td><td><?= money($u['spent']) ?></td><td><form class="u-flex-gap6" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>"><button class="btn btn-outline btn-sm" name="action" value="reset" onclick="return confirm('Reset this customer\'s password?')">Reset password</button><button class="btn btn-outline btn-sm" name="action" value="toggle"><?= (int)$u['is_active']?'Deactivate':'Activate' ?></button></form></td></tr><?php endforeach; ?>
<?php if(!$users): ?><tr><td colspan="7" class="muted">No customers found.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require __DIR__.'/../includes/footer.php'; ?>
