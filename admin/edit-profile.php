<?php
$page_title = 'Edit Profile';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin');

$pdo     = getDBConnection();
$adminId = $_SESSION['user_id'];

$admin = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$admin->execute([$adminId]);
$admin = $admin->fetch();

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'profile';

    // ── Update Profile ────────────────────────────────────────────────────────
    if ($action === 'profile') {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $bio      = trim($_POST['bio'] ?? '');

        if (!$name)  $errors[] = 'Name is required.';
        if (!$email) $errors[] = 'Email is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

        // Check email uniqueness
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $chk->execute([$email, $adminId]);
        if ($chk->fetch()) $errors[] = 'Email is already in use by another account.';

        // Profile photo upload
        $photoFilename = $admin['profile_image'] ?? null;
        if (!empty($_FILES['profile_image']['name'])) {
            $file     = $_FILES['profile_image'];
            $allowed  = ['image/jpeg','image/png','image/gif','image/webp'];
            $maxSize  = 2 * 1024 * 1024; // 2MB

            if (!in_array($file['type'], $allowed)) {
                $errors[] = 'Photo must be JPG, PNG, GIF, or WebP.';
            } elseif ($file['size'] > $maxSize) {
                $errors[] = 'Photo must be under 2MB.';
            } else {
                $ext           = pathinfo($file['name'], PATHINFO_EXTENSION);
                $photoFilename = 'admin_' . $adminId . '_' . time() . '.' . $ext;
                $uploadDir     = __DIR__ . '/../assets/images/profiles/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                if (!move_uploaded_file($file['tmp_name'], $uploadDir . $photoFilename)) {
                    $errors[] = 'Failed to upload photo.';
                    $photoFilename = $admin['profile_image'] ?? null;
                }
            }
        }

        if (empty($errors)) {
            $upd = $pdo->prepare("UPDATE users SET name=?, email=?, phone=?, location=?, bio=?, profile_image=? WHERE id=?");
            $upd->execute([$name, $email, $phone, $location, $bio, $photoFilename, $adminId]);
            $_SESSION['name'] = $name;
            $success = 'Profile updated successfully!';
            // Refresh admin data
            $admin = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $admin->execute([$adminId]);
            $admin = $admin->fetch();
        }
    }

    // ── Change Password ───────────────────────────────────────────────────────
    if ($action === 'password') {
        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (!$current) $errors[] = 'Current password is required.';
        if (strlen($new) < 8) $errors[] = 'New password must be at least 8 characters.';
        if ($new !== $confirm) $errors[] = 'New passwords do not match.';

        if (empty($errors)) {
            if (!password_verify($current, $admin['password'])) {
                $errors[] = 'Current password is incorrect.';
            } else {
                $hashed = password_hash($new, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hashed, $adminId]);
                $success = 'Password changed successfully!';
            }
        }
    }
}

$adminPhoto = $admin['profile_image'] ?? null;
?>

<style>
.ep-layout   { display:grid;grid-template-columns:260px 1fr;gap:1.5rem;align-items:start; }
.ep-sidebar  { position:sticky;top:1.5rem; }

