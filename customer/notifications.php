<?php
require_once __DIR__ . '/../includes/customer_layout.php';
require_role('customer');
$filter = ($_GET['filter'] ?? 'all') === 'unread' ? 'unread' : 'all';
if (is_post()) {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id)$pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([$id, user()['id']]);
    else $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([user()['id']]);
    flash('success', 'Notification status updated.');
    redirect('/LegalEase_eProject/customer/notifications.php?filter=' . $filter);
}
$cond=$filter==='unread'?' AND is_read=0':'';
$page=max(1, (int)($_GET['page']??1));
$per=10;
$c=$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=?'.$cond);
$c->execute([user()['id']]);
$total=(int)$c->fetchColumn();
$pages=max(1, (int)ceil($total/$per));
if($page>$pages)$page=$pages;
$offset=($page-1)*$per;
$sql='SELECT * FROM notifications WHERE user_id=?'.$cond.' ORDER BY created_at DESC LIMIT '.$per.' OFFSET '.$offset;
$q=$pdo->prepare($sql);
$q->execute([user()['id']]);
$rows=$q->fetchAll();
customer_shell_start('Notifications', 'Booking confirmations, payment updates, consultation reminders, Q&A responses and account activity.');
?>
<div class="card">
<div class="card-header">
<div class="notification-page-tabs">
<a class="btn btn-light btn-sm <?= $filter === 'all' ? 'active-tab' : ''?>" href="?filter=all">All</a>
<a class="btn btn-light btn-sm <?= $filter === 'unread' ? 'active-tab' : ''?>" href="?filter=unread">Unread</a>
</div>
<form method="post"><?= csrf_field()?><button class="btn btn-light btn-sm">Mark all as read</button>
</form>
</div>
<?php
foreach ($rows as $n):?><article class="notification-card <?= $n['is_read'] ? '' : 'unread'?>">
<a class="notification-main-link" href="/LegalEase_eProject/customer/notification_open.php?id=<?= (int) $n['id']?>">
<span class="notification-type"><?= e($n['type'])?></span>
<h3><?= e($n['message'])?></h3>
<small class="muted"><?= e(date('d M Y H:i', strtotime($n['created_at'])))?></small><?php
if (!empty($n['link_url'])):?><span class="notification-go">Open related activity →</span><?php
endif?></a><?php
if (!$n['is_read']):?><form method="post"><?= csrf_field()?><input type="hidden" name="id" value="<?= $n['id']?>">
<button class="btn btn-light btn-sm">Mark as read</button>
</form><?php
endif?></article><?php
endforeach?><?php
if (!$rows):?><div class="empty">No <?= $filter === 'unread' ? 'unread ' : ''?>notifications.</div><?php
endif?><?=paginate($total, $page, $per, '/LegalEase_eProject/customer/notifications.php?filter='.urlencode($filter))?></div>
<?php
customer_shell_end();
?>
