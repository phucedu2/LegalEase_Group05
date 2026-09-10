<?php
require_once __DIR__.'/../includes/lawyer_layout.php';
require_role('lawyer');
$lid=current_lawyer_id($pdo);
$id=(int)($_GET['id']??$_POST['id']??0);
function load_lawyer_appointment(PDO $pdo, int $id, int $lid):?array {
    $s=$pdo->prepare("SELECT a.*,cp.full_name customer_name,cp.phone customer_phone,u.email customer_email,cp.user_id customer_user_id,vs.available_date,vs.start_time,vs.end_time,p.id payment_id,p.status payment_status,p.amount payment_amount,p.method payment_method,r.refund_amount,r.cancellation_fee,r.lawyer_compensation,r.platform_fee refund_platform_fee FROM appointments a JOIN customer_profiles cp ON cp.id=a.customer_id JOIN users u ON u.id=cp.user_id JOIN availability_slots vs ON vs.id=a.slot_id LEFT JOIN payments p ON p.appointment_id=a.id LEFT JOIN refunds r ON r.appointment_id=a.id WHERE a.id=? AND a.lawyer_id=?");
    $s->execute([$id, $lid]);
    $r=$s->fetch();
    return$r?:null;
}
if($id<=0) {
    http_response_code(400);
    exit('Invalid appointment ID.');
}
if(is_post()) {
    verify_csrf();
    $action=$_POST['action']??'';
    if(!in_array($action, ['confirm', 'complete', 'cancel'], true)) {
        flash('error', 'Invalid appointment action.');
        redirect('/LegalEase_eProject/lawyer/appointment.php?id='.$id);
    }
    try {
        $pdo->beginTransaction();
        $lock=$pdo->prepare("SELECT a.id,a.slot_id,a.customer_id,a.status,a.appointment_date,a.start_time,a.end_time,p.id payment_id,p.status payment_status,p.amount payment_amount FROM appointments a LEFT JOIN payments p ON p.appointment_id=a.id WHERE a.id=? AND a.lawyer_id=? FOR UPDATE");
        $lock->execute([$id, $lid]);
        $current=$lock->fetch();
        if(!$current)throw new RuntimeException('Appointment not found.');
        $new=null;
        if(in_array($action, ['confirm', 'cancel'], true)&&($current['payment_status']??'Pending')!=='Success')throw new RuntimeException('You cannot confirm or cancel this appointment because the customer has not completed payment.');
        if($action==='complete') {
            $eligibleAt=strtotime($current['appointment_date'].' '.$current['end_time'])+1800;
            if(time()<$eligibleAt) {
                $scheduledEnd=date('d M Y H:i', strtotime($current['appointment_date'].' '.$current['end_time']));
                $eligibleText=date('d M Y H:i', $eligibleAt);
                throw new RuntimeException('You cannot mark this appointment completed yet. The scheduled consultation ends at '.$scheduledEnd.'. Mark Completed becomes available at '.$eligibleText.' (30 minutes after the scheduled end). Current time: '.date('d M Y H:i').'.');
            }
        }
        if($action==='confirm'&&$current['status']==='Pending')$new='Confirmed';
        if($action==='complete'&&$current['status']==='Confirmed')$new='Completed';
        if($action==='cancel'&&in_array($current['status'], ['Pending', 'Confirmed'], true))$new='Cancelled';
        if(!$new)throw new RuntimeException('This status change is not allowed from the current appointment status.');
        $reason=trim($_POST['cancel_reason']??'');
        $reasonOther=trim($_POST['cancel_reason_other']??'');
        if($new==='Cancelled') {
            if($reason==='')throw new RuntimeException('Please select a cancellation reason.');
            if($reason==='Other') {
                if($reasonOther==='')throw new RuntimeException('Please enter the cancellation reason in the Other field.');
                $reason='Other: '.$reasonOther;
            }$pdo->prepare("UPDATE appointments SET status=?,cancel_reason=?,cancelled_by='lawyer',notes=CONCAT(COALESCE(notes,''),' | Lawyer cancellation reason: ',?),updated_at=NOW() WHERE id=? AND lawyer_id=?")->execute([$new, $reason, $reason, $id, $lid]);
        }else {
            $pdo->prepare('UPDATE appointments SET status=?,updated_at=NOW() WHERE id=? AND lawyer_id=?')->execute([$new, $id, $lid]);
        }
        $u=$pdo->prepare('SELECT user_id FROM customer_profiles WHERE id=?');
        $u->execute([$current['customer_id']]);
        $customerUid=(int)$u->fetchColumn();
        if($new==='Cancelled') {
            $pdo->prepare('UPDATE availability_slots SET is_available=1 WHERE id=?')->execute([$current['slot_id']]);
            $amount=(float)($current['payment_amount']??0);
            $pdo->prepare("INSERT INTO refunds(payment_id,appointment_id,customer_id,original_amount,cancellation_fee,refund_amount,status,reason,processed_at) VALUES(?,?,?,?,0,?,'Processed',?,NOW()) ON DUPLICATE KEY UPDATE cancellation_fee=0,refund_amount=VALUES(refund_amount),status='Processed',reason=VALUES(reason),processed_at=NOW()")->execute([$current['payment_id'], $id, $current['customer_id'], $amount, $amount, 'Lawyer cancellation: '.$reason]);
            $pdo->prepare("UPDATE payments SET status='Refunded',updated_at=NOW() WHERE id=?")->execute([$current['payment_id']]);
            if($customerUid)notify($pdo, $customerUid, 'Appointment #'.$id.' was cancelled by the lawyer. Your full payment of '.money_usd($amount).' has been returned to your account. Reason: '.$reason.'.', 'System', '/LegalEase_eProject/customer/payments.php');
        }
        elseif($new==='Confirmed') {
            if($customerUid)notify($pdo, $customerUid, 'Appointment #'.$id.' has been confirmed by your lawyer.', 'System', '/LegalEase_eProject/customer/appointments.php');
            notify($pdo, (int)user()['id'], 'Appointment #'.$id.' confirmed. Expected lawyer income: '.money_usd((float)$current['payment_amount']*.80).' (80% of the consultation fee).', 'System', '/LegalEase_eProject/lawyer/dashboard.php');
        }
        elseif($new==='Completed') {
            if($customerUid)notify($pdo, $customerUid, 'Appointment #'.$id.' has been marked completed.', 'System', '/LegalEase_eProject/customer/appointments.php');
            notify($pdo, (int)user()['id'], 'Appointment #'.$id.' completed. Your income is '.money_usd((float)$current['payment_amount']*.80).'. The amount will be settled automatically within 3 hours.', 'System', '/LegalEase_eProject/lawyer/dashboard.php');
        }
        $pdo->commit();
        flash('success', 'Appointment updated to '.$new.'.'.($new==='Completed'?' Earnings will be settled automatically within 3 hours.':''));
    }catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        flash('error', $e->getMessage());
    }
    redirect('/LegalEase_eProject/lawyer/appointment.php?id='.$id);
}
$a=load_lawyer_appointment($pdo, $id, $lid);
if(!$a) {
    http_response_code(404);
    exit('Appointment not found.');
}$gross=(float)($a['payment_amount']??$a['fee']);
$platform=$gross*.20;
$net=$gross*.80;
$cancelLawyer=(float)($a['lawyer_compensation']??0);
$cancelPlatform=(float)($a['refund_platform_fee']??0);
$cancelRefund=(float)($a['refund_amount']??0);
$cancelFee=(float)($a['cancellation_fee']??0);
$paid=($a['payment_status']??'')==='Success';
$completeEligibleAt=strtotime($a['available_date'].' '.$a['end_time'])+1800;
$canComplete=time()>=$completeEligibleAt;
$completeEligibilityMessage='Mark Completed is available from '.date('d M Y H:i', $completeEligibleAt).' (30 minutes after the scheduled consultation ends). Current time: '.date('d M Y H:i').'.';
lawyer_shell_start('Appointment #'.$id, 'Review customer information, legal issue and consultation schedule.');
?>
<div class="detail-grid">
<div class="card">
<div class="card-header">
<h2>Customer Information</h2>
<span class="badge <?=lawyer_badge_class($a['status'])?>"><?=e($a['status'])?></span>
</div>
<div class="info-block">
<div class="info-row">
<span>Name</span>
<strong><?=e($a['customer_name'])?></strong>
</div>
<div class="info-row">
<span>Email</span>
<strong><?=e($a['customer_email'])?></strong>
</div>
<div class="info-row">
<span>Phone</span>
<strong><?=e($a['customer_phone'])?></strong>
</div>
</div>
<div class="info-block">
<h3>Customer's Note</h3>
<p><?=trim((string)$a['note'])!==''?nl2br(e($a['note'])):'No description provided.'?></p>
</div><?php
if($a['status']==='Cancelled'):?><?php
$cancelSource=(str_starts_with((string)$a['cancel_reason'], 'Automatically cancelled')?'System':(ucfirst((string)($a['cancelled_by']?:'System'))));
?><div class="info-block cancellation-reason-box">
<h3>Cancellation reason</h3>
<p>
<strong>Cancelled by: <?=e($cancelSource)?></strong>
</p>
<p><?=trim((string)$a['cancel_reason'])!==''?nl2br(e($a['cancel_reason'])):'No specific cancellation reason was recorded.'?></p>
</div><?php
endif?></div>
<div class="card">
<div class="card-header">
<h2>Service & Schedule</h2>
</div>
<div class="info-row">
<span>Service</span>
<strong>Legal Consultation</strong>
</div>
<div class="info-row">
<span>Date</span>
<strong><?=e(date('d/m/Y', strtotime($a['available_date'])))?></strong>
</div>
<div class="info-row">
<span>Time Slot</span>
<strong><?=e(substr($a['start_time'], 0, 5))?> - <?=e(substr($a['end_time'], 0, 5))?></strong>
</div>
<div class="info-row">
<span>Duration</span>
<strong>1 hour</strong>
</div>
<div class="info-row">
<span>Payment</span>
<strong><?=e($a['payment_status']??'Pending')?><?=!empty($a['payment_method'])?' · '.e($a['payment_method']):''?></strong>
</div>
<div class="info-row">
<span>Status</span>
<span class="badge <?=lawyer_badge_class($a['status'])?>"><?=e($a['status'])?></span>
</div>
<?php
if(in_array($a['status'], ['Confirmed', 'Cancelled', 'Completed'], true)):?><div class="appointment-finance-box">
<h3>Appointment financial details</h3>
<div class="info-row">
<span>Consultation fee</span>
<strong><?=money_usd($gross)?></strong>
</div><?php
if($a['status']==='Cancelled'):?><div class="info-row">
<span>Cancellation fee</span>
<strong><?=money_usd($cancelFee)?></strong>
</div>
<div class="info-row">
<span>Customer refund</span>
<strong><?=money_usd($cancelRefund)?></strong>
</div>
<div class="info-row">
<span>Lawyer compensation</span>
<strong><?=money_usd($cancelLawyer)?></strong>
</div>
<div class="info-row">
<span>LegalEase platform fee</span>
<strong><?=money_usd($cancelPlatform)?></strong>
</div><?php
else:?><div class="info-row">
<span>LegalEase platform fee (20%)</span>
<strong><?=money_usd($platform)?></strong>
</div>
<div class="info-row">
<span><?=$a['status']==='Completed'?'Your income (80%)':'Expected lawyer income (80%)'?></span>
<strong><?=money_usd($net)?></strong>
</div><?php
if($a['status']==='Completed'):?><p class="muted">Automatic settlement target: within 3 hours after completion.</p><?php
endif?><?php
endif?></div><?php
endif?>
<?php
if(!$paid&&in_array($a['status'], ['Pending', 'Confirmed'], true)):?><div class="policy-box" style="border-color:#f0b8b8;background:#fff4f4;color:#8b2424">
<b>Payment required.</b> You cannot confirm or cancel this appointment because the customer has not completed payment.</div><?php
endif?>
<form method="post" id="appointmentActionForm"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>">
<input type="hidden" name="cancel_reason" id="lawyerCancelReason">
<input type="hidden" name="cancel_reason_other" id="lawyerCancelReasonOther">
<div class="action-row"><?php
if($a['status']==='Pending'):?><?php
if($paid):?><button name="action" value="confirm" class="btn btn-success" data-confirm="Confirm this paid appointment request?">Confirm Request</button>
<button type="button" class="btn btn-danger" id="openLawyerCancel">Cancel Appointment</button><?php
else:?><button type="button" class="btn btn-success" onclick="alert('You cannot confirm this appointment because the customer has not completed payment.')">Confirm Request</button>
<button type="button" class="btn btn-danger" onclick="alert('You cannot cancel this appointment because the customer has not completed payment.')">Cancel Appointment</button><?php
endif?><?php
elseif($a['status']==='Confirmed'):?><?php
if($canComplete):?><button type="button" class="btn btn-success" id="reviewCompleteBtn">Mark Completed</button>
<button name="action" value="complete" id="completeSubmitBtn" style="display:none">Confirm completion</button><?php
else:?><button type="button" class="btn btn-success" onclick="alert(<?=e(json_encode($completeEligibilityMessage))?>)">Mark Completed</button><?php
endif?><?php
if($paid):?><button type="button" class="btn btn-danger" id="openLawyerCancel">Cancel Appointment</button><?php
endif?><?php
else:?><span class="muted">No status actions are available for this appointment.</span><?php
endif?></div>
</form>
</div>
</div>
<?php
if($a['status']==='Confirmed'):?><div class="complete-review-backdrop" id="completeReview">
<div class="complete-review-card">
<h3>Review completed appointment</h3>
<p class="muted">Please review the appointment and earnings breakdown before marking it completed.</p>
<div class="info-row">
<span>Customer</span>
<strong><?=e($a['customer_name'])?></strong>
</div>
<div class="info-row">
<span>Date</span>
<strong><?=e(date('d/m/Y', strtotime($a['available_date'])))?></strong>
</div>
<div class="info-row">
<span>Time</span>
<strong><?=e(substr($a['start_time'], 0, 5))?> - <?=e(substr($a['end_time'], 0, 5))?></strong>
</div>
<div class="info-row">
<span>Consultation fee</span>
<strong><?=money_usd($gross)?></strong>
</div>
<div class="info-row">
<span>LegalEase platform fee (20%)</span>
<strong>- <?=money_usd($platform)?></strong>
</div>
<div class="info-row earning-net">
<span>Your income (80%)</span>
<strong><?=money_usd($net)?></strong>
</div>
<div class="policy-box">
<b>Settlement notice:</b> your 80% income will be settled automatically within 3 hours after this appointment is marked completed.</div>
<div class="action-row">
<button type="button" class="btn btn-light" id="closeCompleteReview">Back</button>
<button type="button" class="btn btn-success" id="confirmCompleteReview">Confirm & Mark Completed</button>
</div>
</div>
</div><?php
endif?>
<?php
if($paid&&in_array($a['status'], ['Pending', 'Confirmed'], true)):?><div class="professional-modal" id="lawyerCancelModal">
<div class="professional-modal-card">
<button class="modal-x" type="button" id="lawyerCancelClose">×</button>
<h2>Cancel appointment</h2>
<p class="muted">Review the appointment before cancelling. A lawyer cancellation returns the customer's full payment.</p>
<div class="cancel-summary">
<div>
<span>Customer</span>
<b><?=e($a['customer_name'])?></b>
</div>
<div>
<span>Date & time</span>
<b><?=e(date('d M Y', strtotime($a['available_date'])))?> · <?=e(substr($a['start_time'], 0, 5))?>–<?=e(substr($a['end_time'], 0, 5))?></b>
</div>
<div>
<span>Consultation fee</span>
<b><?=money_usd($gross)?></b>
</div>
<div>
<span>Customer refund</span>
<b><?=money_usd($gross)?></b>
</div>
</div>
<div class="field">
<label>Reason for cancellation</label>
<select id="lawyerCancelSelect">
<option value="">Select a reason</option>
<option>Schedule conflict</option>
<option>Professional emergency</option>
<option>Conflict of interest</option>
<option>Case outside practice scope</option>
<option>Unable to attend</option>
<option>Other</option>
</select>
</div>
<div class="field" id="lawyerOtherReasonWrap" style="display:none">
<label>Please specify the other reason</label>
<textarea id="lawyerOtherReason" rows="3" maxlength="500" placeholder="Enter the reason for cancelling this appointment">
</textarea>
<small class="muted">Required when you select Other.</small>
</div>
<div class="policy-box">When confirmed, the customer's full payment is returned immediately in this demo and the customer receives a notification that the funds have been returned to their account.</div>
<div class="modal-actions">
<button type="button" class="btn btn-light" id="lawyerCancelBack">Keep appointment</button>
<button type="button" class="btn btn-danger" id="lawyerCancelConfirm">Confirm cancellation & refund</button>
</div>
</div>
</div><?php
endif?>
<script>(function(){const cr=document.getElementById('completeReview');document.getElementById('reviewCompleteBtn')?.addEventListener('click',()=>cr.classList.add('open'));document.getElementById('closeCompleteReview')?.addEventListener('click',()=>cr.classList.remove('open'));document.getElementById('confirmCompleteReview')?.addEventListener('click',()=>{cr.classList.remove('open');document.getElementById('completeSubmitBtn').click()});const modal=document.getElementById('lawyerCancelModal'),open=document.getElementById('openLawyerCancel'),sel=document.getElementById('lawyerCancelSelect'),otherWrap=document.getElementById('lawyerOtherReasonWrap'),other=document.getElementById('lawyerOtherReason');function close(){modal&&modal.classList.remove('open')}function syncOther(){if(!sel)return;const show=sel.value==='Other';if(otherWrap)otherWrap.style.display=show?'block':'none';if(!show&&other)other.value=''}open?.addEventListener('click',()=>modal.classList.add('open'));sel?.addEventListener('change',syncOther);document.getElementById('lawyerCancelClose')?.addEventListener('click',close);document.getElementById('lawyerCancelBack')?.addEventListener('click',close);document.getElementById('lawyerCancelConfirm')?.addEventListener('click',()=>{if(!sel.value){alert('Please select a cancellation reason.');return;}if(sel.value==='Other'&&!other.value.trim()){alert('Please enter the cancellation reason in the Other field.');other.focus();return;}document.getElementById('lawyerCancelReason').value=sel.value;document.getElementById('lawyerCancelReasonOther').value=sel.value==='Other'?other.value.trim():'';const f=document.getElementById('appointmentActionForm'),btn=document.createElement('button');btn.name='action';btn.value='cancel';btn.style.display='none';f.appendChild(btn);btn.click();});})();</script>
<?php
lawyer_shell_end();
?>
