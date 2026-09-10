<?php
require_once __DIR__ . '/../includes/customer_layout.php';
require_role('customer');
$cid = current_customer_id($pdo);
$page=max(1, (int)($_GET['page']??1));
$per=10;
$c=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE customer_id=?');
$c->execute([$cid]);
$total=(int)$c->fetchColumn();
$pages=max(1, (int)ceil($total/$per));
if($page>$pages)$page=$pages;
$offset=($page-1)*$per;
$q = $pdo->prepare("SELECT p.*,a.appointment_date,a.status appointment_status,lp.full_name,(SELECT refund_amount FROM refunds r WHERE r.payment_id=p.id LIMIT 1) refund_amount,(SELECT cancellation_fee FROM refunds r WHERE r.payment_id=p.id LIMIT 1) cancellation_fee FROM payments p JOIN appointments a ON a.id=p.appointment_id JOIN lawyer_profiles lp ON lp.id=p.lawyer_id WHERE p.customer_id=? ORDER BY p.created_at DESC LIMIT $per OFFSET $offset");
$q->execute([$cid]);
$rows = $q->fetchAll();
$pending = 0;
$paid = 0;
$refunded = 0;
$sum=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN p.status='Pending' THEN p.amount ELSE 0 END),0) pending,COALESCE(SUM(CASE WHEN p.status='Success' THEN p.amount ELSE 0 END),0) paid,COALESCE(SUM(CASE WHEN p.status IN('Refunded','Partially Refunded') THEN COALESCE(r.refund_amount,0) ELSE 0 END),0) refunded FROM payments p LEFT JOIN refunds r ON r.payment_id=p.id WHERE p.customer_id=?");
$sum->execute([$cid]);
$sr=$sum->fetch();
$pending=(float)$sr['pending'];
$paid=(float)$sr['paid'];
$refunded=(float)$sr['refunded'];
customer_shell_start('Payments', 'Manage payment methods and review your billing history.');
?>
<div class="billing-summary">
<div class="stat-card">
<div class="label">Paid</div>
<div class="value"><?= money_usd($paid)?></div>
</div>
<div class="stat-card warning">
<div class="label">Pending</div>
<div class="value"><?= money_usd($pending)?></div>
</div>
<div class="stat-card success">
<div class="label">Refunded</div>
<div class="value"><?= money_usd($refunded)?></div>
</div>
</div>
<div class="card">
<div class="card-header">
<div>
<h2>Payment Methods</h2>
<p class="muted">Accepted methods for LegalEase consultation checkout.</p>
</div>
</div>
<div class="payment-method-grid">
<div class="payment-method-card">
<div class="payment-logo">VISA · Mastercard</div>
<strong>Credit / Debit Card</strong>
<span class="muted">International card checkout</span>
</div>
<div class="payment-method-card">
<div class="payment-logo">🍎 Pay</div>
<strong>Apple Pay</strong>
<span class="muted">Supported wallet checkout</span>
</div>
<div class="payment-method-card">
<div class="payment-logo">G Pay</div>
<strong>Google Pay</strong>
<span class="muted">Supported wallet checkout</span>
</div>
<div class="payment-method-card">
<div class="payment-logo">🏦</div>
<strong>Bank Transfer</strong>
<span class="muted">Domestic/international transfer</span>
</div>
</div>
<p class="refund-note">For this local demo, payment methods simulate a successful processor response. No card credentials are stored.</p>
</div>
<div class="card">
<div class="card-header">
<h2>Billing History</h2>
</div>
<div class="table-wrap">
<table class="customer-table">
<thead>
<tr>
<th>Transaction</th>
<th>Lawyer</th>
<th>Appointment</th>
<th>Amount</th>
<th>Method</th>
<th>Status</th>
<th>Action</th>
</tr>
</thead>
<tbody><?php
foreach ($rows as $r):?><tr>
<td>PAY-<?= str_pad((string) $r['id'], 3, '0', STR_PAD_LEFT)?><br>
<small class="muted"><?= e(date('d M Y', strtotime($r['created_at'])))?></small>
</td>
<td><?= e($r['full_name'])?></td>
<td><?= e(date('d M Y', strtotime($r['appointment_date'])))?></td>
<td>
<b><?= money_usd($r['amount'])?></b><?php
if ($r['refund_amount'] !== null):?><br>
<small class="muted">Refund: <?= money_usd($r['refund_amount'])?></small><?php
endif?></td>
<td><?= e($r['method'] ?: 'Not selected')?></td>
<td>
<span class="badge <?= customer_badge($r['status'])?>"><?= e($r['status'])?></span>
</td>
<td><?php
if ($r['status'] === 'Pending' && $r['appointment_status'] !== 'Cancelled'):?><a class="btn btn-success btn-sm" href="checkout.php?appointment=<?= $r['appointment_id']?>">Pay Now</a><?php
else:?>—<?php
endif?></td>
</tr><?php
endforeach?><?php
if (!$rows):?><tr>
<td colspan="7" class="empty">No billing history.</td>
</tr><?php
endif?></tbody>
</table>
</div><?=paginate($total, $page, $per, '/LegalEase_eProject/customer/payments.php')?></div>
<?php
customer_shell_end();
?>
