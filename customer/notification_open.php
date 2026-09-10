<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role('customer');
$id = (int) ($_GET['id'] ?? 0);
$s = $pdo->prepare('SELECT link_url FROM notifications WHERE id=? AND user_id=?');
$s->execute([$id, user()['id']]);
$row = $s->fetch();
if (!$row) {
    redirect('/LegalEase_eProject/customer/notifications.php');
}
$pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([$id, user()['id']]);
redirect(safe_notification_url($row['link_url'] ?? null, '/LegalEase_eProject/customer/notifications.php'));
