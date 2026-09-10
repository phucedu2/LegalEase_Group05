<?php
require_once __DIR__ . '/bootstrap.php';
function lawyer_badge_class(string $status): string {
    $s=strtolower($status);
    return match($s) {
        'pending'=>'badge-pending', 'confirmed'=>'badge-confirmed', 'completed'=>'badge-completed', 'cancelled', 'rejected'=>'badge-cancelled', 'approved', 'available'=>'badge-approved', 'new'=>'badge-new', 'answered'=>'badge-answered', default=>'badge-neutral'
    };
}
function lawyer_shell_start(string $title, string $subtitle=''):void {
    $current=basename($_SERVER['PHP_SELF']);
    $uid=(int)(user()['id']??0);
    global $pdo;
    $notifications=[];
    $profile=null;
    $n=0;
    if($uid) {
        $s=$pdo->prepare('SELECT id,message,type,link_url,is_read,created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 30');
        $s->execute([$uid]);
        $notifications=$s->fetchAll();
        foreach($notifications as $x)if(!$x['is_read'])$n++;
        $p=$pdo->prepare('SELECT lp.id,lp.avatar_file,lp.full_name,lp.is_verified,u.email FROM lawyer_profiles lp JOIN users u ON u.id=lp.user_id WHERE lp.user_id=?');
        $p->execute([$uid]);
        $profile=$p->fetch();
    }
    $links=[['dashboard.php', '◔', 'Dashboard', ['dashboard.php']], ['schedule.php', '▦', 'Work Schedule', ['schedule.php']], ['appointments.php', '▣', 'Appointments', ['appointments.php', 'appointment.php', 'appointment_detail.php']], ['profile.php', '◉', 'Personal Profile', ['profile.php', 'license.php', 'verification.php']],];
    $avatar=avatar_url($profile['avatar_file']??'');
    $cssVer=@filemtime(__DIR__.'/../assets/css/lawyer.css')?:time();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).' | LegalEase</title><link rel="stylesheet" href="/LegalEase_eProject/assets/css/lawyer.css?v='.$cssVer.'"></head><body>';
    echo '<div class="lawyer-layout"><aside class="sidebar"><div class="brand"><div class="brand-mark">⚖</div><div><strong>LegalEase</strong><span>Lawyer Partner</span></div></div><nav class="sidebar-nav">';
    foreach($links as [$url, $icon, $label, $pages]) {
        $active=in_array($current, $pages, true)?'active':'';
        echo '<a class="'.$active.'" href="'.$url.'"><span>'.$icon.'</span>'.e($label).'</a>';
    }
    echo '<a href="/LegalEase_eProject/index.php"><span>⌂</span> Home Page</a></nav><a class="logout-btn" href="/LegalEase_eProject/logout.php">↪ Logout</a></aside>';
    echo '<section class="content-shell"><header class="topbar"><div></div><div class="topbar-actions">';
    echo '<div class="topbar-menu"><button type="button" class="icon-button" data-menu="notificationMenu" aria-label="Notifications">🔔'.($n?'<b>'.$n.'</b>':'').'</button><div class="dropdown-panel notification-panel" id="notificationMenu"><div class="dropdown-title"><strong>Notifications</strong><span>'.$n.' unread</span></div><div class="notification-filter-tabs"><button type="button" class="note-filter active" data-note-filter="all">All</button><button type="button" class="note-filter" data-note-filter="unread">Unread</button></div><div class="notification-scroll">';
    if(!$notifications)echo '<div class="dropdown-empty">No notifications.</div>';
    else foreach($notifications as $x)echo '<a data-note-state="'.($x['is_read']?'read':'unread').'" class="notification-entry '.(!$x['is_read']?'unread':'').'" href="/LegalEase_eProject/lawyer/notification_open.php?id='.(int)$x['id'].'"><span>'.e($x['message']).'</span><small>'.e(date('d M, H:i', strtotime($x['created_at']))).'</small></a>';
    echo '</div><a class="notification-view-all" href="/LegalEase_eProject/lawyer/notifications.php">View all notifications</a></div></div><span class="topbar-divider"></span>';
    echo '<div class="topbar-menu profile-menu-wrap"><button type="button" class="profile-trigger" data-menu="profileMenu">'.'<img src="'.e($avatar).'" alt="Profile">'.'<span>'.e($profile['full_name']??user()['name']??'Lawyer').'</span><span class="chevron">⌄</span></button><div class="dropdown-panel profile-dropdown" id="profileMenu"><div class="profile-dropdown-head">'.'<img src="'.e($avatar).'" alt="Profile">'.'<div><strong>'.e($profile['full_name']??'Lawyer').'</strong><small>'.e($profile['email']??'').'</small><span class="verification-pill">'.(!empty($profile['is_verified'])?'Verified':'Verification pending').'</span></div></div><a href="/LegalEase_eProject/lawyer/profile.php">Manage profile</a><a href="/LegalEase_eProject/lawyer/notifications.php">Notifications</a><a href="/LegalEase_eProject/logout.php">Logout</a></div></div></div></header><main class="main-content">';
    echo '<h1 class="page-title">'.e($title).'</h1>'.($subtitle?'<p class="page-subtitle">'.e($subtitle).'</p>':'');
    render_flash();
}
function lawyer_shell_end():void {
    echo '</main></section></div><div class="confirm-modal-backdrop" id="confirmBackdrop"><div class="confirm-modal"><h3>Confirm action</h3><p id="confirmMessage">Are you sure you want to continue?</p><div class="confirm-modal-actions"><button type="button" class="btn btn-light" id="confirmNo">Go back</button><button type="button" class="btn btn-danger" id="confirmYes">Continue</button></div></div></div><script>(function(){document.addEventListener("click",function(e){const btn=e.target.closest("[data-menu]");document.querySelectorAll(".dropdown-panel.open").forEach(p=>{if(!btn||p.id!==btn.dataset.menu)p.classList.remove("open")});if(btn){e.preventDefault();e.stopPropagation();const p=document.getElementById(btn.dataset.menu);if(p)p.classList.toggle("open")}});document.querySelectorAll("[data-note-filter]").forEach(btn=>btn.addEventListener("click",e=>{e.stopPropagation();document.querySelectorAll("[data-note-filter]").forEach(x=>x.classList.remove("active"));btn.classList.add("active");const mode=btn.dataset.noteFilter;document.querySelectorAll("#notificationMenu [data-note-state]").forEach(x=>x.style.display=(mode==="all"||x.dataset.noteState==="unread")?"flex":"none")}));let pending=null,pendingSubmitter=null;const b=document.getElementById("confirmBackdrop"),m=document.getElementById("confirmMessage"),y=document.getElementById("confirmYes"),n=document.getElementById("confirmNo");document.addEventListener("submit",function(e){const t=e.submitter,msg=(t&&t.dataset.confirm)||e.target.dataset.confirm;if(!msg||e.target.dataset.confirmed==="1")return;e.preventDefault();pending=e.target;pendingSubmitter=t||null;m.textContent=msg;b.classList.add("open")});document.addEventListener("click",function(e){const a=e.target.closest("a[data-confirm]");if(!a)return;e.preventDefault();pending=a;pendingSubmitter=null;m.textContent=a.dataset.confirm;b.classList.add("open")});y.onclick=()=>{b.classList.remove("open");if(!pending)return;if(pending.tagName==="FORM"){pending.dataset.confirmed="1";pending.requestSubmit(pendingSubmitter)}else if(pending.href)location.href=pending.href;pending=null;pendingSubmitter=null};n.onclick=()=>{b.classList.remove("open");pending=null;pendingSubmitter=null};})();</script></body></html>';
}?>
