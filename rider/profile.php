<?php
$page_title = 'My Profile';
$hide_navbar = true;
require_once __DIR__ . '/../includes/header.php';
requireRole('rider');

$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];

// Fetch rider
$userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$user = $userStmt->fetch();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $phone    = trim($_POST['phone']    ?? '');
    $location = trim($_POST['location'] ?? '');
    $profileImage = $user['profile_image'];

    // ── Profile image upload ──────────────────────────────────────────
    if (!empty($_FILES['profile_image']['name'])) {
        $allowed = ['jpg','jpeg','png','webp','gif'];
        $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            $error = 'Profile image must be JPG, PNG, WebP, or GIF.';
        } elseif ($_FILES['profile_image']['size'] > 3 * 1024 * 1024) {
            $error = 'Profile image must be under 3MB.';
        } else {
            $uploadDir = __DIR__ . '/../assets/images/profiles/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            if ($user['profile_image'] && file_exists($uploadDir . $user['profile_image'])) {
                @unlink($uploadDir . $user['profile_image']);
            }
            $profileImage = 'prof_' . $userId . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadDir . $profileImage);
        }
    }

    // ── Remove profile image ──────────────────────────────────────────
    if (isset($_POST['remove_profile_image']) && $user['profile_image']) {
        $uploadDir = __DIR__ . '/../assets/images/profiles/';
        if (file_exists($uploadDir . $user['profile_image'])) @unlink($uploadDir . $user['profile_image']);
        $profileImage = null;
    }

    if (!$error) {
        if (!$name) {
            $error = 'Name is required.';
        } else {
            // Auto-geocode if location changed
            $latitude  = $user['latitude']  ?? null;
            $longitude = $user['longitude'] ?? null;
            if ($location && $location !== ($user['location'] ?? '')) {
                $geoUrl = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($location . ', Philippines');
                $geoCtx = stream_context_create(['http' => ['header' => "User-Agent: GreenLink/1.0\r\n", 'timeout' => 5]]);
                $geoRes = @file_get_contents($geoUrl, false, $geoCtx);
                if ($geoRes) {
                    $geoData = json_decode($geoRes, true);
                    if (!empty($geoData[0])) {
                        $latitude  = floatval($geoData[0]['lat']);
                        $longitude = floatval($geoData[0]['lon']);
                    }
                }
            }

            // Manual pin overrides geocode
            $manualLat = !empty($_POST['latitude'])  ? floatval($_POST['latitude'])  : null;
            $manualLng = !empty($_POST['longitude']) ? floatval($_POST['longitude']) : null;
            if ($manualLat && $manualLng) {
                $latitude  = $manualLat;
                $longitude = $manualLng;
            }

            $uCols   = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $hasLatLng = in_array('latitude', $uCols);

            if ($hasLatLng) {
                $pdo->prepare("UPDATE users SET name=?, phone=?, location=?, profile_image=?, latitude=?, longitude=?, updated_at=NOW() WHERE id=?")
                    ->execute([$name, $phone, $location, $profileImage, $latitude, $longitude, $userId]);
            } else {
                $pdo->prepare("UPDATE users SET name=?, phone=?, location=?, profile_image=?, updated_at=NOW() WHERE id=?")
                    ->execute([$name, $phone, $location, $profileImage, $userId]);
            }
            $_SESSION['user_name'] = $name;

            // Refresh
            $u2 = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $u2->execute([$userId]);
            $user = $u2->fetch();

            $success = 'Profile updated successfully! 🎉';
        }
    }
}

// Stats for sidebar
$delivered = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE rider_id = ? AND status = 'completed'");
$delivered->execute([$userId]); $delivered = $delivered->fetchColumn();

$totalOrders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE rider_id = ?");
$totalOrders->execute([$userId]); $totalOrders = $totalOrders->fetchColumn();

$totalEarnings = $pdo->prepare("SELECT COALESCE(SUM(delivery_fee),0) FROM orders WHERE rider_id = ? AND status = 'completed'");
$totalEarnings->execute([$userId]); $totalEarnings = $totalEarnings->fetchColumn();

$profileImageUrl = !empty($user['profile_image'])
    ? BASE_URL . '/assets/images/profiles/' . htmlspecialchars($user['profile_image'])
    : null;

// Map coords — always read from latest $user (re-fetched after POST)
$savedLat   = !empty($user['latitude'])  ? floatval($user['latitude'])  : null;
$savedLng   = !empty($user['longitude']) ? floatval($user['longitude']) : null;
$defaultLat = $savedLat ?? 7.1907;
$defaultLng = $savedLng ?? 125.4553;

