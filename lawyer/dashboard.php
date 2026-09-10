<?php
require_once __DIR__.'/../includes/lawyer_layout.php';
require_role('lawyer');
$lid=current_lawyer_id($pdo);
$filter=$_GET['revenue']??'1m';
if(!in_array($filter, ['1m', '3m', '1y', 'all'], true))$filter='1m';
$periodSql=match($filter) {
    '3m'=>"DATE_SUB(NOW(),INTERVAL 3 MONTH)", '1y'=>"DATE_SUB(NOW(),INTERVAL 1 YEAR)", 'all'=>null, default=>"DATE_SUB(NOW(),INTERVAL 1 MONTH)"
};
function dashboard_period_detail(string $period): string {
    $today=new DateTimeImmutable('today');
    if($period==='all') return 'All recorded dates';
    $start=match($period) {
        '3m'=>$today->sub(new DateInterval('P3M')), '1y'=>$today->sub(new DateInterval('P1Y')), default=>$today->sub(new DateInterval('P1M'))
    };
    return $start->format('d/m/Y').' – '.$today->format('d/m/Y');
}
$revenuePeriodDetail=dashboard_period_detail($filter);
function revenue_condition(?string $periodSql, string $alias='p'):string {
    return $periodSql?" AND COALESCE($alias.paid_at,$alias.created_at) >= $periodSql":'';
}
function lawyer_money(PDO $pdo, int $lid, ?string $periodSql=null):array {
    $cond=revenue_condition($periodSql, 'p');
    $s=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN a.status='Completed' AND p.status='Success' THEN p.amount*0.80 ELSE 0 END),0) completed,COALESCE(SUM(CASE WHEN a.status IN('Pending','Confirmed') AND p.status='Success' THEN p.amount*0.80 ELSE 0 END),0) pending_processing FROM payments p JOIN appointments a ON a.id=p.appointment_id WHERE p.lawyer_id=? $cond");
    $s->execute([$lid]);
    $r=$s->fetch();
    $refundCond=$periodSql?' AND r.created_at >= '.$periodSql:'';
    $q=$pdo->prepare("SELECT COALESCE(SUM(r.lawyer_compensation),0) FROM refunds r JOIN appointments a ON a.id=r.appointment_id WHERE a.lawyer_id=? AND r.status='Processed' $refundCond");
    $q->execute([$lid]);
    $cancelComp=(float)$q->fetchColumn();
    return['earned'=>(float)$r['completed']+$cancelComp, 'pending_processing'=>(float)$r['pending_processing'], 'cancellation_compensation'=>$cancelComp];
}
$all=lawyer_money($pdo, $lid, null);
$period=lawyer_money($pdo, $lid, $periodSql);
$counts=['Pending'=>0, 'Confirmed'=>0, 'Completed'=>0, 'Cancelled'=>0];
$s=$pdo->prepare('SELECT status,COUNT(*) c FROM appointments WHERE lawyer_id=? GROUP BY status');
$s->execute([$lid]);
foreach($s as $r)$counts[$r['status']]=(int)$r['c'];
$p=$pdo->prepare('SELECT rating,is_verified FROM lawyer_profiles WHERE id=?');
$p->execute([$lid]);
$profile=$p->fetch()?:['rating'=>0, 'is_verified'=>0];
$up=$pdo->prepare("SELECT a.id,a.status,a.fee,cp.full_name customer_name,vs.available_date,vs.start_time,vs.end_time,p.amount payment_amount,p.status payment_status FROM appointments a JOIN customer_profiles cp ON cp.id=a.customer_id JOIN availability_slots vs ON vs.id=a.slot_id LEFT JOIN payments p ON p.appointment_id=a.id WHERE a.lawyer_id=? AND a.status IN('Pending','Confirmed') AND TIMESTAMP(vs.available_date,vs.start_time)>=NOW() ORDER BY vs.available_date,vs.start_time LIMIT 6");
$up->execute([$lid]);
$upcoming=$up->fetchAll();
$incomeFilter=$_GET['income_history']??'1m';
if(!in_array($incomeFilter, ['1m', '3m', '1y', 'all'], true))$incomeFilter='1m';
$incomeSince=match($incomeFilter) {
    '3m'=>"DATE_SUB(CURDATE(),INTERVAL 3 MONTH)", '1y'=>"DATE_SUB(CURDATE(),INTERVAL 1 YEAR)", 'all'=>null, default=>"DATE_SUB(CURDATE(),INTERVAL 1 MONTH)"
};
$incomeCond=$incomeSince?" AND a.appointment_date >= $incomeSince":'';
$incomePeriodDetail=dashboard_period_detail($incomeFilter);
$incomePage=max(1, (int)($_GET['income_page']??1));
$incomePer=10;
$ic=$pdo->prepare("SELECT COUNT(*) FROM appointments a JOIN payments p ON p.appointment_id=a.id WHERE a.lawyer_id=? AND a.status='Completed' AND p.status='Success' $incomeCond");
$ic->execute([$lid]);
$incomeTotal=(int)$ic->fetchColumn();
$incomePages=max(1, (int)ceil($incomeTotal/$incomePer));
if($incomePage>$incomePages)$incomePage=$incomePages;
$incomeOffset=($incomePage-1)*$incomePer;
$eh=$pdo->prepare("SELECT a.id,cp.full_name customer_name,a.appointment_date,a.start_time,a.end_time,p.amount gross_amount,(p.amount*0.20) platform_fee,(p.amount*0.80) lawyer_income FROM appointments a JOIN payments p ON p.appointment_id=a.id JOIN customer_profiles cp ON cp.id=a.customer_id WHERE a.lawyer_id=? AND a.status='Completed' AND p.status='Success' $incomeCond ORDER BY a.appointment_date DESC,a.start_time DESC LIMIT $incomePer OFFSET $incomeOffset");
$eh->execute([$lid]);
$earningHistory=$eh->fetchAll();
$ee=$pdo->prepare("SELECT a.id,cp.full_name customer_name,a.appointment_date,a.start_time,a.end_time,a.status,p.status payment_status,COALESCE(p.amount,a.fee) gross_amount,(COALESCE(p.amount,a.fee)*0.80) expected_income FROM appointments a LEFT JOIN payments p ON p.appointment_id=a.id JOIN customer_profiles cp ON cp.id=a.customer_id WHERE a.lawyer_id=? AND a.status IN('Pending','Confirmed') AND TIMESTAMP(a.appointment_date,a.start_time)>=NOW() ORDER BY a.appointment_date,a.start_time LIMIT 12");
$ee->execute([$lid]);
$expectedEarnings=$ee->fetchAll();
function dash_dist_rows(PDO $pdo, string $sql, array $params):array {
    $s=$pdo->prepare($sql);
    $s->execute($params);
    return$s->fetchAll();
}function dash_analytics_block(string $title, array $rows, int $total):void {
    echo '<div class="analytics-card"><h3>'.e($title).'</h3>';
    if(!$rows) {
        echo '<p class="muted">No data available.</p></div>';
        return;
    }foreach($rows as $r) {
        $pct=round(((int)$r['c']/$total)*100, 1);
        echo '<div class="metric-row"><div><span>'.e($r['label']).'</span><b>'.$pct.'%</b></div><div class="metric-track"><i style="width:'.$pct.'%"></i></div><small>'.(int)$r['c'].' appointment(s)</small></div>';
    }echo '</div>';
}
$analyticsTotal=max(1, (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE lawyer_id=".(int)$lid)->fetchColumn());
$region=dash_dist_rows($pdo, "SELECT COALESCE(NULLIF(TRIM(SUBSTRING_INDEX(cp.address,',',-1)),''),'Not specified') label,COUNT(*) c FROM appointments a JOIN customer_profiles cp ON cp.id=a.customer_id WHERE a.lawyer_id=? GROUP BY label ORDER BY c DESC LIMIT 6", [$lid]);
$gender=dash_dist_rows($pdo, "SELECT COALESCE(cp.gender,'Not specified') label,COUNT(*) c FROM appointments a JOIN customer_profiles cp ON cp.id=a.customer_id WHERE a.lawyer_id=? GROUP BY label ORDER BY c DESC", [$lid]);
$age=dash_dist_rows($pdo, "SELECT CASE WHEN cp.birth_date IS NULL THEN 'Not specified' WHEN TIMESTAMPDIFF(YEAR,cp.birth_date,CURDATE())<25 THEN 'Under 25' WHEN TIMESTAMPDIFF(YEAR,cp.birth_date,CURDATE())<=34 THEN '25–34' WHEN TIMESTAMPDIFF(YEAR,cp.birth_date,CURDATE())<=44 THEN '35–44' WHEN TIMESTAMPDIFF(YEAR,cp.birth_date,CURDATE())<=54 THEN '45–54' ELSE '55+' END label,COUNT(*) c FROM appointments a JOIN customer_profiles cp ON cp.id=a.customer_id WHERE a.lawyer_id=? GROUP BY label ORDER BY c DESC", [$lid]);
$matter=dash_dist_rows($pdo, "SELECT CASE WHEN LOWER(COALESCE(a.note,'')) LIKE '%intellectual%' THEN 'Intellectual Property' WHEN LOWER(COALESCE(a.note,'')) LIKE '%labor%' OR LOWER(COALESCE(a.note,'')) LIKE '%employment%' THEN 'Labor & Employment' WHEN LOWER(COALESCE(a.note,'')) LIKE '%insurance%' THEN 'Insurance' WHEN LOWER(COALESCE(a.note,'')) LIKE '%family%' OR LOWER(COALESCE(a.note,'')) LIKE '%marriage%' THEN 'Family & Marriage' WHEN LOWER(COALESCE(a.note,'')) LIKE '%property%' OR LOWER(COALESCE(a.note,'')) LIKE '%real estate%' THEN 'Property & Real Estate' WHEN LOWER(COALESCE(a.note,'')) LIKE '%business%' OR LOWER(COALESCE(a.note,'')) LIKE '%contract%' THEN 'Corporate & Commercial' ELSE 'Civil / General' END label,COUNT(*) c FROM appointments a WHERE a.lawyer_id=? GROUP BY label ORDER BY c DESC", [$lid]);
lawyer_shell_start('Lawyer Dashboard', 'Manage your schedule, appointment requests, earnings and consultation performance.');
?>
<div class="card revenue-panel">
<div class="card-header">
<div>
<h2>Earnings overview</h2>
<p class="muted">You receive 80% of every successfully completed paid consultation. LegalEase retains a 20% platform fee. Completed consultation earnings are settled automatically to your payout account.</p>
</div>
<form method="get" class="revenue-filter period-filter-detail">
<select name="revenue" onchange="this.form.submit()">
<option value="1m" <?=$filter==='1m'?'selected':''?>>1 month</option>
<option value="3m" <?=$filter==='3m'?'selected':''?>>3 months</option>
<option value="1y" <?=$filter==='1y'?'selected':''?>>1 year</option>
<option value="all" <?=$filter==='all'?'selected':''?>>All time</option>
</select>
<small><?=e($revenuePeriodDetail)?></small>
<input type="hidden" name="income_history" value="<?=e($incomeFilter)?>">
</form>
</div>
<div class="stats-grid">
<div class="stat-card success">
<div class="label">Earned in period</div>
<div class="value"><?=money_usd($period['earned'])?></div>
<small>80% consultation share + eligible cancellation compensation</small>
</div>
<div class="stat-card warning">
<div class="label">Pending consultations</div>
<div class="value"><?=money_usd($all['pending_processing'])?></div>
<small>Expected 80% share after completion</small>
</div>
<div class="stat-card">
<div class="label">Lifetime earned income</div>
<div class="value"><?=money_usd($all['earned'])?></div>
<small>Automatic settlement applies</small>
</div>
</div>
</div>
<div class="hero-card">
<div class="eyebrow">Consultation performance</div>
<div class="hero-number"><?=$counts['Completed']?> completed consultations</div>
<div class="hero-meta">
<span>Pending requests: <?=$counts['Pending']?></span>
<span>Confirmed: <?=$counts['Confirmed']?></span>
<span>Average rating: <?=number_format((float)$profile['rating'], 1)?> / 5</span>
<span>Profile status: <?=$profile['is_verified']?'Verified':'Waiting for verification'?></span>
</div>
</div>
<div class="stats-grid">
<div class="stat-card warning">
<div class="label">Pending requests</div>
<div class="value"><?=$counts['Pending']?></div>
</div>
<div class="stat-card">
<div class="label">Confirmed</div>
<div class="value"><?=$counts['Confirmed']?></div>
</div>
<div class="stat-card success">
<div class="label">Completed</div>
<div class="value"><?=$counts['Completed']?></div>
</div>
<div class="stat-card danger">
<div class="label">Cancelled</div>
<div class="value"><?=$counts['Cancelled']?></div>
</div>
</div>
<div class="card">
<div class="card-header">
<div>
<h2>Upcoming appointments</h2>
<p class="muted">Financial figures show the 80% lawyer share and 20% LegalEase platform fee.</p>
</div>
<a class="btn btn-light btn-sm" href="appointments.php">View all</a>
</div>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Customer</th>
<th>Date</th>
<th>Time</th>
<th>Consultation fee</th>
<th>Platform fee (20%)</th>
<th>Your income (80%)</th>
<th>Status</th>
<th>Action</th>
</tr>
</thead>
<tbody><?php
if(!$upcoming):?><tr>
<td colspan="8" class="empty">No upcoming appointments.</td>
</tr><?php
else:foreach($upcoming as $r):$gross=(float)($r['payment_amount']??$r['fee']);
?><tr>
<td>
<strong><?=e($r['customer_name'])?></strong>
</td>
<td><?=e(date('d/m/Y', strtotime($r['available_date'])))?></td>
<td><?=e(substr($r['start_time'], 0, 5))?> - <?=e(substr($r['end_time'], 0, 5))?></td>
<td><?=money_usd($gross)?></td>
<td><?=money_usd($gross*.20)?></td>
<td>
<strong><?=money_usd($gross*.80)?></strong>
</td>
<td>
<span class="badge <?=lawyer_badge_class($r['status'])?>"><?=e($r['status'])?></span>
</td>
<td>
<a class="btn btn-light btn-sm" href="appointment.php?id=<?=(int)$r['id']?>">Details</a>
</td>
</tr><?php
endforeach;
endif?></tbody>
</table>
</div>
</div>
<div class="card earnings-calendar">
<div class="earnings-split">
<section>
<div class="income-history-head" id="income-history">
<h3>Income history</h3>
<form method="get" class="income-history-filter period-filter-detail">
<input type="hidden" name="revenue" value="<?=e($filter)?>">
<label for="incomeHistoryRange">Period</label>
<select id="incomeHistoryRange" name="income_history" onchange="this.form.submit()">
<option value="1m" <?=$incomeFilter==='1m'?'selected':''?>>1 month</option>
<option value="3m" <?=$incomeFilter==='3m'?'selected':''?>>3 months</option>
<option value="1y" <?=$incomeFilter==='1y'?'selected':''?>>1 year</option>
<option value="all" <?=$incomeFilter==='all'?'selected':''?>>All time</option>
</select>
<small><?=e($incomePeriodDetail)?></small>
</form>
</div>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Appointment</th>
<th>Customer</th>
<th>Date & time</th>
<th>Consultation fee</th>
<th>Platform fee (20%)</th>
<th>Your income (80%)</th>
</tr>
</thead>
<tbody><?php
if(!$earningHistory):?><tr>
<td colspan="6" class="empty">No completed paid earnings yet.</td>
</tr><?php
else:foreach($earningHistory as $er):?><tr>
<td>#<?=(int)$er['id']?></td>
<td><?=e($er['customer_name'])?></td>
<td><?=e(date('d M Y', strtotime($er['appointment_date'])))?> · <?=e(substr($er['start_time'], 0, 5))?>–<?=e(substr($er['end_time'], 0, 5))?></td>
<td><?=money_usd($er['gross_amount'])?></td>
<td><?=money_usd($er['platform_fee'])?></td>
<td>
<strong><?=money_usd($er['lawyer_income'])?></strong>
</td>
</tr><?php
endforeach;
endif?></tbody>
</table>
</div><?=paginate($incomeTotal, $incomePage, $incomePer, '/LegalEase_eProject/lawyer/dashboard.php?revenue='.urlencode($filter).'&income_history='.urlencode($incomeFilter))?></section>
<section>
<h3>Expected upcoming income</h3>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Appointment</th>
<th>Customer</th>
<th>Date & time</th>
<th>Booking</th>
<th>Payment</th>
<th>Expected income (80%)</th>
</tr>
</thead>
<tbody><?php
if(!$expectedEarnings):?><tr>
<td colspan="6" class="empty">No upcoming appointments.</td>
</tr><?php
else:foreach($expectedEarnings as $er):?><tr>
<td>#<?=(int)$er['id']?></td>
<td><?=e($er['customer_name'])?></td>
<td><?=e(date('d M Y', strtotime($er['appointment_date'])))?> · <?=e(substr($er['start_time'], 0, 5))?>–<?=e(substr($er['end_time'], 0, 5))?></td>
<td>
<span class="badge <?=lawyer_badge_class($er['status'])?>"><?=e($er['status'])?></span>
</td>
<td><?=e($er['payment_status']??'Pending')?></td>
<td>
<strong><?=money_usd($er['expected_income'])?></strong>
</td>
</tr><?php
endforeach;
endif?></tbody>
</table>
</div>
</section>
</div>
</div>
<div class="analytics-section dashboard-analytics">
<div class="card-header">
<div>
<h2>Client Analytics</h2>
<p class="muted">Aggregated insights from your appointment history.</p>
</div>
</div>
<div class="analytics-grid"><?php
dash_analytics_block('Region', $region, $analyticsTotal);
dash_analytics_block('Age group', $age, $analyticsTotal);
dash_analytics_block('Gender', $gender, $analyticsTotal);
dash_analytics_block('Legal matter', $matter, $analyticsTotal);
?></div>
</div>
<?php
lawyer_shell_end();
?>
