<?php
require_once __DIR__.'/../includes/customer_layout.php';
require_role('customer');
$cid=current_customer_id($pdo);
function cancellation_terms(string $appointmentDate, string $startTime, float $fee):array {
    $now=new DateTimeImmutable('now');
    $start=new DateTimeImmutable($appointmentDate.' '.$startTime);
    $seconds=max(0, $start->getTimestamp()-$now->getTimestamp());
    $days=$seconds/86400;
    if($days>15) {
        $rate=.05;
        $lawyerRate=0;
        $platformRate=.05;
        $label='More than 15 days';
    }elseif($days>=8) {
        $rate=.10;
        $lawyerRate=0;
        $platformRate=.10;
        $label='8–15 days';
    }elseif($days>=3) {
        $rate=.20;
        $lawyerRate=0;
        $platformRate=.20;
        $label='3–7 days';
    }elseif($days>=1) {
        $rate=.50;
        $lawyerRate=.30;
        $platformRate=.20;
        $label='1–3 days';
    }else {
        $rate=1.00;
        $lawyerRate=.80;
        $platformRate=.20;
        $label='Less than 24 hours';
    }$cancelFee=round($fee*$rate, 2);
    $lawyerShare=round($fee*$lawyerRate, 2);
    $platformShare=round($fee*$platformRate, 2);
    return[$days, $rate, $cancelFee, max(0, $fee-$cancelFee), $lawyerShare, $platformShare, $label];
}
if(is_post()&&($_POST['action']??'')==='cancel_unpaid') {
    verify_csrf();
    $id=(int)($_POST['id']??0);
    $s=$pdo->prepare("SELECT a.slot_id,a.status,a.appointment_date,a.start_time,a.end_time,a.fee,lp.user_id lawyer_user_id,p.id payment_id,p.status payment_status FROM appointments a JOIN lawyer_profiles lp ON lp.id=a.lawyer_id LEFT JOIN payments p ON p.appointment_id=a.id WHERE a.id=? AND a.customer_id=?");
    $s->execute([$id, $cid]);
    $a=$s->fetch();
    if(!$a||$a['status']!=='Pending'||$a['payment_status']!=='Pending') {
        flash('error', 'This unpaid reservation can no longer be cancelled from this action.');
        redirect('/LegalEase_eProject/customer/appointments.php');
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE appointments SET status='Cancelled',cancel_reason='Customer cancelled unpaid reservation',cancelled_by='customer',notes=CONCAT(COALESCE(notes,''),' | Customer cancelled unpaid reservation before payment.'),updated_at=NOW() WHERE id=?")->execute([$id]);
        if($a['slot_id'])$pdo->prepare('UPDATE availability_slots SET is_available=1 WHERE id=?')->execute([$a['slot_id']]);
        if($a['payment_id'])$pdo->prepare("UPDATE payments SET status='Cancelled',updated_at=NOW() WHERE id=?")->execute([$a['payment_id']]);
        notify($pdo, (int)$a['lawyer_user_id'], 'Customer cancelled unpaid appointment #'.$id.'. The reserved time slot has been released and is available for booking again.', 'System', '/LegalEase_eProject/lawyer/appointment.php?id='.$id);
        notify($pdo, (int)user()['id'], 'Appointment #'.$id.' was cancelled before payment. The reserved time slot has been released.', 'System', '/LegalEase_eProject/customer/appointments.php');
        $pdo->commit();
        flash('success', 'Appointment cancelled. The reserved time slot is now available to other customers.');
    }catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        flash('error', 'Unable to cancel this reservation right now. Please try again.');
    }
    redirect('/LegalEase_eProject/customer/appointments.php');
}
if(is_post()&&($_POST['action']??'')==='cancel') {
    verify_csrf();
    $id=(int)$_POST['id'];
    $reasonChoice=trim($_POST['cancel_reason']??'');
    $reasonOther=trim($_POST['cancel_reason_other']??'');
    $allowedReasons=['Schedule conflict', 'Issue resolved', 'Booked another lawyer', 'Financial reasons', 'Personal reasons', 'Other'];
    if(!in_array($reasonChoice, $allowedReasons, true)) {
        flash('error', 'Please select a valid cancellation reason.');
        redirect('/LegalEase_eProject/customer/appointments.php');
    }$reason=$reasonChoice;
    if($reasonChoice==='Other') {
        if($reasonOther==='') {
            flash('error', 'Please enter the cancellation reason in the Other field.');
            redirect('/LegalEase_eProject/customer/appointments.php');
        }$reason='Other: '.$reasonOther;
    }
    $s=$pdo->prepare("SELECT a.slot_id,a.status,a.appointment_date,a.start_time,a.fee,lp.user_id lawyer_user_id,p.id payment_id,p.status payment_status,p.amount payment_amount FROM appointments a JOIN lawyer_profiles lp ON lp.id=a.lawyer_id LEFT JOIN payments p ON p.appointment_id=a.id WHERE a.id=? AND a.customer_id=?");
    $s->execute([$id, $cid]);
    $a=$s->fetch();
    if($a&&in_array($a['status'], ['Pending', 'Confirmed'], true)) {
        if($a['status']==='Confirmed'&&$a['payment_status']==='Success'&&new DateTimeImmutable('now')>=new DateTimeImmutable($a['appointment_date'].' '.$a['start_time'])) {
            flash('error', 'You cannot cancel this appointment because the confirmed consultation has already started. Please contact support if you need assistance.');
            redirect('/LegalEase_eProject/customer/appointments.php');
        } [$days, $rate, $cancelFee, $refund, $lawyerShare, $platformShare, $policyLabel]=cancellation_terms($a['appointment_date'], $a['start_time'], (float)($a['payment_amount']??$a['fee']));
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE appointments SET status='Cancelled',cancel_reason=?,cancelled_by='customer',notes=CONCAT(COALESCE(notes,''),' | Customer cancellation reason: ',?),updated_at=NOW() WHERE id=?")->execute([$reason, $reason, $id]);
        $pdo->prepare('UPDATE availability_slots SET is_available=1 WHERE id=?')->execute([$a['slot_id']]);
        if($a['payment_id']) {
            if($a['payment_status']==='Pending')$pdo->prepare("UPDATE payments SET status='Cancelled',updated_at=NOW() WHERE id=?")->execute([$a['payment_id']]);
            elseif($a['payment_status']==='Success') {
                $newStatus=$refund>=(float)$a['payment_amount']?'Refunded':'Partially Refunded';
                $pdo->prepare("INSERT INTO refunds(payment_id,appointment_id,customer_id,original_amount,cancellation_fee,refund_amount,lawyer_compensation,platform_fee,status,reason,processed_at) VALUES(?,?,?,?,?,?,?,?,'Processed',?,NOW()) ON DUPLICATE KEY UPDATE cancellation_fee=VALUES(cancellation_fee),refund_amount=VALUES(refund_amount),lawyer_compensation=VALUES(lawyer_compensation),platform_fee=VALUES(platform_fee),status='Processed',processed_at=NOW(),reason=VALUES(reason)")->execute([$a['payment_id'], $id, $cid, $a['payment_amount'], $cancelFee, $refund, $lawyerShare, $platformShare, 'Customer cancellation: '.$reason]);
                $pdo->prepare("UPDATE payments SET status=?,updated_at=NOW() WHERE id=?")->execute([$newStatus, $a['payment_id']]);
            }
        }
        $pct=(int)round($rate*100);
        notify($pdo, (int)$a['lawyer_user_id'], 'Customer cancelled appointment #'.$id.'. Policy: '.$policyLabel.'. Cancellation fee: '.$pct.'% ('.money_usd($cancelFee).'). Lawyer compensation: '.money_usd($lawyerShare).'. Platform fee: '.money_usd($platformShare).'. Reason: '.$reason.'.', 'System', '/LegalEase_eProject/lawyer/appointments.php');
        notify($pdo, (int)user()['id'], 'Appointment #'.$id.' was cancelled. '.($a['payment_status']==='Success'?'Refund '.money_usd($refund).' was processed successfully to your original payment account.':'No completed payment requires a refund.'), 'System', '/LegalEase_eProject/customer/payments.php');
        $pdo->commit();
        flash('success', 'Appointment cancelled. '.($a['payment_status']==='Success'?'Your refund has been processed successfully.':''));
    }redirect('/LegalEase_eProject/customer/appointments.php');
}
$status=$_GET['status']??'all';
$allowed=['all', 'Pending', 'Cancelled', 'Confirmed', 'Completed'];
if(!in_array($status, $allowed, true))$status='all';
$dateSort=strtolower($_GET['date_sort']??'desc')==='asc'?'asc':'desc';
$page=max(1, (int)($_GET['page']??1));
$per=10;
function customer_appt_url(array $changes=[]):string {
    $q=array_merge($_GET, $changes);
    return'?'.http_build_query($q);
}
$where='a.customer_id=?';
$params=[$cid];
if($status!=='all') {
    $where.=' AND a.status=?';
    $params[]=$status;
}$c=$pdo->prepare("SELECT COUNT(*) FROM appointments a WHERE $where");
$c->execute($params);
$total=(int)$c->fetchColumn();
$pages=max(1, (int)ceil($total/$per));
if($page>$pages)$page=$pages;
$offset=($page-1)*$per;
$sql="SELECT a.*,lp.full_name,(SELECT COUNT(*) FROM reviews r WHERE r.appointment_id=a.id) reviewed,(SELECT status FROM payments p WHERE p.appointment_id=a.id LIMIT 1) payment_status FROM appointments a JOIN lawyer_profiles lp ON lp.id=a.lawyer_id WHERE $where ORDER BY a.appointment_date ".strtoupper($dateSort).",a.start_time ".strtoupper($dateSort)." LIMIT $per OFFSET $offset";
$q=$pdo->prepare($sql);
$q->execute($params);
$rows=$q->fetchAll();
customer_shell_start('My Appointments', 'Track bookings, payments and consultation history.');
?>
<div class="policy-box" style="margin-bottom:18px">
<b>Cancellation & refund policy</b>
<br>Less than 24 hours: 100% fee (80% lawyer · 20% platform) · 1–3 days: 50% fee (30% lawyer · 20% platform) · 3–7 days: 20% fee · 8–15 days: 10% fee · more than 15 days: 5% fee. Once cancellation is confirmed, the eligible refund is processed immediately in this demo.</div>
<div class="appointment-filter-bar">
<div>
<span>Appointment status</span>
<div class="appointment-status-pills"><?php
foreach(['all'=>'All', 'Pending'=>'Pending', 'Cancelled'=>'Cancelled', 'Confirmed'=>'Confirmed', 'Completed'=>'Completed'] as $k=>$label):?><a class="<?=$status===$k?'active':''?>" href="<?=e(customer_appt_url(['status'=>$k, 'page'=>1]))?>">
<span><?=e($label)?></span>
</a><?php
endforeach?></div>
</div>
<a class="date-sort-control" href="<?=e(customer_appt_url(['date_sort'=>$dateSort==='asc'?'desc':'asc', 'page'=>1]))?>">Date <?=$dateSort==='asc'?'↑ Oldest first':'↓ Newest first'?></a>
</div>
<div class="table-wrap">
<table class="customer-table">
<thead>
<tr>
<th>Appointment ID</th>
<th>Lawyer</th>
<th>Date</th>
<th>Time</th>
<th>Fee</th>
<th>Status</th>
<th>Action</th>
</tr>
</thead>
<tbody><?php
foreach($rows as $r):[$days, $rate, $cancelFee, $refund, $lawyerShare, $platformShare, $policyLabel]=cancellation_terms($r['appointment_date'], $r['start_time'], (float)$r['fee']);
$startedConfirmed=$r['status']==='Confirmed'&&$r['payment_status']==='Success'&&new DateTimeImmutable('now')>=new DateTimeImmutable($r['appointment_date'].' '.$r['start_time']);
?><tr>
<td>
<strong>APT-<?=str_pad((string)$r['id'], 2, '0', STR_PAD_LEFT)?></strong>
<div class="muted appointment-created-at"><?=e(date('d/m/Y h:i A', strtotime($r['created_at'])))?></div>
</td>
<td>
<b><?=e($r['full_name'])?></b>
</td>
<td><?=e(date('d/m/Y', strtotime($r['appointment_date'])))?></td>
<td><?=e(substr($r['start_time'], 0, 5))?></td>
<td><?=money_usd($r['fee'])?></td>
<td>
<span class="badge <?=customer_badge($r['status'])?>"><?=e($r['status'])?></span><?php
if($r['payment_status']==='Pending'&&$r['status']==='Pending'):?><br>
<a class="payment-due-link" href="appointment_summary.php?appointment=<?=$r['id']?>">Payment required</a><?php
endif?></td>
<td><?php
if($r['payment_status']==='Pending'&&$r['status']==='Pending'):?><a class="btn btn-success btn-sm" href="appointment_summary.php?appointment=<?=$r['id']?>">Review & Pay</a>
<button type="button" class="btn btn-danger btn-sm open-unpaid-cancel" data-id="<?=$r['id']?>" data-lawyer="<?=e($r['full_name'])?>" data-date="<?=e(date('d M Y', strtotime($r['appointment_date'])))?>" data-time="<?=e(substr($r['start_time'], 0, 5).'–'.substr($r['end_time'], 0, 5))?>" data-fee="<?=e(money_usd($r['fee']))?>">Cancel</button><?php
elseif(in_array($r['status'], ['Pending', 'Confirmed'], true)):?><?php
if($startedConfirmed):?><button type="button" class="btn btn-danger btn-sm cancel-started-warning">Cancel</button><?php
else:?><button type="button" class="btn btn-danger btn-sm open-cancel" data-id="<?=$r['id']?>" data-fee="<?=e(money_usd($r['fee']))?>" data-rate="<?=(int)round($rate*100)?>" data-cancel-fee="<?=e(money_usd($cancelFee))?>" data-refund="<?=e(money_usd($refund))?>" data-lawyer-share="<?=e(money_usd($lawyerShare))?>" data-platform-share="<?=e(money_usd($platformShare))?>" data-policy="<?=e($policyLabel)?>" data-date="<?=e(date('d M Y', strtotime($r['appointment_date'])))?>">Cancel</button><?php
endif?><?php
elseif($r['status']==='Completed'&&!$r['reviewed']):?><a class="btn btn-light btn-sm" href="reviews.php?appointment=<?=$r['id']?>">Review</a><?php
else:?>—<?php
endif?></td>
</tr><?php
endforeach?><?php
if(!$rows):?><tr>
<td colspan="7" class="empty">No appointments in this category.</td>
</tr><?php
endif?></tbody>
</table>
</div><?=paginate($total, $page, $per, '/LegalEase_eProject/customer/appointments.php?status='.urlencode($status).'&date_sort='.urlencode($dateSort))?>

