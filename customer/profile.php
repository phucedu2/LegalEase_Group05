<?php
require_once __DIR__ . '/../includes/customer_layout.php';
require_role('customer');
$cid=current_customer_id($pdo);
if(is_post()) {
    verify_csrf();
    $action=$_POST['action']??'profile';
    if($action==='profile') {
        $name=trim($_POST['full_name']??'');
        $phone=trim($_POST['phone']??'');
        $email=strtolower(trim($_POST['email']??''));
        $address=trim($_POST['address']??'');
        $gender=$_POST['gender']??'';
        $birth=$_POST['birth_date']??'';
        try {
            if($name===''||!preg_match('/^[\p{L}]+(?: [\p{L}]+)*$/u', $name))throw new RuntimeException('Full name must contain letters only with single spaces between name parts.');
            if(!filter_var($email, FILTER_VALIDATE_EMAIL))throw new RuntimeException('Please enter a valid email address.');
            if($phone!==''&&!preg_match('/^\d{10}$/', $phone))throw new RuntimeException('Phone number must contain exactly 10 digits with no spaces.');
            if($address==='')throw new RuntimeException('Address is required.');
            if(!in_array($gender, ['Male', 'Female', 'Other', ''], true))throw new RuntimeException('Please select a valid gender.');
            if($birth!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth))throw new RuntimeException('Please enter a valid birth date.');
            $dup=$pdo->prepare('SELECT COUNT(*) FROM users WHERE email=? AND id<>?');
            $dup->execute([$email, (int)user()['id']]);
            if((int)$dup->fetchColumn())throw new RuntimeException('This email address is already in use.');
            $avatar=null;
            if(!empty($_FILES['avatar']['name'])) {
                $ext=strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
                if(!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true))throw new RuntimeException('Avatar must be JPG, PNG or WEBP.');
                if($_FILES['avatar']['size']>3*1024*1024)throw new RuntimeException('Avatar must be under 3 MB.');
                $dir=__DIR__.'/../uploads/avatars';
                if(!is_dir($dir))mkdir($dir, 0775, true);
                $fn='customer_'.$cid.'_'.time().'.'.$ext;
                if(!move_uploaded_file($_FILES['avatar']['tmp_name'], $dir.'/'.$fn))throw new RuntimeException('Unable to save avatar.');
                $avatar='uploads/avatars/'.$fn;
            }
            $pdo->beginTransaction();
            if($avatar!==null)$pdo->prepare('UPDATE customer_profiles SET full_name=?,phone=?,address=?,gender=?,birth_date=?,avatar_file=? WHERE id=?')->execute([$name, $phone, $address, $gender?:null, $birth?:null, $avatar, $cid]);
            else $pdo->prepare('UPDATE customer_profiles SET full_name=?,phone=?,address=?,gender=?,birth_date=? WHERE id=?')->execute([$name, $phone, $address, $gender?:null, $birth?:null, $cid]);
            $pdo->prepare('UPDATE users SET name=?,email=? WHERE id=?')->execute([$name, $email, user()['id']]);
            $pdo->commit();
            $_SESSION['user']['name']=$name;
            $_SESSION['user']['email']=$email;
            notify($pdo, (int)user()['id'], 'Your customer profile was updated successfully.', 'System', '/LegalEase_eProject/customer/profile.php');
            flash('success', 'Profile updated successfully.');
        }catch(Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            flash('error', $e->getMessage());
        }
    }elseif($action==='password') {
        $old=$_POST['current_password']??'';
        $new=$_POST['new_password']??'';
        $confirm=$_POST['confirm_password']??'';
        $s=$pdo->prepare('SELECT password FROM users WHERE id=?');
        $s->execute([user()['id']]);
        $hash=$s->fetchColumn();
        if(!password_verify($old, $hash))flash('error', 'Current password is incorrect.');
        elseif(strlen($new)<8||!preg_match('/[A-Za-z]/', $new)||!preg_match('/\d/', $new))flash('error', 'New password must contain at least 8 characters with letters and numbers.');
        elseif($new!==$confirm)flash('error', 'Password confirmation does not match.');
        else {
            $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), user()['id']]);
            flash('success', 'Password updated successfully.');
        }
    }
    redirect('/LegalEase_eProject/customer/profile.php');
}
$s=$pdo->prepare('SELECT cp.*,u.email FROM customer_profiles cp JOIN users u ON u.id=cp.user_id WHERE cp.id=?');
$s->execute([$cid]);
$p=$s->fetch();
customer_shell_start('Your Profile', 'Keep your personal and contact information up to date across LegalEase.');
?>
<div class="two-col">
<div class="card">
<div class="card-header">
<div>
<h2>Personal Information</h2>
<p class="muted">Changes are applied only after you press Save Changes.</p>
</div>
</div>
<form method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="profile">
<div class="profile-avatar-edit">
<img src="<?=e(avatar_url($p['avatar_file']??''))?>" alt="Avatar">
<div>
<label>Profile photo</label>
<input type="file" name="avatar" accept=".jpg,.jpeg,.png,.webp">
<small class="muted">If no photo is uploaded, the LegalEase logo is used.</small>
</div>
</div>
<div class="field">
<label>Full Name</label>
<input name="full_name" value="<?=e($p['full_name'])?>" required>
</div>
<div class="field">
<label>Email</label>
<input type="email" name="email" value="<?=e($p['email'])?>" required>
</div>
<div class="field">
<label>Phone Number</label>
<input name="phone" value="<?=e($p['phone'])?>" inputmode="numeric" pattern="[0-9]{10}" maxlength="10">
</div>
<div class="field">
<label>Address</label>
<input name="address" value="<?=e($p['address'])?>" required>
</div>
<div class="form-grid">
<div class="field">
<label>Gender</label>
<select name="gender">
<option value="">Select</option><?php
foreach(['Male', 'Female', 'Other'] as $g):?><option <?=$p['gender']===$g?'selected':''?>><?=e($g)?></option><?php
endforeach?></select>
</div>
<div class="field">
<label>Birth date</label>
<input type="date" name="birth_date" value="<?=e($p['birth_date']??'')?>">
</div>
</div>
<button class="btn btn-success">Save Changes</button>
</form>
</div>
<div class="card">
<div class="card-header">
<h2>Change Password</h2>
</div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="password">
<div class="field">
<label>Current Password</label>
<input type="password" name="current_password" required>
</div>
<div class="field">
<label>New Password</label>
<input type="password" name="new_password" required>
</div>
<div class="field">
<label>Confirm New Password</label>
<input type="password" name="confirm_password" required>
</div>
<button class="btn btn-success">Update Password</button>
</form>
</div>
</div>
<?php
customer_shell_end();
?>