$greeting = (function() {
    $h = (int)date('H');
    if ($h < 12) return ['Good morning', '☀️'];
    if ($h < 17) return ['Good afternoon', '🌤️'];
    return ['Good evening', '🌙'];
})();
?>

<style>
:root {
    --rider-primary: #1B5E20;
    --rider-accent:  #4CAF50;
    --rider-orange:  #F97316;
    --rider-blue:    #3B82F6;
    --rider-bg:      #F0F7F0;
}

.dash-page { background: var(--rider-bg); min-height: 100vh; padding-bottom: 3rem; }

/* Sidebar stat card */
.profile-stat { display:flex; align-items:center; gap:12px; padding:.85rem 1rem; background:#F8FAF8; border-radius:12px; border:1px solid #E0EDE0; margin-bottom:.6rem; }
.profile-stat-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
.profile-stat-val  { font-size:1.2rem; font-weight:900; color:var(--text); line-height:1; }
.profile-stat-lbl  { font-size:.68rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.04em; margin-top:2px; }

/* Form card */
.form-card { background:white; border-radius:20px; border:1px solid #E2E8F0; box-shadow:0 2px 8px rgba(0,0,0,.05); overflow:hidden; margin-bottom:1.25rem; }
.form-card-head { padding:.9rem 1.25rem; border-bottom:1px solid #F1F5F9; display:flex; align-items:center; gap:.5rem; }
.form-card-head h6 { margin:0; font-weight:800; font-size:.95rem; color:var(--text); }
.form-card-body { padding:1.25rem; }

@keyframes fadeSlideUp { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
.fade-up   { animation: fadeSlideUp .45s ease both; }
.fade-up-1 { animation-delay:.08s; }
.fade-up-2 { animation-delay:.16s; }
</style>

<div class="dash-page">

    <!-- ═══════════ HEADER ═══════════ -->
    <div style="background:linear-gradient(135deg,#0D3B13 0%,#1B5E20 45%,#2E7D32 100%);padding:1.5rem 0;position:relative;overflow:hidden;">
        <div style="position:absolute;top:-60px;right:-60px;width:250px;height:250px;border-radius:50%;background:rgba(255,255,255,.03);pointer-events:none;"></div>
        <div style="position:absolute;bottom:-80px;left:20%;width:300px;height:300px;border-radius:50%;background:rgba(255,255,255,.025);pointer-events:none;"></div>

        <div class="container" style="position:relative;">
            <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">

                <!-- Left -->
                <div style="display:flex;align-items:center;gap:14px;">
                    <?php if ($profileImageUrl): ?>
                        <img src="<?= $profileImageUrl ?>"
                             style="width:52px;height:52px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.3);flex-shrink:0;">
                    <?php else: ?>
                        <div style="width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:800;color:white;border:3px solid rgba(255,255,255,.25);flex-shrink:0;">
                            <?= strtoupper(substr($user['name'],0,1)) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <div style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:white;line-height:1.1;">
                            <?= $greeting[1] ?> Edit Profile
                        </div>
                        <div style="font-size:.82rem;color:rgba(255,255,255,.65);margin-top:.2rem;">
                            🛵 <?= sanitize($user['name']) ?> · Delivery Rider
                        </div>
                    </div>
                </div>

                <!-- Nav -->
                <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;">
                    <a href="dashboard.php" style="display:flex;align-items:center;gap:6px;padding:.5rem 1rem;color:white;font-size:.8rem;font-weight:700;text-decoration:none;border-radius:10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);">
                        <i class="fa-solid fa-gauge"></i> Dashboard
                    </a>
                    <a href="pickup.php" style="display:flex;align-items:center;gap:6px;padding:.5rem 1rem;color:white;font-size:.8rem;font-weight:700;text-decoration:none;border-radius:10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);">
                        <i class="fa-solid fa-box-open"></i> Pickup
                    </a>
                    <a href="orders.php" style="display:flex;align-items:center;gap:6px;padding:.5rem 1rem;color:white;font-size:.8rem;font-weight:700;text-decoration:none;border-radius:10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);">
                        <i class="fa-solid fa-list"></i> All Orders
                    </a>
                    <a href="messages.php" style="display:flex;align-items:center;gap:6px;padding:.5rem 1rem;color:white;font-size:.8rem;font-weight:700;text-decoration:none;border-radius:10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);">
                        <i class="fa-solid fa-comments"></i> Messages
                    </a>
                    <a href="<?= BASE_URL ?>/auth/logout.php" style="display:flex;align-items:center;gap:6px;padding:.5rem 1rem;color:white;font-size:.8rem;font-weight:700;text-decoration:none;border-radius:10px;background:rgba(239,68,68,.2);border:1px solid rgba(239,68,68,.3);">
                        <i class="fa-solid fa-right-from-bracket"></i> Logout
                    </a>
                </div>

            </div>
        </div>
    </div>

    <!-- ═══════════ BODY ═══════════ -->
    <div class="container" style="padding-top:1.5rem;">

        <?php if ($error): ?>
        <div style="background:#FEE2E2;border:1px solid #FECACA;color:#DC2626;border-radius:12px;padding:.8rem 1.1rem;font-weight:700;font-size:.85rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-circle-exclamation"></i><?= sanitize($error) ?>
        </div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div style="background:#DCFCE7;border:1px solid #BBF7D0;color:#16A34A;border-radius:12px;padding:.8rem 1.1rem;font-weight:700;font-size:.85rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-circle-check"></i><?= sanitize($success) ?>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="row g-4">

                <!-- ── LEFT: Photo + Stats ── -->
                <div class="col-lg-4 fade-up">

                    <!-- Photo card -->
                    <div class="form-card">
                        <div class="form-card-head">
                            <i class="fa-solid fa-camera" style="color:var(--rider-primary);"></i>
                            <h6>Profile Photo</h6>
                        </div>
                        <div class="form-card-body text-center">
                            <div id="profilePreviewWrap" style="position:relative;display:inline-block;margin-bottom:1.2rem;">
                                <?php if ($profileImageUrl): ?>
                                <img id="profilePreview" src="<?= $profileImageUrl ?>"
                                     style="width:120px;height:120px;border-radius:50%;object-fit:cover;border:4px solid var(--rider-primary);box-shadow:0 4px 20px rgba(27,94,32,.2);">
                                <?php else: ?>
                                <div id="profileInitial" style="width:120px;height:120px;border-radius:50%;background:linear-gradient(135deg,#1B5E20,#4CAF50);display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:2.5rem;border:4px solid var(--rider-primary);margin:0 auto;box-shadow:0 4px 20px rgba(27,94,32,.2);">
                                    <?= strtoupper(substr($user['name'],0,1)) ?>
                                </div>
                                <img id="profilePreview" src="" style="width:120px;height:120px;border-radius:50%;object-fit:cover;border:4px solid var(--rider-primary);box-shadow:0 4px 20px rgba(27,94,32,.2);display:none;">
                                <?php endif; ?>
                                <button type="button" onclick="document.getElementById('profileImageInput').click()"
                                        style="position:absolute;bottom:4px;right:4px;width:34px;height:34px;border-radius:50%;background:var(--rider-primary);border:2px solid white;color:white;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.8rem;box-shadow:0 2px 8px rgba(0,0,0,.2);">
                                    <i class="fa-solid fa-camera"></i>
                                </button>
                            </div>

                            <input type="file" name="profile_image" id="profileImageInput"
                                   accept="image/jpeg,image/png,image/webp,image/gif"
                                   style="display:none;"
                                   onchange="previewProfileImage(this)">

                            <button type="button" onclick="document.getElementById('profileImageInput').click()"
                                    style="display:flex;align-items:center;justify-content:center;gap:6px;width:100%;padding:.55rem 1rem;border-radius:10px;border:2px solid var(--rider-primary);background:white;color:var(--rider-primary);font-weight:700;font-size:.82rem;cursor:pointer;margin-bottom:.5rem;transition:background .15s;">
                                <i class="fa-solid fa-upload"></i>
                                <?= $profileImageUrl ? 'Change Photo' : 'Upload Photo' ?>
                            </button>

                            <?php if ($profileImageUrl): ?>
                            <button type="submit" name="remove_profile_image" value="1"
                                    onclick="return confirm('Remove your profile photo?')"
                                    style="display:flex;align-items:center;justify-content:center;gap:6px;width:100%;background:none;border:1px solid #FECACA;color:#DC2626;border-radius:10px;padding:.4rem 1rem;font-size:.8rem;font-weight:700;cursor:pointer;">
                                <i class="fa-solid fa-trash"></i> Remove Photo
                            </button>
                            <?php endif; ?>

                            <div style="font-size:.7rem;color:var(--text-muted);margin-top:.75rem;line-height:1.5;">
                                JPG, PNG, WebP or GIF · Max 3MB
                            </div>
                        </div>
                    </div>

                    <!-- Stats card -->
                    <div class="form-card">
                        <div class="form-card-head">
                            <i class="fa-solid fa-chart-simple" style="color:var(--rider-primary);"></i>
                            <h6>Your Stats</h6>
                        </div>
                        <div class="form-card-body">
                            <div class="profile-stat">
                                <div class="profile-stat-icon" style="background:#DCFCE7;">
                                    <i class="fa-solid fa-circle-check" style="color:#16A34A;"></i>
                                </div>
                                <div>
                                    <div class="profile-stat-val" style="color:#16A34A;"><?= $delivered ?></div>
                                    <div class="profile-stat-lbl">Deliveries Done</div>
                                </div>
                            </div>
                            <div class="profile-stat">
                                <div class="profile-stat-icon" style="background:#EEF2FF;">
                                    <i class="fa-solid fa-receipt" style="color:#4F46E5;"></i>
                                </div>
                                <div>
                                    <div class="profile-stat-val" style="color:#4F46E5;"><?= $totalOrders ?></div>
                                    <div class="profile-stat-lbl">Total Orders</div>
                                </div>
                            </div>
                            <div class="profile-stat" style="border-color:#BBF7D0;background:#F0FDF4;">
                                <div class="profile-stat-icon" style="background:#DCFCE7;">
                                    <i class="fa-solid fa-peso-sign" style="color:var(--rider-primary);"></i>
                                </div>
                                <div>
                                    <div class="profile-stat-val" style="color:var(--rider-primary);">₱<?= number_format($totalEarnings,2) ?></div>
                                    <div class="profile-stat-lbl">Total Earnings</div>
                                </div>
                            </div>

                            <div style="margin-top:.85rem;background:linear-gradient(135deg,#F0FDF4,#DCFCE7);border-radius:12px;padding:.85rem;border:1px solid #BBF7D0;">
                                <div style="font-size:.72rem;font-weight:800;color:var(--rider-primary);margin-bottom:.4rem;">
                                    <i class="fa-solid fa-circle-info me-1"></i> Location Tip
                                </div>
                                <div style="font-size:.72rem;color:#166534;line-height:1.5;">
                                    Keep your location updated so farmers can see you on the rider map and assign pickups faster.
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- ── RIGHT: Form fields ── -->
                <div class="col-lg-8 fade-up fade-up-1">

                    <!-- Personal Info -->
                    <div class="form-card">
                        <div class="form-card-head">
                            <i class="fa-solid fa-user" style="color:var(--rider-primary);"></i>
                            <h6>Personal Information</h6>
                        </div>
                        <div class="form-card-body">

                            <div class="gl-form-group">
                                <label>Full Name <span style="color:red;">*</span></label>
                                <div class="gl-input-wrap">
                                    <i class="fa-solid fa-user input-icon"></i>
                                    <input type="text" name="name" class="gl-input"
                                           value="<?= sanitize($user['name']) ?>" required>
                                </div>
                            </div>

                            <div class="gl-form-group">
                                <label>Email Address</label>
                                <div class="gl-input-wrap">
                                    <i class="fa-solid fa-envelope input-icon"></i>
                                    <input type="email" class="gl-input"
                                           value="<?= sanitize($user['email']) ?>" disabled
                                           style="background:#F9FAFB;cursor:not-allowed;color:var(--text-muted);">
                                </div>
                                <small style="color:var(--text-muted);font-size:.75rem;">Email cannot be changed.</small>
                            </div>

                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <div class="gl-form-group">
                                        <label>Phone Number</label>
                                        <div class="gl-input-wrap">
                                            <i class="fa-solid fa-phone input-icon"></i>
                                            <input type="text" name="phone" class="gl-input"
                                                   placeholder="09XXXXXXXXX"
                                                   value="<?= sanitize($user['phone'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="gl-form-group">
                                        <label>Location</label>
                                        <div class="gl-input-wrap">
                                            <i class="fa-solid fa-location-dot input-icon"></i>
                                          <input type="text" name="location" class="gl-input"
       placeholder="City / Province"
       value="<?= sanitize($user['location'] ?? '') ?>"
       id="locationText"
       onchange="searchLocation(this.value)">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Rider vehicle info (if riders table exists) -->
                            <?php
                            $riderTables = $pdo->query("SHOW TABLES LIKE 'riders'")->fetchAll();
                            $riderRow = null;
                            if (!empty($riderTables)) {
                                $rStmt = $pdo->prepare("SELECT * FROM riders WHERE user_id = ?");
                                $rStmt->execute([$userId]);
                                $riderRow = $rStmt->fetch();
                            }
                            if (!empty($riderTables)):
                            ?>
                            <hr style="border-color:#E2E8F0;margin:1.25rem 0;">
                            <div style="font-weight:800;font-size:.95rem;color:var(--text);margin-bottom:1rem;display:flex;align-items:center;gap:8px;">
                                <span style="background:#FFF7ED;border-radius:8px;padding:4px 10px;font-size:.78rem;color:#EA580C;border:1px solid #FED7AA;">🛵 Rider Details</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <div class="gl-form-group">
                                        <label>Vehicle Type</label>
                                        <div class="gl-input-wrap">
                                            <i class="fa-solid fa-motorcycle input-icon"></i>
                                            <select name="vehicle_type" class="gl-input" style="appearance:auto;">
                                                <option value="">Select type</option>
                                                <?php foreach (['Motorcycle','Scooter','Bicycle','Tricycle','Van'] as $vt): ?>
                                                <option value="<?= $vt ?>" <?= ($riderRow['vehicle_type'] ?? '') === $vt ? 'selected' : '' ?>><?= $vt ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="gl-form-group">
                                        <label>Plate / ID Number</label>
                                        <div class="gl-input-wrap">
                                            <i class="fa-solid fa-id-card input-icon"></i>
                                            <input type="text" name="plate_number" class="gl-input"
                                                   placeholder="e.g. ABC 1234"
                                                   value="<?= sanitize($riderRow['plate_number'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                        </div>
                    </div>

                    <!-- Pin Map -->
                    <div class="form-card fade-up fade-up-2">
                        <div class="form-card-head">
                            <i class="fa-solid fa-map-pin" style="color:var(--rider-primary);"></i>
                            <h6>Pin Your Exact Location <span style="font-size:.72rem;color:var(--text-muted);font-weight:600;">— drag the pin or click the map</span></h6>
                        </div>
                        <div class="form-card-body">
                            <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
                            <div id="pinMap" style="height:280px;border-radius:12px;border:1.5px solid #BBF7D0;margin-bottom:.5rem;"></div>
                            <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

                            <input type="hidden" name="latitude"  id="lat_input" value="<?= $savedLat ?? '' ?>">
                            <input type="hidden" name="longitude" id="lng_input" value="<?= $savedLng ?? '' ?>">

                            <div id="coordDisplay" style="font-size:.73rem;color:var(--text-muted);text-align:center;padding:4px 0;background:#F0FDF4;border-radius:8px;border:1px solid #BBF7D0;">
                                <?php if ($savedLat): ?>
                                    📍 Pinned: <?= number_format($savedLat,5) ?>, <?= number_format($savedLng,5) ?>
                                <?php else: ?>
                                    Click the map or type your location above to set your pin
                                <?php endif; ?>
                            </div>

                            <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                const defaultLat = <?= $defaultLat ?>;
                                const defaultLng = <?= $defaultLng ?>;
                                const hasSaved   = <?= $savedLat ? 'true' : 'false' ?>;

                                const map = L.map('pinMap').setView([defaultLat, defaultLng], hasSaved ? 14 : 12);
                                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                    attribution: '© OpenStreetMap'
                                }).addTo(map);

                                const pinIcon = L.divIcon({
                                    html: '<div style="background:#1B5E20;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 3px 10px rgba(27,94,32,.4);font-size:18px;">🛵</div>',
                                    className: '',
                                    iconSize: [38, 38],
                                    iconAnchor: [19, 19]
                                });

                                let marker = L.marker([defaultLat, defaultLng], {
                                    draggable: true,
                                    icon: pinIcon
                                }).addTo(map);

                                function updatePin(lat, lng) {
                                    document.getElementById('lat_input').value = lat.toFixed(7);
                                    document.getElementById('lng_input').value = lng.toFixed(7);
                                    document.getElementById('coordDisplay').innerHTML =
                                        '📍 Pinned: ' + lat.toFixed(5) + ', ' + lng.toFixed(5) +
                                        ' <span style="color:#16a34a;font-weight:700;">✓ saved on submit</span>';

                                    fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng, {
                                        headers: { 'User-Agent': 'GreenLink/1.0' }
                                    })
                                    .then(r => r.json())
                                    .then(data => {
                                        if (data && data.address) {
                                            const a = data.address;
                                            const parts = [
                                                a.road || a.pedestrian || '',
                                                a.neighbourhood || a.suburb || '',
                                                a.village || a.hamlet || '',
                                                a.city || a.town || a.municipality || a.county || '',
                                                a.province || a.state || '',
                                            ].filter(v => v.trim() !== '');
                                            const unique  = parts.filter((v,i,arr) => arr.indexOf(v) === i);
                                            const readable = unique.join(', ') || data.display_name.split(',').slice(0,4).join(',').trim();
                                            const locField = document.getElementById('locationText');
                                            if (locField && readable) locField.value = readable;
                                            document.getElementById('coordDisplay').innerHTML =
                                                '📍 ' + (readable || 'Location pinned') +
                                                ' <span style="color:#16a34a;font-weight:700;">✓ saved on submit</span>';
                                        }
                                    })
                                    .catch(() => {});
                                }

                                marker.on('dragend', function(e) {
                                    const p = e.target.getLatLng();
                                    updatePin(p.lat, p.lng);
                                });

                                map.on('click', function(e) {
                                    marker.setLatLng(e.latlng);
                                    updatePin(e.latlng.lat, e.latlng.lng);
                                });

                                let geoTimer = null;
                                window.searchLocation = function(val) {
                                    clearTimeout(geoTimer);
                                    if (val.length < 4) return;
                                    geoTimer = setTimeout(function() {
                                        fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(val + ', Philippines'), {
                                            headers: {'User-Agent': 'GreenLink/1.0'}
                                        })
                                        .then(r => r.json())
                                        .then(data => {
                                            if (data && data[0]) {
                                                const lat = parseFloat(data[0].lat);
                                                const lng = parseFloat(data[0].lon);
                                                map.setView([lat, lng], 13);
                                                marker.setLatLng([lat, lng]);
                                                updatePin(lat, lng);
                                            }
                                        });
                                    }, 800);
                                };

                                setTimeout(() => map.invalidateSize(), 300);
                            });
                            </script>
                        </div>
                    </div>

                    <!-- Save / Cancel -->
                    <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
                        <button type="submit"
                                style="display:inline-flex;align-items:center;gap:8px;padding:.75rem 2rem;border-radius:12px;border:none;background:linear-gradient(135deg,#1B5E20,#2E7D32);color:white;font-weight:800;font-size:.9rem;cursor:pointer;box-shadow:0 4px 14px rgba(27,94,32,.35);transition:opacity .15s;">
                            <i class="fa-solid fa-floppy-disk"></i> Save Changes
                        </button>
                        <a href="dashboard.php"
                           style="display:inline-flex;align-items:center;gap:8px;padding:.75rem 1.5rem;border-radius:12px;border:2px solid #CBD5E1;background:white;color:var(--text-muted);font-weight:700;font-size:.9rem;text-decoration:none;transition:opacity .15s;">
                            <i class="fa-solid fa-arrow-left"></i> Cancel
                        </a>
                    </div>

                </div>
            </div>
        </form>
    </div>
