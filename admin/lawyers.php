<?php
require_once __DIR__.'/includes/auth.php';
$active_page='lawyers';
if(is_post()) {
    verify_csrf();
    $action=$_POST['action']??'';
    $lid=(int)($_POST['lawyer_id']??0);
    $uid=(int)($_POST['user_id']??0);
    if(in_array($action, ['approve', 'reject'], true)&&$lid) {
        $st=$action==='approve'?'Approved':'Rejected';
        $note=trim($_POST['admin_note']??'');
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE lawyer_verifications SET status=?,admin_note=?,reviewed_by=?,reviewed_at=NOW() WHERE lawyer_id=?")->execute([$st, $note, user()['id'], $lid]);
        $pdo->prepare("UPDATE lawyer_profiles SET is_verified=? WHERE id=?")->execute([$action==='approve'?1:0, $lid]);
        $pdo->commit();
        flash('success', 'Verification '.$st.'.');
    }
    elseif($action==='toggle'&&$uid) {
        $pdo->prepare("UPDATE users SET is_active=1-is_active WHERE id=?")->execute([$uid]);
        flash('success', 'Lawyer account status updated.');
    }
    elseif($action==='edit'&&$lid) {
        $spec=trim($_POST['specialization']??'');
        $phone=trim($_POST['phone']??'');
        $exp=max(0, (int)($_POST['experience_years']??0));
        $bio=trim($_POST['bio']??'');
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE lawyer_profiles SET phone=?,experience_years=?,bio=? WHERE id=?")->execute([$phone, $exp, $bio, $lid]);
        if($spec!=='') {
            $pdo->prepare("INSERT INTO specializations(name) VALUES(?) ON DUPLICATE KEY UPDATE name=VALUES(name)")->execute([$spec]);
            $sid=(int)$pdo->query("SELECT id FROM specializations WHERE name=".$pdo->quote($spec))->fetchColumn();
            $pdo->prepare("DELETE FROM lawyer_specialties WHERE lawyer_id=?")->execute([$lid]);
            $pdo->prepare("INSERT INTO lawyer_specialties(lawyer_id,specialization_id) VALUES(?,?)")->execute([$lid, $sid]);
        }$pdo->commit();
        flash('success', 'Lawyer profile updated.');
    }
    redirect('/LegalEase_eProject/admin/lawyers.php'.(!empty($_GET['filter'])?'?filter='.urlencode($_GET['filter']):''));
}
$filter=strtolower($_GET['filter']??'');
$where='';
$params=[];
if(in_array($filter, ['pending', 'approved', 'rejected'], true)) {
    $where='WHERE LOWER(COALESCE(v.status,\'Pending\'))=?';
    $params[]=$filter;
}
$search=trim($_GET['search']??'');
if($search!=='') {
    $where.=($where?' AND':'WHERE').' (lp.full_name LIKE ? OR u.email LIKE ?)';
    $params[]='%'.$search.'%';
    $params[]='%'.$search.'%';
}
$sortMap=['name_az'=>'lp.full_name ASC', 'name_za'=>'lp.full_name DESC', 'exp_desc'=>'lp.experience_years DESC', 'exp_asc'=>'lp.experience_years ASC', 'rating_desc'=>'lp.rating DESC', 'rating_asc'=>'lp.rating ASC', 'status'=>'u.is_active DESC,lp.full_name ASC'];
$sort=$_GET['sort']??'name_az';
$order=$sortMap[$sort]??$sortMap['name_az'];
$page=max(1, (int)($_GET['page']??1));
$per=15;
$cq=$pdo->prepare("SELECT COUNT(DISTINCT lp.id) FROM lawyer_profiles lp JOIN users u ON u.id=lp.user_id LEFT JOIN lawyer_verifications v ON v.lawyer_id=lp.id $where");
$cq->execute($params);
$total=(int)$cq->fetchColumn();
$pages=max(1, (int)ceil($total/$per));
if($page>$pages)$page=$pages;
$offset=($page-1)*$per;
$q=$pdo->prepare("SELECT lp.id lawyer_id,lp.full_name,lp.phone,lp.experience_years,lp.bio,lp.rating,u.id user_id,u.email,u.is_active,COALESCE(v.status,'Pending') verification_status,v.license_number,v.id_card_number,v.document_file,v.admin_note,GROUP_CONCAT(sp.name ORDER BY sp.name SEPARATOR ', ') specialization FROM lawyer_profiles lp JOIN users u ON u.id=lp.user_id LEFT JOIN lawyer_verifications v ON v.lawyer_id=lp.id LEFT JOIN lawyer_specialties ls ON ls.lawyer_id=lp.id LEFT JOIN specializations sp ON sp.id=ls.specialization_id $where GROUP BY lp.id ORDER BY $order LIMIT $per OFFSET $offset");
$q->execute($params);
$rows=$q->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lawyer Management - LegalEase</title>
<link rel="stylesheet" href="../assets/css/admin_base.css">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
<div class="lawyer-layout"><?php
require __DIR__.'/includes/sidebar.php';
?><main class="main-content">
<h1>Lawyer Management</h1>
<p>Approve/reject lawyer registrations, update profiles, and disable or re-activate lawyers.</p>
<br><?php
render_flash();
?><div class="tabs">
<a href="lawyers.php" class="<?=$filter===''?'active':''?>">All</a>
<a href="lawyers.php?filter=pending" class="<?=$filter==='pending'?'active':''?>">Pending</a>
<a href="lawyers.php?filter=approved" class="<?=$filter==='approved'?'active':''?>">Approved</a>
<a href="lawyers.php?filter=rejected" class="<?=$filter==='rejected'?'active':''?>">Rejected</a>
</div>
<form class="filter-bar" method="get">
<input type="hidden" name="filter" value="<?=e($filter)?>">
<div>
<label>Search (name or email)</label>
<input name="search" value="<?=e($search)?>">
</div>
<div>
<label>Sort by</label>
<select name="sort" onchange="this.form.submit()">
   <?php
