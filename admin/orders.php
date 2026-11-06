<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$statuses = order_statuses();

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id     = (int)($_POST['order_id'] ?? 0);
    $action = $_POST['action'] ?? 'status';
    try {
        if ($action === 'correct_address') {
            $place = resolve_delivery_input($pdo, $_POST);
            $r = update_order_address($pdo, $id, $place, (string)($_POST['reason'] ?? ''));
            flash('Delivery address corrected. The customer has been notified.' . ($r['zone_changed']
                ? ' Note: the delivery zone changed (' . ($r['old_zone'] ?? 'standard fee') . ' → ' . ($r['new_zone'] ?? 'standard fee') . '). The delivery fee and total were not changed; use Adjust delivery fee if it should change.' : ''));
        } elseif ($action === 'adjust_fee') {
            adjust_delivery_fee($pdo, $id, (float)($_POST['new_fee'] ?? -1), trim((string)($_POST['reason'] ?? '')));
            flash('Delivery fee updated. The total and receipt were adjusted and the customer was notified.');
        } elseif ($action === 'refund') {
            record_refund($pdo, $id, (float)($_POST['amount'] ?? 0), (string)($_POST['method'] ?? ''), trim((string)($_POST['reference'] ?? '')), trim((string)($_POST['reason'] ?? '')));
            flash('Refund recorded.');
        } elseif ($action === 'verify_payment') {
            verify_online_payment($pdo, $id, trim($_POST['transaction_reference'] ?? ''));
            flash('Online payment marked as verified.');
        } else {
            $status  = (string)($_POST['order_status'] ?? '');
            $remarks = trim($_POST['remarks'] ?? '');
            // Delivery-stage steps are done from Delivery Management, not by hand.
            $cur = $pdo->prepare('SELECT order_status FROM orders WHERE order_id=?');
            $cur->execute([$id]);
            $curStatus = (string)$cur->fetchColumn();
            if (!in_array($status, manual_status_options($curStatus), true)) {
                throw new UserFacingException('That status change is done from Delivery Management, or is not allowed from "' . $curStatus . '".');
            }
            change_order_status($pdo, $id, $status, $remarks, ['refund' => !empty($_POST['refund'])]);
            flash('Order status updated.');
        }
    } catch (Throwable $e) {
        // Our own UserFacingException messages are shown; anything else is logged and replaced by a generic line.
        flash(user_error_message($e, 'order update'), 'error');
    }
    redirect('/admin/orders.php' . (!empty($_POST['return_id']) ? '?id=' . (int)$_POST['return_id'] : ''));
}

$pageTitle = 'Manage Orders';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/nav.php';

