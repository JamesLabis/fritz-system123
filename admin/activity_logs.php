<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$action = trim($_GET['action'] ?? '');
$q      = trim($_GET['q'] ?? '');
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 50;

$where = ['1']; $args = [];
if ($action !== '') { $where[] = 'action=?'; $args[] = $action; }
if ($q !== '')      { $where[] = '(admin_name LIKE ? OR details LIKE ? OR CAST(entity_id AS CHAR)=?)'; array_push($args, "%$q%", "%$q%", $q); }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'DATE(created_at)>=?'; $args[] = $from; } else { $from = ''; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 'DATE(created_at)<=?'; $args[] = $to; }   else { $to = ''; }
$cond = implode(' AND ', $where);

$total = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE $cond"); $total->execute($args);
$total = (int)$total->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);

$s = $pdo->prepare("SELECT * FROM activity_logs WHERE $cond ORDER BY log_id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$s->execute($args);
$logs = $s->fetchAll();
$actions = $pdo->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

$qs = function (array $extra) use ($action, $q, $from, $to) {
    return '?' . http_build_query(array_merge(['action' => $action, 'q' => $q, 'from' => $from, 'to' => $to], $extra));
};

$pageTitle = 'Activity Logs';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><div><h1>Activity Logs</h1><p class="muted">A read-only record of important administrator actions and order events.</p></div></div>
<form class="admin-search u-wrap-gap8" method="get">
  <select name="action" aria-label="Action"><option value="">All actions</option><?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= $a === $action ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Admin, order number or detail…" aria-label="Search logs">
  <input type="date" name="from" value="<?= e($from) ?>" aria-label="From date"><input type="date" name="to" value="<?= e($to) ?>" aria-label="To date">
  <button class="btn btn-primary">Filter</button>
  <?php if ($action !== '' || $q !== '' || $from !== '' || $to !== ''): ?><a class="btn btn-outline" href="activity_logs.php">Clear</a><?php endif; ?>
</form>
<div class="card"><div class="table-wrap"><table>
<thead><tr><th>When</th><th>Admin</th><th>Action</th><th>Record</th><th>Details</th><th>IP</th></tr></thead><tbody>
<?php foreach ($logs as $l): ?>
<tr><td class="small"><?= e($l['created_at']) ?></td><td><?= e($l['admin_name'] ?? 'System') ?></td><td><span class="pill"><?= e($l['action']) ?></span></td>
<td class="small"><?= e($l['entity']) ?><?= $l['entity_id'] ? ' #' . (int)$l['entity_id'] : '' ?></td><td class="small"><?= e($l['details'] ?? '') ?></td><td class="small muted"><?= e($l['ip'] ?? '') ?></td></tr>
<?php endforeach; ?>
<?php if (!$logs): ?><tr><td colspan="6" class="muted">No activity recorded yet.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php if ($pages > 1): ?>
<p class="small u-mt-14">Page <?= $page ?> of <?= $pages ?> · <?= $total ?> entries
  <?php if ($page > 1): ?> · <a href="<?= e($qs(['page' => $page - 1])) ?>">← Newer</a><?php endif; ?>
  <?php if ($page < $pages): ?> · <a href="<?= e($qs(['page' => $page + 1])) ?>">Older →</a><?php endif; ?></p>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