foreach(['name_az'=>'Name A-Z', 'name_za'=>'Name Z-A', 'exp_desc'=>'Experience (high-low)', 'exp_asc'=>'Experience (low-high)', 'rating_desc'=>'Rating (high-low)', 'rating_asc'=>'Rating (low-high)', 'status'=>'Active first'] as $k=>$lbl):?>
   <option value="<?=$k?>" <?=$sort===$k?'selected':''?>><?=$lbl?></option><?php
endforeach?>
 </select>
</div>
<button>APPLY</button>
<a class="btn-small btn-neutral" href="lawyers.php<?=$filter?'?filter='.e($filter):''?>" style="align-self:center">Reset</a>
</form>
<?php
if(!$rows):?><div class="card">
<p>No lawyers match this filter.</p>
</div><?php
endif?><?php
foreach($rows as $r):?><div class="card" style="margin-bottom:20px">
<div class="page-header">
<div>
<h2><?=e($r['full_name'])?></h2>
<p><?=e($r['email'])?></p>
</div>
<div>
<span class="badge badge-<?=strtolower($r['verification_status'])?>"><?=e(strtoupper($r['verification_status']))?></span>
<span class="badge badge-<?=$r['is_active']?'active':'disabled'?>"><?=$r['is_active']?'ACTIVE':'DISABLED'?></span>
</div>
</div>
<p>
<strong>Phone:</strong> <?=e($r['phone'])?></p>
<p>
<strong>Specialization:</strong> <?=e($r['specialization']?:'Not set')?></p>
<p>
<strong>Experience:</strong> <?=(int)$r['experience_years']?> years</p>
<p>
<strong>Bio:</strong> <?=e($r['bio'])?></p>
<p>
<strong>National ID:</strong> <?=e($r['id_card_number']?:'Not submitted')?></p>
<p>
<strong>License:</strong> <?=e($r['license_number']?:'Not submitted')?>
<?php
$docs=array_filter(array_map('trim', explode(',', (string)$r['document_file'])));
?>
<?php
if($docs):?>— <?php
foreach($docs as $di=>$doc):?><a href="<?=e(verification_document_url($doc))?>" target="_blank">Document <?=$di+1?></a><?=$di<count($docs)-1?', ':''?><?php
endforeach?><?php
endif?></p>
<?php
if($r['verification_status']==='Pending'):?><form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="lawyer_id" value="<?=$r['lawyer_id']?>">
<input type="text" name="admin_note" placeholder="Note (optional)">
<button name="action" value="approve" class="btn-small btn-approve">Approve</button>
<button name="action" value="reject" class="btn-small btn-reject">Reject</button>
</form>
<br><?php
endif?>
<div class="action-links">
<form method="post"><?=csrf_field()?><input type="hidden" name="lawyer_id" value="<?=$r['lawyer_id']?>">
<input type="hidden" name="user_id" value="<?=$r['user_id']?>">
<button name="action" value="toggle" class="btn-small <?=$r['is_active']?'btn-danger':'btn-approve'?>" onclick="return confirm('Change account status?')"><?=$r['is_active']?'Disable Account':'Activate Account'?></button>
</form>
<button class="btn-small btn-neutral" type="button" onclick="toggleEdit(<?=$r['lawyer_id']?>)">Edit Profile</button>
</div>
<form method="post" id="edit-form-<?=$r['lawyer_id']?>" class="edit-panel" style="display:none"><?=csrf_field()?><input type="hidden" name="lawyer_id" value="<?=$r['lawyer_id']?>">
<label>Specialization</label>
<input name="specialization" value="<?=e($r['specialization'])?>">
<label>Experience (years)</label>
<input type="number" min="0" name="experience_years" value="<?=(int)$r['experience_years']?>">
<label>Phone</label>
<input name="phone" value="<?=e($r['phone'])?>">
<label>Bio</label>
<textarea name="bio" rows="3"><?=e($r['bio'])?></textarea>
<button name="action" value="edit">SAVE CHANGES</button>
</form>
</div><?php
endforeach?><?=paginate($total, $page, $per, '/LegalEase_eProject/admin/lawyers.php?filter='.urlencode($filter).'&search='.urlencode($search).'&sort='.urlencode($sort))?></main>
</div>
<script>function toggleEdit(id){const f=document.getElementById('edit-form-'+id);f.style.display=f.style.display==='none'?'block':'none';}</script>
</body>
</html>
