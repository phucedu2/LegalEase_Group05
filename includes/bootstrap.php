<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function money_usd(float|int|string $amount): string {
    return number_format((float)$amount, 0, ',', '.') . ' VND';
}
function avatar_url(?string $stored): string {
    $stored=trim((string)$stored);
    if($stored==='') return app_base_url().'/assets/images/logo.png';
    return app_base_url().'/'.ltrim($stored, '/');
}
function star_rating_html(float $rating): string {
    $rating=max(0, min(5, $rating));
    $full=(int)floor($rating);
    $partial=$rating-$full;
    $html='<span class="rating-stars" aria-label="'.e(number_format($rating, 1)).' out of 5 stars">';
    for($i=1;$i<=5;$i++) {
        if($i<=$full)$html.='<span class="star full">★</span>';
        elseif($i===$full+1 && $partial>0)$html.='<span class="star partial" style="--fill:'.round($partial*100).'%">★</span>';
        else $html.='<span class="star empty">★</span>';
    } return $html.'</span>';
}
function is_valid_email_strict(string $email): bool {
    if ($email === '' || strlen($email) > 254 || strpos($email, ' ') !== false) return false;
    if (substr_count($email, '@') !== 1) return false;
    [$local, $domain] = explode('@', $email);
    if ($local === '' || strlen($local) > 64) return false;
    if (!preg_match('/^[a-zA-Z0-9_%+-]+(\.[a-zA-Z0-9_%+-]+)*$/', $local)) return false;
    if ($domain === '' || strlen($domain) > 255) return false;
    if (!preg_match('/^[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)*\.[a-zA-Z]{2,}$/', $domain)) return false;
    foreach (explode('.', $domain) as $label) if (strlen($label) > 63) return false;
    return true;
}
function normalize_vn_phone(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return '';
    if (!preg_match('/^[0-9+\-\s()]+$/', $raw)) return null;
    $digits = preg_replace('/[\s\-()]+/', '', $raw);
    if (str_starts_with($digits, '+')) {
        if (!preg_match('/^\+84([0-9]{9})$/', $digits, $m)) return null;
        $local = '0' . $m[1];
    } elseif (($digits[0] ?? '') === '0') {
        if (!preg_match('/^0[0-9]{9}$/', $digits)) return null;
        $local = $digits;
    } else return null;
    return preg_match('/^0(3|5|7|8|9)[0-9]{8}$/', $local) === 1 ? $local : null;
}
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}
function is_post(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
}
function verify_csrf(): void {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
        http_response_code(419);
        exit('Invalid CSRF token. Please go back and try again.');
    }
}
function flash(string $type, string $message): void {
    $_SESSION['flash'] = [$type, $message];
}
function render_flash(): void {
    if (!empty($_SESSION['flash'])) {
        [$type, $message] = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="alert '.e($type).'">'.e($message).'</div>';
    }
}
function user(): ?array {
    return $_SESSION['user'] ?? null;
}
function require_login(): void {
    if (!user()) redirect('/LegalEase_eProject/login.php');
}
function require_role(string $role): void {
    require_login();
    if ((user()['role'] ?? '') !== $role) {
        http_response_code(403);
        exit('403 - Access denied');
    }
}
function current_lawyer_id(PDO $pdo): ?int {
    if (!user() || user()['role'] !== 'lawyer') return null;
    $s=$pdo->prepare('SELECT id FROM lawyer_profiles WHERE user_id=?');
    $s->execute([user()['id']]);
    return ($r=$s->fetch()) ? (int)$r['id'] : null;
}
function current_customer_id(PDO $pdo): ?int {
    if (!user() || user()['role'] !== 'customer') return null;
    $s=$pdo->prepare('SELECT id FROM customer_profiles WHERE user_id=?');
    $s->execute([user()['id']]);
    return ($r=$s->fetch()) ? (int)$r['id'] : null;
}
function notify(PDO $pdo, int $userId, string $message, string $type='System', ?string $linkUrl=null): void {
    $s=$pdo->prepare('INSERT INTO notifications(user_id,message,type,link_url) VALUES(?,?,?,?)');
    $s->execute([$userId, $message, $type, $linkUrl]);
}
function safe_notification_url(?string $url, string $fallback): string {
    if (!$url) return $fallback;
    return str_starts_with($url, app_base_url().'/') ? $url : $fallback;
}
function verification_document_url(?string $stored): string {
    $stored=trim((string)$stored);
    if($stored==='') return '';
    $stored=ltrim($stored, '/');
    if(!str_contains($stored, '/')) $stored='uploads/licenses/'.$stored;
    return app_base_url().'/'.$stored;
}
function process_unpaid_appointment_holds(PDO $pdo): void {
    try {
        $expired=$pdo->query("SELECT a.id,a.slot_id,cp.user_id customer_user_id,lp.user_id lawyer_user_id,p.id payment_id FROM appointments a JOIN payments p ON p.appointment_id=a.id JOIN customer_profiles cp ON cp.id=a.customer_id JOIN lawyer_profiles lp ON lp.id=a.lawyer_id WHERE a.status='Pending' AND p.status='Pending' AND a.payment_due_at IS NOT NULL AND a.payment_due_at<=NOW() LIMIT 100")->fetchAll();
        foreach($expired as $row) {
            try {
                $pdo->beginTransaction();
                $lock=$pdo->prepare("SELECT a.status,p.status payment_status FROM appointments a JOIN payments p ON p.appointment_id=a.id WHERE a.id=? FOR UPDATE");
                $lock->execute([(int)$row['id']]);
                $state=$lock->fetch();
                if($state && $state['status']==='Pending' && $state['payment_status']==='Pending') {
                    $pdo->prepare("UPDATE appointments SET status='Cancelled',updated_at=NOW() WHERE id=?")->execute([(int)$row['id']]);
                    $pdo->prepare("UPDATE payments SET status='Cancelled',updated_at=NOW() WHERE id=?")->execute([(int)$row['payment_id']]);
                    $pdo->prepare("UPDATE availability_slots SET is_available=1 WHERE id=?")->execute([(int)$row['slot_id']]);
                    notify($pdo, (int)$row['customer_user_id'], 'Appointment #'.$row['id'].' was automatically cancelled because payment was not completed within the 20-minute reservation period.', 'Reminder', '/LegalEase_eProject/customer/appointments.php');
                    notify($pdo, (int)$row['lawyer_user_id'], 'Appointment #'.$row['id'].' was released because the customer did not complete payment within 20 minutes.', 'System', '/LegalEase_eProject/lawyer/appointments.php');
                }
                $pdo->commit();
            }catch(Throwable $e) {
                if($pdo->inTransaction())$pdo->rollBack();
            }
        }
        $rem=$pdo->query("SELECT a.id,cp.user_id,a.payment_due_at FROM appointments a JOIN payments p ON p.appointment_id=a.id JOIN customer_profiles cp ON cp.id=a.customer_id WHERE a.status='Pending' AND p.status='Pending' AND a.payment_due_at>NOW() AND a.payment_reminder_sent_at IS NULL AND a.created_at<=DATE_SUB(NOW(),INTERVAL 10 MINUTE) LIMIT 100")->fetchAll();
        foreach($rem as $row) {
            $u=$pdo->prepare("UPDATE appointments SET payment_reminder_sent_at=NOW() WHERE id=? AND payment_reminder_sent_at IS NULL");
            $u->execute([(int)$row['id']]);
            if($u->rowCount()) notify($pdo, (int)$row['user_id'], 'Payment is still pending for appointment #'.$row['id'].'. Complete checkout before '.date('H:i', strtotime($row['payment_due_at'])).' to keep the reserved time slot.', 'Reminder', '/LegalEase_eProject/customer/checkout.php?appointment='.(int)$row['id']);
        }
    } catch(Throwable $e) {
    }
}
function process_pending_refunds(PDO $pdo): void {
    try {
        $rows=$pdo->query("SELECT r.id,r.payment_id,r.refund_amount,r.original_amount,cp.user_id customer_user_id FROM refunds r JOIN customer_profiles cp ON cp.id=r.customer_id WHERE r.status='Pending' AND r.created_at<=DATE_SUB(NOW(),INTERVAL 30 MINUTE) LIMIT 100")->fetchAll();
        foreach($rows as $r) {
            $pdo->beginTransaction();
            $u=$pdo->prepare("UPDATE refunds SET status='Processed',processed_at=NOW() WHERE id=? AND status='Pending'");
            $u->execute([(int)$r['id']]);
            if($u->rowCount()) {
                $newStatus=((float)$r['refund_amount']>=(float)$r['original_amount'])?'Refunded':'Partially Refunded';
                $pdo->prepare("UPDATE payments SET status=?,updated_at=NOW() WHERE id=?")->execute([$newStatus, (int)$r['payment_id']]);
                notify($pdo, (int)$r['customer_user_id'], 'Your refund of '.money_usd($r['refund_amount']).' has been returned to your account.', 'System', '/LegalEase_eProject/customer/payments.php');
            }
            $pdo->commit();
        }
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
    }
}
function process_appointment_lifecycle(PDO $pdo): void {
    try {
        // 1) Paid requests that reach the scheduled start without lawyer confirmation are cancelled and fully refunded.
        $rows=$pdo->query("SELECT a.id,a.slot_id,a.customer_id,cp.user_id customer_user_id,lp.user_id lawyer_user_id,p.id payment_id,p.amount FROM appointments a JOIN payments p ON p.appointment_id=a.id JOIN customer_profiles cp ON cp.id=a.customer_id JOIN lawyer_profiles lp ON lp.id=a.lawyer_id WHERE a.status='Pending' AND p.status='Success' AND TIMESTAMP(a.appointment_date,a.start_time)<=NOW() ORDER BY a.appointment_date,a.start_time LIMIT 100")->fetchAll();
        foreach($rows as $r) {
            try {
                $pdo->beginTransaction();
                $lock=$pdo->prepare("SELECT a.status,p.status payment_status FROM appointments a JOIN payments p ON p.appointment_id=a.id WHERE a.id=? FOR UPDATE");
                $lock->execute([(int)$r['id']]);
                $state=$lock->fetch();
                if($state && $state['status']==='Pending' && $state['payment_status']==='Success') {
                    $pdo->prepare("UPDATE appointments SET status='Cancelled',cancelled_by='lawyer',cancel_reason='Automatically cancelled - lawyer did not confirm before appointment time',notes=CONCAT(COALESCE(notes,''),' | Auto-cancelled because the paid request was not confirmed before the scheduled start.'),updated_at=NOW() WHERE id=?")->execute([(int)$r['id']]);
                    $pdo->prepare("UPDATE availability_slots SET is_available=1 WHERE id=?")->execute([(int)$r['slot_id']]);
                    $amount=(float)$r['amount'];
                    $pdo->prepare("INSERT INTO refunds(payment_id,appointment_id,customer_id,original_amount,cancellation_fee,refund_amount,status,reason,processed_at) VALUES(?,?,?,?,0,?,'Processed','Automatic full refund - lawyer did not confirm before appointment time',NOW()) ON DUPLICATE KEY UPDATE cancellation_fee=0,refund_amount=VALUES(refund_amount),status='Processed',reason=VALUES(reason),processed_at=NOW()")->execute([(int)$r['payment_id'], (int)$r['id'], (int)$r['customer_id'], $amount, $amount]);
                    $pdo->prepare("UPDATE payments SET status='Refunded',updated_at=NOW() WHERE id=?")->execute([(int)$r['payment_id']]);
                    notify($pdo, (int)$r['customer_user_id'], 'Appointment #'.$r['id'].' was automatically cancelled because the lawyer did not confirm it before the scheduled start. Your full payment of '.money_usd($amount).' has been refunded.', 'System', '/LegalEase_eProject/customer/payments.php');
                    notify($pdo, (int)$r['lawyer_user_id'], 'Appointment #'.$r['id'].' was automatically cancelled because it was not confirmed before the scheduled start. The customer received a 100% refund.', 'System', '/LegalEase_eProject/lawyer/appointments.php');
                }
                $pdo->commit();
            }catch(Throwable $e) {
                if($pdo->inTransaction())$pdo->rollBack();
            }
        }
        // 2) Confirmed appointments are automatically completed seven days after their scheduled end.
        $rows=$pdo->query("SELECT a.id,cp.user_id customer_user_id,lp.user_id lawyer_user_id,p.amount FROM appointments a JOIN payments p ON p.appointment_id=a.id JOIN customer_profiles cp ON cp.id=a.customer_id JOIN lawyer_profiles lp ON lp.id=a.lawyer_id WHERE a.status='Confirmed' AND p.status='Success' AND TIMESTAMP(a.appointment_date,a.end_time)<=DATE_SUB(NOW(),INTERVAL 7 DAY) ORDER BY a.appointment_date,a.end_time LIMIT 100")->fetchAll();
        foreach($rows as $r) {
            $u=$pdo->prepare("UPDATE appointments SET status='Completed',notes=CONCAT(COALESCE(notes,''),' | Automatically completed 7 days after the scheduled consultation end.'),updated_at=NOW() WHERE id=? AND status='Confirmed'");
            $u->execute([(int)$r['id']]);
            if($u->rowCount()) {
                notify($pdo, (int)$r['customer_user_id'], 'Appointment #'.$r['id'].' was automatically marked completed because 7 days passed after the confirmed consultation.', 'System', '/LegalEase_eProject/customer/appointments.php');
                notify($pdo, (int)$r['lawyer_user_id'], 'Appointment #'.$r['id'].' was automatically marked completed 7 days after the scheduled consultation. Your 80% income is '.money_usd((float)$r['amount']*.80).'.', 'System', '/LegalEase_eProject/lawyer/dashboard.php');
            }
        }
        // 3) One reminder per day for every future paid appointment still awaiting lawyer confirmation.
        $rows=$pdo->query("SELECT a.id,a.appointment_date,a.start_time,lp.user_id lawyer_user_id FROM appointments a JOIN payments p ON p.appointment_id=a.id JOIN lawyer_profiles lp ON lp.id=a.lawyer_id WHERE a.status='Pending' AND p.status='Success' AND TIMESTAMP(a.appointment_date,a.start_time)>NOW() ORDER BY a.appointment_date,a.start_time LIMIT 200")->fetchAll();
        foreach($rows as $r) {
            $needle='Paid appointment #'.(int)$r['id'].' is waiting for your confirmation.';
            $q=$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND type='Reminder' AND DATE(created_at)=CURDATE() AND message LIKE ?");
            $q->execute([(int)$r['lawyer_user_id'], $needle.'%']);
            if(!(int)$q->fetchColumn()) {
                $when=date('d M Y H:i', strtotime($r['appointment_date'].' '.$r['start_time']));
                notify($pdo, (int)$r['lawyer_user_id'], $needle.' Scheduled for '.$when.'. Please confirm it before the appointment time; otherwise it will be cancelled automatically and the customer will receive a 100% refund.', 'Reminder', '/LegalEase_eProject/lawyer/appointments.php');
            }
        }
    } catch(Throwable $e) {
    }
}
function app_base_url(): string {
    return '/LegalEase_eProject';
}
function send_app_email(PDO $pdo, string $to, string $subject, string $body): bool {
    $headers = "MIME-Version: 1.0
Content-type: text/html; charset=UTF-8
From: LegalEase <no-reply@legalease.local>
";
    $sent = false;
    if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $sent = @mail($to, $subject, $body, $headers);
    }
    try {
        $stmt=$pdo->prepare('INSERT INTO email_outbox(recipient,subject,body,delivery_status) VALUES(?,?,?,?)');
        $stmt->execute([$to, $subject, $body, $sent?'Sent':'Queued']);
    } catch (Throwable $e) {
    }
    return $sent;
}
function confirmation_ui(): string {
    return '<div class="confirm-modal-backdrop" id="confirmBackdrop"><div class="confirm-modal"><h3>Confirm action</h3><p id="confirmMessage">Are you sure you want to continue?</p><div class="confirm-modal-actions"><button type="button" class="btn secondary" id="confirmNo">Go back</button><button type="button" class="btn danger" id="confirmYes">Continue</button></div></div></div>';
}
function global_interaction_script(): string {
    return '<script>(function(){let pending=null;const b=document.getElementById("confirmBackdrop"),msg=document.getElementById("confirmMessage"),yes=document.getElementById("confirmYes"),no=document.getElementById("confirmNo");function ask(el,text){pending=el;msg.textContent=text||"Are you sure you want to continue?";b.classList.add("open");}document.addEventListener("click",function(e){const el=e.target.closest("[data-confirm]");if(!el)return;e.preventDefault();ask(el,el.dataset.confirm);});document.addEventListener("submit",function(e){const f=e.target;if(f.dataset.confirmed==="1")return;const submitter=e.submitter;const text=(submitter&&submitter.dataset.confirm)||f.dataset.confirm;if(text){e.preventDefault();pending=f;ask(f,text);}});yes&&yes.addEventListener("click",function(){b.classList.remove("open");if(!pending)return;if(pending.tagName==="FORM"){pending.dataset.confirmed="1";pending.submit();}else if(pending.href){location.href=pending.href;}pending=null;});no&&no.addEventListener("click",function(){b.classList.remove("open");pending=null;});b&&b.addEventListener("click",function(e){if(e.target===b){b.classList.remove("open");pending=null;}});})();</script>';
}
function page_header(string $title, string $area='public'): void {
    global $pdo;
    $u=user();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    $cssVer=@filemtime(__DIR__.'/../assets/css/app.css')?:time();
    echo '<title>'.e($title).' | LegalEase</title><link rel="stylesheet" href="/LegalEase_eProject/assets/css/app.css?v='.$cssVer.'"></head><body>';
    echo '<header class="topbar"><a class="brand" href="/LegalEase_eProject/index.php"><span>⚖</span> LegalEase</a><nav>';
    echo '<a href="/LegalEase_eProject/index.php">Home</a><a href="/LegalEase_eProject/public/lawyers.php">Find Lawyers</a><a href="/LegalEase_eProject/public/faq.php">FAQ</a><a href="/LegalEase_eProject/public/about.php">About Us</a>';
    if (!$u) {
        echo '<a href="/LegalEase_eProject/login.php">Login</a><a class="btn small register-link" href="/LegalEase_eProject/register.php">Register</a>';
    } elseif (($u['role']??'')==='customer') {
        $name=$u['name']??'Customer';
        try {
            $q=$pdo->prepare('SELECT full_name,avatar_file FROM customer_profiles WHERE user_id=?');
            $q->execute([(int)$u['id']]);
            $r=$q->fetch();
            if($r&&$r['full_name'])$name=$r['full_name'];
        } catch(Throwable $e) {
            $r=[];
        }
        $avatar=avatar_url($r['avatar_file']??'');
        echo '<div class="public-customer-menu"><button type="button" class="public-customer-trigger" id="publicCustomerTrigger" aria-haspopup="true" aria-expanded="false"><img class="public-customer-avatar" src="'.e($avatar).'" alt="Avatar"><span>'.e($name).'</span><span class="chevron">▾</span></button><div class="public-customer-dropdown" id="publicCustomerDropdown"><a href="/LegalEase_eProject/customer/dashboard.php"><span>▦</span><div><b>Your profile</b><small>Open your customer dashboard</small></div></a><a href="/LegalEase_eProject/customer/notifications.php"><span>🔔</span><div><b>Notifications</b><small>View account and appointment updates</small></div></a><a class="logout-item" href="/LegalEase_eProject/logout.php"><span>↪</span><div><b>Logout</b><small>Sign out securely</small></div></a></div></div>';
    } else {
        $dash = ($u['role']??'')==='admin'?'/LegalEase_eProject/admin/dashboard.php':'/LegalEase_eProject/lawyer/dashboard.php';
        echo '<a href="'.$dash.'">Dashboard</a><a href="/LegalEase_eProject/logout.php">Logout</a>';
    }
    echo '</nav></header>';
    if ($area !== 'public') {
        echo '<div class="app-shell">'.sidebar($area).'<main class="content"><div class="page-head"><div><p class="eyebrow">'.e(ucfirst($area)).' Portal</p><h1>'.e($title).'</h1></div></div>';
    } else echo '<main>';
}
function page_footer(string $area='public'): void {
    if ($area !== 'public') echo '</main></div>';
    else echo '</main>';
    echo '<footer class="site-footer"><div class="site-footer-main"><div class="site-footer-grid">';
    echo '<section class="footer-about"><div class="footer-brand"><span>⚖</span><b>LegalEase</b></div><p>LegalEase is an online platform connecting clients with lawyers, delivering reliable and fast legal solutions for everyone.</p><p class="footer-hours"><b>Business Hours:</b> 08:00 AM – 06:00 PM (Monday – Saturday)</p><div class="footer-social footer-social-icons"><a href="#" aria-label="Facebook" title="Facebook"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 8h3V4h-3c-3.3 0-5 2-5 5v2H6v4h3v7h4v-7h3.2l.8-4H13V9c0-.7.3-1 1-1z"/></svg></a><a href="#" aria-label="WhatsApp" title="WhatsApp"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a9.7 9.7 0 0 0-8.3 14.7L2.4 22l5.5-1.4A9.8 9.8 0 1 0 12 2zm0 17.6a7.6 7.6 0 0 1-3.9-1.1l-.3-.2-3.3.9.9-3.2-.2-.3A7.7 7.7 0 1 1 12 19.6zm4.2-5.7c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.6.1l-.7.8c-.1.2-.3.2-.5.1-1.3-.6-2.4-1.6-3.2-2.8-.2-.3 0-.4.1-.6l.5-.6c.1-.2.1-.4 0-.6l-.7-1.7c-.1-.3-.3-.3-.5-.3h-.5c-.2 0-.5.1-.7.3-.7.7-1.1 1.6-1 2.6.1 1.1.8 2.5 1 2.8 1.2 2 3 3.6 5.2 4.5.7.3 1.3.5 1.8.6.8.2 1.6.1 2.2-.1.7-.2 1.4-1.1 1.5-1.7.2-.5.2-1 .1-1.1-.1-.2-.2-.2-.4-.3z"/></svg></a><a href="#" aria-label="Telegram" title="Telegram"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21.7 3.4 18.5 20c-.2 1.2-.9 1.5-1.8.9l-4.9-3.6-2.4 2.3c-.3.3-.5.5-1 .5l.3-5 9.2-8.3c.4-.4-.1-.6-.6-.2L6 13.7 1.1 12.2c-1.1-.3-1.1-1.1.2-1.6L20.4 3c.9-.3 1.6.2 1.3.4z"/></svg></a></div></section>';
    echo '<section class="footer-office"><h3>LEGALEASE - HEAD OFFICE</h3><ul class="footer-contact"><li><span>📍</span><span>86A Nguyễn Hồng, Phường Hạnh Thông, TP. Hồ Chí Minh</span></li><li><span>☎</span><a href="tel:19002026">19002026</a></li><li><span>✉</span><a href="mailto:info@legalease.com">info@legalease.com</a></li><li><span>🕘</span><span>08:00 AM – 06:00 PM, Monday – Saturday. (Closed on Sunday)</span></li></ul><a class="footer-directions" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=86A+Nguyen+Hong+Hanh+Thong+Ho+Chi+Minh">Get Directions</a><div class="footer-map"><iframe title="LegalEase Head Office Map" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="https://www.google.com/maps?q=86A%20Nguyen%20Hong%20Hanh%20Thong%20Ho%20Chi%20Minh&output=embed"></iframe></div></section>';
    echo '<section class="footer-links"><h3>QUICK LINKS</h3><a href="/LegalEase_eProject/index.php">Home</a><a href="/LegalEase_eProject/public/lawyers.php">Find Lawyers</a><a href="/LegalEase_eProject/public/faq.php">FAQ</a><a href="/LegalEase_eProject/public/about.php">About Us</a><a href="/LegalEase_eProject/register.php">Register</a><a href="/LegalEase_eProject/login.php">Login</a></section>';
    echo '</div><div class="footer-sitemap-row"><a class="footer-sitemap-btn" href="/LegalEase_eProject/public/sitemap.php">VIEW SITEMAP</a></div></div><div class="site-footer-bottom">© 2026 Copyright by LegalEase. All rights reserved.</div></footer>';
    echo confirmation_ui().global_interaction_script();
    echo '<script>(function(){const t=document.getElementById("publicCustomerTrigger"),d=document.getElementById("publicCustomerDropdown");if(!t||!d)return;t.addEventListener("click",function(e){e.stopPropagation();const open=d.classList.toggle("open");t.setAttribute("aria-expanded",open?"true":"false")});d.addEventListener("click",e=>e.stopPropagation());document.addEventListener("click",()=>{d.classList.remove("open");t.setAttribute("aria-expanded","false")});})();</script></body></html>';
}
function sidebar(string $area): string {
    $links = [
    'customer'=>[
    ['/LegalEase_eProject/customer/dashboard.php', 'Dashboard'], ['/LegalEase_eProject/public/lawyers.php', 'Search Lawyers'], ['/LegalEase_eProject/customer/appointments.php', 'My Appointments'], ['/LegalEase_eProject/customer/notifications.php', 'Notifications'], ['/LegalEase_eProject/customer/profile.php', 'My Profile']],
    'lawyer'=>[
    ['/LegalEase_eProject/lawyer/dashboard.php', 'Dashboard'], ['/LegalEase_eProject/lawyer/profile.php', 'Profile'], ['/LegalEase_eProject/lawyer/verification.php', 'Verification'], ['/LegalEase_eProject/lawyer/schedule.php', 'Work Schedule'], ['/LegalEase_eProject/lawyer/appointments.php', 'Appointments']],
    'admin'=>[
    ['/LegalEase_eProject/admin/dashboard.php', 'Dashboard'], ['/LegalEase_eProject/admin/lawyers.php', 'Manage Lawyers'], ['/LegalEase_eProject/admin/customers.php', 'Manage Customers'], ['/LegalEase_eProject/admin/appointments.php', 'Appointments'], ['/LegalEase_eProject/admin/content.php', 'Content'], ['/LegalEase_eProject/admin/reports.php', 'Reports']]];
    $html='<aside class="sidebar"><div class="side-title">'.e(ucfirst($area)).'</div>';
    foreach($links[$area]??[] as [$url, $label]) $html.='<a href="'.$url.'">'.e($label).'</a>';
    $html.='<a href="/LegalEase_eProject/logout.php">Logout</a></aside>';
    return $html;
}
try {
    process_unpaid_appointment_holds($pdo);
    process_pending_refunds($pdo);
    process_appointment_lifecycle($pdo);
} catch (Throwable $e) {
}
function paginate(int $total, int $page, int $perPage, string $base): string {
    $pages=max(1, (int)ceil($total/$perPage));
    if($pages<=1) return '';
    $page=max(1, min($page, $pages));
    $sep=str_contains($base, '?')?'&':'?';
    $url=fn(int $p)=>e($base).$sep.'page='.$p;
    $items=[];
    if($pages<=7) {
        for($i=1;$i<=$pages;$i++) $items[]=$i;
    }
    else {
        $items=[1];
        $start=max(2, $page-2);
        $end=min($pages-1, $page+2);
        if($start>2) $items[]='…';
        for($i=$start;$i<=$end;$i++) $items[]=$i;
        if($end<$pages-1) $items[]='…';
        $items[]=$pages;
    }
    $html='<nav class="pagination professional-pagination" aria-label="Pagination">';
    $html.='<a class="page-nav '.($page<=1?'disabled':'').'" '.($page>1?'href="'.$url($page-1).'"':'aria-disabled="true"').'>‹</a>';
    foreach($items as $it) {
        if($it==='…') $html.='<span class="page-ellipsis">…</span>';
        else $html.='<a class="'.((int)$it===$page?'active':'').'" href="'.$url((int)$it).'">'.(int)$it.'</a>';
    }
    $html.='<a class="page-nav '.($page>=$pages?'disabled':'').'" '.($page<$pages?'href="'.$url($page+1).'"':'aria-disabled="true"').'>›</a>';
    return $html.'</nav>';
}
