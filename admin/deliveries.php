<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $tab    = $_POST['tab'] ?? 'assign';
    try {
        switch ($action) {
            case 'assign':
                assign_delivery($pdo, (int)($_POST['order_id'] ?? 0), (int)($_POST['driver_id'] ?? 0), (string)($_POST['scheduled_date'] ?? ''), trim($_POST['notes'] ?? ''));
                flash('Driver assigned. The customer has been notified.');
                $tab = 'assigned';
                break;
            case 'edit':
                update_delivery($pdo, (int)($_POST['delivery_id'] ?? 0), (int)($_POST['driver_id'] ?? 0), (string)($_POST['scheduled_date'] ?? ''), trim($_POST['notes'] ?? ''));
                flash('Delivery updated.');
                break;
            case 'dispatch':
                dispatch_delivery($pdo, (int)($_POST['delivery_id'] ?? 0));
                flash('Delivery dispatched. The order is now Out for Delivery.');
                $tab = 'out';
                break;
            case 'complete':
                $cod = ($_POST['cod_collected'] ?? '') !== '' ? (float)$_POST['cod_collected'] : null;
                complete_delivery($pdo, (int)($_POST['delivery_id'] ?? 0), trim($_POST['received_by'] ?? ''), $cod);
                flash('Delivery completed. The order is now Completed.');
                $tab = 'delivered';
                break;
            case 'fail':
                fail_delivery($pdo, (int)($_POST['delivery_id'] ?? 0), trim($_POST['reason'] ?? ''));
                flash('Delivery marked as failed. The order is back in "To assign" so it can be rescheduled.');
                $tab = 'assign';
                break;
            default:
                throw new UserFacingException('Unknown action.');
        }
    } catch (Throwable $e) {
        // Our own UserFacingException messages are shown; anything else is logged and replaced by a generic line.
        flash(user_error_message($e, 'delivery action'), 'error');
    }
    redirect('/admin/deliveries.php?tab=' . urlencode($tab));
}

/* ---------- Data ---------- */
$tabs = [
    'assign'    => 'To assign',
    'assigned'  => 'Assigned',
    'out'       => 'Out for delivery',
    'delivered' => 'Delivered',
    'failed'    => 'Failed attempts',
];
$statusOfTab = ['assigned' => 'Assigned', 'out' => 'Out for Delivery', 'delivered' => 'Delivered', 'failed' => 'Failed'];

$orderFilter = (int)($_GET['order'] ?? 0);
$tab = $_GET['tab'] ?? 'assign';
if ($orderFilter && !isset($_GET['tab'])) {
    // Coming from an order page: jump to the tab where that order currently is.
    $open = open_delivery($pdo, $orderFilter);
    $tab = $open ? ($open['status'] === 'Out for Delivery' ? 'out' : 'assigned') : 'assign';
}
if (!isset($tabs[$tab])) $tab = 'assign';

$needAssignSql = "FROM orders o
    JOIN users u ON u.user_id=o.user_id
    LEFT JOIN payments p ON p.order_id=o.order_id
    WHERE o.order_status IN ('Preparing','Ready for Delivery')
      AND NOT EXISTS (SELECT 1 FROM deliveries d WHERE d.order_id=o.order_id AND d.status IN ('Assigned','Out for Delivery'))";

$counts = ['assign' => (int)$pdo->query('SELECT COUNT(*) ' . $needAssignSql)->fetchColumn()];
foreach ($statusOfTab as $k => $st) {
    $c = $pdo->prepare('SELECT COUNT(*) FROM deliveries WHERE status=?');
    $c->execute([$st]);
    $counts[$k] = (int)$c->fetchColumn();
}

$drivers = $pdo->query("SELECT dr.driver_id,dr.driver_name,dr.phone,dr.vehicle,(SELECT COUNT(*) FROM deliveries dx WHERE dx.driver_id=dr.driver_id AND dx.status IN ('Assigned','Out for Delivery')) workload FROM drivers dr WHERE dr.is_active=1 ORDER BY dr.driver_name")->fetchAll();