$detailId = (int)($_GET['id'] ?? 0);
$detail = null;
if ($detailId) {
    $s = $pdo->prepare('SELECT o.*,u.full_name,u.email,u.phone,p.payment_method,p.payment_status,p.transaction_reference,p.amount pay_amount
                        FROM orders o JOIN users u ON u.user_id=o.user_id
                        LEFT JOIN payments p ON p.order_id=o.order_id WHERE o.order_id=?');
    $s->execute([$detailId]);
    $detail = $s->fetch();
    if ($detail) {
        $s = $pdo->prepare('SELECT oi.*,p.image,p.image_alt,p.category_id,c.category_name FROM order_items oi
                            JOIN products p ON p.product_id=oi.product_id LEFT JOIN categories c ON c.category_id=p.category_id
                            WHERE oi.order_id=?');
        $s->execute([$detailId]);
        $detailItems = $s->fetchAll();
        $s = $pdo->prepare('SELECT h.*,a.admin_name FROM order_status_history h LEFT JOIN administrators a ON a.admin_id=h.admin_id
                            WHERE h.order_id=? ORDER BY h.status_date DESC, h.history_id DESC');
        $s->execute([$detailId]);
        $history = $s->fetchAll();
        $s = $pdo->prepare('SELECT d.*,dr.driver_name,dr.phone driver_phone FROM deliveries d LEFT JOIN drivers dr ON dr.driver_id=d.driver_id
                            WHERE d.order_id=? ORDER BY d.delivery_id DESC');
        $s->execute([$detailId]);
        $attempts = $s->fetchAll();
        $s = $pdo->prepare('SELECT r.*,a.admin_name FROM refunds r LEFT JOIN administrators a ON a.admin_id=r.admin_id WHERE r.order_id=? ORDER BY r.refund_id');
        $s->execute([$detailId]);
        $refunds = $s->fetchAll();
        $refundedTotal = round(array_sum(array_map(fn($r) => (float)$r['amount'], $refunds)), 2);
        $refundLeft = max(0, round((float)($detail['pay_amount'] ?? 0) - $refundedTotal, 2));
    }
}

if ($detailId): ?>
<div class="page-head"><div><h1>Order #<?= $detailId ?></h1><p class="muted">Customer order details and fulfillment history.</p></div><a class="btn btn-outline" href="orders.php">← All orders</a></div>
<?php if (!$detail): ?>
<div class="card">Order not found. <a href="orders.php">Return to orders</a>.</div>
<?php else:
    $manual = manual_status_options($detail['order_status']);
    $isPaid = ($detail['payment_status'] ?? '') === 'Paid';
    $inDeliveryStage = in_array($detail['order_status'], ['Preparing', 'Ready for Delivery', 'Out for Delivery'], true);
?>
<div class="stats">
  <div class="card stat"><span>Customer</span><strong class="detail-stat"><?= e($detail['full_name']) ?></strong></div>
  <div class="card stat"><span>Order total</span><strong><?= money($detail['total_amount']) ?></strong></div>
  <div class="card stat"><span>Status</span><strong class="detail-stat"><span class="pill <?= pill_class($detail['order_status']) ?>"><?= e($detail['order_status']) ?></span></strong></div>
  <div class="card stat"><span>Payment</span><strong class="detail-stat"><?= e($detail['payment_method'] ?? '—') ?> · <?= e($detail['payment_status'] ?? '—') ?></strong></div>
</div>
<div class="admin-dashboard-grid">
  <section class="card"><h2>Items</h2>
    <?php foreach ($detailItems as $it): ?>
    <div class="order-detail-item"><div class="cart-prod"><div class="thumb"><?= product_visual($it) ?></div><span><?= e($it['product_name']) ?> × <?= (int)$it['quantity'] ?></span></div><strong><?= money($it['subtotal']) ?></strong></div>
    <?php endforeach; ?>
    <p class="small muted u-mt-10">Delivery fee: <?= money($detail['delivery_fee'] ?? 0) ?></p>
    <div class="summary-total"><b>Total</b><strong><?= money($detail['total_amount']) ?></strong></div>
  </section>
  <section class="card"><h2>Customer &amp; delivery</h2>
    <p><b><?= e($detail['full_name']) ?></b><br><?= e($detail['email']) ?><br>Account phone: <?= e($detail['phone'] ?? '') ?></p>
    <h3>Deliver to</h3><div class="u-mb-18"><?= delivery_block_html($detail, (string)$detail['full_name'], (string)($detail['phone'] ?? '')) ?>
    <?php if (!empty($detail['delivery_zone'])): ?><div class="small muted">Zone: <?= e($detail['delivery_zone']) ?></div><?php endif; ?></div>
    <p class="muted small">Placed: <?= e($detail['order_date']) ?><br>Payment reference: <?= e($detail['transaction_reference'] ?? '—') ?></p>
    <div class="form-actions u-flex-wrap-gap8">
      <?php if ($manual): ?>
      <button class="btn btn-primary" type="button" onclick='openOrderModal(<?= $detailId ?>,<?= json_encode($detail['order_status']) ?>,<?= json_encode($manual) ?>,<?= $isPaid ? 'true' : 'false' ?>)'>Update status</button>
      <?php endif; ?>
      <?php if ($inDeliveryStage): ?>
      <a class="btn btn-outline" href="deliveries.php?order=<?= $detailId ?>">Manage delivery</a>
      <?php endif; ?>
      <?php if (!in_array($detail['order_status'], ['Completed', 'Cancelled'], true)): ?>
      <button class="btn btn-outline" type="button" onclick="openAddressModal()">Correct address</button>
      <?php if (($detail['payment_status'] ?? 'Pending') === 'Pending'): ?><button class="btn btn-outline" type="button" onclick="document.getElementById('fee-modal').hidden=false">Adjust delivery fee</button><?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
</div>

<?php if (($detail['payment_method'] ?? '') === 'Online Payment' && ($detail['payment_status'] ?? '') === 'Pending' && !in_array($detail['order_status'], ['Cancelled', 'Completed'], true)): ?>
<div class="card u-mb-18"><h2>Verify online payment</h2>
  <p class="muted small">The system does not charge cards or wallets. Check the payment in your bank or e-wallet, then confirm the reference below. The order cannot be prepared until this is done.</p>
  <form class="u-flex-end-wrap" method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="verify_payment">
    <input type="hidden" name="order_id" value="<?= $detailId ?>">
    <input type="hidden" name="return_id" value="<?= $detailId ?>">
    <div class="u-grow-220"><label for="pay-ref">Transaction reference</label><input id="pay-ref" name="transaction_reference" value="<?= e($detail['transaction_reference'] ?? '') ?>" required></div>
    <button class="btn btn-primary">Mark payment as verified</button>
  </form>
</div>
<?php endif; ?>

<?php if (($detail['payment_status'] ?? '') === 'Paid' || $refunds): ?>
<div class="card u-mb-18"><h2>Refunds</h2>
  <p class="muted small">Paid <?= money($detail['pay_amount'] ?? 0) ?> · refunded <?= money($refundedTotal) ?> · <?= $refundLeft > 0 ? money($refundLeft) . ' can still be refunded' : 'fully refunded' ?>.</p>
  <?php if ($refunds): ?><div class="table-wrap"><table><thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Reason</th><th>By</th></tr></thead><tbody>
  <?php foreach ($refunds as $rf): ?><tr><td><?= e(substr((string)$rf['refunded_at'], 0, 16)) ?></td><td><?= money($rf['amount']) ?></td><td><?= e($rf['method']) ?></td><td><?= e($rf['reference'] ?? '—') ?></td><td><?= e($rf['reason'] ?? '') ?></td><td><?= e($rf['admin_name'] ?? 'System') ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <?php if (($detail['payment_status'] ?? '') === 'Paid' && $refundLeft > 0): ?>
  <form class="u-flex-end-wrap" method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="refund">
    <input type="hidden" name="order_id" value="<?= $detailId ?>"><input type="hidden" name="return_id" value="<?= $detailId ?>">
    <div><label for="rf-amount">Amount (₱)</label><input id="rf-amount" type="number" name="amount" step="0.01" min="0.01" max="<?= e((string)$refundLeft) ?>" value="<?= e((string)$refundLeft) ?>" required></div>
    <div><label for="rf-method">Returned by</label><select id="rf-method" name="method"><?php foreach (REFUND_METHODS as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select></div>
    <div><label for="rf-ref">Reference (optional)</label><input id="rf-ref" name="reference" maxlength="150"></div>
    <div class="u-grow-220"><label for="rf-reason">Reason</label><input id="rf-reason" name="reason" maxlength="255" required placeholder="e.g. Damaged item returned"></div>
    <button class="btn btn-primary" onclick="return confirm('Record this refund? This cannot be undone.')">Record refund</button>
  </form><?php endif; ?>
</div>
<?php endif; ?>

<div class="card u-mb-18"><h2>Delivery</h2>
  <?php if (!$attempts): ?>
    <p class="muted">No delivery assigned yet.
    <?php if ($detail['order_status'] === 'Preparing'): ?><a href="deliveries.php?order=<?= $detailId ?>">Assign a driver</a>.<?php endif; ?></p>
  <?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>#</th><th>Driver</th><th>Scheduled</th><th>Status</th><th>Details</th></tr></thead><tbody>
    <?php foreach ($attempts as $a): ?>
      <tr><td><?= (int)$a['delivery_id'] ?></td>
        <td><?= e($a['driver_name'] ?? '—') ?><div class="small muted"><?= e($a['driver_phone'] ?? '') ?></div></td>
        <td><?= e($a['scheduled_date']) ?></td>
        <td><span class="pill <?= pill_class($a['status']) ?>"><?= e($a['status']) ?></span></td>
        <td class="small">
          <?php if ($a['dispatched_at']): ?>Dispatched <?= e($a['dispatched_at']) ?><br><?php endif; ?>
          <?php if ($a['delivered_at']): ?>Delivered <?= e($a['delivered_at']) ?> · received by <?= e($a['received_by'] ?? '') ?><br><?php endif; ?>
          <?php if ($a['cod_collected'] !== null): ?>Cash collected <?= money($a['cod_collected']) ?><br><?php endif; ?>
          <?php if ($a['failure_reason']): ?>Failed: <?= e($a['failure_reason']) ?><br><?php endif; ?>
          <?php if ($a['notes']): ?><span class="muted"><?= e($a['notes']) ?></span><?php endif; ?>
        </td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</div>

<div class="card"><h2>Status history</h2>
  <?php if (!$history): ?><p class="muted">No status updates recorded.</p>
  <?php else: foreach ($history as $h): ?>
    <div class="history-entry"><span class="history-dot"></span><div><b><?= e($h['status']) ?></b><small><?= e($h['status_date']) ?> · <?= e($h['admin_name'] ?? 'System') ?></small><?php if ($h['remarks']): ?><p><?= e($h['remarks']) ?></p><?php endif; ?></div></div>
  <?php endforeach; endif; ?>
</div>
<?php endif; ?>

<?php else:
    $counts = [];
    foreach ($pdo->query('SELECT order_status,COUNT(*) total FROM orders GROUP BY order_status')->fetchAll() as $r) $counts[$r['order_status']] = (int)$r['total'];
    $allCount = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $filter = $_GET['status'] ?? 'All';
    if (!in_array($filter, array_merge(['All'], $statuses), true)) $filter = 'All';
    $search = trim($_GET['q'] ?? '');
    $sql = 'SELECT o.*,u.full_name,p.payment_method,p.payment_status FROM orders o JOIN users u ON u.user_id=o.user_id LEFT JOIN payments p ON p.order_id=o.order_id WHERE 1';
    $args = [];
    if ($filter !== 'All') { $sql .= ' AND o.order_status=?'; $args[] = $filter; }
    if ($search !== '') {
        $sql .= ' AND (CAST(o.order_id AS CHAR) LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)';
        array_push($args, "%$search%", "%$search%", "%$search%");
    }
    $sql .= ' ORDER BY o.order_date DESC';
    $s = $pdo->prepare($sql); $s->execute($args); $rows = $s->fetchAll();
?>
<div class="page-head"><div><h1>Order Management</h1><p class="muted">Review customer orders. Delivery steps are handled in Delivery Management.</p></div></div>
<div class="order-tabs"><a class="<?= $filter === 'All' ? 'active' : '' ?>" href="?status=All">All <b><?= $allCount ?></b></a><?php foreach ($statuses as $st): ?><a class="<?= $filter === $st ? 'active' : '' ?>" href="?status=<?= urlencode($st) ?>"><?= e($st) ?> <b><?= $counts[$st] ?? 0 ?></b></a><?php endforeach; ?></div>
<form class="admin-search" method="get"><input type="hidden" name="status" value="<?= e($filter) ?>"><input name="q" value="<?= e($search) ?>" placeholder="Search order number, customer or email"><button class="btn btn-primary">Search</button><?php if ($search !== ''): ?><a class="btn btn-outline" href="?status=<?= urlencode($filter) ?>">Clear</a><?php endif; ?></form>
<div class="table-wrap"><table><thead><tr><th>Order / Customer</th><th>Date</th><th>Total</th><th>Payment</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($rows as $o):
    $manual = manual_status_options($o['order_status']);
    $paid = ($o['payment_status'] ?? '') === 'Paid';
    $stage = in_array($o['order_status'], ['Preparing', 'Ready for Delivery', 'Out for Delivery'], true);
?>
<tr>
  <td><a href="?id=<?= $o['order_id'] ?>"><b>#<?= $o['order_id'] ?></b></a><br><?= e($o['full_name']) ?></td>
  <td><?= e($o['order_date']) ?></td>
  <td><?= money($o['total_amount']) ?></td>
  <td><?= e($o['payment_method'] ?? '') ?><br><span class="small muted"><?= e($o['payment_status'] ?? '') ?></span></td>
  <td><span class="pill <?= pill_class($o['order_status']) ?>"><?= e($o['order_status']) ?></span><?php $steps=['Pending','Confirmed','Preparing','Ready for Delivery','Out for Delivery','Completed'];$stepIndex=array_search($o['order_status'],$steps,true); ?><div class="order-progress" aria-label="Order progress"><span style="width:<?= $stepIndex===false?0:(int)round(($stepIndex+1)/count($steps)*100) ?>%"></span></div><small class="muted"><?= $stepIndex===false?'Cancelled':('Step '.($stepIndex+1).' of '.count($steps)) ?></small></td>
  <td>
    <?php if ($manual): ?><button class="btn btn-primary btn-sm" type="button" onclick='openOrderModal(<?= (int)$o['order_id'] ?>,<?= json_encode($o['order_status']) ?>,<?= json_encode($manual) ?>,<?= $paid ? 'true' : 'false' ?>)'><?= $o['order_status']==='Pending'?'Confirm':($o['order_status']==='Confirmed'?'Prepare':($o['order_status']==='Ready for Delivery'?'Assign driver':'Next action')) ?></button><?php endif; ?>
    <?php if ($stage): ?><a class="btn btn-outline btn-sm" href="deliveries.php?order=<?= (int)$o['order_id'] ?>">Delivery</a><?php endif; ?>
    <a class="btn btn-outline btn-sm" href="?id=<?= $o['order_id'] ?>">Details</a>
    <a href="<?= BASE_URL ?>/receipt.php?order_id=<?= $o['order_id'] ?>">Receipt</a>
  </td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="muted">No orders match your search.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>

<?php if ($detailId && $detail && !in_array($detail['order_status'], ['Completed', 'Cancelled'], true)):
    $fixVals = ['recipient_name' => $detail['recipient_name'] ?? $detail['full_name'], 'recipient_phone' => $detail['recipient_phone'] ?? ($detail['phone'] ?? ''),
        'province' => $detail['delivery_province'] ?? DEFAULT_PROVINCE, 'city' => $detail['delivery_city'] ?? '', 'barangay' => $detail['delivery_barangay'] ?? '',
        'house_no' => $detail['delivery_house_no'] ?? '', 'street' => $detail['delivery_street'] ?? mb_substr((string)$detail['delivery_address'], 0, STREET_MAX), 'landmark' => $detail['delivery_landmark'] ?? '']; ?>
<div class="modal-backdrop" id="address-modal" hidden><div class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="address-title">
  <button class="modal-close" type="button" onclick="closeAddressModal()">×</button>
  <h2 id="address-title">Correct delivery address, order #<?= $detailId ?></h2>
  <p class="small muted">Use this when the address was wrong (for example after a failed delivery). The customer is notified and the change is saved in the order history. The delivery fee and total stay the same.</p>
  <?php if (empty($detail['delivery_street'])): ?><p class="small muted">This is an older order with a one-line address: <?= e($detail['delivery_address']) ?>. Fill in the boxes below.</p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="correct_address">
    <input type="hidden" name="order_id" value="<?= $detailId ?>"><input type="hidden" name="return_id" value="<?= $detailId ?>">
    <?= address_fields($fixVals, '', 'fix', $pdo) ?>
    <label for="fix-reason">Why is it being corrected? <span class="req" aria-hidden="true">*</span></label>
    <input id="fix-reason" name="reason" maxlength="150" required placeholder="e.g. Customer called with the right street">
    <div class="modal-actions"><button type="button" class="btn btn-outline" onclick="closeAddressModal()">Cancel</button><button class="btn btn-primary">Save address</button></div>
  </form>
</div></div>
<?= place_picker_script($pdo) ?>
<script>
function openAddressModal(){document.getElementById('address-modal').hidden=false}
function closeAddressModal(){document.getElementById('address-modal').hidden=true}
document.getElementById('address-modal').addEventListener('click',function(e){if(e.target.id==='address-modal')closeAddressModal()});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeAddressModal()});
<?php if (!empty($_GET['fix'])): ?>openAddressModal();<?php endif; ?>
</script>
<?php endif; ?>
<?php if ($detailId && $detail && !in_array($detail['order_status'], ['Completed', 'Cancelled'], true) && ($detail['payment_status'] ?? 'Pending') === 'Pending'): ?>
<div class="modal-backdrop" id="fee-modal" hidden><div class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="fee-title">
  <button class="modal-close" type="button" onclick="document.getElementById('fee-modal').hidden=true">×</button>
  <h2 id="fee-title">Adjust delivery fee, order #<?= $detailId ?></h2>
  <p class="small muted">Current fee: <?= money($detail['delivery_fee'] ?? 0) ?>. The order total, pending payment and receipt are updated and the customer is notified.</p>
  <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="adjust_fee">
    <input type="hidden" name="order_id" value="<?= $detailId ?>"><input type="hidden" name="return_id" value="<?= $detailId ?>">
    <label for="new-fee">New delivery fee (₱)</label><input id="new-fee" type="number" name="new_fee" step="0.01" min="0" max="100000" required value="<?= e((string)($detail['delivery_fee'] ?? 0)) ?>">
    <label for="fee-reason">Reason <span class="req" aria-hidden="true">*</span></label><input id="fee-reason" name="reason" maxlength="150" required placeholder="e.g. Address moved to a farther zone">
    <div class="modal-actions"><button type="button" class="btn btn-outline" onclick="document.getElementById('fee-modal').hidden=true">Cancel</button><button class="btn btn-primary">Save fee</button></div>
  </form>
</div></div>
<?php endif; ?>
<div class="modal-backdrop" id="order-modal" hidden><div class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="order-modal-title">
  <button class="modal-close" type="button" onclick="closeOrderModal()">×</button>
  <h2 id="order-modal-title">Update order</h2>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="status">
    <input type="hidden" name="order_id" id="modal-order-id">
    <input type="hidden" name="return_id" value="<?= (int)$detailId ?>">
    <p class="muted small" id="modal-current"></p>
    <label for="modal-order-status">Move order to</label>
    <select name="order_status" id="modal-order-status" required></select>
    <div id="refund-wrap" hidden><label class="u-check-row"><input class="u-check-input" type="checkbox" name="refund" value="1"> <span>Mark payment as refunded (this order is already paid)</span></label></div>
    <label for="modal-remarks">Remarks (optional, shown to the customer)</label>
    <textarea name="remarks" id="modal-remarks" rows="3" placeholder="Add an update for this order"></textarea>
    <div class="modal-actions"><button type="button" class="btn btn-outline" onclick="closeOrderModal()">Cancel</button><button class="btn btn-primary">Save update</button></div>
  </form>
</div></div>
<script>
var orderPaid=false;
function syncRefund(){document.getElementById('refund-wrap').hidden=!(orderPaid&&document.getElementById('modal-order-status').value==='Cancelled');}
function openOrderModal(id,status,options,paid){
  orderPaid=!!paid;
  document.getElementById('modal-order-id').value=id;
  document.getElementById('modal-current').textContent='Order #'+id+' is currently '+status+'.';
  var sel=document.getElementById('modal-order-status');sel.innerHTML='';
  options.forEach(function(o){sel.add(new Option(o,o));});
  syncRefund();
  document.getElementById('order-modal').hidden=false;
}
function closeOrderModal(){document.getElementById('order-modal').hidden=true}
document.getElementById('modal-order-status').addEventListener('change',syncRefund);
document.getElementById('order-modal').addEventListener('click',function(e){if(e.target.id==='order-modal')closeOrderModal()});
document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeOrderModal();var fm=document.getElementById('fee-modal');if(fm)fm.hidden=true;}});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
