<?php
require_once __DIR__.'/../includes/customer_layout.php';
require_role('customer');
$cid=current_customer_id($pdo);
if(is_post()) {
    verify_csrf();
    $action=$_POST['action']??'create';
    $rating=(int)($_POST['rating']??0);
    $comment=trim((string)($_POST['comment']??''));
    if($rating<1||$rating>5) {
        flash('error', 'Please select a rating from 1 to 5 stars.');
        redirect('/LegalEase_eProject/customer/reviews.php');
    }
    if((function_exists('mb_strlen')?mb_strlen($comment):strlen($comment))<8) {
        flash('error', 'Please enter at least 8 characters for your review.');
        redirect('/LegalEase_eProject/customer/reviews.php');
    }
    if((function_exists('mb_strlen')?mb_strlen($comment):strlen($comment))>1000) {
        flash('error', 'Your review must not exceed 1,000 characters.');
        redirect('/LegalEase_eProject/customer/reviews.php');
    }
    if($action==='edit') {
        $reviewId=(int)($_POST['review_id']??0);
        $s=$pdo->prepare("SELECT r.id,r.lawyer_id,r.created_at FROM reviews r JOIN appointments a ON a.id=r.appointment_id WHERE r.id=? AND a.customer_id=? AND r.created_at>=DATE_SUB(NOW(),INTERVAL 72 HOUR)");
        $s->execute([$reviewId, $cid]);
        $review=$s->fetch();
        if(!$review) {
            flash('error', 'This review can no longer be edited. Reviews are editable for 72 hours after publication.');
            redirect('/LegalEase_eProject/customer/reviews.php');
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE reviews SET rating=?,comment=? WHERE id=?')->execute([$rating, $comment, $reviewId]);
            $avg=$pdo->prepare('SELECT AVG(rating) FROM reviews WHERE lawyer_id=?');
            $avg->execute([$review['lawyer_id']]);
            $pdo->prepare('UPDATE lawyer_profiles SET rating=? WHERE id=?')->execute([round((float)$avg->fetchColumn(), 1), $review['lawyer_id']]);
            $pdo->commit();
            flash('success', 'Review updated successfully.');
        }catch(Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            flash('error', 'Unable to update this review. Please try again.');
        }
        redirect('/LegalEase_eProject/customer/reviews.php');
    }
    $appointment=(int)($_POST['appointment_id']??0);
    if($comment==='') {
        flash('error', 'Please choose a quick review or write a short comment before submitting.');
        redirect('/LegalEase_eProject/customer/reviews.php?appointment='.$appointment);
    }
    $s=$pdo->prepare("SELECT a.*,lp.id lawyer_id FROM appointments a JOIN lawyer_profiles lp ON lp.id=a.lawyer_id LEFT JOIN reviews r ON r.appointment_id=a.id WHERE a.id=? AND a.customer_id=? AND a.status='Completed' AND r.id IS NULL");
    $s->execute([$appointment, $cid]);
    $a=$s->fetch();
    if($a) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO reviews(appointment_id,lawyer_id,user_id,rating,comment) VALUES(?,?,?,?,?)')->execute([$appointment, $a['lawyer_id'], user()['id'], $rating, $comment]);
            $avg=$pdo->prepare('SELECT AVG(rating) FROM reviews WHERE lawyer_id=?');
            $avg->execute([$a['lawyer_id']]);
            $pdo->prepare('UPDATE lawyer_profiles SET rating=? WHERE id=?')->execute([round((float)$avg->fetchColumn(), 1), $a['lawyer_id']]);
            $lu=$pdo->prepare('SELECT user_id FROM lawyer_profiles WHERE id=?');
            $lu->execute([$a['lawyer_id']]);
            $lawyerUid=(int)$lu->fetchColumn();
            if($lawyerUid)notify($pdo, $lawyerUid, 'A customer submitted a new review for a completed consultation.', 'System', '/LegalEase_eProject/public/lawyer.php?id='.$a['lawyer_id']);
            $pdo->commit();
            flash('success', 'Review submitted successfully.');
        }catch(Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            flash('error', 'This appointment may already have a review.');
        }
    }
    redirect('/LegalEase_eProject/customer/reviews.php');
}
$pre=(int)($_GET['appointment']??0);
$pendingPage=max(1, (int)($_GET['pending_page']??1));
$per=10;
$pc=$pdo->prepare("SELECT COUNT(*) FROM appointments a LEFT JOIN reviews r ON r.appointment_id=a.id WHERE a.customer_id=? AND a.status='Completed' AND r.id IS NULL");
$pc->execute([$cid]);
$pendingTotal=(int)$pc->fetchColumn();
$pendingPages=max(1, (int)ceil($pendingTotal/$per));
if($pendingPage>$pendingPages)$pendingPage=$pendingPages;
$pendingOffset=($pendingPage-1)*$per;
$orderPre=$pre>0?'(a.id='.((int)$pre).') DESC,':'';
$eligible=$pdo->prepare("SELECT a.id,a.appointment_date,a.start_time,a.end_time,a.fee,lp.full_name,lp.avatar_file,lp.rating FROM appointments a JOIN lawyer_profiles lp ON lp.id=a.lawyer_id LEFT JOIN reviews r ON r.appointment_id=a.id WHERE a.customer_id=? AND a.status='Completed' AND r.id IS NULL ORDER BY $orderPre a.appointment_date DESC,a.start_time DESC LIMIT $per OFFSET $pendingOffset");
$eligible->execute([$cid]);
$apps=$eligible->fetchAll();
$page=max(1, (int)($_GET['page']??1));
$c=$pdo->prepare("SELECT COUNT(*) FROM reviews r JOIN appointments a ON a.id=r.appointment_id WHERE a.customer_id=?");
$c->execute([$cid]);
$total=(int)$c->fetchColumn();
$pages=max(1, (int)ceil($total/$per));
if($page>$pages)$page=$pages;
$offset=($page-1)*$per;
$q=$pdo->prepare("SELECT r.*,lp.full_name,lp.avatar_file,a.appointment_date,a.start_time,a.end_time,a.fee,(r.created_at>=DATE_SUB(NOW(),INTERVAL 72 HOUR)) AS can_edit FROM reviews r JOIN appointments a ON a.id=r.appointment_id JOIN lawyer_profiles lp ON lp.id=a.lawyer_id WHERE a.customer_id=? ORDER BY r.created_at DESC LIMIT $per OFFSET $offset");
$q->execute([$cid]);
$reviews=$q->fetchAll();
$phrases=[
'Clear and easy-to-understand legal advice.',
'Professional, responsive and very helpful.',
'Explained my options carefully and patiently.',
'Well prepared and provided practical guidance.',
'Friendly communication and strong legal knowledge.'];
function review_page_url(array $changes=[]):string {
    $q=array_merge($_GET, $changes);
    unset($q['appointment']);
    return'?'.http_build_query($q);
}
customer_shell_start('Reviews', 'Share feedback after completed consultations and keep track of your published reviews.');
?>
<section class="review-page-intro card">
<div>
<span class="review-kicker">Your feedback matters</span>
<h2>Appointments waiting for review</h2>
<p class="muted">Newest completed appointments that have not been reviewed are shown first. Choose a quick phrase or write your own feedback.</p>
</div>
<div class="review-count-pill"><?=$pendingTotal?> waiting</div>
</section>
<div class="review-pending-list">
<?php
if(!$apps):?><div class="card empty">No completed consultation is waiting for review.</div><?php
else:foreach($apps as $a):$formId='reviewForm'.(int)$a['id'];
$textId='reviewText'.(int)$a['id'];
?>
<article class="card review-appointment-card <?=$pre===(int)$a['id']?'highlight':''?>">
<div class="review-lawyer-summary">
<img src="<?=e(avatar_url($a['avatar_file']??''))?>" alt="<?=e($a['full_name'])?>">
<div>
<span class="review-kicker">Completed consultation</span>
<h3><?=e($a['full_name'])?></h3>
<div class="review-appointment-meta">
<span>📅 <?=e(date('d M Y', strtotime($a['appointment_date'])))?></span>
<span>🕒 <?=e(substr($a['start_time'], 0, 5))?>–<?=e(substr($a['end_time'], 0, 5))?></span>
<span>💳 <?=money_usd($a['fee'])?></span>
</div>
</div>
<div class="review-lawyer-rating"><?=star_rating_html((float)$a['rating'])?><small>Current lawyer rating <?=number_format((float)$a['rating'], 1)?></small>
</div>
</div>
<form method="post" id="<?=$formId?>" class="review-card-form"><?=csrf_field()?><input type="hidden" name="appointment_id" value="<?=(int)$a['id']?>">
<div class="review-form-row">
<div>
<label>Your rating</label>
<div class="review-stars-input"><?php
for($i=1;$i<=5;$i++):?><label>
<input type="radio" name="rating" value="<?=$i?>" <?=$i===5?'checked':''?>>
<span>★</span>
</label><?php
endfor?></div>
</div>
</div>
<div class="field">
<label>Quick review suggestions</label>
<div class="review-phrase-chips"><?php
foreach($phrases as $phrase):?><button type="button" class="review-phrase" data-target="<?=$textId?>" data-text="<?=e($phrase)?>"><?=e($phrase)?></button><?php
endforeach?></div>
</div>
<div class="field">
<label>Your review</label>
<textarea id="<?=$textId?>" name="comment" placeholder="Choose a suggestion above or write your own experience..." required>
</textarea>
</div>
<div class="review-submit-row">
<span class="muted">Appointment #<?=(int)$a['id']?> · Verified completed consultation</span>
<button class="btn btn-success" data-confirm="Publish this review?">Submit Review</button>
</div>
</form>
</article>
<?php
endforeach;
endif?>
</div>
<?php
if($pendingPages>1):?>
<nav class="pagination professional-pagination" aria-label="Appointments waiting for review">
<?php
$prev=max(1, $pendingPage-1);
$next=min($pendingPages, $pendingPage+1);
?><a class="page-nav <?=$pendingPage<=1?'disabled':''?>" href="<?=e(review_page_url(['pending_page'=>$prev]))?>">‹</a><?php
for($i=1;$i<=$pendingPages;$i++):if($pendingPages>7 && $i>1 && $i<$pendingPages && abs($i-$pendingPage)>2) {
    if($i===2||$i===$pendingPages-1)echo '<span class="page-ellipsis">…</span>';
    continue;
}?><a class="<?=$i===$pendingPage?'active':''?>" href="<?=e(review_page_url(['pending_page'=>$i]))?>"><?=$i?></a><?php
endfor?><a class="page-nav <?=$pendingPage>=$pendingPages?'disabled':''?>" href="<?=e(review_page_url(['pending_page'=>$next]))?>">›</a>
</nav>
<?php
endif?>

