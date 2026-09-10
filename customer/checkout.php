<?php
require_once __DIR__ . '/../includes/customer_layout.php';
require_role('customer');
$cid = current_customer_id($pdo);
$appointmentId = (int) ($_GET['appointment'] ?? $_POST['appointment_id'] ?? 0);
$q = $pdo->prepare("SELECT p.id payment_id,p.amount,p.status payment_status,a.id appointment_id,a.appointment_date,a.start_time,a.end_time,a.note,a.payment_due_at,a.status appointment_status,lp.full_name lawyer_name,lp.user_id lawyer_user_id FROM payments p JOIN appointments a ON a.id=p.appointment_id JOIN lawyer_profiles lp ON lp.id=a.lawyer_id WHERE a.id=? AND p.customer_id=?");
$q->execute([$appointmentId, $cid]);
$bill = $q->fetch();
if (!$bill) {
    http_response_code(404);
    exit('Payment record not found.');
}
$error = '';
if ($bill['payment_due_at'] && strtotime($bill['payment_due_at']) <= time() && $bill['payment_status'] === 'Pending') {
    process_unpaid_appointment_holds($pdo);
    flash('error', 'The 20-minute payment window expired. The appointment was automatically cancelled.');
    redirect('/LegalEase_eProject/customer/appointments.php');
}
if (is_post() && ($_POST['action'] ?? '') === 'pay') {
    verify_csrf();
    $method = $_POST['method'] ?? '';
    $allowed = ['Visa / Mastercard', 'Apple Pay', 'Google Pay', 'Bank Transfer'];
    if (!in_array($method, $allowed, true))$error = 'Please select a valid payment method.';
    elseif ($bill['payment_status'] !== 'Pending')$error = 'This payment is no longer pending.';
    elseif ($bill['appointment_status'] === 'Cancelled')$error = 'A cancelled appointment cannot be paid.';
    elseif ($bill['payment_due_at'] && strtotime($bill['payment_due_at']) <= time())$error = 'The 20-minute payment window has expired.';
    else {
        if ($method === 'Visa / Mastercard') {
            $card = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
            $holder = trim($_POST['cardholder'] ?? '');
            $expiry = trim($_POST['expiry'] ?? '');
            $cvc = preg_replace('/\D/', '', $_POST['cvc'] ?? '');
            if (strlen($holder) < 2 || strlen($card) < 13 || strlen($card) > 19 || !preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $expiry) || strlen($cvc) < 3 || strlen($cvc) > 4)$error = 'Please enter valid card details. Card details are validated for this demo and are not stored.';
        }
        if (!$error)try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT a.status,a.payment_due_at,p.status payment_status FROM appointments a JOIN payments p ON p.appointment_id=a.id WHERE a.id=? AND a.customer_id=? FOR UPDATE");
            $lock->execute([$appointmentId, $cid]);
            $state = $lock->fetch();
            if (!$state || $state['status'] === 'Cancelled' || $state['payment_status'] !== 'Pending' || ($state['payment_due_at'] && strtotime($state['payment_due_at']) <= time()))throw new RuntimeException('This reservation is no longer eligible for payment.');
            $pdo->prepare("UPDATE payments SET status='Success',method=?,paid_at=NOW(),updated_at=NOW() WHERE id=? AND customer_id=? AND status='Pending'")->execute([$method, $bill['payment_id'], $cid]);
            notify($pdo, (int) user()['id'], 'Payment completed for appointment #' . $appointmentId . '. The request is now awaiting lawyer confirmation.', 'System', '/LegalEase_eProject/customer/appointments.php');
            notify($pdo, (int) $bill['lawyer_user_id'], 'Customer payment received for appointment #' . $appointmentId . '. You can now confirm or cancel the request.', 'System', '/LegalEase_eProject/lawyer/appointment.php?id=' . $appointmentId);
            $pdo->commit();
            flash('success', 'Payment completed successfully. Your appointment request is now awaiting the lawyer’s confirmation.');
            redirect('/LegalEase_eProject/customer/appointments.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction())$pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}
$seconds = max(0, strtotime($bill['payment_due_at']) - time());
customer_shell_start('Secure Checkout', 'Review your consultation bill and choose a payment method.');
if ($error)echo '<div class="alert alert-error">' . e($error) . '</div>';
?>
<div class="payment-time-warning">
<b>Complete payment within 20 minutes to keep this appointment.</b>
<span id="checkoutTimer" data-seconds="<?= $seconds?>">
</span>
<small>If the deadline is missed, LegalEase automatically cancels the unpaid reservation and releases the lawyer’s time slot.</small>
</div>
<div class="checkout-grid">
<div class="card">
<div class="card-header">
<h2>Payment method</h2>
<span class="badge badge-pending">Secure demo checkout</span>
</div>
<form method="post" id="checkoutForm" data-confirm="Confirm payment for this consultation? Please verify the bill and payment method before continuing."><?= csrf_field()?><input type="hidden" name="action" value="pay">
<input type="hidden" name="appointment_id" value="<?= $appointmentId?>">
<div class="pay-choice">
<label>
<input type="radio" name="method" value="Visa / Mastercard" checked> 💳 <b>Visa / Mastercard</b>
</label>
<label>
<input type="radio" name="method" value="Apple Pay"> 🍎 <b>Apple Pay</b>
</label>
<label>
<input type="radio" name="method" value="Google Pay"> G <b>Google Pay</b>
</label>
<label>
<input type="radio" name="method" value="Bank Transfer"> 🏦 <b>Bank Transfer</b>
</label>
</div>
<div id="cardFields" style="margin-top:18px">
<div class="form-grid">
<div class="form-group full">
<label>Cardholder name</label>
<input name="cardholder" maxlength="100" autocomplete="cc-name">
</div>
<div class="form-group full">
<label>Card number</label>
<input name="card_number" inputmode="numeric" maxlength="23" placeholder="1234 5678 9012 3456" autocomplete="cc-number">
</div>
<div class="form-group">
<label>Expiry (MM/YY)</label>
<input name="expiry" maxlength="5" placeholder="MM/YY" autocomplete="cc-exp">
</div>
<div class="form-group">
<label>CVC</label>
<input name="cvc" inputmode="numeric" maxlength="4" placeholder="CVC" autocomplete="cc-csc">
</div>
</div>
</div>
<p class="refund-note">Demo environment: no real card or wallet transaction is submitted to an external payment processor, and card details are not stored.</p>
<button class="btn btn-success" style="margin-top:16px">Pay <?= money_usd($bill['amount'])?></button>
</form>
</div>
<div class="bill-box">
<h2>Bill summary</h2>
<div class="bill-row">
<span>Appointment</span>
<b>#<?= $appointmentId?></b>
</div>
<div class="bill-row">
<span>Lawyer</span>
<b><?= e($bill['lawyer_name'])?></b>
</div>
<div class="bill-row">
<span>Date</span>
<b><?= e(date('d M Y', strtotime($bill['appointment_date'])))?></b>
</div>
<div class="bill-row">
<span>Time</span>
<b><?= e(substr($bill['start_time'], 0, 5))?>–<?= e(substr($bill['end_time'], 0, 5))?></b>
</div>
<div class="bill-row">
<span>Legal issue</span>
<b><?= trim((string) $bill['note']) !== '' ? e(mb_strimwidth($bill['note'], 0, 90, '...')) : 'No description provided.'?></b>
</div>
<div class="bill-row">
<span>Legal consultation</span>
<b><?= money_usd($bill['amount'])?></b>
</div>
<div class="bill-row total">
<span>Total</span>
<b><?= money_usd($bill['amount'])?></b>
</div>
<div class="policy-box">
<b>Cancellation policy</b>
<br>More than 15 days before: 5% fee.<br>8–15 days before: 10% fee.<br>3–7 days before: 20% fee.<br>1–3 days before: 50% fee (30% lawyer compensation + 20% platform fee).<br>Less than 24 hours: 100% fee (80% lawyer compensation + 20% platform fee).</div>
</div>
</div>
<script>const fields=document.getElementById('cardFields');document.querySelectorAll('input[name="method"]').forEach(r=>r.addEventListener('change',()=>{fields.style.display=document.querySelector('input[name="method"]:checked').value==='Visa / Mastercard'?'block':'none'}));(function(){const el=document.getElementById('checkoutTimer');if(!el)return;let s=Number(el.dataset.seconds||0);function draw(){if(s<=0){el.textContent='Time remaining: 0:00';setTimeout(()=>location.reload(),900);return;}const m=Math.floor(s/60),sec=s%60;el.textContent='Time remaining: '+m+':'+String(sec).padStart(2,'0');s--;setTimeout(draw,1000)}draw()})();</script>
<?php
customer_shell_end();
?>
