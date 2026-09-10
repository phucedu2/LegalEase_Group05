<?php
require_once __DIR__.'/includes/bootstrap.php';
if(user()) redirect('/LegalEase_eProject/index.php');
$error='';
if(is_post()) {
    verify_csrf();
    $email=strtolower(trim($_POST['email']??''));
    $password=$_POST['password']??'';
    if(!filter_var($email, FILTER_VALIDATE_EMAIL)||$password==='') $error='Please enter a valid email address and password.';
    else {
        $s=$pdo->prepare('SELECT id,name,email,password,role,is_active FROM users WHERE email=? LIMIT 1');
        $s->execute([$email]);
        $u=$s->fetch();
        if(!$u) $error='Account does not exist. Please enter the correct email address or register a new account.';
        elseif(!$u['is_active']) $error='This account is currently inactive. Please contact LegalEase support.';
        elseif(!password_verify($password, $u['password'])) $error='Incorrect password. Please try again or use Forgot password.';
        else {
            session_regenerate_id(true);
            $_SESSION['user']=['id'=>(int)$u['id'], 'name'=>$u['name'], 'email'=>$u['email'], 'role'=>$u['role']];
            $target=$u['role']==='admin'?'/LegalEase_eProject/admin/dashboard.php':($u['role']==='lawyer'?'/LegalEase_eProject/lawyer/dashboard.php':'/LegalEase_eProject/customer/dashboard.php');
            notify($pdo, (int)$u['id'], 'Successful sign-in to your LegalEase account.', 'System', $target);
            redirect($target);
        }
    }
}
page_header('Login');
?>
<div class="login-page">
<div class="login-card">
<h1>Welcome back</h1>
<p class="muted">Sign in to access your LegalEase account.</p><?php
if($error):?><div class="alert error"><?=e($error)?></div><?php
endif?><?php
render_flash();
?>
<form method="post"><?=csrf_field()?><div class="field">
<label>Email</label>
<input type="email" name="email" autocomplete="email" required>
</div>
<div class="field" style="margin-top:12px">
<label>Password</label>
<div class="password-wrap">
<input id="loginPassword" type="password" name="password" autocomplete="current-password" required>
<button class="password-toggle" type="button" data-password-toggle="loginPassword" aria-label="Show password" title="Show password">👁</button>
</div>
</div>
<div style="text-align:right;margin-top:8px">
<a style="color:#2563eb;font-weight:700" href="/LegalEase_eProject/forgot_password.php">Forgot password?</a>
</div>
<div class="actions">
<button>Login</button>
<a class="btn secondary" href="/LegalEase_eProject/register.php">Create account</a>
</div>
</form>
<hr style="border:0;border-top:1px solid #e5e9f0;margin:24px 0">
<small class="muted">Demo Admin: admin@legalease.com / 123456 · Lawyer: lawyer@gmail.com / 123456 · Customer: tantuyen@gmail.com / 123456</small>
</div>
</div>
<script>document.querySelectorAll('[data-password-toggle]').forEach(b=>b.addEventListener('click',()=>{const i=document.getElementById(b.dataset.passwordToggle);const show=i.type==='password';i.type=show?'text':'password';b.textContent=show?'🙈':'👁';b.setAttribute('aria-label',show?'Hide password':'Show password');}));</script>
<?php
page_footer();
?>