/* Avatar upload area */
.avatar-upload-wrap { position:relative;width:110px;height:110px;margin:0 auto 1rem; }
.avatar-upload-img  { width:110px;height:110px;border-radius:50%;object-fit:cover;border:3px solid white;box-shadow:0 4px 18px rgba(0,0,0,.15); }
.avatar-initials    { width:110px;height:110px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#639922);color:white;display:flex;align-items:center;justify-content:center;font-size:2.2rem;font-weight:800;border:3px solid white;box-shadow:0 4px 18px rgba(0,0,0,.15);font-family:'Playfair Display',serif; }
.avatar-edit-btn    { position:absolute;bottom:4px;right:4px;width:28px;height:28px;border-radius:50%;background:var(--primary);color:white;display:flex;align-items:center;justify-content:center;font-size:.65rem;cursor:pointer;border:2px solid white;transition:background .2s; }
.avatar-edit-btn:hover { background:var(--primary-dark,#2d5f2e); }

/* Tab nav */
.ep-tabs    { display:flex;gap:4px;margin-bottom:1.5rem;background:white;border:1px solid var(--border);border-radius:var(--radius-lg,12px);padding:.4rem; }
.ep-tab     { flex:1;text-align:center;padding:.5rem .75rem;border-radius:8px;font-size:.82rem;font-weight:700;cursor:pointer;color:var(--text-muted);transition:all .2s;border:none;background:none; }
.ep-tab.active { background:var(--primary);color:white; }
.ep-tab:not(.active):hover { background:var(--pale-green,#f0fdf0);color:var(--primary); }

/* Form */
.ep-form-section { display:none; }
.ep-form-section.active { display:block; }

.form-row   { display:grid;grid-template-columns:1fr 1fr;gap:1rem; }
.form-group { margin-bottom:1rem; }
.form-label { font-size:.78rem;font-weight:800;color:var(--text);margin-bottom:.4rem;display:block;text-transform:uppercase;letter-spacing:.04em; }
.form-control {
    width:100%;padding:.6rem .85rem;border:1.5px solid var(--border);border-radius:var(--radius,8px);
    font-size:.88rem;color:var(--text);background:white;transition:border-color .2s,box-shadow .2s;
    font-family:inherit;
}
.form-control:focus { outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(62,124,63,.12); }
textarea.form-control { resize:vertical;min-height:90px; }

/* Password strength */
.pw-strength { height:4px;border-radius:99px;margin-top:6px;transition:all .3s;background:#e5e7eb;overflow:hidden; }
.pw-strength-fill { height:100%;border-radius:99px;transition:width .3s,background .3s; }

/* Sidebar info card */
.info-card  { background:white;border:1px solid var(--border);border-radius:var(--radius-lg,12px);padding:1.25rem;box-shadow:var(--shadow-sm); }
.info-row   { display:flex;align-items:flex-start;gap:.6rem;padding:.55rem 0;border-bottom:1px solid var(--border);font-size:.82rem; }
.info-row:last-child { border-bottom:none; }
.info-icon  { width:28px;height:28px;border-radius:8px;background:var(--pale-green,#f0fdf0);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:.72rem;flex-shrink:0;margin-top:1px; }
.info-label { font-size:.68rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em; }
.info-value { font-weight:700;color:var(--text); }

@media(max-width:768px){
    .ep-layout { grid-template-columns:1fr; }
    .ep-sidebar { position:static; }
    .form-row   { grid-template-columns:1fr; }
}
</style>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">

    <div class="page-header">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h1><i class="fa-solid fa-user-pen text-green me-2"></i>Edit Profile</h1>
                    <div class="page-breadcrumb">
                        <a href="dashboard.php">Dashboard</a> &rsaquo; Edit Profile
                    </div>
                </div>
                <a href="dashboard.php" class="btn-outline-green" style="padding:.45rem 1rem;font-size:.82rem;">
                    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <div class="container">

        <?php if ($success): ?>
        <div style="padding:.85rem 1.1rem;border-radius:var(--radius,8px);margin-bottom:1.25rem;font-size:.85rem;font-weight:700;
                    background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0;display:flex;align-items:center;gap:.6rem;">
            <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div style="padding:.85rem 1.1rem;border-radius:var(--radius,8px);margin-bottom:1.25rem;font-size:.85rem;font-weight:700;
                    background:#fee2e2;color:#dc2626;border:1px solid #fecaca;">
            <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.4rem;">
                <i class="fa-solid fa-circle-exclamation"></i> Please fix the following:
            </div>
            <ul style="margin:0 0 0 1.5rem;padding:0;font-weight:600;">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="ep-layout">

            <!-- ── Sidebar ── -->
            <div class="ep-sidebar">
                <!-- Avatar + name -->
                <div class="info-card" style="text-align:center;margin-bottom:1rem;">
                    <div class="avatar-upload-wrap" id="avatarWrap">
                        <?php if (!empty($adminPhoto)): ?>
                            <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($adminPhoto) ?>"
                                 class="avatar-upload-img" id="avatarPreview" alt="Profile">
                        <?php else: ?>
                            <div class="avatar-initials" id="avatarInitials">
                                <?= strtoupper(substr($admin['name'], 0, 2)) ?>
                            </div>
                        <?php endif; ?>
                        <label class="avatar-edit-btn" for="profile_image_input" title="Change photo">
                            <i class="fa-solid fa-camera"></i>
                        </label>
                    </div>
                    <div style="font-weight:800;font-size:1rem;color:var(--text);"><?= htmlspecialchars($admin['name']) ?></div>
                    <div style="display:inline-flex;align-items:center;gap:4px;background:var(--pale-green,#f0fdf0);color:var(--primary);border-radius:99px;padding:2px 10px;font-size:.72rem;font-weight:800;margin-top:5px;">
                        <i class="fa-solid fa-shield-halved" style="font-size:.6rem;"></i> Administrator
                    </div>
                    <div style="font-size:.75rem;color:var(--text-muted);margin-top:.5rem;">
                        Member since <?= date('M Y', strtotime($admin['created_at'])) ?>
                    </div>
                </div>

                <!-- Info rows -->
                <div class="info-card">
                    <div style="font-size:.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem;">Account Info</div>
                    <div class="info-row">
                        <div class="info-icon"><i class="fa-solid fa-envelope"></i></div>
                        <div>
                            <div class="info-label">Email</div>
                            <div class="info-value" style="word-break:break-all;"><?= htmlspecialchars($admin['email']) ?></div>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon"><i class="fa-solid fa-phone"></i></div>
                        <div>
                            <div class="info-label">Phone</div>
                            <div class="info-value"><?= htmlspecialchars($admin['phone'] ?? '—') ?></div>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon"><i class="fa-solid fa-location-dot"></i></div>
                        <div>
                            <div class="info-label">Location</div>
                            <div class="info-value"><?= htmlspecialchars($admin['location'] ?? '—') ?></div>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon"><i class="fa-solid fa-circle-dot"></i></div>
                        <div>
                            <div class="info-label">Status</div>
                            <div class="info-value" style="color:#16a34a;">
                                <i class="fa-solid fa-circle" style="font-size:.45rem;vertical-align:middle;margin-right:4px;"></i>Active
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Main Panel ── -->
            <div>
                <!-- Tabs -->
                <div class="ep-tabs">
                    <button class="ep-tab active" onclick="switchTab('profile', this)">
                        <i class="fa-solid fa-user me-1"></i> Profile Info
                    </button>
                    <button class="ep-tab" onclick="switchTab('password', this)">
                        <i class="fa-solid fa-lock me-1"></i> Change Password
                    </button>
                </div>

                <!-- ── Profile Form ── -->
                <div class="ep-form-section active" id="tab-profile">
                    <div class="gl-card">
                        <div class="gl-card-body">
                            <h5 style="font-weight:800;margin-bottom:1.25rem;font-size:.95rem;">
                                <i class="fa-solid fa-user-circle text-green me-2"></i>Personal Information
                            </h5>
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="profile">

                                <!-- Hidden file input triggered by camera icon -->
                                <input type="file" id="profile_image_input" name="profile_image"
                                       accept="image/jpeg,image/png,image/gif,image/webp"
                                       style="display:none;" onchange="previewAvatar(this)">

                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label" for="name">Full Name <span style="color:#ef4444;">*</span></label>
                                        <input type="text" id="name" name="name" class="form-control"
                                               value="<?= htmlspecialchars($admin['name']) ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="email">Email Address <span style="color:#ef4444;">*</span></label>
                                        <input type="email" id="email" name="email" class="form-control"
                                               value="<?= htmlspecialchars($admin['email']) ?>" required>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label" for="phone">Phone Number</label>
                                        <input type="text" id="phone" name="phone" class="form-control"
                                               placeholder="+63 9XX XXX XXXX"
                                               value="<?= htmlspecialchars($admin['phone'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="location">Location</label>
                                        <input type="text" id="location" name="location" class="form-control"
                                               placeholder="e.g. Davao City, Philippines"
                                               value="<?= htmlspecialchars($admin['location'] ?? '') ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="bio">Bio / About</label>
                                    <textarea id="bio" name="bio" class="form-control"
                                              placeholder="Short description about yourself..."><?= htmlspecialchars($admin['bio'] ?? '') ?></textarea>
                                    <div style="font-size:.7rem;color:var(--text-muted);margin-top:4px;">
                                        <span id="bioCount">0</span> / 300 characters
                                    </div>
                                </div>

                                <!-- Photo upload hint -->
                                <div style="background:var(--pale-green,#f0fdf0);border-radius:10px;padding:.75rem 1rem;margin-bottom:1.1rem;display:flex;align-items:center;gap:.6rem;">
                                    <i class="fa-solid fa-circle-info text-green" style="font-size:.85rem;"></i>
                                    <span style="font-size:.78rem;color:var(--text-muted);">
                                        Click the <strong style="color:var(--primary);">camera icon</strong> on your avatar to change your profile photo. Max 2MB · JPG, PNG, WebP.
                                    </span>
                                </div>

                                <div style="display:flex;justify-content:flex-end;gap:.75rem;padding-top:.5rem;border-top:1px solid var(--border);">
                                    <a href="dashboard.php" class="btn-outline-green" style="padding:.55rem 1.25rem;font-size:.85rem;">
                                        Cancel
                                    </a>
                                    <button type="submit" class="btn-green" style="padding:.55rem 1.5rem;font-size:.85rem;">
                                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- ── Password Form ── -->
                <div class="ep-form-section" id="tab-password">
                    <div class="gl-card">
                        <div class="gl-card-body">
                            <h5 style="font-weight:800;margin-bottom:1.25rem;font-size:.95rem;">
                                <i class="fa-solid fa-lock text-green me-2"></i>Change Password
                            </h5>
                            <form method="POST">
                                <input type="hidden" name="action" value="password">

                                <div class="form-group" style="max-width:460px;">
                                    <label class="form-label" for="current_password">Current Password <span style="color:#ef4444;">*</span></label>
                                    <div style="position:relative;">
                                        <input type="password" id="current_password" name="current_password"
                                               class="form-control" placeholder="Enter your current password" required>
                                        <button type="button" onclick="togglePw('current_password', this)"
                                                style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:.82rem;padding:0;">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="form-group" style="max-width:460px;">
                                    <label class="form-label" for="new_password">New Password <span style="color:#ef4444;">*</span></label>
                                    <div style="position:relative;">
                                        <input type="password" id="new_password" name="new_password"
                                               class="form-control" placeholder="Min. 8 characters" required
                                               oninput="checkStrength(this.value)">
                                        <button type="button" onclick="togglePw('new_password', this)"
                                                style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:.82rem;padding:0;">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="pw-strength"><div class="pw-strength-fill" id="pwStrengthFill" style="width:0%;"></div></div>
                                    <div style="font-size:.7rem;color:var(--text-muted);margin-top:4px;" id="pwStrengthLabel"></div>
                                </div>

                                <div class="form-group" style="max-width:460px;">
                                    <label class="form-label" for="confirm_password">Confirm New Password <span style="color:#ef4444;">*</span></label>
                                    <div style="position:relative;">
                                        <input type="password" id="confirm_password" name="confirm_password"
                                               class="form-control" placeholder="Re-enter new password" required>
                                        <button type="button" onclick="togglePw('confirm_password', this)"
                                                style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:.82rem;padding:0;">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Tips -->
                                <div style="background:#f8fafc;border-radius:10px;padding:.85rem 1rem;margin-bottom:1.25rem;max-width:460px;">
                                    <div style="font-size:.75rem;font-weight:800;color:var(--text);margin-bottom:.5rem;">
                                        <i class="fa-solid fa-shield-check text-green me-1"></i>Password Tips
                                    </div>
                                    <ul style="margin:0 0 0 1.1rem;padding:0;font-size:.74rem;color:var(--text-muted);line-height:1.8;">
                                        <li>At least 8 characters long</li>
                                        <li>Mix uppercase and lowercase letters</li>
                                        <li>Include at least one number</li>
                                        <li>Use a special character (!, @, #…)</li>
                                    </ul>
                                </div>

                                <div style="display:flex;justify-content:flex-end;gap:.75rem;padding-top:.5rem;border-top:1px solid var(--border);">
                                    <button type="reset" class="btn-outline-green" style="padding:.55rem 1.25rem;font-size:.85rem;">
                                        Clear
                                    </button>
                                    <button type="submit" class="btn-green" style="padding:.55rem 1.5rem;font-size:.85rem;">
                                        <i class="fa-solid fa-key me-1"></i> Update Password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            </div><!-- /main panel -->
        </div><!-- /ep-layout -->
    </div><!-- /container -->
</div>

<script>
// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(tab, btn) {
    document.querySelectorAll('.ep-form-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.ep-tab').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    btn.classList.add('active');
}

// ── Avatar preview ────────────────────────────────────────────────────────────
function previewAvatar(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const wrap = document.getElementById('avatarWrap');
        // Remove initials div if present
        const initDiv = document.getElementById('avatarInitials');
        if (initDiv) initDiv.remove();
        // Update or create preview img
        let img = document.getElementById('avatarPreview');
        if (!img) {
            img = document.createElement('img');
            img.id        = 'avatarPreview';
            img.className = 'avatar-upload-img';
            img.alt       = 'Profile';
            wrap.insertBefore(img, wrap.querySelector('label'));
        }
        img.src = e.target.result;
    };
    reader.readAsDataURL(input.files[0]);
}

// ── Toggle password visibility ────────────────────────────────────────────────
function togglePw(id, btn) {
    const inp = document.getElementById(id);
    const icon = btn.querySelector('i');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.classList.replace('fa-eye','fa-eye-slash');
    } else {
        inp.type = 'password';
        icon.classList.replace('fa-eye-slash','fa-eye');
    }
}

// ── Password strength ─────────────────────────────────────────────────────────
function checkStrength(val) {
    let score = 0;
    if (val.length >= 8)          score++;
    if (/[A-Z]/.test(val))        score++;
    if (/[0-9]/.test(val))        score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const fill  = document.getElementById('pwStrengthFill');
    const label = document.getElementById('pwStrengthLabel');
    const map = [
        { pct:'0%',   color:'#e5e7eb', text:'' },
        { pct:'25%',  color:'#ef4444', text:'Weak' },
        { pct:'50%',  color:'#f97316', text:'Fair' },
        { pct:'75%',  color:'#eab308', text:'Good' },
        { pct:'100%', color:'#22c55e', text:'Strong 💪' },
    ];
    fill.style.width      = map[score].pct;
    fill.style.background = map[score].color;
    label.textContent     = map[score].text;
    label.style.color     = map[score].color;
}

// ── Bio character counter ─────────────────────────────────────────────────────
const bioEl = document.getElementById('bio');
const bioCount = document.getElementById('bioCount');
if (bioEl) {
    bioEl.addEventListener('input', () => {
        const len = bioEl.value.length;
        bioCount.textContent = len;
        bioCount.style.color = len > 280 ? '#ef4444' : 'var(--text-muted)';
        if (len > 300) bioEl.value = bioEl.value.substring(0, 300);
    });
    bioCount.textContent = bioEl.value.length;
}

// ── Auto-open password tab if that form errored ───────────────────────────────
<?php if (!empty($errors) && ($_POST['action'] ?? '') === 'password'): ?>
switchTab('password', document.querySelectorAll('.ep-tab')[1]);
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>