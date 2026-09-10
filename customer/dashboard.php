<?php
require_once __DIR__ . '/../includes/customer_layout.php';
require_role('customer');
$cid = current_customer_id($pdo);
$counts = ['upcoming' => 0, 'pending_payment' => 0, 'completed' => 0];
$s = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE customer_id=? AND status IN('Pending','Confirmed') AND appointment_date>=CURDATE()");
$s->execute([$cid]);
$counts['upcoming'] = (int) $s->fetchColumn();
$s = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE customer_id=? AND status='Pending'");
$s->execute([$cid]);
$counts['pending_payment'] = (int) $s->fetchColumn();
$s = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE customer_id=? AND status='Completed'");
$s->execute([$cid]);
$counts['completed'] = (int) $s->fetchColumn();
$up = $pdo->prepare("SELECT a.id,a.appointment_date,a.start_time,a.status,lp.full_name FROM appointments a JOIN lawyer_profiles lp ON lp.id=a.lawyer_id WHERE a.customer_id=? AND a.status IN('Pending','Confirmed') AND a.appointment_date>=CURDATE() ORDER BY a.appointment_date,a.start_time LIMIT 1");
$up->execute([$cid]);
$next = $up->fetch();
customer_shell_start('Customer Dashboard', 'Manage consultations, payments, reviews and notifications.');
?>
<div class="hero-card">
<h2>Welcome back, <?= e(user()['name'])?></h2>
<p>Your LegalEase customer portal keeps all consultation activity in one place.</p>
<a class="btn btn-light" href="/LegalEase_eProject/public/lawyers.php">Find a Lawyer</a>
</div>
<div class="stats-grid">
<div class="stat-card">
<div class="label">Upcoming Appointments</div>
<div class="value"><?= $counts['upcoming']?></div>
<small>Pending or confirmed</small>
</div>
<div class="stat-card warning">
<div class="label">Pending Payments</div>
<div class="value"><?= $counts['pending_payment']?></div>
<small>Awaiting payment</small>
</div>
<div class="stat-card success">
<div class="label">Completed Consultations</div>
<div class="value"><?= $counts['completed']?></div>
<small>Consultation history</small>
</div>
</div>
<div class="card upcoming-customer-card">
<div class="card-header">
<div>
<span class="upcoming-label">Next consultation</span>
<h2>Upcoming Appointment</h2>
</div>
</div><?php
if ($next):?><div class="customer-next-appointment">
<div>
<span>Lawyer</span>
<strong><?= e($next['full_name'])?></strong>
</div>
<div>
<span>Date</span>
<strong><?= e(date('d M Y', strtotime($next['appointment_date'])))?></strong>
</div>
<div>
<span>Time</span>
<strong><?= e(substr($next['start_time'], 0, 5))?></strong>
</div>
</div>
<p>
<span class="badge <?= customer_badge($next['status'])?>"><?= e($next['status'])?></span>
</p>
<a class="btn btn-light btn-sm" href="appointments.php">View details</a><?php
else:?><div class="empty">No upcoming appointment.</div><?php
endif?></div>
<?php
customer_shell_end();
?>
