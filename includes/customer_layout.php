<?php
require_once __DIR__ . '/bootstrap.php';
function customer_badge(string $status): string {
    $s = strtolower($status);
    return match ($s) {
        'pending', 'refund pending' => 'badge-pending', 'confirmed', 'success', 'active' => 'badge-confirmed', 'completed' => 'badge-completed', 'cancelled', 'failed', 'inactive' => 'badge-cancelled', 'refunded', 'partially refunded' => 'badge-completed', default => 'badge-neutral'
    };
}
function customer_shell_start(string $title, string $subtitle = ''): void {
    global $pdo;
    $current = basename($_SERVER['PHP_SELF']);
    $uid = (int) (user()['id'] ?? 0);
    $n = 0;
    $notes = [];
    $profile = null;
    if ($uid) {
        $s = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
        $s->execute([$uid]);
        $n = (int) $s->fetchColumn();
        $s = $pdo->prepare('SELECT id,message,type,link_url,is_read,created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 30');
        $s->execute([$uid]);
        $notes = $s->fetchAll();
        $s = $pdo->prepare('SELECT cp.full_name,cp.phone,cp.address,cp.avatar_file,u.email FROM customer_profiles cp JOIN users u ON u.id=cp.user_id WHERE cp.user_id=?');
        $s->execute([$uid]);
        $profile = $s->fetch();
    }
    $links = [['dashboard.php', '◔', 'Dashboard'], ['/LegalEase_eProject/public/lawyers.php', '⌕', 'Find Lawyer'], ['appointments.php', '▣', 'Appointments'], ['payments.php', '$', 'Payments'], ['reviews.php', '★', 'Reviews'], ['profile.php', '◉', 'Your Profile'], ['notifications.php', '🔔', 'Notifications']];
    $cssVer = @filemtime(__DIR__ . '/../assets/css/customer.css') ?: time();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . ' | LegalEase</title><link rel="stylesheet" href="/LegalEase_eProject/assets/css/customer.css?v=' . $cssVer . '"></head><body><div class="customer-layout"><aside class="customer-sidebar"><a class="customer-brand" href="/LegalEase_eProject/customer/dashboard.php" style="text-decoration:none"><div class="mark">⚖</div><div><strong>LegalEase</strong><span>Customer Portal</span></div></a><nav class="customer-nav">';
    foreach ($links as [$url, $icon, $label]) {
        $file = basename(parse_url($url, PHP_URL_PATH));
        $active = $current === $file ? 'active' : '';
        echo '<a class="' . $active . '" href="' . $url . '"><span>' . $icon . '</span>' . e($label) . '</a>';
    }
    echo '<a href="/LegalEase_eProject/index.php"><span>⌂</span> Home Page</a></nav><a class="customer-logout" href="/LegalEase_eProject/logout.php">↪ Logout</a></aside><section class="customer-shell"><header class="customer-topbar"><nav class="customer-public-nav"><a href="/LegalEase_eProject/index.php">Home</a><a href="/LegalEase_eProject/public/lawyers.php">Find Lawyers</a><a href="/LegalEase_eProject/public/faq.php">FAQ</a><a href="/LegalEase_eProject/public/about.php">About Us</a></nav><div class="customer-user">';
    echo '<div style="position:relative"><button type="button" class="customer-bell-btn" id="customerBell" aria-label="Notifications">🔔' . ($n ? '<span class="bell"><b>' . $n . '</b></span>' : '') . '</button><div class="customer-dropdown notification-smart" id="customerNotifications"><div class="notification-drop-head"><strong>Notifications</strong><span>' . $n . ' unread</span></div><div class="notification-filter-tabs"><button type="button" class="drop-filter active" data-note-filter="all">All</button><button type="button" class="drop-filter" data-note-filter="unread">Unread</button></div><div class="notification-drop-list">';
    if (!$notes) echo '<div class="drop-item muted">No notifications yet.</div>';
    else foreach ($notes as $note) echo '<a data-note-state="' . ($note['is_read'] ? 'read' : 'unread') . '" class="drop-item ' . (!$note['is_read'] ? 'unread' : '') . '" href="/LegalEase_eProject/customer/notification_open.php?id=' . (int) $note['id'] . '"><b>' . e($note['type']) . '</b><br><span>' . e($note['message']) . '</span><br><small class="muted">' . e(date('d M Y H:i', strtotime($note['created_at']))) . '</small></a>';
    echo '</div><a class="drop-item view-all" href="/LegalEase_eProject/customer/notifications.php"><b>View all notifications</b></a></div></div><span class="divider"></span>';
    $initial = e(strtoupper(substr($profile['full_name'] ?? user()['name'] ?? 'C', 0, 1)));
    $customerAvatar=avatar_url($profile['avatar_file']??'');
    echo '<div style="position:relative"><button type="button" class="customer-profile-btn" id="customerProfile"><img class="customer-top-avatar" src="'.e($customerAvatar).'" alt="Avatar"><strong>' . e(user()['name'] ?? 'Customer') . '</strong> ▾</button><div class="customer-dropdown customer-profile-card" id="customerProfileMenu"><div class="mini-profile"><img class="customer-top-avatar" src="'.e($customerAvatar).'" alt="Avatar"><div><b>' . e($profile['full_name'] ?? user()['name'] ?? 'Customer') . '</b><br><small>' . e($profile['email'] ?? user()['email'] ?? '') . '</small></div></div><a href="/LegalEase_eProject/customer/profile.php">Your profile</a><a href="/LegalEase_eProject/customer/notifications.php">Notifications</a><a href="/LegalEase_eProject/logout.php">Logout</a></div></div></div></header><main class="customer-main"><h1 class="page-title">' . e($title) . '</h1>' . ($subtitle ? '<p class="page-subtitle">' . e($subtitle) . '</p>' : '');
    if (!empty($_SESSION['flash'])) {
        [$type, $msg] = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="alert ' . ($type === 'success' ? 'alert-success' : 'alert-error') . '">' . e($msg) . '</div>';
    }
}
function customer_shell_end(): void {
    echo '</main></section></div><div class="confirm-modal-backdrop" id="confirmBackdrop"><div class="confirm-modal"><h3>Confirm action</h3><p id="confirmMessage">Are you sure you want to continue?</p><div class="confirm-modal-actions"><button type="button" class="btn btn-light" id="confirmNo">Go back</button><button type="button" class="btn btn-danger" id="confirmYes">Continue</button></div></div></div><script>(function(){const bell=document.getElementById("customerBell"),nd=document.getElementById("customerNotifications"),prof=document.getElementById("customerProfile"),pd=document.getElementById("customerProfileMenu");bell&&bell.addEventListener("click",e=>{e.stopPropagation();nd.classList.toggle("open");pd&&pd.classList.remove("open")});prof&&prof.addEventListener("click",e=>{e.stopPropagation();pd.classList.toggle("open");nd&&nd.classList.remove("open")});document.querySelectorAll("[data-note-filter]").forEach(btn=>btn.addEventListener("click",e=>{e.stopPropagation();document.querySelectorAll("[data-note-filter]").forEach(x=>x.classList.remove("active"));btn.classList.add("active");const mode=btn.dataset.noteFilter;document.querySelectorAll("#customerNotifications [data-note-state]").forEach(x=>x.style.display=(mode==="all"||x.dataset.noteState==="unread")?"block":"none")}));document.addEventListener("click",()=>{nd&&nd.classList.remove("open");pd&&pd.classList.remove("open")});let pending=null,pendingSubmitter=null;const b=document.getElementById("confirmBackdrop"),m=document.getElementById("confirmMessage"),y=document.getElementById("confirmYes"),n=document.getElementById("confirmNo");document.addEventListener("submit",function(e){const t=e.submitter,msg=(t&&t.dataset.confirm)||e.target.dataset.confirm;if(!msg||e.target.dataset.confirmed==="1")return;e.preventDefault();pending=e.target;pendingSubmitter=t||null;m.textContent=msg;b.classList.add("open")});document.addEventListener("click",function(e){const a=e.target.closest("a[data-confirm]");if(!a)return;e.preventDefault();pending=a;pendingSubmitter=null;m.textContent=a.dataset.confirm;b.classList.add("open")});y.onclick=()=>{b.classList.remove("open");if(!pending)return;if(pending.tagName==="FORM"){pending.dataset.confirmed="1";pending.requestSubmit(pendingSubmitter)}else if(pending.href)location.href=pending.href;pending=null;pendingSubmitter=null};n.onclick=()=>{b.classList.remove("open");pending=null;pendingSubmitter=null};})();</script></body></html>';
}?>
