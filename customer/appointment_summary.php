<?php
require_once __DIR__.'/../includes/customer_layout.php';
require_role('customer');
$cid=current_customer_id($pdo);
$id=(int)($_GET['appointment']??0);
$q=$pdo->prepare("SELECT a.*,p.status payment_status,p.amount,lp.full_name lawyer_name FROM appointments a JOIN payments p ON p.appointment_id=a.id JOIN lawyer_profiles lp ON lp.id=a.lawyer_id WHERE a.id=? AND a.customer_id=?");
$q->execute([$id, $cid]);
$a=$q->fetch();
if(!$a) {
    http_response_code(404);
    exit('Appointment not found.');
}
if($a['status']==='Cancelled'||$a['payment_status']==='Cancelled') {
    flash('error', 'This reservation is no longer active. Please select another time slot.');
    redirect('/LegalEase_eProject/public/lawyers.php');
}if($a['payment_status']==='Success')redirect('/LegalEase_eProject/customer/appointments.php');
if($a['payment_due_at']&&strtotime($a['payment_due_at'])<=time()) {
    process_unpaid_appointment_holds($pdo);
    flash('error', 'The 20-minute payment window expired. The appointment was automatically cancelled.');
    redirect('/LegalEase_eProject/customer/appointments.php');
}
$seconds=max(0, strtotime($a['payment_due_at'])-time());
customer_shell_start('Confirm Appointment', 'Review the appointment details and payment deadline carefully.');
?>
<div class="appointment-review-card card">
<div class="card-header">
<div>
<h2>Appointment summary</h2>
<p class="muted">Please verify the appointment before payment.</p>
</div>
<span class="badge badge-pending">Payment pending</span>
</div>
<div class="appointment-focus-banner">
<small>Your appointment with lawyer</small>
<strong><?=e($a['lawyer_name'])?></strong>
<div>
<span>📅 <?=e(date('l, d M Y', strtotime($a['appointment_date'])))?></span>
<span>🕘 <?=e(substr($a['start_time'], 0, 5))?>–<?=e(substr($a['end_time'], 0, 5))?></span>
</div>
</div>
<div class="summary-grid">
<div>
<span>Lawyer</span>
<strong><?=e($a['lawyer_name'])?></strong>
</div>
<div>
<span>Consultation fee</span>
<strong><?=money_usd($a['amount'])?></strong>
</div>
<div>
<span>Appointment created</span>
<strong><?=e(date('d M Y H:i:s', strtotime($a['created_at'])))?></strong>
</div>
<div>
<span>Payment deadline</span>
<strong><?=e(date('d M Y H:i:s', strtotime($a['payment_due_at'])))?></strong>
</div>
</div>
<div class="legal-note-review">
<span>Brief description of legal issue</span>
<p><?=trim((string)$a['note'])!==''?nl2br(e($a['note'])):'No description provided.'?></p>
</div>
<div class="payment-deadline">
<b>Reservation deadline:</b> payment must be completed within 20 minutes of creating the appointment. <span id="holdTimer" data-seconds="<?=$seconds?>">
</span>
<br>
<small>After the deadline, the booking is automatically cancelled, its payment becomes Cancelled, and the lawyer's slot is released.</small>
</div>
<div class="actions">
<a class="btn btn-light" href="appointments.php">Pay later</a>
<a class="btn btn-success" href="checkout.php?appointment=<?=$id?>" data-confirm="Proceed to secure checkout for appointment #<?=$id?>?">Confirm & Proceed to Payment</a>
</div>
</div>
<script>(()=>{const el=document.getElementById('holdTimer');if(!el)return;let s=Number(el.dataset.seconds||0);function draw(){if(s<=0){el.textContent='Time remaining: 00:00';setTimeout(()=>location.reload(),700);return;}const m=Math.floor(s/60),sec=s%60;el.textContent='Time remaining: '+String(m).padStart(2,'0')+':'+String(sec).padStart(2,'0');s--;setTimeout(draw,1000)}draw()})();</script>
<?php
customer_shell_end();
?>