$rows = [];
if ($tab === 'assign') {
    $sql = 'SELECT o.order_id,o.order_status,o.total_amount,o.delivery_address,o.delivery_province,o.delivery_city,o.delivery_barangay,o.delivery_house_no,o.delivery_street,o.delivery_landmark,o.recipient_name,o.recipient_phone,o.order_date,u.full_name,u.phone,p.payment_method,p.payment_status,
        (SELECT d2.failure_reason FROM deliveries d2 WHERE d2.order_id=o.order_id AND d2.status=\'Failed\' ORDER BY d2.delivery_id DESC LIMIT 1) last_failure ' . $needAssignSql;
    $args = [];
    if ($orderFilter) { $sql .= ' AND o.order_id=?'; $args[] = $orderFilter; }
    $sql .= ' ORDER BY o.order_date ASC';
    $s = $pdo->prepare($sql); $s->execute($args); $rows = $s->fetchAll();
} else {
    $sql = 'SELECT d.*,o.total_amount,o.delivery_address,o.delivery_province,o.delivery_city,o.delivery_barangay,o.delivery_house_no,o.delivery_street,o.delivery_landmark,o.recipient_name,o.recipient_phone,u.full_name,u.phone customer_phone,dr.driver_name,dr.phone driver_phone,dr.vehicle,p.payment_method,p.payment_status
            FROM deliveries d JOIN orders o ON o.order_id=d.order_id JOIN users u ON u.user_id=o.user_id
            LEFT JOIN drivers dr ON dr.driver_id=d.driver_id LEFT JOIN payments p ON p.order_id=o.order_id
            WHERE d.status=?';
    $args = [$statusOfTab[$tab]];
    if ($orderFilter) { $sql .= ' AND d.order_id=?'; $args[] = $orderFilter; }
    $sql .= in_array($tab, ['assigned', 'out'], true) ? ' ORDER BY d.scheduled_date ASC, d.delivery_id ASC' : ' ORDER BY d.delivery_id DESC LIMIT 100';
    $s = $pdo->prepare($sql); $s->execute($args); $rows = $s->fetchAll();
}

$pageTitle = 'Delivery Management';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><div><h1>Delivery Management</h1><p class="muted">Assign drivers, dispatch orders and record proof of delivery.</p></div>
  <div class="delivery-head-actions"><button class="btn btn-outline" type="button" onclick="window.print()">Print run-sheet</button><a class="btn btn-outline" href="drivers.php">Manage drivers</a><a class="btn btn-outline" href="zones.php">Delivery zones</a></div></div>

<?php if (!$drivers): ?>
<div class="alert alert-error">There are no active drivers yet. <a href="drivers.php">Add a driver</a> before assigning deliveries.</div>
<?php endif; ?>

<div class="order-tabs">
<?php foreach ($tabs as $k => $label): ?>
  <a class="<?= $tab === $k ? 'active' : '' ?>" href="?tab=<?= $k ?>"><?= e($label) ?> <b><?= $counts[$k] ?? 0 ?></b></a>
<?php endforeach; ?>
</div>
<?php if ($orderFilter): ?><p class="muted small">Showing order #<?= $orderFilter ?> only. <a href="?tab=<?= e($tab) ?>">Show all</a></p><?php endif; ?>

<div class="table-wrap"><table>
<?php if ($tab === 'assign'): ?>
<thead><tr><th>Order / Customer</th><th>Deliver to</th><th>Payment</th><th>Total</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r):
    $blocked = $r['payment_method'] === 'Online Payment' && $r['payment_status'] !== 'Paid'; ?>
