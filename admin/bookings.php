<?php
require_once __DIR__.'/includes/auth.php';
$active_page='bookings';
function admin_booking_return_url(): string {
    $params=[];
    foreach(['status', 'search', 'page'] as $k) if(isset($_POST[$k]) && $_POST[$k]!=='') $params[$k]=$_POST[$k];
    return '/LegalEase_eProject/admin/bookings.php'.($params?'?'.http_build_query($params):'');
}
if(is_post()) {
    verify_csrf();
    $id=(int)($_POST['appointment_id']??0);
    $action=(string)($_POST['action']??'');
    $return=admin_booking_return_url();
    if($action==='cancel' && $id) {
        $reason=trim((string)($_POST['cancel_reason']??''));
        if(mb_strlen($reason)<5) {
            flash('error', 'Please enter a cancellation reason of at least 5 characters.');
            redirect($return);
        }
        try {
            $pdo->beginTransaction();
            $s=$pdo->prepare("SELECT a.id,a.slot_id,a.status,a.customer_id,a.lawyer_id,cp.user_id customer_user_id,lp.user_id lawyer_user_id,p.id payment_id,p.amount,p.status payment_status FROM appointments a JOIN customer_profiles cp ON cp.id=a.customer_id JOIN lawyer_profiles lp ON lp.id=a.lawyer_id LEFT JOIN payments p ON p.appointment_id=a.id WHERE a.id=? FOR UPDATE");
            $s->execute([$id]);
            $a=$s->fetch();
            if(!$a || !in_array($a['status'], ['Pending', 'Confirmed'], true)) throw new RuntimeException('This appointment can no longer be cancelled.');
            $pdo->prepare("UPDATE appointments SET status='Cancelled',cancel_reason=?,cancelled_by='admin',notes=CONCAT(COALESCE(notes,''),' | Admin cancellation reason: ',?),updated_at=NOW() WHERE id=?")->execute([$reason, $reason, $id]);
            if(!empty($a['slot_id'])) $pdo->prepare('UPDATE availability_slots SET is_available=1 WHERE id=?')->execute([(int)$a['slot_id']]);
            if(!empty($a['payment_id'])) {
                if($a['payment_status']==='Success') {
                    $amount=(float)$a['amount'];
                    $pdo->prepare("INSERT INTO refunds(payment_id,appointment_id,customer_id,original_amount,cancellation_fee,refund_amount,lawyer_compensation,platform_fee,status,reason,processed_at,created_at) VALUES(?,?,?,?,0,?,0,0,'Processed',?,NOW(),NOW()) ON DUPLICATE KEY UPDATE cancellation_fee=0,refund_amount=VALUES(refund_amount),lawyer_compensation=0,platform_fee=0,status='Processed',reason=VALUES(reason),processed_at=NOW()")->execute([(int)$a['payment_id'], $id, (int)$a['customer_id'], $amount, $amount, 'Administrative cancellation: '.$reason]);
                    $pdo->prepare("UPDATE payments SET status='Refunded',updated_at=NOW() WHERE id=?")->execute([(int)$a['payment_id']]);
                } elseif($a['payment_status']==='Pending') {
                    $pdo->prepare("UPDATE payments SET status='Cancelled',updated_at=NOW() WHERE id=?")->execute([(int)$a['payment_id']]);
                }
            }
            notify($pdo, (int)$a['customer_user_id'], 'Appointment #'.$id.' was cancelled by LegalEase administration. '.($a['payment_status']==='Success'?'Your payment has been fully refunded. ':'').'Reason: '.$reason, 'System', '/LegalEase_eProject/customer/appointments.php');
            notify($pdo, (int)$a['lawyer_user_id'], 'Appointment #'.$id.' was cancelled by LegalEase administration. Reason: '.$reason, 'System', '/LegalEase_eProject/lawyer/appointment.php?id='.$id);
            $pdo->commit();
            flash('success', 'Appointment #'.$id.' was cancelled successfully.'.($a['payment_status']==='Success'?' A full refund was processed.':''));
        }catch(Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            flash('error', $e instanceof RuntimeException?$e->getMessage():'Unable to cancel the appointment right now.');
        }
        redirect($return);
    }
    if($action==='reschedule' && $id) {
        $date=trim((string)($_POST['new_date']??''));
        $start=trim((string)($_POST['new_start']??''));
        $end=trim((string)($_POST['new_end']??''));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)||!preg_match('/^\d{2}:\d{2}$/', $start)||!preg_match('/^\d{2}:\d{2}$/', $end)||$start>=$end) {
            flash('error', 'Please select a valid reschedule date and time.');
            redirect($return);
        }
        $newStart=new DateTimeImmutable($date.' '.$start, new DateTimeZone('Asia/Ho_Chi_Minh'));
        $newEnd=new DateTimeImmutable($date.' '.$end, new DateTimeZone('Asia/Ho_Chi_Minh'));
        if($newStart<=new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh'))) {
            flash('error', 'The new appointment time must be in the future.');
            redirect($return);
        }
        if(($newEnd->getTimestamp()-$newStart->getTimestamp())!==3600) {
            flash('error', 'LegalEase consultations must remain exactly 1 hour.');
            redirect($return);
        }
        try {
            $pdo->beginTransaction();
            $s=$pdo->prepare("SELECT a.id,a.slot_id,a.lawyer_id,a.status,cp.user_id customer_user_id,lp.user_id lawyer_user_id FROM appointments a JOIN customer_profiles cp ON cp.id=a.customer_id JOIN lawyer_profiles lp ON lp.id=a.lawyer_id WHERE a.id=? FOR UPDATE");
            $s->execute([$id]);
            $a=$s->fetch();
            if(!$a || !in_array($a['status'], ['Pending', 'Confirmed'], true)) throw new RuntimeException('This appointment can no longer be rescheduled.');
            $conf=$pdo->prepare("SELECT COUNT(*) FROM appointments WHERE lawyer_id=? AND id<>? AND status IN('Pending','Confirmed') AND appointment_date=? AND start_time < ? AND end_time > ?");
            $conf->execute([(int)$a['lawyer_id'], $id, $date, $end, $start]);
            if((int)$conf->fetchColumn()>0) throw new RuntimeException('The lawyer already has another booked appointment during that time.');
            $exact=$pdo->prepare("SELECT id,is_available FROM availability_slots WHERE lawyer_id=? AND id<>? AND available_date=? AND start_time=? AND end_time=? ORDER BY is_available DESC,id LIMIT 1 FOR UPDATE");
            $exact->execute([(int)$a['lawyer_id'], (int)$a['slot_id'], $date, $start.':00', $end.':00']);
            $target=$exact->fetch();
            if(!$target) {
                $slotConflict=$pdo->prepare("SELECT COUNT(*) FROM availability_slots WHERE lawyer_id=? AND id<>? AND is_available=0 AND available_date=? AND start_time < ? AND end_time > ?");
                $slotConflict->execute([(int)$a['lawyer_id'], (int)$a['slot_id'], $date, $end, $start]);
                if((int)$slotConflict->fetchColumn()>0) throw new RuntimeException('That time is already locked by another booking.');
                $pdo->prepare("INSERT INTO availability_slots(lawyer_id,available_date,start_time,end_time,is_available) VALUES(?,?,?,?,0)")->execute([(int)$a['lawyer_id'], $date, $start, $end]);
                $newSlot=(int)$pdo->lastInsertId();
            } else {
                if(!(int)$target['is_available']) throw new RuntimeException('That time slot is already booked.');
                $newSlot=(int)$target['id'];
                $pdo->prepare('UPDATE availability_slots SET is_available=0 WHERE id=?')->execute([$newSlot]);
            }
            if(!empty($a['slot_id']) && (int)$a['slot_id']!==$newSlot) $pdo->prepare('UPDATE availability_slots SET is_available=1 WHERE id=?')->execute([(int)$a['slot_id']]);
            $pdo->prepare("UPDATE appointments SET slot_id=?,appointment_date=?,start_time=?,end_time=?,updated_at=NOW() WHERE id=?")->execute([$newSlot, $date, $start, $end, $id]);
            notify($pdo, (int)$a['customer_user_id'], 'Appointment #'.$id.' was rescheduled by LegalEase administration to '.date('d M Y', strtotime($date)).' at '.$start.'–'.$end.'.', 'System', '/LegalEase_eProject/customer/appointments.php');
            notify($pdo, (int)$a['lawyer_user_id'], 'Appointment #'.$id.' was rescheduled by LegalEase administration to '.date('d M Y', strtotime($date)).' at '.$start.'–'.$end.'.', 'System', '/LegalEase_eProject/lawyer/appointment.php?id='.$id);
            $pdo->commit();
            flash('success', 'Appointment #'.$id.' was rescheduled successfully.');
        }catch(Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            flash('error', $e instanceof RuntimeException?$e->getMessage():'Unable to reschedule the appointment right now.');
        }
        redirect($return);
    }
}
$status=$_GET['status']??'';
$search=trim($_GET['search']??'');
$where=[];
$params=[];
if(in_array(strtolower($status), ['pending', 'confirmed', 'completed', 'cancelled'], true)) {
    $where[]='LOWER(a.status)=?';
    $params[]=strtolower($status);
}if($search!=='') {
    $where[]='(cp.full_name LIKE ? OR lp.full_name LIKE ?)';
    $params[]='%'.$search.'%';
    $params[]='%'.$search.'%';
}
$wh=$where?'WHERE '.implode(' AND ', $where):'';
$page=max(1, (int)($_GET['page']??1));
$per=15;
$cq=$pdo->prepare("SELECT COUNT(*) FROM appointments a JOIN customer_profiles cp ON cp.id=a.customer_id JOIN lawyer_profiles lp ON lp.id=a.lawyer_id $wh");
$cq->execute($params);
$total=(int)$cq->fetchColumn();
$pages=max(1, (int)ceil($total/$per));
if($page>$pages)$page=$pages;
$offset=($page-1)*$per;
$q=$pdo->prepare("SELECT a.id appoint_id,cp.full_name customer_name,lp.full_name lawyer_name,a.appointment_date available_date,a.start_time,a.end_time,a.fee,a.status,a.cancel_reason,a.cancelled_by,COALESCE(p.status,'Pending') payment_status FROM appointments a JOIN customer_profiles cp ON cp.id=a.customer_id JOIN lawyer_profiles lp ON lp.id=a.lawyer_id LEFT JOIN payments p ON p.appointment_id=a.id $wh ORDER BY a.appointment_date DESC,a.start_time DESC LIMIT $per OFFSET $offset");
$q->execute($params);
$rows=$q->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Booking Appointments - LegalEase</title>
<link rel="stylesheet" href="../assets/css/admin_base.css">
<link rel="stylesheet" href="../assets/css/admin.css">
<style>
.booking-actions{display:flex;gap:8px;flex-wrap:wrap}.admin-modal-backdrop{position:fixed;inset:0;background:rgba(10,20,40,.52);display:none;align-items:center;justify-content:center;padding:20px;z-index:9999}.admin-modal-backdrop.open{display:flex}.admin-modal{width:min(560px,100%);background:#fff;border-radius:18px;box-shadow:0 25px 70px rgba(0,0,0,.25);padding:24px}.admin-modal h2{margin:0 0 6px}.admin-modal .summary{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:18px 0;padding:14px;background:#f7f9fc;border-radius:12px}.admin-modal .summary div{display:flex;flex-direction:column;gap:3px}.admin-modal label{display:block;font-weight:700;margin:12px 0 6px}.admin-modal input,.admin-modal textarea{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #d6dce7;border-radius:9px}.admin-modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}.admin-modal .warning{padding:12px 14px;border-radius:10px;background:#fff5f5;color:#9f2d2d;border:1px solid #f1c7c7}.btn-small{cursor:pointer}
</style>
</head>
<body>
<div class="lawyer-layout"><?php
require __DIR__.'/includes/sidebar.php';
?><main class="main-content">
<h1>Booking Appointments</h1>
<p>Manage every appointment across all lawyers and customers.</p>
<br><?php
render_flash();
?>
<form class="filter-bar" method="get">
<div>
<label>Status</label>
<select name="status">
<option value="">All</option><?php
foreach(['Pending', 'Confirmed', 'Completed', 'Cancelled'] as $s):?><option value="<?=$s?>" <?=strtolower($status)===strtolower($s)?'selected':''?>><?=$s?></option><?php
endforeach?></select>
</div>
<div>
<label>Search (customer or lawyer)</label>
<input name="search" value="<?=e($search)?>">
</div>
<button>FILTER</button>
</form>
<div class="table-container">
<table>
<thead>
<tr>
<th>Customer</th>
<th>Lawyer</th>
<th>Date</th>
<th>Time</th>
<th>Fee</th>
<th>Status</th>
<th>Actions</th>
</tr>
</thead>
<tbody><?php
if(!$rows):?><tr>
<td colspan="7">No appointments found.</td>
</tr><?php
endif?><?php
foreach($rows as $r):?><tr>
<td><?=e($r['customer_name'])?></td>
<td><?=e($r['lawyer_name'])?></td>
<td><?=e(date('d/m/Y', strtotime($r['available_date'])))?></td>
<td><?=e(substr($r['start_time'], 0, 5))?> - <?=e(substr($r['end_time'], 0, 5))?></td>
<td><?=number_format((float)$r['fee'], 0, ',', '.')?> VND</td>
<td>
<span class="badge badge-<?=strtolower($r['status'])?>"><?=e(strtoupper($r['status']))?></span>
<br>
<small class="muted">Payment: <?=e($r['payment_status'])?></small><?php
if($r['status']==='Cancelled'&&trim((string)($r['cancel_reason']??''))!==''):?><br>
<small class="muted" title="<?=e($r['cancel_reason'])?>">Reason<?=!empty($r['cancelled_by'])?' ('.e($r['cancelled_by']).')':''?>: <?=e(mb_strimwidth($r['cancel_reason'], 0, 60, '…'))?></small><?php
endif?></td>
<td><?php
if(in_array($r['status'], ['Pending', 'Confirmed'], true)):?><div class="booking-actions">
<button type="button" class="btn-small btn-neutral js-reschedule" data-id="<?=$r['appoint_id']?>" data-customer="<?=e($r['customer_name'])?>" data-lawyer="<?=e($r['lawyer_name'])?>" data-date="<?=e($r['available_date'])?>" data-start="<?=e(substr($r['start_time'], 0, 5))?>" data-end="<?=e(substr($r['end_time'], 0, 5))?>">Reschedule</button>
<button type="button" class="btn-small btn-reject js-admin-cancel" data-id="<?=$r['appoint_id']?>" data-customer="<?=e($r['customer_name'])?>" data-lawyer="<?=e($r['lawyer_name'])?>" data-date="<?=e($r['available_date'])?>" data-time="<?=e(substr($r['start_time'], 0, 5).' - '.substr($r['end_time'], 0, 5))?>" data-payment="<?=e($r['payment_status'])?>">Cancel</button>
</div><?php
else:?>—<?php
endif?></td>
</tr><?php
endforeach?></tbody>
</table>
</div><?=paginate($total, $page, $per, '/LegalEase_eProject/admin/bookings.php?status='.urlencode($status).'&search='.urlencode($search))?></main>
</div>
<div class="admin-modal-backdrop" id="rescheduleModal">
<div class="admin-modal">
<h2>Reschedule appointment</h2>
<p class="muted">Choose a new future one-hour consultation time.</p>
<div class="summary">
<div>
<span>Appointment</span>
<strong id="rsIdText">
</strong>
</div>
<div>
<span>Customer</span>
<strong id="rsCustomer">
</strong>
</div>
<div>
<span>Lawyer</span>
<strong id="rsLawyer">
</strong>
</div>
<div>
<span>Current schedule</span>
<strong id="rsCurrent">
</strong>
</div>
</div>
<form method="post" id="rescheduleForm"><?=csrf_field()?><input type="hidden" name="action" value="reschedule">
<input type="hidden" name="appointment_id" id="rsId">
<input type="hidden" name="status" value="<?=e($status)?>">
<input type="hidden" name="search" value="<?=e($search)?>">
<input type="hidden" name="page" value="<?=$page?>">
<label>New date</label>
<input type="date" name="new_date" id="rsDate" min="<?=date('Y-m-d')?>" required>
<label>Start time</label>
<input type="time" name="new_start" id="rsStart" step="3600" required>
<label>End time</label>
<input type="time" name="new_end" id="rsEnd" step="3600" required>
<div class="admin-modal-actions">
<button type="button" class="btn-small btn-neutral js-close-modal">Keep current schedule</button>
<button class="btn-small btn-approve">Save new schedule</button>
</div>
</form>
</div>
</div>
<div class="admin-modal-backdrop" id="cancelModal">
<div class="admin-modal">
<h2>Cancel appointment</h2>
<p class="muted">Review the appointment and record an administrative cancellation reason.</p>
<div class="warning" id="cancelWarning">If the customer has already paid, LegalEase will process a full refund.</div>
<div class="summary">
<div>
<span>Appointment</span>
<strong id="caIdText">
</strong>
</div>
<div>
<span>Customer</span>
<strong id="caCustomer">
</strong>
</div>
<div>
<span>Lawyer</span>
<strong id="caLawyer">
</strong>
</div>
<div>
<span>Schedule</span>
<strong id="caSchedule">
</strong>
</div>
</div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="cancel">
<input type="hidden" name="appointment_id" id="caId">
<input type="hidden" name="status" value="<?=e($status)?>">
<input type="hidden" name="search" value="<?=e($search)?>">
<input type="hidden" name="page" value="<?=$page?>">
<label>Reason for cancellation</label>
<textarea name="cancel_reason" rows="4" minlength="5" maxlength="255" required placeholder="Explain why LegalEase is cancelling this appointment">
</textarea>
<div class="admin-modal-actions">
<button type="button" class="btn-small btn-neutral js-close-modal">Keep appointment</button>
<button class="btn-small btn-reject">Confirm cancellation</button>
</div>
</form>
</div>
</div>
<script>
(()=>{const rs=document.getElementById('rescheduleModal'),ca=document.getElementById('cancelModal');function closeAll(){rs.classList.remove('open');ca.classList.remove('open')}document.querySelectorAll('.js-close-modal').forEach(b=>b.onclick=closeAll);[rs,ca].forEach(m=>m.addEventListener('click',e=>{if(e.target===m)closeAll()}));document.querySelectorAll('.js-reschedule').forEach(b=>b.onclick=()=>{document.getElementById('rsId').value=b.dataset.id;document.getElementById('rsIdText').textContent='#'+b.dataset.id;document.getElementById('rsCustomer').textContent=b.dataset.customer;document.getElementById('rsLawyer').textContent=b.dataset.lawyer;document.getElementById('rsCurrent').textContent=b.dataset.date+' · '+b.dataset.start+'–'+b.dataset.end;document.getElementById('rsDate').value=b.dataset.date;document.getElementById('rsStart').value=b.dataset.start;document.getElementById('rsEnd').value=b.dataset.end;rs.classList.add('open')});document.getElementById('rsStart').addEventListener('change',function(){if(!this.value)return;const [h,m]=this.value.split(':').map(Number);const total=h*60+m+60;document.getElementById('rsEnd').value=String(Math.floor(total/60)).padStart(2,'0')+':'+String(total%60).padStart(2,'0')});document.querySelectorAll('.js-admin-cancel').forEach(b=>b.onclick=()=>{document.getElementById('caId').value=b.dataset.id;document.getElementById('caIdText').textContent='#'+b.dataset.id;document.getElementById('caCustomer').textContent=b.dataset.customer;document.getElementById('caLawyer').textContent=b.dataset.lawyer;document.getElementById('caSchedule').textContent=b.dataset.date+' · '+b.dataset.time;document.getElementById('cancelWarning').textContent=b.dataset.payment==='Success'?'This appointment is paid. A full customer refund will be processed when you confirm cancellation.':'The reserved time slot will be released for another customer.';ca.classList.add('open')});document.addEventListener('keydown',e=>{if(e.key==='Escape')closeAll()});})();
</script>
</body>
</html>
