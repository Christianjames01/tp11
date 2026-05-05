<?php
$page_title = 'Register';
require_once __DIR__ . '/../config/database.php';

if (isLoggedIn()) { header('Location: ../index.php'); exit(); }

$error = '';
$success = '';
$role = sanitize($_GET['role'] ?? 'buyer');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $role     = $_POST['role'] ?? 'buyer';
    $phone    = trim($_POST['phone'] ?? '');
    $location = trim($_POST['location'] ?? '');

    if (!$name || !$email || !$password || !$confirm) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
  } elseif (!in_array($role, ['farmer', 'buyer', 'rider'])) {
        $error = 'Invalid role selected.';
          } elseif ($role === 'rider' && empty($_POST['vehicle_type'])) {
        $error = 'Please select your vehicle type.';
    } elseif ($role === 'rider' && empty(trim($_POST['plate_number']))) {
        $error = 'Please enter your plate number.';
    } else {
        $pdo = getDBConnection();
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = 'This email is already registered. Please login.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, phone, location) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$name, $email, $hash, $role, $phone, $location]);
            $userId = $pdo->lastInsertId();

            if ($role === 'farmer') {
                $farmName = trim($_POST['farm_name'] ?? $name . "'s Farm");
                $farmLoc  = trim($_POST['farm_location'] ?? $location);
                $bio      = trim($_POST['bio'] ?? '');
                $pdo->prepare("INSERT INTO farmers (user_id, farm_name, farm_location, bio) VALUES (?,?,?,?)")
                    ->execute([$userId, $farmName, $farmLoc, $bio]);
            }
              if ($role === 'rider') {
                $vehicleType = trim($_POST['vehicle_type'] ?? '');
                $plateNumber = trim(strtoupper($_POST['plate_number'] ?? ''));
                $pdo->prepare("INSERT INTO riders (user_id, vehicle_type, plate_number) VALUES (?,?,?)")
                    ->execute([$userId, $vehicleType, $plateNumber]);
            }

            setFlash('success', "Welcome to GreenLink, $name! 🌿 Your account has been created.");


            header('Location: login.php'); exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register – GreenLink Innovators</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/main.css" rel="stylesheet">
</head>
<body>
<div class="auth-wrapper" style="align-items:flex-start;padding-top:2rem;">
    <div class="auth-card fade-up" style="max-width:520px;">
        <div class="auth-logo">
            <a href="../index.php" class="d-block text-decoration-none">
                <div class="logo-icon">🌿</div>
                <h2>Join GreenLink</h2>
            </a>
            <p>Create your free account today.</p>
        </div>

        <?php if ($error): ?>
        <div style="background:#FFF5F5;border:1px solid #FED7D7;color:#C53030;border-radius:12px;padding:0.8rem 1rem;margin-bottom:1.2rem;font-size:0.88rem;font-weight:600;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?= sanitize($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="">
            <!-- Role Selection -->
            <div class="gl-form-group">
                <label>I am a...</label>
                <div class="row g-2">
                    <div class="col-4">
                        <input type="radio" name="role" id="role-farmer" value="farmer" <?= $role === 'farmer' ? 'checked' : '' ?> style="display:none;">
                        <label for="role-farmer" id="label-farmer" class="d-block text-center p-3 rounded-3 border-2 border" style="cursor:pointer;border-radius:14px !important;transition:all 0.2s;<?= $role === 'farmer' ? 'background:var(--pale-green);border-color:var(--primary) !important;' : 'background:var(--bg);border-color:var(--border);' ?>">
                            <div style="font-size:2rem;">🧑‍🌾</div>
                            <div style="font-weight:800;font-size:0.9rem;margin-top:4px;">Farmer</div>
                            <div style="font-size:0.72rem;color:var(--text-muted);">Sell my produce</div>
                        </label>
                    </div>
                    <div class="col-4">
                        <input type="radio" name="role" id="role-buyer" value="buyer" <?= $role === 'buyer' ? 'checked' : '' ?> style="display:none;">
                        <label for="role-buyer" id="label-buyer" class="d-block text-center p-3 rounded-3 border-2 border" style="cursor:pointer;border-radius:14px !important;transition:all 0.2s;<?= $role === 'buyer' ? 'background:var(--pale-green);border-color:var(--primary) !important;' : 'background:var(--bg);border-color:var(--border);' ?>">
                            <div style="font-size:2rem;">🍽️</div>
                            <div style="font-weight:800;font-size:0.9rem;margin-top:4px;">Buyer</div>
                            <div style="font-size:0.72rem;color:var(--text-muted);">Buy fresh produce</div>
                        </label>
                    </div>
                    <div class="col-4">
                        <input type="radio" name="role" id="role-rider" value="rider" <?= $role === 'rider' ? 'checked' : '' ?> style="display:none;">
                        <label for="role-rider" id="label-rider" class="d-block text-center p-3 rounded-3 border-2 border" style="cursor:pointer;border-radius:14px !important;transition:all 0.2s;<?= $role === 'rider' ? 'background:var(--pale-green);border-color:var(--primary) !important;' : 'background:var(--bg);border-color:var(--border);' ?>">
                            <div style="font-size:2rem;">🏍️</div>
                            <div style="font-weight:800;font-size:0.9rem;margin-top:4px;">Rider</div>
                            <div style="font-size:0.72rem;color:var(--text-muted);">Deliver orders</div>
                        </label>
                    </div>
                </div>
            </div>

            <div class="gl-form-group">
                <label>Full Name <span style="color:red;">*</span></label>
                <div class="gl-input-wrap">
                    <i class="fa-solid fa-user input-icon"></i>
                    <input type="text" name="name" class="gl-input" placeholder="Your full name" value="<?= sanitize($_POST['name'] ?? '') ?>" required>
                </div>
            </div>
            <div class="gl-form-group">
                <label>Email Address <span style="color:red;">*</span></label>
                <div class="gl-input-wrap">
                    <i class="fa-solid fa-envelope input-icon"></i>
                    <input type="email" name="email" class="gl-input" placeholder="your@email.com" value="<?= sanitize($_POST['email'] ?? '') ?>" required>
                </div>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <div class="gl-form-group">
                        <label>Phone Number</label>
                        <div class="gl-input-wrap">
                            <i class="fa-solid fa-phone input-icon"></i>
                            <input type="text" name="phone" class="gl-input" placeholder="09XXXXXXXXX" value="<?= sanitize($_POST['phone'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="gl-form-group">
                        <label>Location</label>
                        <div class="gl-input-wrap">
                            <i class="fa-solid fa-location-dot input-icon"></i>
                            <input type="text" name="location" class="gl-input" placeholder="City / Province" value="<?= sanitize($_POST['location'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Farmer-specific fields -->
            <div id="farmer-fields" style="<?= $role === 'farmer' ? '' : 'display:none;' ?>">
                <div style="background:var(--bg);border-radius:12px;padding:1rem;margin-bottom:1rem;">
                    <div style="font-size:0.8rem;font-weight:800;color:var(--primary);margin-bottom:0.8rem;text-transform:uppercase;letter-spacing:0.5px;">🌾 Farm Details</div>
                    <div class="gl-form-group" style="margin-bottom:0.8rem;">
                        <label>Farm Name</label>
                        <div class="gl-input-wrap">
                            <i class="fa-solid fa-tractor input-icon"></i>
                            <input type="text" name="farm_name" class="gl-input" placeholder="My Organic Farm" value="<?= sanitize($_POST['farm_name'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="gl-form-group" style="margin-bottom:0.8rem;">
                        <label>Farm Location</label>
                        <div class="gl-input-wrap">
                            <i class="fa-solid fa-map-pin input-icon"></i>
                            <input type="text" name="farm_location" class="gl-input" placeholder="e.g. Davao del Sur" value="<?= sanitize($_POST['farm_location'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="gl-form-group" style="margin-bottom:0;">
                        <label>Short Bio</label>
                        <div class="gl-input-wrap">
                            <i class="fa-solid fa-pen input-icon" style="top:14px;transform:none;"></i>
                            <textarea name="bio" class="gl-input" placeholder="Tell buyers about your farm..." rows="2"><?= sanitize($_POST['bio'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div id="rider-fields" style="<?= $role === 'rider' ? '' : 'display:none;' ?>">
                <div style="background:var(--bg);border-radius:12px;padding:1rem;margin-bottom:1rem;">
                    <div style="font-size:0.8rem;font-weight:800;color:var(--primary);margin-bottom:0.8rem;text-transform:uppercase;letter-spacing:0.5px;">🏍️ Rider Details</div>
                    <div class="gl-form-group" style="margin-bottom:0.8rem;">
                        <label>Vehicle Type <span style="color:red;">*</span></label>
                        <div class="gl-input-wrap">
                            <i class="fa-solid fa-motorcycle input-icon"></i>
                            <select name="vehicle_type" class="gl-input" style="appearance:none;padding-left:2.6rem;">
                                <option value="" disabled <?= empty($_POST['vehicle_type']) ? 'selected' : '' ?>>Select vehicle type</option>
                                <option value="motorcycle" <?= ($_POST['vehicle_type'] ?? '') === 'motorcycle' ? 'selected' : '' ?>>🏍️ Motorcycle</option>
                                <option value="bicycle"    <?= ($_POST['vehicle_type'] ?? '') === 'bicycle'    ? 'selected' : '' ?>>🚲 Bicycle</option>
                                <option value="scooter"    <?= ($_POST['vehicle_type'] ?? '') === 'scooter'    ? 'selected' : '' ?>>🛵 Scooter</option>
                                <option value="tricycle"   <?= ($_POST['vehicle_type'] ?? '') === 'tricycle'   ? 'selected' : '' ?>>🛺 Tricycle</option>
                                <option value="van"        <?= ($_POST['vehicle_type'] ?? '') === 'van'        ? 'selected' : '' ?>>🚐 Van</option>
                            </select>
                        </div>
                    </div>
                    <div class="gl-form-group" style="margin-bottom:0;">
                        <label>Plate Number <span style="color:red;">*</span></label>
                        <div class="gl-input-wrap">
                            <i class="fa-solid fa-id-card input-icon"></i>
                            <input type="text" name="plate_number" class="gl-input" placeholder="e.g. ABC 1234" value="<?= sanitize($_POST['plate_number'] ?? '') ?>" style="text-transform:uppercase;" maxlength="30">
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-2">
                <div class="col-6">
                    <div class="gl-form-group">
                        <label>Password <span style="color:red;">*</span></label>
                        <div class="gl-input-wrap">
                            <i class="fa-solid fa-lock input-icon"></i>
                            <input type="password" name="password" class="gl-input" placeholder="Min. 6 characters" required>
                        </div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="gl-form-group">
                        <label>Confirm Password <span style="color:red;">*</span></label>
                        <div class="gl-input-wrap">
                            <i class="fa-solid fa-lock-open input-icon"></i>
                            <input type="password" name="confirm_password" class="gl-input" placeholder="Repeat password" required>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-green w-100 justify-content-center" style="padding:0.85rem;">
                <i class="fa-solid fa-seedling"></i> Create My Account
            </button>
        </form>

        <div class="gl-divider"></div>
        <p style="text-align:center;color:var(--text-muted);font-size:0.88rem;margin:0;">
            Already have an account? <a href="login.php" style="color:var(--primary);font-weight:700;text-decoration:none;">Sign in →</a>
        </p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
(function () {
    const ROLES = ['farmer', 'buyer', 'rider'];
    function syncUI() {
        const active = document.querySelector('input[name="role"]:checked')?.value;
        ROLES.forEach(r => {
            const lbl = document.getElementById('label-' + r);
            if (!lbl) return;
            lbl.style.background  = r === active ? 'var(--pale-green)' : 'var(--bg)';
            lbl.style.borderColor = r === active ? 'var(--primary)'    : 'var(--border)';
        });
        document.getElementById('farmer-fields').style.display = active === 'farmer' ? '' : 'none';
        document.getElementById('rider-fields').style.display  = active === 'rider'  ? '' : 'none';
    }
    ROLES.forEach(r => {
        const el = document.getElementById('role-' + r);
        if (el) el.addEventListener('change', syncUI);
    });
    syncUI();
})();
</script></body>
</html>
