<?php
require_once __DIR__.'/../includes/bootstrap.php';
require_role('lawyer');
$id=(int)($_GET['id']??0);
$s=$pdo->prepare('SELECT link_url,message FROM notifications WHERE id=? AND user_id=?');
$s->execute([$id, user()['id']]);
$row=$s->fetch();
if(!$row) {
    redirect('/LegalEase_eProject/lawyer/notifications.php');
}
$pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([$id, user()['id']]);
if(preg_match('/appointment\s*#\s*(\d+)/i', (string)($row['message']??''), $m)) {
    $appointmentId=(int)$m[1];
    $lid=current_lawyer_id($pdo);
    $check=$pdo->prepare('SELECT COUNT(*) FROM appointments WHERE id=? AND lawyer_id=?');
    $check->execute([$appointmentId, $lid]);
    if((int)$check->fetchColumn()>0) redirect('/LegalEase_eProject/lawyer/appointment.php?id='.$appointmentId);
}
redirect(safe_notification_url($row['link_url']??null, '/LegalEase_eProject/lawyer/notifications.php'));