<tr>
  <td><a href="orders.php?id=<?= (int)$r['order_id'] ?>"><b>#<?= (int)$r['order_id'] ?></b></a><br><?= e($r['full_name']) ?><div class="small muted">Account: <?= e($r['phone'] ?? '') ?></div></td>
  <td class="small u-mw-260"><?= delivery_block_html($r, (string)$r['full_name'], (string)($r['phone'] ?? '')) ?><?php if ($r['last_failure']): ?><div class="u-mt-10"><b>Last attempt failed:</b> <?= e($r['last_failure']) ?></div><?php endif; ?></td>
  <td><?= e($r['payment_method'] ?? '') ?><br><span class="small muted"><?= e($r['payment_status'] ?? '') ?></span></td>
  <td><?= money($r['total_amount']) ?></td>
  <td><span class="pill <?= pill_class($r['order_status']) ?>"><?= e($r['order_status']) ?></span></td>
  <td><?php if ($blocked): ?><a class="btn btn-outline btn-sm" href="orders.php?id=<?= (int)$r['order_id'] ?>">Verify payment first</a>
      <?php elseif (!$drivers): ?><span class="small muted">No active driver</span>
      <?php else: ?><button class="btn btn-primary btn-sm" type="button" onclick='openAssign(<?= (int)$r['order_id'] ?>)'>Assign driver</button><?php endif; ?>
      <a class="btn btn-outline btn-sm" href="orders.php?id=<?= (int)$r['order_id'] ?>&amp;fix=1">Correct address</a></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="muted">No orders are waiting for a driver. Orders appear here once they are Preparing.</td></tr><?php endif; ?>

<?php else: ?>
<thead><tr><th>Delivery / Order</th><th>Customer &amp; address</th><th>Driver</th><th>Scheduled</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php $lastDeliveryGroup = null; foreach ($rows as $r):
    $cod = $r['payment_method'] === 'Cash on Delivery' && $r['payment_status'] === 'Pending';
    $today = date('Y-m-d'); $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $groupKey = $r['scheduled_date'] < $today ? 'Overdue' : ($r['scheduled_date'] === $today ? 'Today' : ($r['scheduled_date'] === $tomorrow ? 'Tomorrow' : 'Later'));
    if ($groupKey !== $lastDeliveryGroup): $lastDeliveryGroup = $groupKey; ?>
<tr class="delivery-group-row"><th colspan="6"><?= e($groupKey) ?></th></tr>
<?php endif; ?>
<tr class="<?= in_array($r['status'], ['Assigned','Out for Delivery'], true) && $r['scheduled_date'] < date('Y-m-d') ? 'delivery-overdue' : '' ?>">
  <td>#<?= (int)$r['delivery_id'] ?><br><a href="orders.php?id=<?= (int)$r['order_id'] ?>"><b>Order #<?= (int)$r['order_id'] ?></b></a></td>
  <td><div class="small muted">Customer: <?= e($r['full_name']) ?> · <?= e($r['customer_phone'] ?? '') ?></div><div class="small"><?= delivery_block_html($r, (string)$r['full_name'], (string)($r['customer_phone'] ?? '')) ?></div></td>
  <td><?= e($r['driver_name'] ?? '—') ?><div class="small muted"><?= e($r['driver_phone'] ?? '') ?> <?= e($r['vehicle'] ?? '') ?></div></td>
  <td><?= e($r['scheduled_date']) ?>
    <?php if ($r['dispatched_at']): ?><div class="small muted">Left: <?= e($r['dispatched_at']) ?></div><?php endif; ?>
    <?php if ($r['delivered_at']): ?><div class="small muted">Delivered: <?= e($r['delivered_at']) ?></div><?php endif; ?></td>
  <td><span class="pill <?= pill_class($r['status']) ?>"><?= e($r['status']) ?></span>
    <?php if ($r['status'] === 'Out for Delivery' && $cod): ?><div class="small"><b>Collect <?= money($r['total_amount']) ?></b></div><?php endif; ?>
    <?php if ($r['received_by']): ?><div class="small muted">Received by <?= e($r['received_by']) ?></div><?php endif; ?>
    <?php if ($r['cod_collected'] !== null): ?><div class="small muted">Cash <?= money($r['cod_collected']) ?></div><?php endif; ?>
    <?php if ($r['failure_reason']): ?><div class="small muted"><?= e($r['failure_reason']) ?></div><?php endif; ?></td>
  <td>
  <button class="btn btn-outline btn-sm" type="button" onclick="printDeliverySlip(this)">Print slip</button>
  <?php if ($r['status'] === 'Assigned'): ?>
    <form class="u-inline" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="dispatch"><input type="hidden" name="tab" value="assigned"><input type="hidden" name="delivery_id" value="<?= (int)$r['delivery_id'] ?>"><button class="btn btn-primary btn-sm">Dispatch</button></form>
    <button class="btn btn-outline btn-sm" type="button" onclick='openEdit(<?= (int)$r['delivery_id'] ?>,<?= (int)$r['driver_id'] ?>,<?= json_encode($r['scheduled_date']) ?>,<?= json_encode($r['notes'] ?? '') ?>)'>Edit</button>
  <?php elseif ($r['status'] === 'Out for Delivery'): ?>
    <button class="btn btn-primary btn-sm" type="button" onclick='openComplete(<?= (int)$r['delivery_id'] ?>,<?= (int)$r['order_id'] ?>,<?= $cod ? json_encode(number_format((float)$r['total_amount'], 2, '.', '')) : 'null' ?>)'>Mark delivered</button>
    <button class="btn btn-outline btn-sm" type="button" onclick='openFail(<?= (int)$r['delivery_id'] ?>,<?= (int)$r['order_id'] ?>)'>Failed</button>
  <?php else: ?><span class="small muted">—</span><?php endif; ?>
  <?php if (in_array($r['status'], ['Assigned','Out for Delivery','Failed'], true)): ?><a class="btn btn-outline btn-sm" href="orders.php?id=<?= (int)$r['order_id'] ?>&amp;fix=1">Correct address</a><?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="muted">Nothing here.</td></tr><?php endif; ?>