</div>

<script>
function previewProfileImage(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    if (file.size > 3 * 1024 * 1024) {
        alert('Image must be under 3MB.');
        input.value = '';
        return;
    }
    const reader = new FileReader();
    reader.onload = function(e) {
        const preview = document.getElementById('profilePreview');
        const initial = document.getElementById('profileInitial');
        preview.src = e.target.result;
        preview.style.display = 'block';
        if (initial) initial.style.display = 'none';
    };
    reader.readAsDataURL(file);
}
</script>

<?php
// Handle vehicle info save if riders table exists
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && $success) {
    $riderTables2 = $pdo->query("SHOW TABLES LIKE 'riders'")->fetchAll();
    if (!empty($riderTables2)) {
        $vt = trim($_POST['vehicle_type'] ?? '');
        $pn = trim($_POST['plate_number'] ?? '');
        $exists = $pdo->prepare("SELECT id FROM riders WHERE user_id = ?");
        $exists->execute([$userId]);
        if ($exists->fetch()) {
            $pdo->prepare("UPDATE riders SET vehicle_type=?, plate_number=? WHERE user_id=?")
                ->execute([$vt, $pn, $userId]);
        } else {
            $pdo->prepare("INSERT INTO riders (user_id, vehicle_type, plate_number) VALUES (?,?,?)")
                ->execute([$userId, $vt, $pn]);
        }
    }
}
?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>