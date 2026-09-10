<?php
require_once __DIR__.'/../includes/lawyer_layout.php';
require_role('lawyer');
$lid=current_lawyer_id($pdo);
$error='';
function day_code(DateTime $d):int {
    return (int)$d->format('N');
}
if(is_post()) {
    verify_csrf();
    $action=$_POST['action']??'';
    if($action==='delete') {
        $id=(int)($_POST['id']??0);
        $q=$pdo->prepare('DELETE FROM availability_slots WHERE id=? AND lawyer_id=? AND is_available=1');
        $q->execute([$id, $lid]);
        flash($q->rowCount()?'success':'error', $q->rowCount()?'Available slot removed.':'Booked slots cannot be removed.');
        redirect('/LegalEase_eProject/lawyer/schedule.php');
    }
    if($action==='create') {
        $startDate=$_POST['start_date']??'';
        $endDate=$_POST['end_date']??$startDate;
        $start=$_POST['start_time']??'';
        $end=$_POST['end_time']??'';
        $repeat=$_POST['repeat_mode']??'daily';
        $weekdays=array_map('intval', $_POST['weekdays']??[]);
        try {
            if(!in_array($repeat, ['daily', 'custom'], true))throw new RuntimeException('Please select Every day or Custom weekdays.');
            $sd=DateTime::createFromFormat('Y-m-d', $startDate);
            $ed=DateTime::createFromFormat('Y-m-d', $endDate);
            if(!$sd||!$ed)throw new RuntimeException('Please select a valid schedule date.');
            $today=new DateTime('today');
            if($sd<$today)throw new RuntimeException('Schedule start date cannot be in the past.');
            if($ed<$sd)throw new RuntimeException('End date must be on or after the start date.');
            if($sd->diff($ed)->days>180)throw new RuntimeException('A recurring schedule can cover a maximum of 180 days at a time.');
            $st=DateTime::createFromFormat('H:i', $start);
            $et=DateTime::createFromFormat('H:i', $end);
            if(!$st||!$et)throw new RuntimeException('Please select valid start and end times.');
            $sm=((int)$st->format('H'))*60+(int)$st->format('i');
            $em=((int)$et->format('H'))*60+(int)$et->format('i');
            if($sm<420||$em>1380||$em<=$sm)throw new RuntimeException('Working hours must stay between 07:00 and 23:00, and end time must be later than start time.');
            if($sm%60!==0||$em%60!==0)throw new RuntimeException('Start and end times must use full-hour boundaries.');
            $todayYmd=date('Y-m-d');
            if($startDate===$todayYmd) {
                $nowMinutes=((int)date('H'))*60+(int)date('i');
                if($sm<$nowMinutes)throw new RuntimeException('Start time cannot be earlier than the current time for today. Please choose a future hour.');
            }
            if($repeat==='custom'&&!$weekdays)throw new RuntimeException('Choose at least one weekday for a custom recurring schedule.');
            $dates=[];
            for($d=clone $sd;$d<=$ed;$d->modify('+1 day')) {
                $n=day_code($d);
                if($repeat==='daily'||in_array($n, $weekdays, true))$dates[]=$d->format('Y-m-d');
            }
            if(!$dates)throw new RuntimeException('No dates match your repeat settings.');
            $pdo->beginTransaction();
            $created=0;
            $skipped=0;
            foreach($dates as $date) {
                for($m=$sm;$m<$em;$m+=60) {
                    $slotStart=sprintf('%02d:%02d', intdiv($m, 60), $m%60);
                    $slotEnd=sprintf('%02d:%02d', intdiv($m+60, 60), ($m+60)%60);
                    $c=$pdo->prepare('SELECT COUNT(*) FROM availability_slots WHERE lawyer_id=? AND available_date=? AND start_time < ? AND end_time > ?');
                    $c->execute([$lid, $date, $slotEnd, $slotStart]);
                    if($c->fetchColumn()) {
                        $skipped++;
                        continue;
                    }$pdo->prepare('INSERT INTO availability_slots(lawyer_id,available_date,start_time,end_time,is_available) VALUES(?,?,?,?,1)')->execute([$lid, $date, $slotStart, $slotEnd]);
                    $created++;
                }
            }
            $pdo->commit();
            notify($pdo, (int)user()['id'], $created.' new consultation slots were added to your work schedule.', 'System', '/LegalEase_eProject/lawyer/schedule.php');
            flash('success', $created.' slot(s) created successfully'.($skipped?' · '.$skipped.' overlapping slot(s) skipped.':'.'));
            redirect('/LegalEase_eProject/lawyer/schedule.php');
        }catch(Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            $error=$e->getMessage();
        }
    }
}
$view=$_GET['view']??'all';
if(!in_array($view, ['all', 'available', 'booked'], true))$view='all';
$filterDate=trim($_GET['date']??'');
if($filterDate!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate))$filterDate='';
$page=max(1, (int)($_GET['page']??1));
$per=10;
$where='lawyer_id=? AND TIMESTAMP(available_date,start_time)>NOW()';
$params=[$lid];
if($view==='available')$where.=' AND is_available=1';
elseif($view==='booked')$where.=' AND is_available=0';
if($filterDate!=='') {
    $where.=' AND available_date=?';
    $params[]=$filterDate;
}$c=$pdo->prepare("SELECT COUNT(*) FROM availability_slots WHERE $where");
$c->execute($params);
$total=(int)$c->fetchColumn();
$pages=max(1, (int)ceil($total/$per));
if($page>$pages)$page=$pages;
$offset=($page-1)*$per;
$q=$pdo->prepare("SELECT * FROM availability_slots WHERE $where ORDER BY available_date,start_time LIMIT $per OFFSET $offset");
$q->execute($params);
$rows=$q->fetchAll();
if($view==='booked') {
    foreach($rows as &$row) {
        $aq=$pdo->prepare('SELECT id FROM appointments WHERE slot_id=? AND lawyer_id=? ORDER BY id DESC LIMIT 1');
        $aq->execute([(int)$row['id'], $lid]);
        $row['appointment_id']=(int)($aq->fetchColumn()?:0);
    }unset($row);
}
function sched_url(array $changes=[]):string {
    $q=array_merge($_GET, $changes);
    return '?'.http_build_query($q);
}
lawyer_shell_start('📅 Schedule Management', 'Create one-hour availability between 07:00 and 23:00. Existing or booked slots are never overwritten.');
if($error)echo '<div class="alert alert-error">'.e($error).'</div>';
?>
<div class="card">
<div class="card-header">
<div>
<h2>＋ Create Availability</h2>
<p class="muted">Create recurring one-hour consultation slots across a date range.</p>
</div>
</div>
<form method="post" class="schedule-builder"><?=csrf_field()?><input type="hidden" name="action" value="create">
<div class="form-grid">
<div class="form-group">
<label>Start date</label>
<input type="date" name="start_date" id="scheduleStartDate" min="<?=date('Y-m-d')?>" required>
</div>
<div class="form-group">
<label>End date</label>
<input type="date" name="end_date" id="scheduleEndDate" min="<?=date('Y-m-d')?>" required>
</div>
<div class="form-group">
<label>Start time</label>
<input type="time" name="start_time" id="scheduleStartTime" min="07:00" max="22:00" step="3600" value="07:00" required>
<small class="muted">Working hours: 7:00 AM–11:00 PM</small>
</div>
<div class="form-group">
<label>End time</label>
<input type="time" name="end_time" id="scheduleEndTime" min="08:00" max="23:00" step="3600" value="17:00" required>
<small class="muted">Working hours: 7:00 AM–11:00 PM</small>
</div>
<div class="form-group full">
<label>Repeat schedule</label>
<div class="repeat-options">
<label>
<input type="radio" name="repeat_mode" value="daily" checked> Every day</label>
<label>
<input type="radio" name="repeat_mode" value="custom"> Custom weekdays</label>
</div>
</div>
<div class="form-group full" id="weekdayPicker" style="display:none">
<label>Choose weekdays</label>
<div class="weekday-grid"><?php
foreach([1=>'Monday', 2=>'Tuesday', 3=>'Wednesday', 4=>'Thursday', 5=>'Friday', 6=>'Saturday', 7=>'Sunday'] as $n=>$day):?><label>
<input type="checkbox" name="weekdays[]" value="<?=$n?>"> <?=e($day)?></label><?php
endforeach?></div>
</div>
</div>
<div class="schedule-note">Recurring schedules can cover up to 180 days. Each generated slot is 1 hour. Available work window: 07:00–23:00.</div>
<button class="btn btn-success" type="submit" data-confirm="Create all matching one-hour consultation slots using this schedule?">Create Schedule</button>
</form>
</div>
<div class="card">
<div class="card-header schedule-upcoming-head">
<div>
<h2>Upcoming work schedule</h2>
<p class="muted">Showing future slots only, up to 10 per page. Filter by booking status or choose one specific date.</p>
</div>
<div class="schedule-tools">
<div class="schedule-filter-tabs">
<a class="<?= $view==='all'?'active':''?>" href="<?=e(sched_url(['view'=>'all', 'page'=>1]))?>">All</a>
<a class="<?= $view==='available'?'active':''?>" href="<?=e(sched_url(['view'=>'available', 'page'=>1]))?>">Available</a>
<a class="<?= $view==='booked'?'active':''?>" href="<?=e(sched_url(['view'=>'booked', 'page'=>1]))?>">Booked</a>
</div>
<form method="get" class="schedule-date-filter">
<input type="hidden" name="view" value="<?=e($view)?>">
<label>Specific date<input type="date" name="date" min="<?=date('Y-m-d')?>" value="<?=e($filterDate)?>">
</label>
<button class="btn btn-light btn-sm">View date</button><?php
if($filterDate!==''):?><a class="btn btn-light btn-sm" href="<?=e(sched_url(['date'=>null, 'page'=>1]))?>">Clear</a><?php
endif?></form>
</div>
</div>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Date</th>
<th>Consultation Time</th>
<th>Duration</th>
<th>Status</th>
<th>Actions</th><?php
if($view==='booked'):?><th>Details</th><?php
endif?></tr>
</thead>
<tbody><?php
if(!$rows):?><tr>
<td colspan="<?=$view==='booked'?6:5?>" class="empty">No upcoming work shifts in this filter.</td>
</tr><?php
else:foreach($rows as $r):?><tr>
<td><?=e(date('d/m/Y', strtotime($r['available_date'])))?></td>
<td>
<strong><?=e(substr($r['start_time'], 0, 5))?> - <?=e(substr($r['end_time'], 0, 5))?></strong>
</td>
<td>1 hour</td>
<td>
<span class="badge <?=$r['is_available']?'badge-available':'badge-booked'?>"><?=$r['is_available']?'Available':'Booked'?></span>
</td>
<td><?php
if($r['is_available']):?><form method="post" data-confirm="Remove this consultation slot?"><?=csrf_field()?><input type="hidden" name="action" value="delete">
<input type="hidden" name="id" value="<?=(int)$r['id']?>">
<button class="btn btn-danger btn-sm">Remove</button>
</form><?php
else:?><span class="muted">Locked</span><?php
endif?></td><?php
if($view==='booked'):?><td><?php
if(!empty($r['appointment_id'])):?><a class="btn btn-light btn-sm" href="/LegalEase_eProject/lawyer/appointment.php?id=<?=(int)$r['appointment_id']?>">Details</a><?php
else:?><span class="muted">Unavailable</span><?php
endif?></td><?php
endif?></tr><?php
endforeach;
endif?></tbody>
</table>
</div><?=paginate($total, $page, $per, '/LegalEase_eProject/lawyer/schedule.php?view='.urlencode($view).'&date='.urlencode($filterDate))?></div>
<script>
const start=document.getElementById('scheduleStartDate'),end=document.getElementById('scheduleEndDate'),startTime=document.getElementById('scheduleStartTime'),endTime=document.getElementById('scheduleEndTime'),picker=document.getElementById('weekdayPicker');
const today='<?=date('Y-m-d')?>';
function ceilCurrentHour(){const now=new Date();let h=now.getHours(),m=now.getMinutes();if(m>0)h++;return String(Math.min(h,23)).padStart(2,'0')+':00';}
function syncTimeBounds(){let min='07:00';if(start.value===today)min=ceilCurrentHour();startTime.min=min;if(start.value===today&&startTime.value<min)startTime.value=min;const h=parseInt(startTime.value.slice(0,2)||'7',10);const endMin=String(Math.min(h+1,23)).padStart(2,'0')+':00';endTime.min=endMin;if(endTime.value<=startTime.value)endTime.value=endMin;}
function sync(){if(start.value){end.min=start.value;if(!end.value||end.value<start.value)end.value=start.value}syncTimeBounds();}
start.addEventListener('change',sync);startTime.addEventListener('change',syncTimeBounds);document.querySelectorAll('input[name="repeat_mode"]').forEach(r=>r.addEventListener('change',()=>{picker.style.display=document.querySelector('input[name="repeat_mode"]:checked').value==='custom'?'block':'none'}));sync();
</script>
<?php
lawyer_shell_end();
?>