<div class="professional-modal" id="unpaidCancelModal">
<div class="professional-modal-card unpaid-cancel-card">
<button type="button" class="modal-x" id="unpaidCancelClose">×</button>
<h2>Cancel unpaid reservation</h2>
<p class="muted">Please review your appointment before cancelling.</p>
<div class="cancel-summary">
<div>
<span>Appointment</span>
<b id="unpaidAppt">
</b>
</div>
<div>
<span>Lawyer</span>
<b id="unpaidLawyer">
</b>
</div>
<div>
<span>Date</span>
<b id="unpaidDate">
</b>
</div>
<div>
<span>Time</span>
<b id="unpaidTime">
</b>
</div>
<div>
<span>Consultation fee</span>
<b id="unpaidFee">
</b>
</div>
<div>
<span>Payment status</span>
<b>Payment required</b>
</div>
</div>
<div class="unpaid-cancel-warning">
<strong>Your reserved time slot will be released and made available to another customer.</strong>
<span>Are you sure you want to cancel?</span>
</div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="cancel_unpaid">
<input type="hidden" name="id" id="unpaidCancelId">
<div class="modal-actions">
<button type="button" class="btn btn-light" id="unpaidCancelBack">Keep appointment</button>
<button class="btn btn-danger">Yes, cancel reservation</button>
</div>
</form>
</div>
</div>
<div class="professional-modal" id="cancelModal">
<div class="professional-modal-card">
<button type="button" class="modal-x" id="cancelClose">×</button>
<h2>Cancel appointment</h2>
<p class="muted">Review the cancellation fee and choose a reason.</p>
<div class="cancel-summary">
<div>
<span>Appointment</span>
<b id="cancelAppt">
</b>
</div>
<div>
<span>Date</span>
<b id="cancelDate">
</b>
</div>
<div>
<span>Consultation fee</span>
<b id="cancelFee">
</b>
</div>
<div>
<span>Cancellation fee</span>
<b id="cancelCharge">
</b>
</div>
<div>
<span>Refund after fee</span>
<b id="cancelRefund">
</b>
</div>
<div>
<span>Lawyer compensation</span>
<b id="cancelLawyerShare">
</b>
</div>
<div>
<span>Platform fee</span>
<b id="cancelPlatformShare">
</b>
</div>
<div>
<span>Policy window</span>
<b id="cancelPolicy">
</b>
</div>
</div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="cancel">
<input type="hidden" name="id" id="cancelId">
<div class="field">
<label>Reason for cancellation</label>
<select name="cancel_reason" id="customerCancelReason" required>
<option value="">Select a reason</option>
<option>Schedule conflict</option>
<option>Issue resolved</option>
<option>Booked another lawyer</option>
<option>Financial reasons</option>
<option>Personal reasons</option>
<option>Other</option>
</select>
</div>
<div class="field" id="customerOtherReasonWrap" style="display:none">
<label>Please specify the other reason</label>
<textarea name="cancel_reason_other" id="customerOtherReason" rows="3" maxlength="500" placeholder="Enter the reason for cancelling this appointment">
</textarea>
<small class="muted">Required when you select Other.</small>
</div>
<div class="modal-actions">
<button type="button" class="btn btn-light" id="cancelBack">Keep appointment</button>
<button class="btn btn-danger">Confirm cancellation</button>
</div>
</form>
</div>
</div>
<script>(()=>{const modal=document.getElementById('cancelModal'),sel=document.getElementById('customerCancelReason'),otherWrap=document.getElementById('customerOtherReasonWrap'),other=document.getElementById('customerOtherReason');function close(){modal.classList.remove('open')}function syncOther(){const show=sel.value==='Other';otherWrap.style.display=show?'block':'none';other.required=show;if(!show)other.value=''}document.querySelectorAll('.open-cancel').forEach(b=>b.onclick=()=>{cancelId.value=b.dataset.id;cancelAppt.textContent='#'+b.dataset.id;cancelDate.textContent=b.dataset.date;cancelFee.textContent=b.dataset.fee;cancelCharge.textContent=b.dataset.cancelFee+' ('+b.dataset.rate+'%)';cancelRefund.textContent=b.dataset.refund;cancelLawyerShare.textContent=b.dataset.lawyerShare;cancelPlatformShare.textContent=b.dataset.platformShare;cancelPolicy.textContent=b.dataset.policy;modal.classList.add('open')});document.querySelectorAll('.cancel-started-warning').forEach(b=>b.onclick=()=>alert('You cannot cancel this appointment because the confirmed consultation has already started. Please contact support if you need assistance.'));sel.addEventListener('change',syncOther);cancelClose.onclick=close;cancelBack.onclick=close;modal.onclick=e=>{if(e.target===modal)close()};document.querySelector('#cancelModal form').addEventListener('submit',e=>{if(sel.value==='Other'&&!other.value.trim()){e.preventDefault();alert('Please enter the cancellation reason in the Other field.');other.focus();}});
const unpaidModal=document.getElementById('unpaidCancelModal');function closeUnpaid(){unpaidModal.classList.remove('open')}document.querySelectorAll('.open-unpaid-cancel').forEach(b=>b.onclick=()=>{document.getElementById('unpaidCancelId').value=b.dataset.id;document.getElementById('unpaidAppt').textContent='#'+b.dataset.id;document.getElementById('unpaidLawyer').textContent=b.dataset.lawyer;document.getElementById('unpaidDate').textContent=b.dataset.date;document.getElementById('unpaidTime').textContent=b.dataset.time;document.getElementById('unpaidFee').textContent=b.dataset.fee;unpaidModal.classList.add('open')});document.getElementById('unpaidCancelClose').onclick=closeUnpaid;document.getElementById('unpaidCancelBack').onclick=closeUnpaid;unpaidModal.onclick=e=>{if(e.target===unpaidModal)closeUnpaid()};})();</script>
<?php
customer_shell_end();
?>
