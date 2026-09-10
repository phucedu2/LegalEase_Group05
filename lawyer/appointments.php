<?php
require_once __DIR__.'/../includes/lawyer_layout.php';
require_role('lawyer');
$lid=current_lawyer_id($pdo);
$allowed=['all', 'Pending', 'Confirmed', 'Completed', 'Cancelled'];
$filter=$_GET['status']??'all';
if(!in_array($filter, $allowed, true))$filter='all';
$dateSort=strtolower($_GET['date_sort']??'desc')==='asc'?'asc':'desc';
$statusSort=strtolower($_GET['status_sort']??'')==='desc'?'desc':(strtolower($_GET['status_sort']??'')==='asc'?'asc':'');
function appt_url(array $changes=[]):string {
    $q=array_merge($_GET, $changes);
    return '?'.http_build_query($q);
}
$up=$pdo->prepare("SELECT a.id,a.status,a.service_name,cp.full_name customer_name,u.email customer_email,vs.available_date,vs.start_time,vs.end_time FROM appointments a JOIN customer_profiles cp ON cp.id=a.customer_id JOIN users u ON u.id=cp.user_id JOIN availability_slots vs ON vs.id=a.slot_id WHERE a.lawyer_id=? AND a.status IN('Pending','Confirmed') AND TIMESTAMP(vs.available_date,vs.start_time)>=NOW() ORDER BY vs.available_date,vs.start_time LIMIT 10");
$up->execute([$lid]);
$upcoming=$up->fetchAll();
$baseWhere='a.lawyer_id=?';
$params=[$lid];
if($filter!=='all') {
    $baseWhere.=' AND a.status=?';
    $params[]=$filter;
}$histPage=max(1, (int)($_GET['page']??1));
$per=10;
$cnt=$pdo->prepare("SELECT COUNT(*) FROM appointments a WHERE $baseWhere");
$cnt->execute($params);
$histTotal=(int)$cnt->fetchColumn();
$histPages=max(1, (int)ceil($histTotal/$per));
if($histPage>$histPages)$histPage=$histPages;
$offset=($histPage-1)*$per;
$order=[];
if($statusSort) {
    $case="CASE a.status WHEN 'Pending' THEN 1 WHEN 'Confirmed' THEN 2 WHEN 'Completed' THEN 3 WHEN 'Cancelled' THEN 4 ELSE 5 END";
    $order[]=$case.' '.strtoupper($statusSort);
}$order[]='vs.available_date '.strtoupper($dateSort);
$order[]='vs.start_time '.strtoupper($dateSort);
$sql="SELECT a.id,a.note,a.status,a.service_name,a.created_at,cp.full_name customer_name,u.email customer_email,vs.available_date,vs.start_time,vs.end_time FROM appointments a JOIN customer_profiles cp ON cp.id=a.customer_id JOIN users u ON u.id=cp.user_id JOIN availability_slots vs ON vs.id=a.slot_id WHERE $baseWhere ORDER BY ".implode(',', $order)." LIMIT $per OFFSET $offset";
$s=$pdo->prepare($sql);
$s->execute($params);
$history=$s->fetchAll();
lawyer_shell_start('▣ Appointment Management', 'Upcoming consultations are prioritized below, followed by your complete client history.');
?>
<div class="card upcoming-highlight">
<div class="card-header">
<div>
<span class="upcoming-kicker">Priority workspace</span>
<h2>Upcoming Appointments</h2>
</div>
<span class="upcoming-count"><?=count($upcoming)?> upcoming</span>
</div>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Customer</th>
<th>Service</th>
<th>Date</th>
<th>Time</th>
<th>Status</th>
<th>Action</th>
</tr>
</thead>
<tbody><?php
if(!$upcoming):?><tr>
<td colspan="6" class="empty">No upcoming appointments.</td>
</tr><?php
else:foreach($upcoming as $r):?><tr>
<td>
<strong><?=e($r['customer_name'])?></strong>
<div class="muted"><?=e($r['customer_email'])?></div>
</td>
<td><?=e($r['service_name'])?></td>
<td><?=e(date('d M Y', strtotime($r['available_date'])))?></td>
<td><?=e(substr($r['start_time'], 0, 5))?>–<?=e(substr($r['end_time'], 0, 5))?></td>
<td>
<span class="badge <?=lawyer_badge_class($r['status'])?>"><?=e($r['status'])?></span>
</td>
<td>
<a class="btn btn-success btn-sm" href="appointment.php?id=<?=(int)$r['id']?>">Open Appointment</a>
</td>
</tr><?php
endforeach;
endif?></tbody>
</table>
</div>
</div>
<div class="card" id="client-history">
<div class="card-header">
<div>
<h2>Client History</h2>
<p class="muted">Complete appointment record for your practice.</p>
</div>
</div>
<div class="tabs"><?php
foreach($allowed as $tab):?><a class="<?=$filter===$tab?'active':''?>" href="<?=e(appt_url(['status'=>$tab]))?>"><?=e(ucfirst($tab))?></a><?php
endforeach?></div>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>APT</th>
<th>Customer</th>
<th>Service</th>
<th>
<a class="sort-link" href="<?=e(appt_url(['date_sort'=>$dateSort==='asc'?'desc':'asc']))?>">Time & Date <span><?=$dateSort==='asc'?'↑':'↓'?></span>
</a>
</th>
<th>
<a class="sort-link" href="<?=e(appt_url(['status_sort'=>$statusSort==='asc'?'desc':'asc']))?>">Status <span><?=$statusSort==='asc'?'↑':($statusSort==='desc'?'↓':'↕')?></span>
</a>
</th>
<th>Action</th>
</tr>
</thead>
<tbody><?php
if(!$history):?><tr>
<td colspan="6" class="empty">No appointments in this category.</td>
</tr><?php
else:foreach($history as $r):?><tr>
<td>
<strong>APT-<?=str_pad((string)$r['id'], 2, '0', STR_PAD_LEFT)?></strong>
</td>
<td>
<strong><?=e($r['customer_name'])?></strong>
<div class="muted"><?=e($r['customer_email'])?></div>
</td>
<td><?=e($r['service_name']?:'Legal Consultation')?></td>
<td>
<strong><?=e(substr($r['start_time'], 0, 5))?> - <?=e(substr($r['end_time'], 0, 5))?></strong>
<div class="muted"><?=e(date('d/m/Y', strtotime($r['available_date'])))?></div>
</td>
<td>
<span class="badge <?=lawyer_badge_class($r['status'])?>"><?=e($r['status'])?></span>
</td>
<td>
<a class="btn btn-light btn-sm" href="appointment.php?id=<?=(int)$r['id']?>">● Details</a>
</td>
</tr><?php
endforeach;
endif?></tbody>
</table>
</div><?=paginate($histTotal, $histPage, $per, '/LegalEase_eProject/lawyer/appointments.php?status='.urlencode($filter).'&date_sort='.urlencode($dateSort).'&status_sort='.urlencode($statusSort))?></div>
<?php
lawyer_shell_end();
?>