<?php endif; ?>
</tbody></table></div>

<!-- Assign / edit modal -->
<div class="modal-backdrop" id="assign-modal" hidden><div class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="assign-title">
  <button class="modal-close" type="button" onclick="closeModals()">×</button>
  <h2 id="assign-title">Assign driver</h2>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" id="assign-action" value="assign">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <input type="hidden" name="order_id" id="assign-order">
    <input type="hidden" name="delivery_id" id="assign-delivery">
    <label for="assign-driver">Driver</label>
    <select name="driver_id" id="assign-driver" required>
      <?php foreach ($drivers as $d): ?><option value="<?= (int)$d['driver_id'] ?>"><?= e($d['driver_name']) ?><?= $d['vehicle'] ? ' · ' . e($d['vehicle']) : '' ?> · <?= (int)$d['workload'] ?> active</option><?php endforeach; ?>
    </select>
    <label for="assign-date">Delivery date</label>
    <input type="date" name="scheduled_date" id="assign-date" min="<?= date('Y-m-d') ?>" required>
    <label for="assign-notes">Notes for the driver (optional)</label>
    <textarea name="notes" id="assign-notes" rows="2" maxlength="255" placeholder="Gate code, landmarks, call before arriving…"></textarea>
    <div class="modal-actions"><button type="button" class="btn btn-outline" onclick="closeModals()">Cancel</button><button class="btn btn-primary" id="assign-submit">Assign</button></div>
  </form>
</div></div>

<!-- Delivered modal -->
<div class="modal-backdrop" id="complete-modal" hidden><div class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="complete-title">
  <button class="modal-close" type="button" onclick="closeModals()">×</button>
  <h2 id="complete-title">Mark delivered</h2>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="complete">
    <input type="hidden" name="tab" value="out">
    <input type="hidden" name="delivery_id" id="complete-delivery">
    <label for="complete-received">Received by</label>
    <input name="received_by" id="complete-received" maxlength="120" required placeholder="Name of the person who received the order">
    <div id="cod-wrap" hidden><label for="complete-cod">Cash collected (₱)</label><input type="number" step="0.01" min="0" name="cod_collected" id="complete-cod"><p class="small muted">Cash on Delivery: must equal the order total. The payment is marked Paid.</p></div>
    <div class="modal-actions"><button type="button" class="btn btn-outline" onclick="closeModals()">Cancel</button><button class="btn btn-primary">Confirm delivery</button></div>
  </form>
</div></div>

