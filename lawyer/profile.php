<?php
require_once __DIR__.'/../includes/lawyer_layout.php';
require_role('lawyer');
$lid=current_lawyer_id($pdo);
$locations=$pdo->query('SELECT id,city_name FROM locations ORDER BY city_name')->fetchAll();
$specs=$pdo->query('SELECT id,name FROM specializations ORDER BY name')->fetchAll();
$error='';
if(is_post()) {
    verify_csrf();
    $action=$_POST['action']??'general';
    try {
        if($action==='general') {
            $name=trim($_POST['full_name']??'');
            $email=strtolower(trim($_POST['email']??''));
            $phone=trim($_POST['phone']??'');
            $bio=trim($_POST['bio']??'');
            $office=trim($_POST['office_address']??'');
            if($name===''||!preg_match('/^[\p{L}]+(?: [\p{L}]+)*$/u', $name))throw new Exception('Full name may contain letters only, with single spaces between name parts. Numbers, leading/trailing spaces and repeated spaces are not allowed.');
            if(!preg_match('/^[a-zA-Z0-9._%+-]+@gmail\.com$/', $email))throw new Exception('Email must be a valid Gmail address, for example name@gmail.com.');
            if(!preg_match('/^\d{10}$/', $phone))throw new Exception('Phone Number must contain exactly 10 digits with no letters or spaces.');
            if($office==='')throw new Exception('Office Address is required.');
            if($bio==='')throw new Exception('Professional Biography is required.');
            $dup=$pdo->prepare('SELECT COUNT(*) FROM users WHERE email=? AND id<>?');
            $dup->execute([$email, (int)user()['id']]);
            if((int)$dup->fetchColumn()>0)throw new Exception('This email address is already in use.');
            $avatar=null;
            if(!empty($_FILES['avatar']['name'])) {
                $ext=strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
                if(!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true))throw new Exception('Only JPG, PNG or WEBP images are allowed.');
                if($_FILES['avatar']['size']>3*1024*1024)throw new Exception('Profile image must be under 3 MB.');
                $dir=__DIR__.'/../uploads/avatars';
                if(!is_dir($dir))mkdir($dir, 0775, true);
                $file='lawyer_'.$lid.'_'.time().'.'.$ext;
                if(!move_uploaded_file($_FILES['avatar']['tmp_name'], $dir.'/'.$file))throw new Exception('Unable to save profile image.');
                $avatar='uploads/avatars/'.$file;
            } $pdo->beginTransaction();
            if($avatar!==null)$pdo->prepare('UPDATE lawyer_profiles SET full_name=?,phone=?,bio=?,office_address=?,location_id=?,avatar_file=? WHERE id=?')->execute([$name, $phone, $bio, $office, ($_POST['location_id']??'')!==''?(int)$_POST['location_id']:null, $avatar, $lid]);
            else $pdo->prepare('UPDATE lawyer_profiles SET full_name=?,phone=?,bio=?,office_address=?,location_id=? WHERE id=?')->execute([$name, $phone, $bio, $office, ($_POST['location_id']??'')!==''?(int)$_POST['location_id']:null, $lid]);
            $pdo->prepare('UPDATE users SET name=?,email=? WHERE id=?')->execute([$name, $email, user()['id']]);
            $pdo->commit();
            $_SESSION['user']['name']=$name;
            $_SESSION['user']['email']=$email;
            notify($pdo, (int)user()['id'], 'Your lawyer profile information was updated.', 'System', '/LegalEase_eProject/lawyer/profile.php');
            flash('success', 'General profile information updated.');
        }
        elseif($action==='avatar') {
            if(empty($_FILES['avatar']['name']))throw new Exception('Choose an image first.');
            $ext=strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            if(!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true))throw new Exception('Only JPG, PNG or WEBP images are allowed.');
            if($_FILES['avatar']['size']>3*1024*1024)throw new Exception('Profile image must be under 3 MB.');
            $dir=__DIR__.'/../uploads/avatars';
            if(!is_dir($dir))mkdir($dir, 0775, true);
            $file='lawyer_'.$lid.'_'.time().'.'.$ext;
            if(!move_uploaded_file($_FILES['avatar']['tmp_name'], $dir.'/'.$file))throw new Exception('Unable to save profile image.');
            $pdo->prepare('UPDATE lawyer_profiles SET avatar_file=? WHERE id=?')->execute(['uploads/avatars/'.$file, $lid]);
            notify($pdo, (int)user()['id'], 'Your lawyer profile photo was updated.', 'System', '/LegalEase_eProject/lawyer/profile.php');
            flash('success', 'Profile photo updated.');
        }
        elseif($action==='expertise') {
            $exp=max(0, (int)($_POST['experience_years']??0));
            $feeRaw=str_replace([',', '.', ' '], '', trim($_POST['consultation_fee']??''));
            if($feeRaw===''||!ctype_digit($feeRaw))throw new Exception('Consultation fee must be a valid VND amount.');
            $fee=(int)$feeRaw;
            if($fee<=0)throw new Exception('Consultation fee must be greater than 0 VND.');
            if($fee%50000!==0)throw new Exception('Consultation fee must be a multiple of 50,000 VND.');
            $selected=array_values(array_unique(array_map('intval', $_POST['specialties']??[])));
            if(!$selected)throw new Exception('Select at least one specialization.');
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE lawyer_profiles SET experience_years=?,consultation_fee=? WHERE id=?')->execute([$exp, $fee, $lid]);
            $pdo->prepare('DELETE FROM lawyer_specialties WHERE lawyer_id=?')->execute([$lid]);
            $ins=$pdo->prepare('INSERT INTO lawyer_specialties(lawyer_id,specialization_id) VALUES(?,?)');
            foreach($selected as $sid)$ins->execute([$lid, $sid]);
            $pdo->commit();
            notify($pdo, (int)user()['id'], 'Your specializations, experience and consultation fee were updated.', 'System', '/LegalEase_eProject/lawyer/profile.php?tab=expertise');
            flash('success', 'Professional expertise and consultation fee updated.');
        }
        elseif($action==='verification') {
            $idcard=trim($_POST['id_card_number']??'');
            $license=trim($_POST['license_number']??'');
            if(!$idcard||!$license)throw new Exception('National ID and lawyer license number are required.');
            $existing=$pdo->prepare('SELECT document_file FROM lawyer_verifications WHERE lawyer_id=?');
            $existing->execute([$lid]);
            $existingFile=(string)($existing->fetchColumn()?:'');
            $file='';
            if(!empty($_FILES['document']['name'])) {
                $ext=strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
                if(!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true))throw new Exception('Verification document must be PDF, JPG or PNG.');
                if($_FILES['document']['size']>3*1024*1024)throw new Exception('Verification document must be under 3 MB.');
                $dir=__DIR__.'/../uploads/licenses';
                if(!is_dir($dir))mkdir($dir, 0775, true);
                $filename='verification_'.$lid.'_'.time().'.'.$ext;
                if(!move_uploaded_file($_FILES['document']['tmp_name'], $dir.'/'.$filename))throw new Exception('Unable to save verification document.');
                $file='uploads/licenses/'.$filename;
            }
            if($file===''&&$existingFile==='')throw new Exception('Please upload a Verification Document before submitting for review.');
            $pdo->prepare("INSERT INTO lawyer_verifications(lawyer_id,id_card_number,license_number,document_file,status,admin_note) VALUES(?,?,?,?, 'Pending',NULL) ON DUPLICATE KEY UPDATE id_card_number=VALUES(id_card_number),license_number=VALUES(license_number),document_file=IF(VALUES(document_file)='',document_file,VALUES(document_file)),status='Pending',admin_note=NULL,reviewed_by=NULL,reviewed_at=NULL,created_at=NOW()")->execute([$lid, $idcard, $license, $file]);
            $pdo->prepare('UPDATE lawyer_profiles SET is_verified=0 WHERE id=?')->execute([$lid]);
            notify($pdo, (int)user()['id'], 'Your Verification Document was submitted and is now awaiting administrator approval.', 'System', '/LegalEase_eProject/lawyer/profile.php?tab=expertise#verification');
            try {
                $admins=$pdo->query("SELECT id FROM users WHERE role='admin' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
                foreach($admins as $aid)notify($pdo, (int)$aid, 'A lawyer submitted a Verification Document for review: '.($p['full_name']??user()['name']).'.', 'System', '/LegalEase_eProject/admin/lawyers.php?filter=pending');
            }catch(Throwable $ignore) {
            }flash('success', 'Verification Document submitted. Your verified badge remains disabled until an administrator approves it.');
        }
    }catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        flash('error', $e->getMessage());
    }
    redirect('/LegalEase_eProject/lawyer/profile.php?tab='.urlencode($action==='general'||$action==='avatar'?'general':'expertise'));
}
$s=$pdo->prepare('SELECT lp.*,u.email,l.city_name FROM lawyer_profiles lp JOIN users u ON u.id=lp.user_id LEFT JOIN locations l ON l.id=lp.location_id WHERE lp.id=?');
$s->execute([$lid]);
$p=$s->fetch();
$sel=$pdo->prepare('SELECT specialization_id FROM lawyer_specialties WHERE lawyer_id=?');
$sel->execute([$lid]);
$selected=array_map('intval', array_column($sel->fetchAll(), 'specialization_id'));
$v=$pdo->prepare('SELECT * FROM lawyer_verifications WHERE lawyer_id=?');
$v->execute([$lid]);
$v=$v->fetch();
$tab=$_GET['tab']??'general';
lawyer_shell_start('Lawyer Profile Management', 'Manage your public profile, professional expertise, and legal verification in one place.');
?>
<div class="profile-tabs">
<button type="button" class="profile-tab <?=$tab==='general'?'active':''?>" data-profile-tab="general">▣ General Information & Office</button>
<button type="button" class="profile-tab <?=$tab==='expertise'?'active':''?>" data-profile-tab="expertise">⚖ Expertise & Legal Capacity</button>
</div>
<section class="profile-panel <?=$tab==='general'?'active':''?>" id="profile-general">
<div class="card profile-management-card">
<div class="profile-top">
<div class="profile-photo-wrap">
<img class="profile-photo" src="<?=e(avatar_url($p['avatar_file']??''))?>" alt="Profile photo">
</div>
<div>
<h2><?=e($p['full_name'])?></h2>
<p class="muted"><?=e($p['email'])?></p>
<span class="badge <?=$p['is_verified']?'badge-approved':'badge-pending'?>"><?=$p['is_verified']?'Verified profile':'Verification pending'?></span>
</div>
</div>
<form method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="general">
<div class="form-group full">
<label>Profile photo</label>
<input type="file" name="avatar" accept=".jpg,.jpeg,.png,.webp">
<small class="muted">Select a new image, then press Save General Information. Until you save, your current avatar remains unchanged.</small>
</div>
<div class="form-grid">
<div class="form-group">
<label>Full Name</label>
<input name="full_name" value="<?=e($p['full_name'])?>" required autocomplete="name" title="Letters only; use single spaces between name parts">
</div>
<div class="form-group">
<label>Email</label>
<input type="email" name="email" value="<?=e($p['email'])?>" pattern="[A-Za-z0-9._%+\-]+@gmail\.com" required autocomplete="email">
<small class="muted">Use a valid Gmail address, e.g. lawyername@gmail.com</small>
</div>
<div class="form-group">
<label>Phone Number</label>
<input name="phone" value="<?=e($p['phone'])?>" inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10" required>
<small class="muted">Exactly 10 digits; no letters or spaces.</small>
</div>
<div class="form-group">
<label>City / Province</label>
<select name="location_id">
<option value="">Select location</option><?php
foreach($locations as $l):?><option value="<?=(int)$l['id']?>" <?=$p['location_id']==$l['id']?'selected':''?>><?=e($l['city_name'])?></option><?php
endforeach?></select>
</div>
<div class="form-group full">
<label>Office Address</label>
<input name="office_address" value="<?=e($p['office_address'])?>" required>
</div>
<div class="form-group full">
<label>Professional Biography</label>
<textarea name="bio" rows="6" required><?=e($p['bio'])?></textarea>
</div>
</div>
<div class="action-row">
<button class="btn btn-primary">Save General Information</button>
</div>
</form>
</div>
</section>
<section class="profile-panel <?=$tab==='expertise'?'active':''?>" id="profile-expertise">
<div class="expertise-grid">
<div class="card">
<div class="card-header">
<h2>⚖ Professional Expertise</h2>
</div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="expertise">
<div class="form-group">
<label>Years of Experience</label>
<input type="number" name="experience_years" min="0" max="60" value="<?=(int)$p['experience_years']?>">
</div>
<div class="form-group">
<label>Specializations</label>
<div class="specialty-chip-grid"><?php
foreach($specs as $sp):?><label class="specialty-check">
<input type="checkbox" name="specialties[]" value="<?=(int)$sp['id']?>" <?=in_array((int)$sp['id'], $selected, true)?'checked':''?>>
<span><?=e($sp['name'])?></span>
</label><?php
endforeach?></div>
</div>
<div class="form-group">
<label>Consultation Fee (VND / 1 hour)</label>
<input type="number" name="consultation_fee" min="50000" step="50000" id="consultationFee" value="<?=(int)$p['consultation_fee']?>" required>
<small class="muted">Enter an amount greater than 0 and in multiples of 50,000 VND. Preview: <b id="consultationFeePreview"><?=number_format((float)$p['consultation_fee'], 0, ',', '.')?> VND</b>
</small>
</div>
<div class="action-row">
<button class="btn btn-primary">Save Expertise</button>
</div>
</form>
</div>
<div class="card" id="verification">
<div class="card-header">
<div>
<h2>▣ Legal Verification</h2>
<p class="muted">Upload your legal document for administrator review. A new upload always returns the verification to Pending.</p>
</div>
<span class="badge <?=lawyer_badge_class($v['status']??'Pending')?>"><?=e($v['status']??'Not submitted')?></span>
</div>
<form method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="verification">
<div class="form-group">
<label>National ID</label>
<input name="id_card_number" value="<?=e($v['id_card_number']??'')?>" required>
</div>
<div class="form-group">
<label>Lawyer License Number</label>
<input name="license_number" value="<?=e($v['license_number']??'')?>" required>
</div>
<div class="form-group">
<label>Verification Document</label>
<input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png">
<small class="muted">PDF/JPG/PNG, maximum 3 MB. Uploading a new document submits the profile for review again.</small>
</div><?php
if(!empty($v['document_file'])):?><div class="uploaded-document">
<span>📄</span>
<div>
<b>Uploaded document</b>
<br>
<a href="<?=e(verification_document_url($v['document_file']))?>" target="_blank">Open current document</a>
</div>
</div><?php
endif?><div class="action-row">
<button class="btn btn-primary">Submit Verification</button>
</div>
</form>
</div>
</div>
</section>
<script>const feeInput=document.getElementById('consultationFee'),feePreview=document.getElementById('consultationFeePreview');function updateFeePreview(){if(!feeInput||!feePreview)return;const n=Math.max(0,Number(feeInput.value||0));feePreview.textContent=new Intl.NumberFormat('vi-VN').format(n)+' VND';}feeInput?.addEventListener('input',updateFeePreview);updateFeePreview();document.querySelectorAll('[data-profile-tab]').forEach(b=>b.addEventListener('click',()=>{document.querySelectorAll('.profile-tab').forEach(x=>x.classList.remove('active'));document.querySelectorAll('.profile-panel').forEach(x=>x.classList.remove('active'));b.classList.add('active');document.getElementById('profile-'+b.dataset.profileTab).classList.add('active');history.replaceState(null,'','?tab='+b.dataset.profileTab);}));</script>
<?php
lawyer_shell_end();
?>
