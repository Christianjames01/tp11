<?php
$page_title = 'Login';
require_once __DIR__ . '/../config/database.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if ($_SESSION['role'] === 'farmer')       $dash = '../farmer/dashboard.php';
elseif ($_SESSION['role'] === 'admin')    $dash = '../admin/dashboard.php';
elseif ($_SESSION['role'] === 'rider')    $dash = '../rider/dashboard.php';
else                                       $dash = '../buyer/dashboard.php';
header("Location: $dash"); exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!$email || !$password) {
        $error = 'Please fill in all fields.';
    } else {
        $pdo  = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();



        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['email']     = $user['email'];

            setFlash('success', 'Welcome back, ' . $user['name'] . '! 🌿');
         $redirect = '../buyer/dashboard.php';
if ($user['role'] === 'farmer')       $redirect = '../farmer/dashboard.php';
elseif ($user['role'] === 'admin')    $redirect = '../admin/dashboard.php';
elseif ($user['role'] === 'rider')    $redirect = '../rider/dashboard.php';

header("Location: $redirect");
exit();
        } else {
            $error = 'Invalid email or password. Please try again.';
        }
    }
}
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login – GreenLink Innovators</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/main.css" rel="stylesheet">
</head>
<body>
<?php if ($flash): ?>
<div id="php-flash" data-type="<?= $flash['type'] ?>" data-message="<?= sanitize($flash['message']) ?>" style="display:none;"></div>
<?php endif; ?>

<div class="auth-wrapper">
    <div class="auth-card fade-up">
        <div class="auth-logo">
            <a href="../index.php" class="d-block text-decoration-none">
                <div class="logo-icon">🌿</div>
                <h2>GreenLink</h2>
            </a>
            <p>Welcome back! Sign in to your account.</p>
        </div>

        <?php if ($error): ?>
        <div style="background:#FFF5F5;border:1px solid #FED7D7;color:#C53030;border-radius:12px;padding:0.8rem 1rem;margin-bottom:1.2rem;font-size:0.88rem;font-weight:600;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-triangle-exclamation"></i> <?= sanitize($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="gl-form-group">
                <label>Email Address</label>
                <div class="gl-input-wrap">
                    <i class="fa-solid fa-envelope input-icon"></i>
                    <input type="email" name="email" class="gl-input" placeholder="your@email.com" value="<?= sanitize($_POST['email'] ?? '') ?>" required>
                </div>
            </div>
            <div class="gl-form-group">
                <label>Password</label>
                <div class="gl-input-wrap">
                    <i class="fa-solid fa-lock input-icon"></i>
                    <input type="password" name="password" class="gl-input" id="passwordField" placeholder="Enter your password" required>
                    <button type="button" onclick="togglePw()" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.85rem;" id="pwToggle">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-green w-100 justify-content-center" style="padding:0.8rem;">
                <i class="fa-solid fa-right-to-bracket"></i> Sign In
            </button>
        </form>

        <div class="gl-divider"></div>
        <div class="text-center">
            <p style="color:var(--text-muted);font-size:0.88rem;margin:0;">Don't have an account? 
                <a href="register.php" style="color:var(--primary);font-weight:700;text-decoration:none;">Create one free →</a>
            </p>
        </div>

    
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
function togglePw() {
    const f = document.getElementById('passwordField');
    const icon = document.querySelector('#pwToggle i');
    if (f.type === 'password') { f.type = 'text'; icon.className = 'fa-solid fa-eye-slash'; }
    else { f.type = 'password'; icon.className = 'fa-solid fa-eye'; }
}
</script>
</body>
</html>
