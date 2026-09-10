<?php
require_once __DIR__ . '/../includes/customer_layout.php';
require_role('customer');
$name = trim($_GET['name'] ?? '');
$spec = (int) ($_GET['spec'] ?? 0);
$sql = "SELECT lp.id,lp.full_name,lp.experience_years,lp.rating,lp.consultation_fee,lp.avatar_file,GROUP_CONCAT(DISTINCT sp.name ORDER BY sp.name SEPARATOR ', ') specialties FROM lawyer_profiles lp JOIN users u ON u.id=lp.user_id LEFT JOIN lawyer_specialties ls ON ls.lawyer_id=lp.id LEFT JOIN specializations sp ON sp.id=ls.specialization_id WHERE lp.is_verified=1 AND u.is_active=1";
$args = [];
if ($name !== '') {
    $sql .= ' AND lp.full_name LIKE ?';
    $args[] = '%' . $name . '%';
}if ($spec) {
    $sql .= ' AND EXISTS(SELECT 1 FROM lawyer_specialties x WHERE x.lawyer_id=lp.id AND x.specialization_id=?)';
    $args[] = $spec;
}$sql .= ' GROUP BY lp.id ORDER BY '.($name!==''?'lp.full_name ASC':'lp.rating DESC,lp.full_name ASC');
$q = $pdo->prepare($sql);
$q->execute($args);
$rows = $q->fetchAll();
$specs = $pdo->query('SELECT * FROM specializations ORDER BY name')->fetchAll();
customer_shell_start('Find Lawyer', 'Search verified lawyers by name or specialization.');
?>
<div class="card">
<form class="filters" method="get">
<input name="name" value="<?= e($name)?>" placeholder="Enter lawyer name...">
<select name="spec">
<option value="0">-- Select Specialization --</option><?php
foreach ($specs as $s):?><option value="<?= $s['id']?>" <?= $spec === $s['id'] ? 'selected' : ''?>><?= e($s['name'])?></option><?php
endforeach?></select>
<button class="btn btn-primary">Search</button>
</form>
</div>
<div class="table-wrap">
<table class="customer-table">
<thead>
<tr>
<th>Lawyer</th>
<th>Specializations</th>
<th>Experience</th>
<th>Rating</th>
<th>Consultation Fee</th>
<th>Action</th>
</tr>
</thead>
<tbody><?php
foreach ($rows as $r):?><tr>
<td>
<div class="customer-lawyer-cell">
<img src="<?=e(avatar_url($r['avatar_file']??''))?>" alt="">
<b><?= e($r['full_name'])?></b>
</div>
</td>
<td><?= e($r['specialties'] ?: 'General Legal Practice')?></td>
<td><?= (int) $r['experience_years']?> years</td>
<td>★ <?= number_format((float) $r['rating'], 1)?></td>
<td><?= money_usd($r['consultation_fee'])?></td>
<td>
<a class="btn btn-success btn-sm" href="/LegalEase_eProject/public/lawyer.php?id=<?= $r['id']?>">View / Book</a>
</td>
</tr><?php
endforeach?><?php
if (!$rows):?><tr>
<td colspan="6" class="empty">No verified lawyer found.</td>
</tr><?php
endif?></tbody>
</table>
</div><?php
customer_shell_end();
?>
