<?php
require_once __DIR__.'/../includes/bootstrap.php';
$id=(int)($_GET['id']??0);
$s=$pdo->prepare("SELECT lp.*,u.email,l.city_name,GROUP_CONCAT(DISTINCT sp.name ORDER BY sp.name SEPARATOR ', ') specialties FROM lawyer_profiles lp JOIN users u ON u.id=lp.user_id LEFT JOIN locations l ON l.id=lp.location_id LEFT JOIN lawyer_specialties ls ON ls.lawyer_id=lp.id LEFT JOIN specializations sp ON sp.id=ls.specialization_id WHERE lp.id=? AND lp.is_verified=1 AND u.is_active=1 GROUP BY lp.id");
$s->execute([$id]);
$lawyer=$s->fetch();
if(!$lawyer) {
    http_response_code(404);
    exit('Lawyer not found');
}
$reviewRating=(int)($_GET['review_rating']??0);
if($reviewRating<0||$reviewRating>5)$reviewRating=0;
$reviewParams=[$id];
if($reviewRating)$reviewParams[]=$reviewRating;
$reviews=$pdo->prepare("SELECT r.rating,r.comment,r.created_at,cp.full_name FROM reviews r LEFT JOIN appointments a ON a.id=r.appointment_id LEFT JOIN customer_profiles cp ON cp.user_id=r.user_id WHERE r.lawyer_id=?".($reviewRating?' AND r.rating=?':'')." ORDER BY r.created_at DESC LIMIT 25");
$reviews->execute($reviewParams);
$reviews=$reviews->fetchAll();
page_header($lawyer['full_name']);
?>
<section class="section lawyer-profile-balanced">
<div class="profile-banner card">
<img class="profile-banner-avatar" src="<?=e(avatar_url($lawyer['avatar_file']??''))?>" alt="<?=e($lawyer['full_name'])?>">
<div class="profile-banner-main">
<span class="verified">✓ Verified Lawyer</span>
<h1><?=e($lawyer['full_name'])?></h1>
<div class="profile-tags">
<span>⚖ <?=(int)$lawyer['experience_years']?>+ Years Experience</span>
<span><?=star_rating_html((float)$lawyer['rating'])?> <?=number_format((float)$lawyer['rating'], 1)?></span>
</div>
<div class="profile-contact">
<span>☎ <?=e($lawyer['phone'])?></span>
<span>✉ <?=e($lawyer['email'])?></span>
</div>
</div>
<div id="book" class="profile-banner-action"><?php
if(user()&&user()['role']==='customer'):?><a class="btn profile-book-btn" href="/LegalEase_eProject/customer/book.php?lawyer=<?=$id?>">▣ Book Appointment</a><?php
elseif(!user()):?><a class="btn profile-book-btn" href="/LegalEase_eProject/login.php">Login to Book Appointment</a><?php
endif?></div>
</div>
<div class="balanced-profile-grid">
<div class="balanced-main">
<div class="card profile-section">
<h2>♟ Introduction</h2>
<p><?=e($lawyer['bio']?:'This lawyer has not updated their biography yet.')?></p>
</div>
<div class="card profile-section">
<h2>▣ Practice Information</h2>
<div class="profile-line">
<span>⌖ Area</span>
<strong><?=e($lawyer['city_name'])?></strong>
</div>
<div class="profile-line">
<span>▦ Practicing since</span>
<strong><?=date('Y')-(int)$lawyer['experience_years']?></strong>
</div>
<div class="profile-line">
<span>▤ Office</span>
<strong><?=e($lawyer['office_address'])?></strong>
</div>
<h3>Practice Areas</h3>
<div class="practice-chips"><?php
foreach(array_filter(array_map('trim', explode(',', $lawyer['specialties']??''))) as $sp):?><span><?=e($sp)?></span><?php
endforeach?></div>
</div>
</div>
<aside class="balanced-side">
<div class="card profile-section">
<h2>🏆 Awards & Certifications</h2>
<div class="award-chip">Verified LegalEase Professional</div>
</div>
<div class="card profile-section">
<h2>💵 Service Fees</h2>
<p>
<b>Consultation fee:</b>
<br>
<span class="fee-big"><?=money_usd($lawyer['consultation_fee'])?></span> / 60 minutes</p>
<p>
<b>Payment methods:</b>
</p>
<div class="payment-method-list">
<span>▣ Visa / Mastercard</span>
<span>▣ Apple Pay</span>
<span>▣ Google Pay</span>
<span>▣ Bank Transfer</span>
</div>
</div>
</aside>
</div>
<div class="card reviews-card">
<div class="review-title-filter-row">
<h2>Recent reviews</h2>
<form method="get" class="review-filter-form review-rating-only">
<input type="hidden" name="id" value="<?=$id?>">
<select name="review_rating" aria-label="Filter reviews by rating" onchange="this.form.submit()">
<option value="0">All ratings</option><?php
for($i=5;$i>=1;$i--):?><option value="<?=$i?>" <?=$reviewRating===$i?'selected':''?>><?=str_repeat('★', $i).str_repeat('☆', 5-$i)?> · <?=$i?> star<?=$i>1?'s':''?></option><?php
endfor?></select>
</form>
</div>
<p class="muted review-subtitle">Verified feedback from completed consultations.</p>
<div class="review-summary-line"><?=star_rating_html((float)$lawyer['rating'])?> <b><?=number_format((float)$lawyer['rating'], 1)?></b>
</div><?php
if(!$reviews):?><p class="muted">No reviews yet.</p><?php
else:foreach($reviews as $r):?><div class="review-item">
<div>
<b><?=e($r['full_name'])?></b>
<span><?=star_rating_html((float)$r['rating'])?></span>
</div>
<p><?=e($r['comment'])?></p>
<small class="muted"><?=e(date('d M Y', strtotime($r['created_at'])))?></small>
</div><?php
endforeach;
endif?></div>
</section>
<?php
page_footer();
?>
