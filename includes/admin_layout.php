<?php
require_once __DIR__.'/bootstrap.php';
function admin_badge(string $status): string {
    $s=strtolower($status);
    return match($s) {
        'pending'=>'badge-pending', 'approved', 'active', 'confirmed', 'completed'=>'badge-approved', 'rejected', 'cancelled', 'disabled', 'inactive'=>'badge-rejected', default=>'badge-neutral'
    };
}
function admin_shell_start(string $title, string $subtitle='', string $active='dashboard'):void {
    $links=[['dashboard', 'Dashboard', 'dashboard.php'], ['appointments', 'Booking Appointments', 'appointments.php'], ['lawyers', 'Lawyer Management', 'lawyers.php'], ['customers', 'User Management', 'customers.php'], ['reports', 'Reporting & Analytics', 'reports.php'], ['content', 'Content Management', 'content.php']];
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).' | LegalEase Admin</title><link rel="stylesheet" href="/LegalEase_eProject/assets/css/admin.css"></head><body><div class="admin-layout"><aside class="admin-sidebar"><div class="admin-brand"><img src="/LegalEase_eProject/assets/images/logo.png" alt="LegalEase"><div><strong>LegalEase</strong><span>Administrator</span></div></div><nav class="admin-nav">';
    foreach($links as [$key, $label, $url])echo '<a class="'.($active===$key?'active':'').'" href="'.$url.'">'.e($label).'</a>';
    echo '<a href="/LegalEase_eProject/index.php">Home Page</a></nav><a class="admin-logout" href="/LegalEase_eProject/logout.php">Logout</a></aside><section class="admin-shell"><header class="admin-topbar"><span class="role">Admin Portal</span><div class="admin-user"><span>🔔</span><strong>'.e(user()['name']??'Administrator').'</strong></div></header><main class="admin-main"><div class="page-header"><div><h1>'.e($title).'</h1>'.($subtitle?'<p>'.e($subtitle).'</p>':'').'</div></div>';
    render_flash();
}
function admin_shell_end():void {
    echo '</main></section></div></body></html>';
}?>
