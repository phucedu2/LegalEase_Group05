<?php
require_once __DIR__.'/../includes/lawyer_layout.php';
require_role('lawyer');
$uid=(int)user()['id'];
$filter=($_GET['filter']??'all')==='unread'?'unread':'all';
if(is_post()) {
    verify_csrf();
    $action=$_POST['action']??'';
    if($action==='all') {
        $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$uid]);
        flash('success', 'All notifications marked as read.');
    }elseif($action==='one') {
        $pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([(int)$_POST['id'], $uid]);
    }redirect('/LegalEase_eProject/lawyer/notifications.php?filter='.$filter);
}
$cond=$filter==='unread'?' AND is_read=0':'';
$page=max(1, (int)($_GET['page']??1));
$per=10;
$c=$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=?'.$cond);
$c->execute([$uid]);
$total=(int)$c->fetchColumn();
$pages=max(1, (int)ceil($total/$per));
if($page>$pages)$page=$pages;
$offset=($page-1)*$per;
$sql='SELECT * FROM notifications WHERE user_id=?'.$cond.' ORDER BY created_at DESC LIMIT '.$per.' OFFSET '.$offset;
$q=$pdo->prepare($sql);
$q->execute([$uid]);
$rows=$q->fetchAll();
lawyer_shell_start('Notifications', 'Appointment activity, customer questions, reminders, profile activity and system messages.');
?>
<div class="card">
<div class="card-header">
<div class="notification-page-tabs">
<a class="btn btn-light btn-sm <?=$filter==='all'?'active-tab':''?>" href="?filter=all">All</a>
<a class="btn btn-light btn-sm <?=$filter==='unread'?'active-tab':''?>" href="?filter=unread">Unread</a>
</div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="all">
<button class="btn btn-light btn-sm">Mark all as read</button>
</form>
</div>
<div class="notification-page-list"><?php
if(!$rows):?><div class="empty">No <?=$filter==='unread'?'unread ':''?>notifications.</div><?php
else:foreach($rows as $r):?><article class="notification-page-item <?=$r['is_read']?'':'unread'?>">
<a class="notification-main-link" href="/LegalEase_eProject/lawyer/notification_open.php?id=<?=(int)$r['id']?>">
<span class="notification-type"><?=e($r['type'])?></span>
<h3><?=e($r['message'])?></h3>
<small><?=e(date('d M Y, H:i', strtotime($r['created_at'])))?></small><?php
if(!empty($r['link_url'])):?><span class="notification-go">Open related activity →</span><?php
endif?></a><?php
if(!$r['is_read']):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="one">
<input type="hidden" name="id" value="<?=(int)$r['id']?>">
<button class="btn btn-light btn-sm">Mark as read</button>
</form><?php
endif?></article><?php
endforeach;
endif?></div><?=paginate($total, $page, $per, '/LegalEase_eProject/lawyer/notifications.php?filter='.urlencode($filter))?></div>
<?php
lawyer_shell_end();
?>
