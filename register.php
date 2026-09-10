<?php
require_once __DIR__ . '/includes/bootstrap.php';
$specs = $pdo->query('SELECT id,name FROM specializations ORDER BY name')->fetchAll();
$locations = $pdo->query('SELECT id,city_name FROM locations ORDER BY city_name')->fetchAll();
$error = '';
$fieldErrors = [];
// field name => message (drives the red highlight)
$selectedSpecs = array_map('intval', (array) ($_POST['specialization_id'] ?? []));
function field_error(string $key): string {
    global $fieldErrors;
    return isset($fieldErrors[$key])
    ? '<p class="field-error" id="err-' . e($key) . '">' . e($fieldErrors[$key]) . '</p>'
    : '';
}
function field_class(string $key): string {
    global $fieldErrors;
    return isset($fieldErrors[$key]) ? ' has-error' : '';
}
if (is_post()) {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? 'customer';
    $phone = trim($_POST['phone'] ?? '');
    if (!in_array($role, ['customer', 'lawyer'], true)) $role = 'customer';
    // ---- shared fields ----
    if ($name === '') {
        $fieldErrors['name'] = 'Please enter your full name.';
    } elseif (strlen($name) < 3 || strlen($name) > 150) {
        $fieldErrors['name'] = 'Full name must contain 3 to 150 characters.';
    } elseif (!preg_match("/^[A-Za-z][A-Za-z .'-]{2,149}$/", $name)) {
        $fieldErrors['name'] = 'Use letters, spaces, apostrophes, periods or hyphens only.';
    }
    if ($email === '') {
        $fieldErrors['email'] = 'Please enter your email address.';
    } elseif (!is_valid_email_strict($email)) {
        $fieldErrors['email'] = 'Please enter a valid email address (e.g. name@example.com).';
    }
    if ($password === '') {
        $fieldErrors['password'] = 'Please enter a password.';
    } elseif (strlen($password) < 6 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $fieldErrors['password'] = 'Password must be at least 6 characters and include letters and numbers.';
    }
    if ($confirm === '') {
        $fieldErrors['confirm_password'] = 'Please confirm your password.';
    } elseif ($password !== $confirm) {
        $fieldErrors['confirm_password'] = 'Password confirmation does not match.';
    }
    if ($phone === '') {
        $fieldErrors['phone'] = 'Please enter your phone number.';
    } else {
        $phoneNormalized = normalize_vn_phone($phone);
        if ($phoneNormalized === null || $phoneNormalized === '') {
            $fieldErrors['phone'] = 'Enter a valid Vietnamese phone number (e.g. 0912345678 or +84912345678).';
        }
    }
    // ---- lawyer-only fields ----
    $savedDocs = [];
    if ($role === 'lawyer') {
        if (empty($_POST['location_id'])) {
            $fieldErrors['location_id'] = 'Please select your city / province.';
        }
        $selectedSpecs = array_values(array_unique(array_filter($selectedSpecs, fn($v) => $v > 0)));
        if (!$selectedSpecs) {
            $fieldErrors['specialization_id'] = 'Please choose at least one practice area.';
        }
        $exp = (int) ($_POST['experience'] ?? 0);
        if ($exp < 0 || $exp > 60) {
            $fieldErrors['experience'] = 'Experience must be between 0 and 60 years.';
        }
        $bio = trim($_POST['bio'] ?? '');
        if ($bio === '') {
            $fieldErrors['bio'] = 'Please write a short professional biography.';
        } elseif (strlen($bio) < 30) {
            $fieldErrors['bio'] = 'Professional biography must contain at least 30 characters.';
        }
        $idCard = trim($_POST['id_card_number'] ?? '');
        if ($idCard === '') {
            $fieldErrors['id_card_number'] = 'Please enter your national ID / citizen card number.';
        } elseif (!preg_match('/^[0-9]{9,12}$/', $idCard)) {
            $fieldErrors['id_card_number'] = 'National ID must be 9 to 12 digits.';
        }
        $license = trim($_POST['license_number'] ?? '');
        if ($license === '') {
            $fieldErrors['license_number'] = 'Please enter your lawyer license / bar card number.';
        } elseif (strlen($license) < 3 || strlen($license) > 100) {
            $fieldErrors['license_number'] = 'License number must contain 3 to 100 characters.';
        }
        // certificate / credential uploads (at least one, images or PDF)
        $files = $_FILES['certificates'] ?? null;
        $names = array_filter((array) ($files['name'] ?? []), fn($n) => $n !== '');
        if (!$names) {
            $fieldErrors['certificates'] = 'Please upload at least one photo or PDF of your license / certificates.';
        } elseif (count($names) > 5) {
            $fieldErrors['certificates'] = 'You can upload up to 5 files.';
        } else {
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
            $dir = __DIR__ . '/uploads/licenses';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            foreach ($files['name'] as $i => $original) {
                if ($original === '') continue;
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    $fieldErrors['certificates'] = 'One of the files failed to upload. Please try again.';
                    break;
                }
                $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) {
                    $fieldErrors['certificates'] = 'Only PDF, JPG, PNG or WEBP files are allowed.';
                    break;
                }
                if ($files['size'][$i] > 3 * 1024 * 1024) {
                    $fieldErrors['certificates'] = 'Each file must be under 3 MB.';
                    break;
                }
                $savedDocs[] = [
                'tmp' => $files['tmp_name'][$i],
                'target' => 'lawyer_reg_' . time() . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $ext,];
            }
        }
    }
    if ($fieldErrors) {
        $error = 'Please correct the highlighted fields below.';
    } else {
        try {
            $pdo->beginTransaction();
            $s = $pdo->prepare('INSERT INTO users(name,email,password,role) VALUES(?,?,?,?)');
            $s->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
            $uid = (int) $pdo->lastInsertId();
            if ($role === 'customer') {
                $s = $pdo->prepare('INSERT INTO customer_profiles(user_id,full_name,phone) VALUES(?,?,?)');
                $s->execute([$uid, $name, $phoneNormalized]);
            } else {
                $s = $pdo->prepare('INSERT INTO lawyer_profiles(user_id,full_name,phone,experience_years,bio,location_id,is_verified) VALUES(?,?,?,?,?,?,0)');
                $s->execute([$uid, $name, $phoneNormalized, $exp, $bio, (int) $_POST['location_id']]);
                $lid = (int) $pdo->lastInsertId();
                $insSpec = $pdo->prepare('INSERT INTO lawyer_specialties(lawyer_id,specialization_id) VALUES(?,?)');
                foreach ($selectedSpecs as $sid) {
                    $insSpec->execute([$lid, $sid]);
                }
                // persist uploaded credential files
                $stored = [];
                foreach ($savedDocs as $doc) {
                    if (move_uploaded_file($doc['tmp'], __DIR__ . '/uploads/licenses/' . $doc['target'])) {
                        $stored[] = 'uploads/licenses/' . $doc['target'];
                    }
                }
                if (!$stored) {
                    throw new RuntimeException('Unable to save the uploaded documents. Please try again.');
                }
                $pdo->prepare('INSERT INTO lawyer_verifications(lawyer_id,id_card_number,license_number,document_file,status) VALUES(?,?,?,?,?)')
                ->execute([$lid, $idCard, $license, implode(',', $stored), 'Pending']);
            }
            $accountMsg = $role === 'lawyer'
            ? 'Your lawyer account was created and your credentials were submitted. An administrator must approve them before you can practise on LegalEase.'
            : 'Your LegalEase account was created successfully.';
            notify($pdo, $uid, $accountMsg, 'System', '/LegalEase_eProject/login.php');
            $pdo->commit();
            $subject = 'Welcome to LegalEase - registration received';
            $body = '<h2>Welcome to LegalEase</h2><p>Hello ' . e($name) . ',</p><p>Your ' . e($role) . ' account has been registered successfully.</p>'
            . ($role === 'lawyer'
            ? '<p>Your license and certificate documents have been sent to our administrators for verification. You will be notified once your account is approved for practice.</p>'
            : '')
            . '<p>You can sign in at <a href="http://localhost/LegalEase_eProject/login.php">LegalEase Login</a>.</p><p>If you did not create this account, please contact the site administrator.</p>';
            send_app_email($pdo, $email, $subject, $body);
            flash('success', $role === 'lawyer'
            ? 'Registration received. Your credentials are pending administrator approval - we will email you once your account is verified.'
            : 'Registration successful. A confirmation email has been queued/sent to your registered email. Please sign in.');
            redirect('/LegalEase_eProject/login.php');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() === '23000') {
                $fieldErrors['email'] = 'This email address is already registered.';
                $error = 'Please correct the highlighted fields below.';
            } else {
                $error = 'Registration failed. Please try again.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}
page_header('Register');
?>
<style>
.req{color:var(--red);font-weight:700;margin-left:2px}
.field-error{color:var(--red);font-size:12px;margin:4px 0 0;font-weight:600}
.field.has-error input,.field.has-error select,.field.has-error textarea{border-color:var(--red);background:#fef2f2}
.field.has-error label{color:var(--red)}
.spec-checkboxes{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;border:1px solid #cfd6e3;border-radius:9px;padding:12px;background:#fff}
.field.has-error .spec-checkboxes{border-color:var(--red);background:#fef2f2}
.spec-checkboxes label{display:flex;flex-direction:row;align-items:center;gap:8px;font-weight:400;margin:0}
.spec-checkboxes input{width:auto;padding:0}
.hint{font-size:12px;color:var(--muted)}
</style>
<div class="login-page">
<div class="login-card" style="width:min(820px,100%)">
<h1>Create an account</h1>
<p class="muted">Register as a customer or lawyer. Lawyer accounts must submit their license and certificates, and an administrator must approve them before they can practise or appear in the public directory.</p>
<p class="hint">Fields marked <span class="req">*</span> are required.</p>
<?php
if ($error):?><div class="alert error"><?= e($error)?></div><?php
endif?>

<form method="post" enctype="multipart/form-data" id="registerForm" novalidate><?= csrf_field()?><div class="form-grid">
<div class="field<?= field_class('name')?>">
<label for="f-name">Full name <span class="req">*</span>
</label>
<input id="f-name" name="name" maxlength="150" value="<?= e($_POST['name'] ?? '')?>" required>
  <?= field_error('name')?>
</div>
<div class="field<?= field_class('email')?>">
<label for="f-email">Email <span class="req">*</span>
</label>
<input id="f-email" type="email" name="email" maxlength="150" value="<?= e($_POST['email'] ?? '')?>" required>
  <?= field_error('email')?>
</div>
<div class="field<?= field_class('password')?>">
<label for="regPassword">Password <span class="req">*</span>
</label>
<div class="password-wrap">
<input id="regPassword" type="password" name="password" autocomplete="new-password" minlength="6" required>
<button class="password-toggle" type="button" data-password-toggle="regPassword" aria-label="Show password" title="Show password">👁</button>
</div>
<small class="muted">At least 6 characters with letters and numbers.</small>
  <?= field_error('password')?>
</div>
<div class="field<?= field_class('confirm_password')?>">
<label for="regConfirmPassword">Confirm password <span class="req">*</span>
</label>
<div class="password-wrap">
<input id="regConfirmPassword" type="password" name="confirm_password" autocomplete="new-password" minlength="6" required>
<button class="password-toggle" type="button" data-password-toggle="regConfirmPassword" aria-label="Show password" title="Show password">👁</button>
</div>
  <?= field_error('confirm_password')?>
</div>
<div class="field">
<label for="roleSelect">Account type <span class="req">*</span>
</label>
<select name="role" id="roleSelect">
<option value="customer" <?= ($_POST['role'] ?? '') === 'lawyer' ? '' : 'selected'?>>Customer</option>
<option value="lawyer" <?= ($_POST['role'] ?? '') === 'lawyer' ? 'selected' : ''?>>Lawyer</option>
</select>
</div>
<div class="field<?= field_class('phone')?>">
<label for="f-phone">Phone <span class="req">*</span>
</label>
<input id="f-phone" name="phone" maxlength="20" placeholder="0912345678 or +84912345678" value="<?= e($_POST['phone'] ?? '')?>" required>
<small class="muted">Vietnamese mobile number (03/05/07/08/09) or +84 format.</small>
  <?= field_error('phone')?>
</div>
<div class="field lawyer-only<?= field_class('location_id')?>">
<label for="f-location">City / Province <span class="req">*</span>
</label>
<select id="f-location" name="location_id">
<option value="">Select</option>
    <?php
foreach ($locations as $l):?>
      <option value="<?= (int) $l['id']?>" <?= (int) ($_POST['location_id'] ?? 0) === (int) $l['id'] ? 'selected' : ''?>><?= e($l['city_name'])?></option>
    <?php
endforeach?>
  </select>
  <?= field_error('location_id')?>
</div>
<div class="field lawyer-only">
<label for="f-experience">Experience years <span class="req">*</span>
</label>
<input id="f-experience" type="number" name="experience" min="0" max="60" value="<?= e($_POST['experience'] ?? '0')?>">
  <?= field_error('experience')?>
</div>
<div class="field full lawyer-only<?= field_class('specialization_id')?>">
<label>Primary specialization(s) <span class="req">*</span>
<span class="hint">— select one or more</span>
</label>
<div class="spec-checkboxes">
    <?php
foreach ($specs as $sp):?>
      <label>
<input type="checkbox" name="specialization_id[]" value="<?= (int) $sp['id']?>" <?= in_array((int) $sp['id'], $selectedSpecs, true) ? 'checked' : ''?>> <?= e($sp['name'])?></label>
    <?php
endforeach?>
  </div>
  <?= field_error('specialization_id')?>
</div>
<div class="field full lawyer-only<?= field_class('bio')?>">
<label for="f-bio">Professional biography <span class="req">*</span>
</label>
<textarea id="f-bio" name="bio" minlength="30" placeholder="Describe your professional background, legal experience and client service approach."><?= e($_POST['bio'] ?? '')?></textarea>
  <?= field_error('bio')?>
</div>
<div class="field lawyer-only<?= field_class('id_card_number')?>">
<label for="f-idcard">National ID / Citizen card number <span class="req">*</span>
</label>
<input id="f-idcard" name="id_card_number" maxlength="12" value="<?= e($_POST['id_card_number'] ?? '')?>">
<small class="muted">9 to 12 digits.</small>
  <?= field_error('id_card_number')?>
</div>
<div class="field lawyer-only<?= field_class('license_number')?>">
<label for="f-license">Lawyer license / Bar card number <span class="req">*</span>
</label>
<input id="f-license" name="license_number" maxlength="100" value="<?= e($_POST['license_number'] ?? '')?>">
  <?= field_error('license_number')?>
</div>
<div class="field full lawyer-only<?= field_class('certificates')?>">
<label for="f-certificates">License &amp; certificate images / PDF <span class="req">*</span>
</label>
<input id="f-certificates" type="file" name="certificates[]" multiple accept="image/png,image/jpeg,image/webp,application/pdf">
<small class="muted">Upload a photo or scan of your bar card and practising certificates. Up to 5 files, PDF/JPG/PNG/WEBP, max 3 MB each. An administrator will review these before approving your account.</small>
  <?= field_error('certificates')?>
  <?php
if (($_POST['role'] ?? '') === 'lawyer' && $fieldErrors):?><p class="hint">Note: for security, uploaded files are not kept after an error — please re-select them.</p><?php
endif?>
</div>
</div>
<div class="actions">
<button>Register</button>
<a class="btn secondary" href="/LegalEase_eProject/login.php">Back to Login</a>
</div>
</form>
</div>
</div>
<script>
(function () {
  var r = document.getElementById('roleSelect'),
      els = document.querySelectorAll('.lawyer-only');
  function sync() {
    var isLawyer = r.value === 'lawyer';
    els.forEach(function (el) {
      el.style.display = isLawyer ? 'flex' : 'none';
      el.querySelectorAll('input,select,textarea').forEach(function (c) { c.disabled = !isLawyer; });
    });
  }
  r.addEventListener('change', sync);
  sync();

  // password show/hide
  document.querySelectorAll('[data-password-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var i = document.getElementById(b.dataset.passwordToggle);
      var show = i.type === 'password';
      i.type = show ? 'text' : 'password';
      b.textContent = show ? '🙈' : '👁';
      b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  // client-side validation: mark red, keep data, focus first invalid field
  var form = document.getElementById('registerForm');
  function clearError(field) {
    field.classList.remove('has-error');
    var m = field.querySelector('.js-client-error');
    if (m) m.remove();
  }
  function setError(field, msg) {
    field.classList.add('has-error');
    if (!field.querySelector('.field-error')) {
      var p = document.createElement('p');
      p.className = 'field-error js-client-error';
      p.textContent = msg;
      field.appendChild(p);
    }
  }
  form.addEventListener('submit', function (e) {
    var firstBad = null;
    form.querySelectorAll('.field').forEach(function (field) {
      clearError(field);
      var ctrl = field.querySelector('input,select,textarea');
      if (!ctrl || ctrl.disabled) return;
      var val = (ctrl.value || '').trim();
      var isRequired = ctrl.hasAttribute('required') ||
        field.classList.contains('lawyer-only') ||
        field.querySelector('.req');
      // specialization checkbox group
      if (field.querySelector('.spec-checkboxes')) {
        if (!field.querySelector('input:checked')) {
          setError(field, 'Please choose at least one practice area.');
          firstBad = firstBad || field;
        }
        return;
      }
      if (isRequired && val === '' && ctrl.type !== 'file') {
        setError(field, 'This field is required.');
        firstBad = firstBad || field;
        return;
      }
      if (ctrl.type === 'file' && isRequired && ctrl.files.length === 0) {
        setError(field, 'Please attach at least one file.');
        firstBad = firstBad || field;
        return;
      }
      if (ctrl.id === 'regConfirmPassword' && val !== (document.getElementById('regPassword').value || '')) {
        setError(field, 'Password confirmation does not match.');
        firstBad = firstBad || field;
      }
    });
    if (firstBad) {
      e.preventDefault();
      var focusable = firstBad.querySelector('input,select,textarea');
      if (focusable) focusable.focus();
      firstBad.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });

  // after a server-side error, focus the first flagged field without touching entered data
  var serverBad = document.querySelector('.field.has-error');
  if (serverBad) {
    var f = serverBad.querySelector('input,select,textarea');
    if (f && !f.disabled) f.focus();
    serverBad.scrollIntoView({ block: 'center' });
  }
})();
</script>
<?php
page_footer();
?>