<!-- Failed modal -->
<div class="modal-backdrop" id="fail-modal" hidden><div class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="fail-title">
  <button class="modal-close" type="button" onclick="closeModals()">×</button>
  <h2 id="fail-title">Delivery failed</h2>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="fail">
    <input type="hidden" name="tab" value="out">
    <input type="hidden" name="delivery_id" id="fail-delivery">
    <label for="fail-reason">What happened?</label>
    <textarea name="reason" id="fail-reason" rows="3" maxlength="255" required placeholder="Customer not home, wrong address, refused order…"></textarea>
    <p class="small muted">The order goes back to "To assign" so you can pick a new date or driver. Stock stays reserved.</p>
    <div class="modal-actions"><button type="button" class="btn btn-outline" onclick="closeModals()">Cancel</button><button class="btn btn-primary">Mark as failed</button></div>
  </form>
</div></div>

<script>
function printDeliverySlip(btn){
  var tr=btn.closest('tr'),w=window.open('','_blank','width=720,height=640');
  if(!w){alert('Allow pop-ups to print the slip.');return}
  var esc=function(s){return s.replace(/[&<>]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c]})};
  var parts=[].slice.call(tr.cells,0,5).map(function(c){return '<pre style="white-space:pre-wrap;font:inherit;border-top:1px solid #bbb;padding:8px 0;margin:0">'+esc(c.innerText.trim())+'</pre>'}).join('');
  parts=parts.replace(/Open in Maps ↗/g,'');
  w.document.write('<!doctype html><title>Delivery slip</title><body style="font:16px/1.5 sans-serif;padding:24px"><h1>BuildSmart delivery slip</h1>'+parts+'</body>');
  w.document.close();w.focus();w.print();
}
function show(id){document.getElementById(id).hidden=false}
function closeModals(){document.querySelectorAll('.modal-backdrop').forEach(function(m){m.hidden=true})}
function openAssign(orderId){
  document.getElementById('assign-title').textContent='Assign driver to order #'+orderId;
  document.getElementById('assign-action').value='assign';
  document.getElementById('assign-order').value=orderId;
  document.getElementById('assign-delivery').value='';
  document.getElementById('assign-date').value='<?= date('Y-m-d') ?>';
  document.getElementById('assign-notes').value='';
  document.getElementById('assign-submit').textContent='Assign driver';
  show('assign-modal');
}
function openEdit(deliveryId,driverId,date,notes){
  document.getElementById('assign-title').textContent='Edit delivery #'+deliveryId;
  document.getElementById('assign-action').value='edit';
  document.getElementById('assign-order').value='';
  document.getElementById('assign-delivery').value=deliveryId;
  document.getElementById('assign-driver').value=driverId;
  document.getElementById('assign-date').value=date;
  document.getElementById('assign-notes').value=notes||'';
  document.getElementById('assign-submit').textContent='Save changes';
  show('assign-modal');
}
function openComplete(deliveryId,orderId,codAmount){
  document.getElementById('complete-title').textContent='Mark order #'+orderId+' delivered';
  document.getElementById('complete-delivery').value=deliveryId;
  document.getElementById('complete-received').value='';
  var wrap=document.getElementById('cod-wrap'),cod=document.getElementById('complete-cod');
  wrap.hidden=(codAmount===null);cod.required=(codAmount!==null);cod.value=codAmount===null?'':codAmount;
  show('complete-modal');
}
function openFail(deliveryId,orderId){
  document.getElementById('fail-title').textContent='Order #'+orderId+': delivery failed';
  document.getElementById('fail-delivery').value=deliveryId;
  document.getElementById('fail-reason').value='';
  show('fail-modal');
}
document.querySelectorAll('.modal-backdrop').forEach(function(m){m.addEventListener('click',function(e){if(e.target===m)closeModals()})});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModals()});
<?php if ($orderFilter && $tab === 'assign' && $rows && $drivers && !($rows[0]['payment_method'] === 'Online Payment' && $rows[0]['payment_status'] !== 'Paid')): ?>
openAssign(<?= $orderFilter ?>);
<?php endif; ?>
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
