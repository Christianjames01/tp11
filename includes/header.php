<?php

require_once __DIR__ . '/../config/database.php';
$flash = getFlash();
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? sanitize($page_title) . ' – GreenLink' : 'GreenLink Innovators – Farm-to-Table Marketplace' ?></title>
    <meta name="description" content="GreenLink Innovators – Connecting Mindanao farmers and restaurant buyers through a digital agritech marketplace.">
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <!-- GreenLink CSS -->
    <link href="<?= BASE_URL ?>/assets/css/main.css" rel="stylesheet">
    <?php if (isset($extra_css)) echo $extra_css; ?>
</head>
<body>

<!-- Flash Message (rendered via JS) -->
<?php if ($flash): ?>
<div id="php-flash" data-type="<?= sanitize($flash['type']) ?>" data-message="<?= sanitize($flash['message']) ?>" style="display:none;"></div>
<?php endif; ?>

<!-- NAVBAR -->
<?php if (empty($hide_navbar)): ?>
<nav class="gl-navbar navbar navbar-expand-lg">
    <div class="container">
        <a class="navbar-brand" href="<?= BASE_URL ?>/index.php">
            <div class="brand-leaf">🌿</div>
            GreenLink
        </a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav mx-auto gap-1">
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'dashboard/index.php' && $current_dir === 'greenlink' ? 'active' : '' ?>" href="<?= BASE_URL ?>/dashboard/index.php">
                        <i class="fa-solid fa-house"></i> Home
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_dir === 'buyer' ? 'active' : '' ?>" href="<?= BASE_URL ?>/buyer/browse.php">
                        <i class="fa-solid fa-store"></i> Browse Products
                    </a>
                </li>
                <li class="nav-item">
                    <?php if (isLoggedIn() && $_SESSION['role'] === 'admin'): ?>
                    <a class="nav-link <?= $current_page === 'marketprices.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/marketprices.php">
                        <i class="fa-solid fa-chart-line"></i> Market Prices
                    </a>
                    <?php else: ?>
                    <a class="nav-link <?= $current_page === 'prices.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/market/prices.php">
                        <i class="fa-solid fa-chart-line"></i> Market Prices
                    </a>
                    <?php endif; ?>
                </li>
                <?php if (isLoggedIn()): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $current_dir === 'orders' ? 'active' : '' ?>" href="<?= BASE_URL ?>/orders/index.php">
                        <i class="fa-solid fa-box"></i> Orders
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_dir === 'messages' ? 'active' : '' ?>" href="<?= BASE_URL ?>/messages/index.php">
                        <i class="fa-solid fa-comments"></i> Messages
                    </a>
                </li>

                <?php if (isLoggedIn() && $_SESSION['role'] === 'buyer'): ?>
                <!-- ── Buyer Premium link (sits right after Messages) ── -->
                <li class="nav-item">
                    <?php
                    $_buyerPdo  = getDBConnection();
                    $_buyerStmt = $_buyerPdo->prepare("SELECT is_premium, premium_until FROM users WHERE id=?");
                    $_buyerStmt->execute([$_SESSION['user_id']]);
                    $_buyerRow  = $_buyerStmt->fetch();
                    $_buyerPrem = $_buyerRow && !empty($_buyerRow['is_premium']) && strtotime($_buyerRow['premium_until']) > time();
                    ?>
                    <?php if ($_buyerPrem): ?>
                    <a class="nav-link <?= $current_page === 'premium.php' && $current_dir === 'buyer' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/buyer/premium.php"
                       style="color:#1565C0;font-weight:800;">
                        <i class="fa-solid fa-crown" style="color:#1565C0;"></i> Premium
                        <span style="background:linear-gradient(135deg,#1565C0,#1976D2);color:white;font-size:.55rem;font-weight:800;padding:1px 6px;border-radius:99px;vertical-align:middle;margin-left:2px;">ACTIVE</span>
                    </a>
                    <?php else: ?>
                    <a class="nav-link <?= $current_page === 'premium.php' && $current_dir === 'buyer' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/buyer/premium.php"
                       style="font-weight:800;color:#1565C0;">
                        <i class="fa-solid fa-crown" style="color:#1565C0;"></i> Go Premium
                    </a>
                    <?php endif; ?>
                </li>
                <?php endif; ?>

                <?php if (isLoggedIn() && $_SESSION['role'] === 'farmer'): ?>
                <li class="nav-item">
                    <?php if (!empty($isPremium)): ?>
                    <a class="nav-link" href="<?= BASE_URL ?>/farmer/premium.php"
                       style="color:#d97706;font-weight:800;">
                        <i class="fa-solid fa-star" style="color:#d97706;"></i> Premium
                        <span style="background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.55rem;font-weight:800;padding:1px 6px;border-radius:99px;vertical-align:middle;margin-left:2px;">ACTIVE</span>
                    </a>
                    <?php else: ?>
                    <a class="nav-link" href="<?= BASE_URL ?>/farmer/premium.php"
                       style="font-weight:800;color:#b45309;">
                        <i class="fa-solid fa-star" style="color:#d97706;"></i> Go Premium
                    </a>
                    <?php endif; ?>
                </li>
                <?php endif; ?>

                <?php if (isLoggedIn() && $_SESSION['role'] === 'admin'): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'premium_payments.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/premium_payments.php">
                        <i class="fa-solid fa-star" style="color:#d97706;"></i> Premium
                    </a>
                </li>
                <?php endif; ?>

              <?php if (isLoggedIn() && $_SESSION['role'] === 'buyer'): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'reports.php' && $current_dir === 'buyer' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/buyer/reports.php"
                       style="font-weight:700;">
                        <i class="fa-solid fa-chart-bar"></i> Reports
                        <?php
                        $_rptPdo  = getDBConnection();
                        $_rptStmt = $_rptPdo->prepare("SELECT is_premium, premium_until FROM users WHERE id=?");
                        $_rptStmt->execute([$_SESSION['user_id']]);
                        $_rptRow  = $_rptStmt->fetch();
                        if ($_rptRow && !empty($_rptRow['is_premium']) && strtotime($_rptRow['premium_until']) > time()):
                        ?>
                        <span style="background:linear-gradient(135deg,#1565C0,#1976D2);color:white;font-size:.5rem;font-weight:800;padding:1px 5px;border-radius:99px;vertical-align:middle;margin-left:1px;">PRO</span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'cart_view.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/buyer/cart_view.php"
                       style="position:relative;">
                        <i class="fa-solid fa-basket-shopping"></i> Cart
                        <span id="cartBadge" style="position:absolute;top:2px;right:-4px;background:#ef4444;color:white;border-radius:99px;min-width:18px;height:18px;font-size:.6rem;font-weight:800;display:none;align-items:center;justify-content:center;padding:0 4px;"></span>
                    </a>
                </li>
                <?php endif; ?>

                <?php endif; ?>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <?php if (isLoggedIn()): ?>