<section class="card review-history-card">
<div class="card-header">
<div>
<h2>Reviewed appointments</h2>
<p class="muted">Your published reviews appear below, newest first. Reviews can be edited for 72 hours after publication.</p>
</div>
<div class="review-count-pill"><?=$total?> reviewed</div>
</div>
<div class="review-history-list"><?php
if(!$reviews):?><div class="empty">No reviews submitted yet.</div><?php
else:foreach($reviews as $r):$editId='editReview'.(int)$r['id'];
?><article class="professional-review enhanced-review">
<img src="<?=e(avatar_url($r['avatar_file']??''))?>" alt="<?=e($r['full_name'])?>">
<div>
<div class="professional-review-head">
<div>
<strong><?=e($r['full_name'])?></strong>
<small><?=e(date('d M Y', strtotime($r['appointment_date'])))?> · <?=e(substr($r['start_time'], 0, 5))?>–<?=e(substr($r['end_time'], 0, 5))?> · <?=money_usd($r['fee'])?></small>
</div>
<div><?=star_rating_html((float)$r['rating'])?></div>
</div>
<p><?=e($r['comment'])?></p>
<div class="review-history-footer">
<small class="muted">Published <?=e(date('d M Y H:i', strtotime($r['created_at'])))?></small><?php
if((int)$r['can_edit']===1):?><button type="button" class="btn btn-soft btn-small edit-review-toggle" data-target="<?=$editId?>">Edit review</button><?php
endif?></div><?php
if((int)$r['can_edit']===1):?><form id="<?=$editId?>" method="post" class="edit-review-form" hidden><?=csrf_field()?><input type="hidden" name="action" value="edit">
<input type="hidden" name="review_id" value="<?=(int)$r['id']?>">
<label>Rating</label>
<div class="edit-rating-select"><?php
for($i=1;$i<=5;$i++):?><label>
<input type="radio" name="rating" value="<?=$i?>" <?=$i===(int)$r['rating']?'checked':''?>>
<span>★</span>
</label><?php
endfor?></div>
<label for="comment<?=$r['id']?>">Review</label>
<textarea id="comment<?=$r['id']?>" name="comment" minlength="8" maxlength="1000" required><?=e($r['comment'])?></textarea>
<div class="edit-review-actions">
<button type="button" class="btn btn-soft edit-review-cancel" data-target="<?=$editId?>">Cancel</button>
<button class="btn btn-success" data-confirm="Save changes to this review?">Save changes</button>
</div>
<small class="muted">You can edit this review until 72 hours after it was published.</small>
</form><?php
endif?></div>
</article><?php
endforeach;
endif?></div><?=paginate($total, $page, $per, '/LegalEase_eProject/customer/reviews.php?pending_page='.$pendingPage)?></section>
<script>(()=>{document.querySelectorAll('.review-phrase').forEach(btn=>btn.addEventListener('click',()=>{const ta=document.getElementById(btn.dataset.target);ta.value=btn.dataset.text;ta.focus();btn.closest('.review-phrase-chips').querySelectorAll('.review-phrase').forEach(x=>x.classList.remove('selected'));btn.classList.add('selected');}));document.querySelectorAll('.edit-review-toggle').forEach(btn=>btn.addEventListener('click',()=>{const form=document.getElementById(btn.dataset.target);form.hidden=!form.hidden;}));document.querySelectorAll('.edit-review-cancel').forEach(btn=>btn.addEventListener('click',()=>{document.getElementById(btn.dataset.target).hidden=true;}));})();</script>
<?php
customer_shell_end();
?>
