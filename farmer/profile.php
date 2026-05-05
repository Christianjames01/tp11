<?php
$page_title = 'Edit Profile';
require_once __DIR__ . '/../includes/header.php';
requireLogin();

$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];
$role   = $_SESSION['role'];

// Initial fetch
$user = $pdo->prepare("SELECT * FROM users WHERE id=?");
$user->execute([$userId]);
$user = $user->fetch();

$farmer = null;
if ($role === 'farmer') {
    $fs = $pdo->prepare("SELECT * FROM farmers WHERE user_id=?");
    $fs->execute([$userId]);
    $farmer = $fs->fetch();
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name         = trim($_POST['name'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $location     = trim($_POST['location'] ?? '');
    $profileImage = $user['profile_image'];

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

    if (isset($_POST['remove_profile_image']) && $user['profile_image']) {
        $uploadDir = __DIR__ . '/../assets/images/profiles/';
        if (file_exists($uploadDir . $user['profile_image'])) @unlink($uploadDir . $user['profile_image']);
        $profileImage = null;
    }

    if (!$error) {
        if (!$name) {
            $error = 'Name is required.';
        } else {
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

            $uCols     = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $hasLatLng = in_array('latitude', $uCols);

            $manualLat = !empty($_POST['latitude'])  ? floatval($_POST['latitude'])  : null;
            $manualLng = !empty($_POST['longitude']) ? floatval($_POST['longitude']) : null;
            if ($manualLat && $manualLng) {
                $latitude  = $manualLat;
                $longitude = $manualLng;
            }

            if ($hasLatLng) {
                $pdo->prepare("UPDATE users SET name=?, phone=?, location=?, profile_image=?, latitude=?, longitude=?, updated_at=NOW() WHERE id=?")
                    ->execute([$name, $phone, $location, $profileImage, $latitude, $longitude, $userId]);
            } else {
                $pdo->prepare("UPDATE users SET name=?, phone=?, location=?, profile_image=?, updated_at=NOW() WHERE id=?")
                    ->execute([$name, $phone, $location, $profileImage, $userId]);
            }
            $_SESSION['user_name'] = $name;

            if ($role === 'farmer') {
                $farmName = trim($_POST['farm_name'] ?? '');
                $farmLoc  = trim($_POST['farm_location'] ?? '');
                $bio      = trim($_POST['bio'] ?? '');
                if ($farmer) {
                    $pdo->prepare("UPDATE farmers SET farm_name=?, farm_location=?, bio=? WHERE user_id=?")
                        ->execute([$farmName, $farmLoc, $bio, $userId]);
                } else {
                    $pdo->prepare("INSERT INTO farmers (user_id, farm_name, farm_location, bio) VALUES (?,?,?,?)")
                        ->execute([$userId, $farmName, $farmLoc, $bio]);
                }
            }

            // Refresh both after saving ✅
            $u2 = $pdo->prepare("SELECT * FROM users WHERE id=?");
            $u2->execute([$userId]);
            $user = $u2->fetch();

            if ($role === 'farmer') {
                $fs2 = $pdo->prepare("SELECT * FROM farmers WHERE user_id=?");
                $fs2->execute([$userId]);
                $farmer = $fs2->fetch();
            }

            $success = 'Profile updated successfully! 🎉';
        }
    }
}

$profileImageUrl = !empty($user['profile_image'])
    ? BASE_URL . '/assets/images/profiles/' . htmlspecialchars($user['profile_image'])
    : null;

if ($role === 'farmer')       $dashUrl = '../farmer/dashboard.php';
elseif ($role === 'admin')    $dashUrl = '../admin/dashboard.php';
else                          $dashUrl = '../buyer/dashboard.php';

$isPrem = $role === 'farmer' && !empty($farmer['is_premium']) && !empty($farmer['premium_until']) && strtotime($farmer['premium_until']) > time();
?>

<div style="background:<?= $isPrem ? 'linear-gradient(180deg,#fffbeb 0%,#fef9ee 40%,var(--bg) 100%)' : 'var(--bg)' ?>;min-height:100vh;padding-bottom:3rem;">
    <div class="page-header" style="<?= $isPrem ? 'background:linear-gradient(135deg,#78350f,#92400e,#b45309,#d97706);box-shadow:0 4px 20px rgba(217,119,6,.25);' : '' ?>">
        <div class="container">
            <div class="d-flex align-items-center gap-2">
                <a href="<?= $dashUrl ?>" style="color:<?= $isPrem ? 'white' : 'var(--primary)' ?>;text-decoration:none;"><i class="fa-solid fa-arrow-left"></i></a>
                <div>
                    <h1 style="<?= $isPrem ? 'color:white;' : '' ?>">
                        <?php if ($isPrem): ?>
                            ⭐ Edit Profile
                        <?php else: ?>
                            <i class="fa-solid fa-user-pen text-green me-2"></i>Edit Profile
                        <?php endif; ?>
                    </h1>
                    <div class="page-breadcrumb" style="<?= $isPrem ? 'color:rgba(255,255,255,.8);' : '' ?>">
                        <a href="<?= $dashUrl ?>" style="<?= $isPrem ? 'color:rgba(255,255,255,.7);' : '' ?>">Dashboard</a> › Edit Profile
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <?php if ($error): ?>
        <div style="background:#FFF5F5;border:1px solid #FED7D7;color:#C53030;border-radius:12px;padding:0.8rem 1rem;margin-bottom:1.2rem;font-size:0.88rem;font-weight:600;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?= sanitize($error) ?>
        </div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div style="background:#F0FFF4;border:1px solid #9AE6B4;color:#276749;border-radius:12px;padding:0.8rem 1rem;margin-bottom:1.2rem;font-size:0.88rem;font-weight:600;">
            <i class="fa-solid fa-circle-check me-2"></i><?= sanitize($success) ?>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="row g-4">
                <!-- LEFT: Profile image card -->
                <div class="col-lg-4">
                    <div class="gl-card">
                        <div class="gl-card-body text-center">
                            <h5 style="font-weight:800;margin-bottom:1.5rem;">Profile Photo</h5>

                            <!-- Current image preview -->
                            <div id="profilePreviewWrap" style="position:relative;display:inline-block;margin-bottom:1.2rem;">
                                <?php if ($profileImageUrl): ?>
                                <img id="profilePreview" src="<?= $profileImageUrl ?>"
                                     alt="Profile"
                                     style="width:120px;height:120px;border-radius:50%;object-fit:cover;border:4px solid var(--primary);box-shadow:0 4px 20px rgba(0,0,0,0.1);">
                                <?php else: ?>
                                <div id="profileInitial" style="width:120px;height:120px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:2.5rem;border:4px solid var(--primary);margin:0 auto;">
                                    <?= strtoupper(substr($user['name'],0,1)) ?>
                                </div>
                                <img id="profilePreview" src="" style="width:120px;height:120px;border-radius:50%;object-fit:cover;border:4px solid var(--primary);box-shadow:0 4px 20px rgba(0,0,0,0.1);display:none;">
                                <?php endif; ?>

                                <!-- Camera overlay button -->
                                <button type="button" onclick="document.getElementById('profileImageInput').click()"
                                        style="position:absolute;bottom:4px;right:4px;width:34px;height:34px;border-radius:50%;background:var(--primary);border:2px solid white;color:white;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:0.8rem;box-shadow:0 2px 8px rgba(0,0,0,0.2);">
                                    <i class="fa-solid fa-camera"></i>
                                </button>
                            </div>

                            <!-- Hidden file input -->
                            <input type="file" name="profile_image" id="profileImageInput"
                                   accept="image/jpeg,image/png,image/webp,image/gif"
                                   style="display:none;"
                                   onchange="previewProfileImage(this)">

                            <!-- Upload / Change button -->
                            <div>
                                <button type="button" onclick="document.getElementById('profileImageInput').click()"
                                        class="btn-outline-green" style="padding:0.5rem 1.2rem;font-size:0.85rem;width:100%;justify-content:center;margin-bottom:0.5rem;">
                                    <i class="fa-solid fa-upload"></i>
                                    <?= $profileImageUrl ? 'Change Photo' : 'Upload Photo' ?>
                                </button>

                                <?php if ($profileImageUrl): ?>
                                <button type="submit" name="remove_profile_image" value="1"
                                        onclick="return confirm('Remove your profile photo?')"
                                        style="background:none;border:1px solid #FED7D7;color:#C53030;border-radius:10px;padding:0.4rem 1rem;font-size:0.8rem;font-weight:700;cursor:pointer;width:100%;">
                                    <i class="fa-solid fa-trash me-1"></i> Remove Photo
                                </button>
                                <?php endif; ?>
                            </div>

                            <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.8rem;line-height:1.5;">
                                JPG, PNG, WebP or GIF<br>Max size: 3MB
                            </div>

                            <!-- Tip -->
                            <div style="background:var(--pale-green);border-radius:10px;padding:0.75rem;margin-top:1.2rem;font-size:0.78rem;color:var(--primary-dark);font-weight:600;text-align:left;">
                                💡 A clear profile photo helps buyers and farmers trust you more!
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Form fields -->
                <div class="col-lg-8">
                    <div class="gl-card">
                        <div class="gl-card-body">
                            <h5 style="font-weight:800;margin-bottom:1.5rem;">Personal Information</h5>

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
                                <small style="color:var(--text-muted);font-size:0.75rem;">Email cannot be changed.</small>
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

                            <!-- Map pin section -->
                            <div class="gl-form-group">
                                <label>
                                    <i class="fa-solid fa-map-pin text-green me-1"></i>
                                    Pin Your Exact Location
                                    <span style="font-size:.72rem;color:var(--text-muted);font-weight:600;"> — drag the pin or click the map</span>
                                </label>

                                <?php
                                $uCols2    = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
                                $hasLL     = in_array('latitude', $uCols2);
                                $savedLat  = ($hasLL && !empty($user['latitude']))  ? floatval($user['latitude'])  : null;
                                $savedLng  = ($hasLL && !empty($user['longitude'])) ? floatval($user['longitude']) : null;
                                $defaultLat = $savedLat ?? 7.1907;
                                $defaultLng = $savedLng ?? 125.4553;
                                ?>

                                <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
                                <div id="pinMap" style="height:260px;border-radius:12px;border:1.5px solid var(--border);margin-bottom:.5rem;"></div>
                                <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

                                <input type="hidden" name="latitude"  id="lat_input" value="<?= $savedLat ?? '' ?>">
                                <input type="hidden" name="longitude" id="lng_input" value="<?= $savedLng ?? '' ?>">

                                <div id="coordDisplay" style="font-size:.73rem;color:var(--text-muted);text-align:center;padding:4px 0;">
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
                                        html: '<div style="background:var(--primary,#3E7C3F);width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 3px 10px rgba(0,0,0,.3);font-size:18px;">📍</div>',
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

    // Reverse geocode to update the location text field
    fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng, {
        headers: { 'User-Agent': 'GreenLink/1.0' }
    })
    .then(r => r.json())
    .then(data => {
        if (data && data.address) {
            const a = data.address;
            // Build full detailed address
            const parts = [
                a.house_number || '',
                a.road || a.pedestrian || a.footway || '',
                a.neighbourhood || a.suburb || a.quarter || '',
                a.village || a.hamlet || '',
                a.city_district || a.district || '',
                a.city || a.town || a.municipality || a.county || '',
                a.province || a.state || '',
            ].filter(v => v.trim() !== '');

            // Remove duplicates
            const unique = parts.filter((v, i, arr) => arr.indexOf(v) === i);
            const readable = unique.join(', ') || data.display_name.split(',').slice(0, 4).join(',').trim();

            const locField = document.getElementById('locationText');
            if (locField && readable) {
                locField.value = readable;
            }
            document.getElementById('coordDisplay').innerHTML =
                '📍 ' + (readable || 'Location pinned') +
                ' <span style="color:#16a34a;font-weight:700;">✓ saved on submit</span>';
        }
    })
    .catch(() => {}); // silently fail if offline
}

                                    marker.on('dragend', function(e) {
                                        const p = e.target.getLatLng();
                                        updatePin(p.lat, p.lng);
                                    });

                                    map.on('click', function(e) {
                                        marker.setLatLng(e.latlng);
                                        updatePin(e.latlng.lat, e.latlng.lng);
                                    });

                                    // Geocode when user types in location field
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
                                });
                                </script>
                            </div>

                            <?php if ($role === 'farmer'): ?>
                            <hr style="border-color:var(--border);margin:1.5rem 0;">
                            <h5 style="font-weight:800;margin-bottom:1.2rem;">🌾 Farm Details</h5>

                            <div class="gl-form-group">
                                <label>Farm Name</label>
                                <div class="gl-input-wrap">
                                    <i class="fa-solid fa-tractor input-icon"></i>
                                    <input type="text" name="farm_name" class="gl-input"
                                           placeholder="My Organic Farm"
                                           value="<?= sanitize($farmer['farm_name'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="gl-form-group">
                                <label>Farm Location</label>
                                <div class="gl-input-wrap">
                                    <i class="fa-solid fa-map-pin input-icon"></i>
                                    <input type="text" name="farm_location" class="gl-input"
                                           placeholder="e.g. Davao del Sur"
                                           value="<?= sanitize($farmer['farm_location'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="gl-form-group">
                                <label>Short Bio</label>
                                <div class="gl-input-wrap">
                                    <i class="fa-solid fa-pen input-icon" style="top:14px;transform:none;"></i>
                                    <textarea name="bio" class="gl-input" rows="3"
                                              placeholder="Tell buyers about your farm..."><?= sanitize($farmer['bio'] ?? '') ?></textarea>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="d-flex gap-2 mt-3">
                                <button type="submit" class="btn-green" style="padding:0.75rem 2rem;">
                                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                                </button>
                                <a href="<?= $dashUrl ?>" class="btn-outline-green" style="padding:0.75rem 1.5rem;">
                                    Cancel
                                </a>
                            </div>
                        </div>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>