<?php
require_once __DIR__.'/includes/bootstrap.php';
$token=$_GET['token']??$_POST['token']??'';
$error='';
$valid=false;
$row=null;
if(preg_match('/^[a-f0-9]{64}$/', $token)) {
    $hash=hash('sha256', $token);
    $s=$pdo->prepare('SELECT prt.id,prt.user_id,u.email,u.name FROM password_reset_tokens prt JOIN users u ON u.id=prt.user_id WHERE prt.token_hash=? AND prt.used_at IS NULL AND prt.expires_at>NOW() LIMIT 1');
    $s->execute([$hash]);
    $row=$s->fetch();
    $valid=(bool)$row;
}
if(is_post()&&$valid) {
    verify_csrf();
    $password=$_POST['password']??'';
    $confirm=$_POST['confirm_password']??'';
    if(strlen($password)<8||!preg_match('/[A-Za-z]/', $password)||!preg_match('/\d/', $password))$error='Password must contain at least 8 characters with letters and numbers.';
    elseif($password!==$confirm)$error='Password confirmation does not match.';
    else {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE id=?')->execute([$row['id']]);
        $pdo->commit();
        send_app_email($pdo, $row['email'], 'LegalEase password changed', '<p>Hello '.e($row['name']).',</p><p>Your LegalEase password was changed successfully.</p>');
        flash('success', 'Password reset successful. You can now sign in.');
        redirect('/LegalEase_eProject/login.php');
    }
}
page_header('Reset Password');
?>
<div class="login-page">
<div class="login-card">
<h1>Reset password</h1><?php
if(!$valid):?><div class="alert error">This password reset link is invalid, expired, or already used.</div>
<a class="btn" href="/LegalEase_eProject/forgot_password.php">Request a new link</a><?php
else:?><?php
if($error):?><div class="alert error"><?=e($error)?></div><?php
endif?><form method="post"><?=csrf_field()?><input type="hidden" name="token" value="<?=e($token)?>">
<div class="field">
<label>New password</label>
<input type="password" name="password" minlength="8" required>
</div>
<div class="field">
<label>Confirm new password</label>
<input type="password" name="confirm_password" minlength="8" required>
</div>
<div class="actions">
<button>Update Password</button>
</div>
</form><?php
endif?></div>
</div>
<?php
page_footer();
?>
