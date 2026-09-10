<?php
require_once __DIR__.'/../includes/customer_layout.php';
require_role('customer');
$cid=current_customer_id($pdo);
$lawyerId=(int)($_GET['lawyer']??$_POST['lawyer_id']??0);
if(!$lawyerId&&!empty($_GET['slot'])) {
    $x=$pdo->prepare('SELECT lawyer_id FROM availability_slots WHERE id=?');
    $x->execute([(int)$_GET['slot']]);
    $lawyerId=(int)$x->fetchColumn();
}
$ls=$pdo->prepare("SELECT lp.id,lp.full_name,lp.consultation_fee,lp.avatar_file,lp.experience_years,lp.rating,lp.phone,u.email,l.city_name FROM lawyer_profiles lp JOIN users u ON u.id=lp.user_id LEFT JOIN locations l ON l.id=lp.location_id WHERE lp.id=? AND lp.is_verified=1 AND u.is_active=1");
$ls->execute([$lawyerId]);
$lawyer=$ls->fetch();
if(!$lawyer) {
    http_response_code(404);
    exit('Lawyer not found');
}$error='';
if(is_post()) {
    verify_csrf();
    $slotId=(int)($_POST['slot_id']??0);
    $note=trim($_POST['note']??'');
    if(!$slotId)$error='You have not selected a suitable appointment time. Please choose a date and available time slot before requesting the appointment.';
    elseif(mb_strlen($note)>2000)$error='The legal issue description must not exceed 2,000 characters.';
    else try {
        $pdo->beginTransaction();
        $lock=$pdo->prepare("SELECT vs.*,lp.user_id lawyer_user_id,lp.consultation_fee FROM availability_slots vs JOIN lawyer_profiles lp ON lp.id=vs.lawyer_id WHERE vs.id=? AND vs.lawyer_id=? FOR UPDATE");
        $lock->execute([$slotId, $lawyerId]);
        $slot=$lock->fetch();
        if(!$slot||!(int)$slot['is_available'])throw new RuntimeException('This consultation slot is no longer available. Please choose another time.');
        $fee=(float)$slot['consultation_fee'];
        $q=$pdo->prepare("INSERT INTO appointments(slot_id,customer_id,lawyer_id,service_name,note,appointment_date,start_time,end_time,fee,status,notes,payment_due_at) VALUES(?,?,?,'Legal Consultation',?,?,?,?,?,'Pending',?,DATE_ADD(NOW(),INTERVAL 20 MINUTE))");
        $q->execute([$slotId, $cid, $lawyerId, $note, $slot['available_date'], $slot['start_time'], $slot['end_time'], $fee, $note]);
        $appointmentId=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE availability_slots SET is_available=0 WHERE id=?')->execute([$slotId]);
        $pdo->prepare("INSERT INTO payments(appointment_id,customer_id,lawyer_id,amount,method,status) VALUES(?,?,?,?, '', 'Pending')")->execute([$appointmentId, $cid, $lawyerId, $fee]);
        notify($pdo, (int)$slot['lawyer_user_id'], 'New appointment request #'.$appointmentId.' is awaiting customer payment.', 'System', '/LegalEase_eProject/lawyer/appointment.php?id='.$appointmentId);
        notify($pdo, (int)user()['id'], 'Appointment #'.$appointmentId.' is reserved for 20 minutes. Complete payment before the deadline to keep this time slot.', 'Reminder', '/LegalEase_eProject/customer/appointment_summary.php?appointment='.$appointmentId);
        $pdo->commit();
        redirect('/LegalEase_eProject/customer/appointment_summary.php?appointment='.$appointmentId);
    }catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        $error=$e->getMessage();
    }
}
$minDate=new DateTimeImmutable('today');
$maxDate=$minDate->modify('+4 months');
$selectedRaw=$_GET['day']??$minDate->format('Y-m-d');
try {
    $selected=new DateTimeImmutable($selectedRaw);
}catch(Throwable $e) {
    $selected=$minDate;
}if($selected<$minDate)$selected=$minDate;
if($selected>$maxDate)$selected=$maxDate;
$windowStart=$selected->modify('-3 days');
if($windowStart<$minDate)$windowStart=$minDate;
$windowEnd=$windowStart->modify('+6 days');
if($windowEnd>$maxDate) {
    $windowEnd=$maxDate;
    $windowStart=$windowEnd->modify('-6 days');
    if($windowStart<$minDate)$windowStart=$minDate;
}
$countQ=$pdo->prepare("SELECT available_date,COUNT(*) c FROM availability_slots WHERE lawyer_id=? AND is_available=1 AND (available_date>CURDATE() OR (available_date=CURDATE() AND start_time>CURTIME())) AND available_date BETWEEN ? AND ? GROUP BY available_date");
$countQ->execute([$lawyerId, $windowStart->format('Y-m-d'), $windowEnd->format('Y-m-d')]);
$counts=[];
foreach($countQ as $r)$counts[$r['available_date']]=(int)$r['c'];
$cnt=$pdo->prepare("SELECT COUNT(*) FROM availability_slots WHERE lawyer_id=? AND is_available=1 AND (available_date>CURDATE() OR (available_date=CURDATE() AND start_time>CURTIME())) AND available_date=?");
$cnt->execute([$lawyerId, $selected->format('Y-m-d')]);
$slotTotal=(int)$cnt->fetchColumn();
$ss=$pdo->prepare("SELECT id,available_date,start_time,end_time FROM availability_slots WHERE lawyer_id=? AND is_available=1 AND (available_date>CURDATE() OR (available_date=CURDATE() AND start_time>CURTIME())) AND available_date=? ORDER BY start_time");
$ss->execute([$lawyerId, $selected->format('Y-m-d')]);
$slots=$ss->fetchAll();
customer_shell_start('Book Appointment', 'Choose an available date and time with your lawyer.');
if($error)echo '<div class="alert alert-error">'.e($error).'</div>';
?>
<div class="appointment-booking-page">
<section class="booking-lawyer-card card">
<img src="<?=e(avatar_url($lawyer['avatar_file']??''))?>" alt="">
<div>
<span class="verified">✓ Verified Lawyer</span>
<h2><?=e($lawyer['full_name'])?></h2>
<div class="booking-lawyer-meta">
<span><?=star_rating_html((float)$lawyer['rating'])?> <?=number_format((float)$lawyer['rating'], 1)?></span>
<span><?=(int)$lawyer['experience_years']?> years experience</span>
<span><?=e($lawyer['city_name'])?></span>
</div>
<small><?=e($lawyer['email'])?> · <?=e($lawyer['phone'])?></small>
</div>
<div class="booking-fee">
<small>Consultation fee</small>
<strong><?=money_usd($lawyer['consultation_fee'])?></strong>
<span>/ 60 minutes</span>
</div>
</section>
<div class="card quick-booking">
<div class="quick-booking-head">
<div>
<h2>Book an appointment</h2>
<p class="muted">Select a date to focus the schedule, then choose an available one-hour slot.</p>
</div>
<label class="date-focus">Choose date<input type="date" id="focusDate" min="<?=$minDate->format('Y-m-d')?>" max="<?=$maxDate->format('Y-m-d')?>" value="<?=$selected->format('Y-m-d')?>">
</label>
</div>
<div class="date-strip">
<a class="date-arrow <?= $windowStart<=$minDate?'disabled':''?>" href="?lawyer=<?=$lawyerId?>&day=<?=$windowStart->modify('-7 days')->format('Y-m-d')?>">‹</a>
<div class="date-strip-grid"><?php
for($d=$windowStart;$d<=$windowEnd;$d=$d->modify('+1 day')):$key=$d->format('Y-m-d');
?><a class="date-pill <?=$key===$selected->format('Y-m-d')?'active':''?>" href="?lawyer=<?=$lawyerId?>&day=<?=$key?>">
<span><?=$d->format('D')?></span>
<b><?=$d->format('d-m')?></b>
<small><?=($counts[$key]??0)?> slots</small>
</a><?php
endfor?></div>
<a class="date-arrow <?= $windowEnd>=$maxDate?'disabled':''?>" href="?lawyer=<?=$lawyerId?>&day=<?=$windowEnd->modify('+1 day')->format('Y-m-d')?>">›</a>
</div>
<div class="slot-section">
<div class="slot-section-title">
<b>Available times · <?=$selected->format('D, d M Y')?></b>
<span><?=$slotTotal?> available</span>
</div>
<div class="time-slot-grid"><?php
if(!$slots):?><div class="empty">No available appointment times on this date. Select one of the nearby dates.</div><?php
else:foreach($slots as $sl):?><button type="button" class="booking-slot" data-slot="<?=$sl['id']?>" data-time="<?=e(substr($sl['start_time'], 0, 5).'–'.substr($sl['end_time'], 0, 5))?>">
<span><?=e(substr($sl['start_time'], 0, 5))?></span>
<small>to <?=e(substr($sl['end_time'], 0, 5))?></small>
</button><?php
endforeach;
endif?></div>
</div>
<form method="post" id="bookingForm" class="booking-request-form"><?=csrf_field()?><input type="hidden" name="lawyer_id" value="<?=$lawyerId?>">
<input type="hidden" name="slot_id" id="slotId">
<div class="selected-booking-callout">
<span>Selected appointment</span>
<strong id="selectedSchedule">Choose a time above</strong>
</div>
<div class="field">
<label>Brief description of your legal issue <span class="muted">(optional)</span>
</label>
<textarea name="note" maxlength="2000" placeholder="Briefly summarize your legal issue for the lawyer...">
</textarea>
</div>
<div class="booking-total">
<span>Total consultation fee</span>
<strong><?=money_usd($lawyer['consultation_fee'])?></strong>
</div>
<div class="booking-validation" id="bookingValidation" role="alert" aria-live="polite">
</div>
<button class="btn btn-success" id="bookingSubmit">Request Appointment</button>
</form>
</div>
</div>
<script>(()=>{const date=document.getElementById('focusDate');date.onchange=()=>location.href='?lawyer=<?=$lawyerId?>&day='+date.value;const buttons=[...document.querySelectorAll('.booking-slot')],slot=document.getElementById('slotId'),summary=document.getElementById('selectedSchedule'),form=document.getElementById('bookingForm'),validation=document.getElementById('bookingValidation');buttons.forEach(btn=>btn.onclick=()=>{buttons.forEach(x=>x.classList.remove('selected'));btn.classList.add('selected');slot.value=btn.dataset.slot;summary.textContent='<?=$selected->format('D, d M Y')?> · '+btn.dataset.time;validation.textContent='';validation.classList.remove('show');});form.addEventListener('submit',e=>{if(!slot.value){e.preventDefault();validation.textContent='You have not selected a suitable appointment time. Please choose a date and an available time slot first.';validation.classList.add('show');validation.scrollIntoView({behavior:'smooth',block:'center'});}});})();</script>
<?php
customer_shell_end();
?>
