<?php
require_once __DIR__.'/includes/auth.php';
$active_page='reports';
/* ---- Year filter (a full calendar year, 12 months) ---- */
$yearsRows=$pdo->query("SELECT DISTINCT y FROM (
    SELECT YEAR(appointment_date) y FROM appointments
    UNION SELECT YEAR(created_at) FROM users
  ) t WHERE y IS NOT NULL ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
$curYear=(int)date('Y');
if(!$yearsRows)$yearsRows=[$curYear];
if(!in_array($curYear, array_map('intval', $yearsRows), true))array_unshift($yearsRows, $curYear);
$year=(int)($_GET['year']??$curYear);
if(!in_array($year, array_map('intval', $yearsRows), true))$year=(int)$yearsRows[0];
/* ---- Monthly buckets for the selected year ---- */
$months=[];
for($m=1;$m<=12;$m++) {
    $key=sprintf('%04d-%02d', $year, $m);
    $months[$key]=['label'=>date('M', mktime(0, 0, 0, $m, 1)), 'appointments'=>0, 'gross'=>0.0];
}
$q=$pdo->prepare("SELECT DATE_FORMAT(appointment_date,'%Y-%m') ym,COUNT(*) c,
   COALESCE(SUM(CASE WHEN status<>'Cancelled' THEN fee ELSE 0 END),0) gross
   FROM appointments WHERE YEAR(appointment_date)=? GROUP BY ym");
$q->execute([$year]);
foreach($q as $r) {
    if(isset($months[$r['ym']])) {
        $months[$r['ym']]['appointments']=(int)$r['c'];
        $months[$r['ym']]['gross']=(float)$r['gross'];
    }
}
$totalAppts =array_sum(array_column($months, 'appointments'));
$totalGross =array_sum(array_column($months, 'gross'));
$maxAppt=max(1, ...array_column($months, 'appointments'));
$maxMoney=max(1, ...array_column($months, 'gross'));
$activeLawyers=(int)$pdo->query("SELECT COUNT(*) FROM lawyer_profiles lp JOIN users u ON u.id=lp.user_id WHERE u.is_active=1")->fetchColumn();
$top=$pdo->query("SELECT lp.full_name,GROUP_CONCAT(DISTINCT sp.name SEPARATOR ', ') specialization,COUNT(a.id) appointments,lp.rating FROM lawyer_profiles lp LEFT JOIN lawyer_specialties ls ON ls.lawyer_id=lp.id LEFT JOIN specializations sp ON sp.id=ls.specialization_id LEFT JOIN appointments a ON a.lawyer_id=lp.id GROUP BY lp.id ORDER BY appointments DESC,lp.rating DESC LIMIT 5")->fetchAll();
$newCustomers=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='customer' AND YEAR(created_at)=".$year)->fetchColumn();
$totalCustomers=(int)$pdo->query("SELECT COUNT(*) FROM customer_profiles")->fetchColumn();
$repeat=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT customer_id FROM appointments GROUP BY customer_id HAVING COUNT(*)>=2) x")->fetchColumn();
$repeatPct=$totalCustomers?round($repeat*100/$totalCustomers):0;
$reviewsYear=(int)$pdo->query("SELECT COUNT(*) FROM reviews WHERE YEAR(created_at)=".$year)->fetchColumn();
$avgRating=(float)$pdo->query("SELECT COALESCE(AVG(rating),0) FROM reviews")->fetchColumn();
$money=fn($v)=>number_format((float)$v).' VND';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reporting &amp; Analytics - LegalEase</title>
<link rel="stylesheet" href="../assets/css/admin_base.css">
<link rel="stylesheet" href="../assets/css/admin.css">
<style>
.report-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:24px}
.report-grid .stat-card{border-left:3px solid #3154a6}
.report-grid .stat-card h3{font-size:13px;color:#5e6674;margin:0 0 8px}
.report-grid .stat-card .number{font-size:22px;font-weight:800}
.money-bars{display:flex;gap:8px;align-items:flex-end;height:150px;margin-top:10px}
.money-bars .col{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:4px}
.money-bars .stack{width:60%;display:flex;flex-direction:column;justify-content:flex-end;height:120px}
.money-bars .g{background:#3154a6}
.money-bars .lbl{font-size:11px;color:#5e6674}
.mini-note{color:#5e6674;font-size:13px}
</style>
</head>
<body>
<div class="lawyer-layout"><?php
require __DIR__.'/includes/sidebar.php';
?><main class="main-content">
<h1>Reporting &amp; Analytics</h1>
<p>Lawyer activity, customer engagement, appointment statistics and revenue.</p>
<br>
<form class="filter-bar" method="get">
<div>
<label>Report year</label>
<select name="year" onchange="this.form.submit()">
   <?php
foreach($yearsRows as $y):?><option value="<?=(int)$y?>" <?=$year===(int)$y?'selected':''?>><?=(int)$y?></option><?php
endforeach?>
  </select>
</div>
<button>APPLY</button>
</form>
<br>
<div class="report-grid">
<div class="stat-card">
<h3>Appointments (<?=$year?>)</h3>
<div class="number"><?=$totalAppts?></div>
</div>
<div class="stat-card">
<h3>Gross revenue (<?=$year?>)</h3>
<div class="number"><?=$money($totalGross)?></div>
</div>
<div class="stat-card">
<h3>Active lawyers</h3>
<div class="number"><?=$activeLawyers?></div>
</div>
</div>
<div class="table-container">
<h2>Appointments per Month &mdash; <?=$year?></h2>
<br>
<div class="bar-chart">
  <?php
foreach($months as $m):?>
   <div class="bar-col">
<div class="bar-value"><?=$m['appointments']?></div>
<div class="bar" style="height:<?=($m['appointments']/$maxAppt)*100?>%">
</div>
<div class="bar-label"><?=e($m['label'])?></div>
</div>
  <?php
endforeach?>
 </div>
</div>
<br>
<div class="table-container">
<h2>Revenue per Month &mdash; <?=$year?></h2>
<p class="mini-note">Blue = gross consultation revenue for each month.</p>
<div class="money-bars">
  <?php
foreach($months as $m):?>
   <div class="col">
<div class="stack">
<div class="g" style="height:<?=($m['gross']/$maxMoney)*100?>%" title="Gross: <?=$money($m['gross'])?>">
</div>
</div>
<div class="lbl"><?=e($m['label'])?></div>
</div>
  <?php
endforeach?>
 </div>
<br>
<table>
<thead>
<tr>
<th>Month</th>
<th>Gross revenue</th>
</tr>
</thead>
<tbody>
   <?php
foreach($months as $m):?>
    <tr>
<td><?=e($m['label'])?></td>
<td><?=$money($m['gross'])?></td>
</tr>
   <?php
endforeach?>
  </tbody>
<tfoot>
<tr>
<th>Total <?=$year?></th>
<th><?=$money($totalGross)?></th>
</tr>
</tfoot>
</table>
</div>
<br>
<div class="table-container">
<h2>Top Performing Lawyers</h2>
<br>
<table>
<thead>
<tr>
<th>Lawyer</th>
<th>Specialization</th>
<th>Appointments</th>
<th>Rating</th>
</tr>
</thead>
<tbody>
 <?php
foreach($top as $r):?><tr>
<td><?=e($r['full_name'])?></td>
<td><?=e($r['specialization']?:'—')?></td>
<td><?=(int)$r['appointments']?></td>
<td><?=number_format((float)$r['rating'], 1)?> / 5</td>
</tr><?php
endforeach?>
 </tbody>
</table>
</div>
<br>
<div class="table-container">
<h2>Customer Engagement &mdash; <?=$year?></h2>
<br>
<table>
<thead>
<tr>
<th>Metric</th>
<th>Value</th>
</tr>
</thead>
<tbody>
<tr>
<td>New customers in <?=$year?></td>
<td><?=$newCustomers?></td>
</tr>
<tr>
<td>Repeat bookings (2+ appointments)</td>
<td><?=$repeatPct?>%</td>
</tr>
<tr>
<td>Reviews submitted in <?=$year?></td>
<td><?=$reviewsYear?></td>
</tr>
<tr>
<td>Average rating across platform</td>
<td><?=number_format($avgRating, 1)?> / 5</td>
</tr>
</tbody>
</table>
</div>
</main>
</div>
</body>
</html>