<?php
if ($_SESSION['role'] === 'farmer')        $dashLink = BASE_URL . '/farmer/dashboard.php';
elseif ($_SESSION['role'] === 'admin')     $dashLink = BASE_URL . '/admin/dashboard.php';
elseif ($_SESSION['role'] === 'delivery')  $dashLink = BASE_URL . '/delivery/dashboard.php';
else                                        $dashLink = BASE_URL . '/buyer/dashboard.php';
?>
                    <a href="<?= $dashLink ?>" class="btn-outline-green" style="padding:0.4rem 1rem;font-size:0.85rem;">
                        <i class="fa-solid fa-grid-2"></i> Dashboard
                    </a>
                    <a href="<?= BASE_URL ?>/auth/logout.php" class="btn-nav-login">
                        <i class="fa-solid fa-right-from-bracket"></i> Logout
                    </a>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/auth/login.php" class="btn-nav-login">Login</a>
                    <a href="<?= BASE_URL ?>/auth/register.php" class="btn-nav-register">Register Free</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
<!-- PREMIUM FARMER BANNER -->
<?php if (isLoggedIn() && $_SESSION['role'] === 'farmer'): ?>
<?php
$farmerId  = $_SESSION['user_id'];
$_hdrPdo   = getDBConnection();
$premStmt  = $_hdrPdo->prepare("SELECT is_premium, premium_until FROM farmers WHERE user_id=?");
$premStmt->execute([$farmerId]);
$premRow   = $premStmt->fetch();
$isPremium = $premRow && $premRow['is_premium'] && strtotime($premRow['premium_until']) > time();
?>
<?php if (!$isPremium): ?>
<div style="background:linear-gradient(90deg,#78350f,#b45309,#d97706);padding:.5rem 0;text-align:center;">
    <div class="container d-flex align-items-center justify-content-center gap-3 flex-wrap">
        <span style="color:white;font-size:.82rem;font-weight:700;">
            🚀 <strong>Boost your farm visibility!</strong> Get the Premium Seller Badge and reach more buyers.
        </span>
        <a href="<?= BASE_URL ?>/farmer/premium.php"
           style="background:white;color:#b45309;font-size:.75rem;font-weight:800;padding:.3rem .85rem;border-radius:99px;text-decoration:none;white-space:nowrap;transition:all .2s;"
           onmouseover="this.style.background='#fef3c7'" onmouseout="this.style.background='white'">
            ⭐ Get Premium – ₱299/mo
        </a>
    </div>
</div>
<?php else: ?>
<div style="background:linear-gradient(90deg,#1B5E20,#2E7D32);padding:.4rem 0;text-align:center;">
    <div class="container">
        <span style="color:white;font-size:.78rem;font-weight:700;">
            ⭐ <strong>Premium Seller</strong> — Your products are boosted in search results!
            Valid until <strong><?= date('M j, Y', strtotime($premRow['premium_until'])) ?></strong>
        </span>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- BUYER SERVICE FEE REMINDER (first visit) -->
<?php if (isLoggedIn() && $_SESSION['role'] === 'buyer' && !isset($_SESSION['fee_notice_seen'])): ?>
<?php $_SESSION['fee_notice_seen'] = true; ?>
<div id="feeNotice" style="background:linear-gradient(90deg,#1e3a5f,#1d4ed8);padding:.45rem 0;">
    <div class="container d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span style="color:white;font-size:.78rem;font-weight:600;">
            💡 A small service fee of <strong>₱50–₱150</strong> applies per bulk order to support our farmers and logistics network.
        </span>
        <button onclick="document.getElementById('feeNotice').style.display='none'"
                style="background:rgba(255,255,255,.2);border:none;color:white;font-size:.72rem;font-weight:700;padding:.2rem .7rem;border-radius:99px;cursor:pointer;">
            Got it ✕
        </button>
    </div>
</div>
<?php endif; ?>

<!-- END NAVBAR -->
<?php endif; ?>