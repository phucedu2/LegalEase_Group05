<?php
require_once __DIR__.'/../includes/bootstrap.php';
$db=$pdo->query("SELECT title,body FROM content_management WHERE type='FAQ' AND is_published=1 ORDER BY updated_at DESC")->fetchAll();
$extra=[
['How do I find the right lawyer?', 'Use Find Lawyers to search by lawyer name or legal issue, then filter by specialization and city/province.'],
['How do I book a consultation?', 'Open a verified lawyer profile, choose an available one-hour consultation slot, sign in as a customer, and confirm your request.'],
['How long is each consultation slot?', 'Each consultation slot is limited to 1 hour. Lawyer work schedules may only be created between 07:00 and 22:00.'],
['What is the appointment cancellation and refund policy?', 'More than 15 days before the appointment: 5% fee. 8–15 days: 10% fee. 3–7 days: 20% fee. 1–3 days: 50% fee, split as 30% lawyer compensation and 20% platform fee. Less than 24 hours: 100% fee, split as 80% lawyer compensation and 20% platform fee.'],
['When can I submit a review?', 'A review can be submitted only after the related appointment has been marked Completed.'],
['How are lawyers verified?', 'Lawyers provide identity and license information. An administrator reviews the verification request before the lawyer is marked Verified.'],
['Can a lawyer have more than one specialization?', 'Yes. A lawyer may have multiple legal specializations such as Civil Law, Insurance Law, Intellectual Property Law, and Labor & Employment Law.'],
['What notifications will I receive?', 'The system may provide booking confirmations, appointment status updates, reminders, payment updates and other system messages.'],
['What should I do if I forget my password?', 'Use the Forgot password link on the Login page. A time-limited reset link will be generated and sent to the registered email address when email delivery is configured.'],
['Is my password stored securely?', 'Passwords are stored as secure password hashes and are verified using PHP password verification functions.']];
$seen=[];
$items=[];
foreach($db as $r) {
    $seen[strtolower(trim($r['title']))]=1;
    $items[]=$r;
}foreach($extra as $r) {
    if(empty($seen[strtolower($r[0])]))$items[]=['title'=>$r[0], 'body'=>$r[1]];
}
page_header('Frequently Asked Questions');
?>
<section class="section">
<div class="section-heading">
<div>
<p class="eyebrow">Help center</p>
<h1>Frequently Asked Questions</h1>
<p class="muted">Answers to common questions about accounts, lawyers, appointments and security.</p>
</div>
</div>
<div class="news-list"><?php
foreach($items as $r):?><article class="faq-item">
<details>
<summary><?=e($r['title'])?></summary>
<p><?=nl2br(e($r['body']))?></p>
</details>
</article><?php
endforeach?></div>
</section>
<?php
page_footer();
?>
