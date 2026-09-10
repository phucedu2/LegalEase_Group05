<?php
require_once __DIR__.'/includes/bootstrap.php';
if(user())redirect('/LegalEase_eProject/index.php');
$message='';
$error='';
if(is_post()) {
    verify_csrf();
    $email=strtolower(trim($_POST['email']??''));
    if(!filter_var($email, FILTER_VALIDATE_EMAIL)) $error='Please enter a valid email address.';
    else {
        $s=$pdo->prepare('SELECT id,name,email,is_active FROM users WHERE email=? LIMIT 1');
        $s->execute([$email]);
        $u=$s->fetch();
        if(!$u) $error='Tài khoản không tồn tại, vui lòng nhập đúng email hoặc đăng kí tài khoản mới.';
        elseif(!$u['is_active']) $error='This account is inactive. Please contact LegalEase support.';
        else {
            $token=bin2hex(random_bytes(32));
            $hash=hash('sha256', $token);
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=? OR expires_at<NOW()')->execute([$u['id']]);
            $pdo->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))')->execute([$u['id'], $hash]);
            $link='http://localhost/LegalEase_eProject/reset_password.php?token='.urlencode($token);
            send_app_email($pdo, $email, 'LegalEase password reset', '<h2>Password reset request</h2><p>Hello '.e($u['name']).',</p><p>Use the secure link below within 30 minutes to choose a new password:</p><p><a href="'.e($link).'">Reset my password</a></p><p>If you did not request a password reset, you can ignore this email.</p>');
            $message='A password reset link has been sent/queued for your registered email. The link expires in 30 minutes.';
        }
    }
}
page_header('Forgot Password');
?>
<div class="login-page">
<div class="login-card">
<h1>Forgot password</h1>
<p class="muted">Enter the email address registered with your LegalEase account.</p><?php
if($error):?><div class="alert error"><?=e($error)?></div><?php
endif?><?php
if($message):?><div class="alert success"><?=e($message)?></div><?php
endif?><form method="post"><?=csrf_field()?><div class="field">
<label>Email</label>
<input type="email" name="email" required>
</div>
<div class="actions">
<button>Send Reset Link</button>
<a class="btn secondary" href="/LegalEase_eProject/login.php">Back to Login</a>
</div>
</form>
</div>
</div>
<?php
page_footer();
?>
