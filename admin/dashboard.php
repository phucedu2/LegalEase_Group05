<?php
require_once __DIR__ . '/includes/auth.php';
$active_page = 'dashboard';
$stats = [
'total_customers' => (int)$pdo->query("SELECT COUNT(*) FROM customer_profiles")->fetchColumn(),
'total_lawyers' => (int)$pdo->query("SELECT COUNT(*) FROM lawyer_profiles")->fetchColumn(),
'pending_verifications' => (int)$pdo->query("SELECT COUNT(*) FROM lawyer_verifications WHERE status='Pending'")->fetchColumn(),
'appointments_this_month' => (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE YEAR(appointment_date)=YEAR(CURDATE()) AND MONTH(appointment_date)=MONTH(CURDATE())")->fetchColumn(),
'revenue_this_month' => (float)$pdo->query("SELECT COALESCE(SUM(fee),0) FROM appointments WHERE YEAR(appointment_date)=YEAR(CURDATE()) AND MONTH(appointment_date)=MONTH(CURDATE()) AND status <> 'Cancelled'")->fetchColumn(),];
$recent = $pdo->query("SELECT a.id, cp.full_name customer_name, lp.full_name lawyer_name, a.appointment_date available_date, a.start_time, a.status FROM appointments a JOIN customer_profiles cp ON cp.id=a.customer_id JOIN lawyer_profiles lp ON lp.id=a.lawyer_id ORDER BY a.appointment_date DESC,a.start_time DESC LIMIT 6")->fetchAll();
$pending = $pdo->query("SELECT v.id verify_id,lp.full_name lawyer_name,v.license_number,v.created_at submitted_at FROM lawyer_verifications v JOIN lawyer_profiles lp ON lp.id=v.lawyer_id WHERE v.status='Pending' ORDER BY v.created_at DESC LIMIT 5")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Dashboard - LegalEase</title>
<link rel="stylesheet" href="../assets/css/admin_base.css">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
<div class="lawyer-layout"><?php
require __DIR__.'/includes/sidebar.php';
?><main class="main-content">
<div class="page-header">
<h1>Welcome, <?= e(user()['name'] ?? 'System Admin')?></h1>
</div>
<div class="dashboard-cards">
<?php
foreach([
'Total Customers'=>$stats['total_customers'], 'Total Lawyers'=>$stats['total_lawyers'], 'Pending Verifications'=>$stats['pending_verifications'], 'Appointments This Month'=>$stats['appointments_this_month']] as $label=>$value):?><div class="stat-card">
<h3><?=e($label)?></h3>
<div class="number"><?=number_format((float)$value)?></div>
</div><?php
endforeach;
?>
<div class="stat-card">
<h3>Revenue This Month</h3>
<div class="number"><?=number_format($stats['revenue_this_month'])?> VND</div>
</div>
</div>
<br>
<div class="table-container">
<h2>Recent Appointments</h2>
<br>
<table>
<thead>
<tr>
<th>Customer</th>
<th>Lawyer</th>
<th>Date</th>
<th>Time</th>
<th>Status</th>
</tr>
</thead>
<tbody>
<?php
foreach($recent as $r):?><tr>
<td><?=e($r['customer_name'])?></td>
<td><?=e($r['lawyer_name'])?></td>
<td><?=e($r['available_date'])?></td>
<td><?=e(substr($r['start_time'], 0, 5))?></td>
<td>
<span class="badge badge-<?=strtolower($r['status'])?>"><?=e(strtoupper($r['status']))?></span>
</td>
</tr><?php
endforeach;
?>
</tbody>
</table>
</div>
<br>
<div class="table-container">
<h2>Pending Lawyer Verifications</h2>
<br>
<table>
<thead>
<tr>
<th>Lawyer</th>
<th>License Number</th>
<th>Submitted On</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php
if(!$pending):?><tr>
<td colspan="4">No pending verifications.</td>
</tr><?php
endif;
?>
<?php
foreach($pending as $r):?><tr>
<td><?=e($r['lawyer_name'])?></td>
<td><?=e($r['license_number'])?></td>
<td><?=e(substr($r['submitted_at'], 0, 10))?></td>
<td>
<a class="btn-small btn-neutral" href="lawyers.php?filter=pending">Review</a>
</td>
</tr><?php
endforeach;
?>
</tbody>
</table>
</div>
</main>
</div>
</body>
</html>
