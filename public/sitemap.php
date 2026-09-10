<?php
require_once __DIR__.'/../includes/bootstrap.php';
page_header('Sitemap');
$groups=[
'PUBLIC & AUTHENTICATION'=>[
['Home', '/LegalEase_eProject/index.php', 'Landing page, featured lawyers, search and public information', '/index.php'],
['Find Lawyers', '/LegalEase_eProject/public/lawyers.php', 'Real-time lawyer directory, filters and profile discovery', '/public/lawyers.php'],
['Lawyer Profile', '/LegalEase_eProject/public/lawyers.php', 'Professional details, fees, practice areas and verified reviews', '/public/lawyer.php?id=…'],
['FAQ', '/LegalEase_eProject/public/faq.php', 'Frequently asked questions about LegalEase', '/public/faq.php'],
['About Us', '/LegalEase_eProject/public/about.php', 'Platform purpose, standards and operating model', '/public/about.php'],
['Legal News', '/LegalEase_eProject/public/news.php', 'Published legal news and platform content', '/public/news.php'],
['Register', '/LegalEase_eProject/register.php', 'Customer or lawyer account registration', '/register.php'],
['Login', '/LegalEase_eProject/login.php', 'Secure role-based account access', '/login.php'],
['Forgot Password', '/LegalEase_eProject/forgot_password.php', 'Request a password reset token', '/forgot_password.php'],
['Reset Password', '/LegalEase_eProject/reset_password.php', 'Set a new password using a valid reset token', '/reset_password.php']],
'CUSTOMER PORTAL'=>[
['Dashboard', '/LegalEase_eProject/customer/dashboard.php', 'Upcoming appointments, payment indicators and consultation summary', '/customer/dashboard.php'],
['Find Lawyer', '/LegalEase_eProject/public/lawyers.php', 'Search and open verified lawyer profiles', '/public/lawyers.php'],
['Book Appointment', '/LegalEase_eProject/public/lawyers.php', 'Choose an available lawyer date and time slot', '/customer/book.php?lawyer=…'],
['Appointment Summary', '/LegalEase_eProject/customer/appointments.php', 'Review a newly requested appointment before payment', '/customer/appointment_summary.php?appointment=…'],
['My Appointments', '/LegalEase_eProject/customer/appointments.php', 'Filter, review and manage customer appointments', '/customer/appointments.php'],
['Secure Checkout', '/LegalEase_eProject/customer/payments.php', 'Complete payment for a pending booking', '/customer/checkout.php?appointment=…'],
['Payments', '/LegalEase_eProject/customer/payments.php', 'Payment methods, billing history and refund status', '/customer/payments.php'],
['Reviews', '/LegalEase_eProject/customer/reviews.php', 'Submit and manage eligible consultation reviews', '/customer/reviews.php'],
['Your Profile', '/LegalEase_eProject/customer/profile.php', 'Update customer profile, contact details and avatar', '/customer/profile.php'],
['Notifications', '/LegalEase_eProject/customer/notifications.php', 'Appointment, payment and platform notifications', '/customer/notifications.php']],
'LAWYER PORTAL'=>[
['Dashboard', '/LegalEase_eProject/lawyer/dashboard.php', 'Consultation activity, earnings and client analytics', '/lawyer/dashboard.php'],
['Work Schedule', '/LegalEase_eProject/lawyer/schedule.php', 'Create, filter and manage availability slots', '/lawyer/schedule.php'],
['Appointments', '/LegalEase_eProject/lawyer/appointments.php', 'Upcoming requests and client appointment history', '/lawyer/appointments.php'],
['Appointment Details', '/LegalEase_eProject/lawyer/appointments.php', 'Review customer, schedule, financial and cancellation details', '/lawyer/appointment.php?id=…'],
['Personal Profile', '/LegalEase_eProject/lawyer/profile.php', 'Edit general information, expertise, fees and avatar', '/lawyer/profile.php'],
['Verification', '/LegalEase_eProject/lawyer/profile.php?tab=expertise', 'Submit and review current professional verification document', '/lawyer/profile.php?tab=expertise'],
['Notifications', '/LegalEase_eProject/lawyer/notifications.php', 'Appointment and verification notifications', '/lawyer/notifications.php']],
'ADMIN PORTAL'=>[
['Dashboard', '/LegalEase_eProject/admin/dashboard.php', 'System overview and platform activity', '/admin/dashboard.php'],
['Booking Appointments', '/LegalEase_eProject/admin/bookings.php', 'View and manage booking records', '/admin/bookings.php'],
['Lawyer Management', '/LegalEase_eProject/admin/lawyers.php', 'Review lawyer accounts and verification information', '/admin/lawyers.php'],
['User Management', '/LegalEase_eProject/admin/users.php', 'Search and manage platform user accounts', '/admin/users.php'],
['Reporting & Analytics', '/LegalEase_eProject/admin/reports.php', 'Operational reporting and analytics', '/admin/reports.php'],
['Content Management', '/LegalEase_eProject/admin/content.php', 'Manage published FAQ, news and content records', '/admin/content.php']],
'SYSTEM NAVIGATION'=>[
['Sitemap', '/LegalEase_eProject/public/sitemap.php', 'Complete navigation map for this LegalEase build', '/public/sitemap.php'],
['Logout', '/LegalEase_eProject/logout.php', 'End the current authenticated session', '/logout.php']]];
$total=array_sum(array_map('count', $groups));
?>
<section class="sitemap-reference">
<div class="sitemap-title-block">
<h1>LegalEase Sitemap <small>Current Application Structure</small>
</h1>
<p>This sitemap reflects the public pages and the Customer, Lawyer and Administrator components included in this LegalEase project.</p>
</div>
<div class="sitemap-reference-grid">
 <?php
foreach($groups as $title=>$items):?>
  <section class="sitemap-module <?=$title==='SYSTEM NAVIGATION'?'support-module':''?>">
<h2><?=e($title)?></h2>
   <?php
foreach($items as $item):?><a href="<?=e($item[1])?>" class="sitemap-row">
<div>
<strong><?=e($item[0])?></strong>
<p><?=e($item[2])?></p>
</div>
<code><?=e($item[3])?></code>
</a><?php
endforeach?>
  </section>
 <?php
endforeach?>
 </div>
<div class="sitemap-status">
<div>
<strong>Application Map</strong>
<span>Only routes and functional areas included in this build are listed.</span>
</div>
<div>
<b><?=count($groups)?></b>
<span>SECTIONS</span>
</div>
<div>
<b><?=$total?></b>
<span>LINKS</span>
</div>
</div>
</section>
<?php
page_footer();
?>
