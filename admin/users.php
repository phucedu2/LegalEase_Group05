<?php
require_once __DIR__.'/includes/auth.php';
$active_page='users';
if(is_post()) {
    verify_csrf();
    $action=$_POST['action']??'';
    $uid=(int)($_POST['user_id']??0);
    $cid=(int)($_POST['customer_id']??0);
    if($action==='toggle'&&$uid) {
        $pdo->prepare("UPDATE users SET is_active=1-is_active WHERE id=?")->execute([$uid]);
        flash('success', 'User status updated.');
    }elseif($action==='edit'&&$uid&&$cid) {
        $name=trim($_POST['full_name']??'');
        $phone=trim($_POST['phone_number']??'');
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET name=? WHERE id=?")->execute([$name, $uid]);
        $pdo->prepare("UPDATE customer_profiles SET full_name=?,phone=? WHERE id=?")->execute([$name, $phone, $cid]);
        $pdo->commit();
        flash('success', 'Customer profile updated.');
    }redirect('/LegalEase_eProject/admin/users.php');
}
$search=trim($_GET['search']??'');
$fYear=trim($_GET['year']??'');
$fMonth=trim($_GET['month']??'');
$where=[];
$params=[];
if($search!=='') {
    $where[]='(cp.full_name LIKE ? OR u.email LIKE ?)';
    $params[]='%'.$search.'%';
    $params[]='%'.$search.'%';
}
if($fYear!==''&&ctype_digit($fYear)) {
    $where[]='YEAR(u.created_at)=?';
    $params[]=(int)$fYear;
}
if($fMonth!==''&&ctype_digit($fMonth)) {
    $where[]='MONTH(u.created_at)=?';
    $params[]=(int)$fMonth;
}
$wh=$where?'WHERE '.implode(' AND ', $where):'';
$order=sort_order_by([
'name' =>'cp.full_name',
'email' =>'u.email',
'phone' =>'cp.phone',
'status'=>'u.is_active',
'joined'=>'u.created_at',], 'cp.full_name ASC');
$years=$pdo->query("SELECT DISTINCT YEAR(created_at) y FROM users WHERE role='customer' ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
$page=max(1, (int)($_GET['page']??1));
$per=15;
$cq=$pdo->prepare("SELECT COUNT(*) FROM customer_profiles cp JOIN users u ON u.id=cp.user_id $wh");
$cq->execute($params);
$total=(int)$cq->fetchColumn();
$pages=max(1, (int)ceil($total/$per));
if($page>$pages)$page=$pages;
$offset=($page-1)*$per;
$q=$pdo->prepare("SELECT cp.id customer_id,cp.full_name,cp.phone,u.id user_id,u.email,u.is_active,u.created_at FROM customer_profiles cp JOIN users u ON u.id=cp.user_id $wh $order LIMIT $per OFFSET $offset");
$q->execute($params);
$rows=$q->fetchAll();
$monthNames=[1=>'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>User Management - LegalEase</title>
<link rel="stylesheet" href="../assets/css/admin_base.css">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
<div class="lawyer-layout"><?php
require __DIR__.'/includes/sidebar.php';
?><main class="main-content">
<h1>User Management</h1>
<p>View and update customer profiles.</p>
<br><?php
render_flash();
?><form class="filter-bar" method="get">
<div>
<label>Search (name or email)</label>
<input name="search" value="<?=e($search)?>">
</div>
<div>
<label>Joined year</label>
<select name="year">
<option value="">All</option><?php
foreach($years as $y):?><option value="<?=(int)$y?>" <?=$fYear===(string)$y?'selected':''?>><?=(int)$y?></option><?php
endforeach?></select>
</div>
<div>
<label>Joined month</label>
<select name="month">
<option value="">All</option><?php
foreach($monthNames as $mn=>$ml):?><option value="<?=$mn?>" <?=$fMonth===(string)$mn?'selected':''?>><?=$ml?></option><?php
endforeach?></select>
</div>
 <?=sort_hidden_inputs()?>
 <button>FILTER</button>
<a class="btn-small btn-neutral" href="users.php" style="align-self:center">Reset</a>
</form>
<p class="mini-note" style="color:#5e6674;font-size:13px">Tip: click a column header to sort.</p>
<div class="table-container">
<table>
<thead>
<tr><?=sort_th('Name', 'name')?><?=sort_th('Email', 'email')?><?=sort_th('Phone', 'phone')?><?=sort_th('Status', 'status')?><?=sort_th('Joined', 'joined')?><th>Actions</th>
</tr>
</thead>
<tbody><?php
if(!$rows):?><tr>
<td colspan="6">No customers found.</td>
</tr><?php
endif?><?php
foreach($rows as $r):?><tr>
<td><?=e($r['full_name'])?></td>
<td><?=e($r['email'])?></td>
<td><?=e($r['phone'])?></td>
<td>
<span class="badge badge-<?=$r['is_active']?'active':'inactive'?>"><?=$r['is_active']?'ACTIVE':'INACTIVE'?></span>
</td>
<td><?=e(substr($r['created_at'], 0, 10))?></td>
<td>
<div class="action-links">
<button class="btn-small btn-neutral" type="button" onclick="toggleEdit(<?=$r['customer_id']?>)">Edit</button>
<form method="post"><?=csrf_field()?><input type="hidden" name="user_id" value="<?=$r['user_id']?>">
<button name="action" value="toggle" class="btn-small <?=$r['is_active']?'btn-danger':'btn-approve'?>"><?=$r['is_active']?'Deactivate':'Activate'?></button>
</form>
</div>
<form method="post" id="edit-form-<?=$r['customer_id']?>" class="inline-form" style="display:none;margin-top:10px"><?=csrf_field()?><input type="hidden" name="customer_id" value="<?=$r['customer_id']?>">
<input type="hidden" name="user_id" value="<?=$r['user_id']?>">
<input name="full_name" value="<?=e($r['full_name'])?>">
<input name="phone_number" value="<?=e($r['phone'])?>">
<button name="action" value="edit" class="btn-small btn-approve">Save</button>
</form>
</td>
</tr><?php
endforeach?></tbody>
</table>
</div><?=paginate($total, $page, $per, '/LegalEase_eProject/admin/users.php?search='.urlencode($search).'&year='.urlencode($fYear).'&month='.urlencode($fMonth).'&sort='.urlencode($_GET['sort']??'').'&dir='.urlencode($_GET['dir']??''))?></main>
</div>
<script>function toggleEdit(id){const f=document.getElementById('edit-form-'+id);f.style.display=f.style.display==='none'?'flex':'none';}</script>
</body>
</html>
