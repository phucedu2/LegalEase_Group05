<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role('customer');
$cid = current_customer_id($pdo);
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$s = $pdo->prepare("SELECT a.*,lp.full_name FROM appointments a JOIN lawyer_profiles lp ON lp.id=a.lawyer_id WHERE a.id=? AND a.customer_id=? AND a.status='Completed'");
$s->execute([$id, $cid]);
$a = $s->fetch();
if (!$a) {
    http_response_code(404);
    exit('Completed appointment not found.');
}
if (is_post()) {
    verify_csrf();
    $rating = max(1, min(5, (int) ($_POST['rating'] ?? 5)));
    $comment = trim($_POST['comment'] ?? '');
    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO reviews(appointment_id,rating,comment) VALUES(?,?,?)')->execute([$id, $rating, $comment]);
        $avg = $pdo->prepare("SELECT AVG(r.rating) FROM reviews r JOIN appointments a2 ON a2.id=r.appointment_id WHERE a2.lawyer_id=?");
        $avg->execute([$a['lawyer_id']]);
        $pdo->prepare('UPDATE lawyer_profiles SET rating=? WHERE id=?')->execute([round((float) $avg->fetchColumn(), 1), $a['lawyer_id']]);
        $pdo->commit();
        flash('success', 'Thank you for your review.');
        redirect('/LegalEase_eProject/customer/appointments.php');
    } catch (PDOException $e) {
        if ($pdo->inTransaction())$pdo->rollBack();
        flash('error', 'This appointment may already have a review.');
        redirect('/LegalEase_eProject/customer/appointments.php');
    }
}
page_header('Write Review', 'customer');
?><div class="form-card">
<h2><?= e($a['full_name'])?></h2>
<form method="post"><?= csrf_field()?><input type="hidden" name="id" value="<?= $id?>">
<div class="field">
<label>Rating</label>
<select name="rating"><?php
for ($i = 5;$i >= 1;$i--):?><option value="<?= $i?>"><?= $i?> star<?= $i > 1 ? 's' : ''?></option><?php
endfor?></select>
</div>
<div class="field">
<label>Comment</label>
<textarea name="comment">
</textarea>
</div>
<div class="actions">
<button>Submit Review</button>
</div>
</form>
</div><?php
page_footer('customer');
?>
