<?php
require_once __DIR__.'/../includes/bootstrap.php';
$q=trim($_GET['q']??'');
$spec=(int)($_GET['specialization']??0);
$loc=(int)($_GET['location']??0);
$sort=$_GET['sort']??($q!==''?'name':'rating');
$page=max(1, (int)($_GET['page']??1));
$per=10;
$where=['lp.is_verified=1', 'u.is_active=1'];
$params=[];
if($q!=='') {
    $where[]='(lp.full_name LIKE ? OR lp.bio LIKE ? OR EXISTS(SELECT 1 FROM lawyer_specialties qls JOIN specializations qs ON qs.id=qls.specialization_id WHERE qls.lawyer_id=lp.id AND qs.name LIKE ?))';
    $like='%'.$q.'%';
    array_push($params, $like, $like, $like);
}
if($loc) {
    $where[]='lp.location_id=?';
    $params[]=$loc;
}
if($spec) {
    $where[]='EXISTS(SELECT 1 FROM lawyer_specialties x WHERE x.lawyer_id=lp.id AND x.specialization_id=?)';
    $params[]=$spec;
}
$orderParams=[];
if($sort==='name' && $q!=='') {
    // A typed name must rank name matches ahead of matches found only in biography/specialization.
    $order="CASE WHEN LOWER(lp.full_name)=LOWER(?) THEN 0 WHEN LOWER(lp.full_name) LIKE LOWER(?) THEN 1 ELSE 2 END, lp.full_name ASC";
    $orderParams=[$q, '%'.$q.'%'];
}else {
    $order=match($sort) {
        'experience'=>'lp.experience_years DESC,lp.full_name ASC', 'name'=>'lp.full_name ASC', default=>'lp.rating DESC,lp.full_name ASC'
    };
}
$count=$pdo->prepare('SELECT COUNT(*) FROM lawyer_profiles lp JOIN users u ON u.id=lp.user_id WHERE '.implode(' AND ', $where));
$count->execute($params);
$total=(int)$count->fetchColumn();
$pages=max(1, (int)ceil($total/$per));
if($page>$pages)$page=$pages;
$offset=($page-1)*$per;
$sql="SELECT lp.id,lp.full_name,lp.bio,lp.experience_years,lp.rating,lp.avatar_file,lp.consultation_fee,l.city_name,GROUP_CONCAT(DISTINCT sp.name ORDER BY sp.name SEPARATOR ', ') specialties FROM lawyer_profiles lp JOIN users u ON u.id=lp.user_id LEFT JOIN locations l ON l.id=lp.location_id LEFT JOIN lawyer_specialties ls ON ls.lawyer_id=lp.id LEFT JOIN specializations sp ON sp.id=ls.specialization_id WHERE ".implode(' AND ', $where)." GROUP BY lp.id ORDER BY $order LIMIT $per OFFSET $offset";
$st=$pdo->prepare($sql);
$st->execute(array_merge($params, $orderParams));
$rows=$st->fetchAll();
ob_start();
if(!$rows):?><div class="empty">No verified lawyers match your search.</div><?php
else:foreach($rows as $l):?><article class="lawyer-list-card">
<img src="<?=e(avatar_url($l['avatar_file']??''))?>" alt="<?=e($l['full_name'])?>">
<div>
<span class="verified">✓ Verified Lawyer</span>
<h3><?=e($l['full_name'])?></h3>
<div class="star-row"><?=star_rating_html((float)$l['rating'])?> <span><?=number_format((float)$l['rating'], 1)?> · <?=(int)$l['experience_years']?> years</span>
</div>
<div class="lawyer-list-meta">
<span>⌖ <?=e($l['city_name'])?></span>
<span><?=money_usd($l['consultation_fee'])?> / hour</span>
</div>
<div class="practice-label">SPECIALIZATIONS</div>
<p class="practice-list"><?=e($l['specialties']?:'General Legal Practice')?></p>
<p><?=e(mb_strimwidth($l['bio']??'', 0, 220, '...'))?></p>
</div>
<div class="lawyer-list-actions">
<a class="btn" href="/LegalEase_eProject/public/lawyer.php?id=<?=$l['id']?>">View Profile</a><?php
if(user()&&user()['role']==='customer'):?><a class="btn success" href="/LegalEase_eProject/customer/book.php?lawyer=<?=$l['id']?>">Book Appointment</a><?php
else:?><a class="btn success" href="/LegalEase_eProject/public/lawyer.php?id=<?=$l['id']?>#book">Book Appointment</a><?php
endif?></div>
</article><?php
endforeach;
endif;
$html=ob_get_clean();
ob_start();
if($pages>1):
$items=[];
if($pages<=7) {
    for($i=1;$i<=$pages;$i++)$items[]=$i;
}else {
    $items=[1];
    $start=max(2, $page-2);
    $end=min($pages-1, $page+2);
    if($start>2)$items[]='…';
    for($i=$start;$i<=$end;$i++)$items[]=$i;
    if($end<$pages-1)$items[]='…';
    $items[]=$pages;
}?><nav class="pagination ajax-pagination professional-pagination" aria-label="Search result pages">
<button type="button" data-page="<?=$page-1?>" class="page-nav" <?=$page<=1?'disabled':''?>>‹</button><?php
foreach($items as $it):?><?php
if($it==='…'):?><span class="page-ellipsis">…</span><?php
else:?><button type="button" data-page="<?=(int)$it?>" class="<?=(int)$it===$page?'active':''?>"><?=(int)$it?></button><?php
endif?><?php
endforeach?><button type="button" data-page="<?=$page+1?>" class="page-nav" <?=$page>=$pages?'disabled':''?>>›</button>
</nav><?php
endif;
$pagination=ob_get_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['html'=>$html, 'pagination'=>$pagination, 'total'=>$total, 'page'=>$page], JSON_UNESCAPED_UNICODE);
