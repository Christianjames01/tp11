<?php
$page_title = 'View User';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin');

$pdo = getDBConnection();

// ── Admin profile ─────────────────────────────────────────────────────────────
$adminId = $_SESSION['user_id'];
$admin   = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$admin->execute([$adminId]);
$admin   = $admin->fetch();

// ── Image helpers ─────────────────────────────────────────────────────────────
function userImgUrl(array $user): string|null {
    $photo = $user['profile_image'] ?? $user['profile_photo'] ?? null;
    if (empty($photo)) return null;
    $img = ltrim($photo, '/');
    if (!str_contains($img, '/')) {
        $img = 'assets/images/profiles/' . $img;
    }
    return BASE_URL . '/' . htmlspecialchars($img) . '?v=' . time();
}

function productImgUrl(array $product): string|null {
    if (empty($product['image'])) return null;
    $img = ltrim($product['image'], '/');
    if (!str_contains($img, '/')) {
        $img = 'assets/images/products/' . $img;
    }
    return BASE_URL . '/' . htmlspecialchars($img) . '?v=' . time();
}

function userAvatar(array $user, string $size = '36px', string $fontSize = '.75rem'): string {
    $initial = strtoupper(substr($user['name'], 0, 1));
    $url     = userImgUrl($user);
    $style   = "width:{$size};height:{$size};border-radius:50%;object-fit:cover;display:block;";
    if ($url) {
        return '<img src="' . $url . '" alt="" style="' . $style . '"'
             . ' onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\';">'
             . '<span style="display:none;width:' . $size . ';height:' . $size . ';border-radius:50%;'
             . 'background:var(--pale-green);color:var(--primary);align-items:center;justify-content:center;'
             . 'font-size:' . $fontSize . ';font-weight:800;">' . $initial . '</span>';
    }
    return '<span style="display:flex;width:' . $size . ';height:' . $size . ';border-radius:50%;'
         . 'background:var(--pale-green);color:var(--primary);align-items:center;justify-content:center;'
         . 'font-size:' . $fontSize . ';font-weight:800;">' . $initial . '</span>';
}

// ── Validate target user ──────────────────────────────────────────────────────
$userId = intval($_GET['id'] ?? 0);
if (!$userId) {
    header('Location: users.php');
    exit;
}

$userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role != 'admin'");
$userStmt->execute([$userId]);
$user = $userStmt->fetch();

if (!$user) {
    header('Location: users.php');
    exit;
}

// Redirect riders to the public rider profile page
if (in_array($user['role'], ['rider', 'delivery'])) {
header('Location: ' . BASE_URL . '/rider/public_profile.php?id=' . $userId);
    exit;
}

// ── Handle POST actions ───────────────────────────────────────────────────────
$msg     = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'")->execute([$userId]);
        header('Location: users.php?msg=deleted');
        exit;
    }

    if ($action === 'toggle_status') {
        try {
            $cur    = $pdo->prepare("SELECT is_active FROM users WHERE id = ?");
            $cur->execute([$userId]);
            $newVal = $cur->fetchColumn() ? 0 : 1;
            $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$newVal, $userId]);
            $user['is_active'] = $newVal;
            $msg     = $newVal ? 'User account activated.' : 'User account deactivated.';
            $msgType = 'success';
        } catch (Exception $e) {
            $msg     = 'Could not update status (is_active column may not exist).';
            $msgType = 'error';
        }
    }
}

// ── User stats ────────────────────────────────────────────────────────────────
$isFarmer = $user['role'] === 'farmer';

// Orders
if ($isFarmer) {
    $sqlOrders    = "SELECT COUNT(*) FROM orders WHERE farmer_id = ?";
    $sqlRevenue   = "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE farmer_id = ? AND status='completed'";
    $sqlPending   = "SELECT COUNT(*) FROM orders WHERE farmer_id = ? AND status='pending'";
    $sqlCompleted = "SELECT COUNT(*) FROM orders WHERE farmer_id = ? AND status='completed'";
} else {
    $sqlOrders    = "SELECT COUNT(*) FROM orders WHERE buyer_id = ?";
    $sqlRevenue   = "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE buyer_id = ? AND status='completed'";
    $sqlPending   = "SELECT COUNT(*) FROM orders WHERE buyer_id = ? AND status='pending'";
    $sqlCompleted = "SELECT COUNT(*) FROM orders WHERE buyer_id = ? AND status='completed'";
}

$stOrders = $pdo->prepare($sqlOrders);
$stOrders->execute([$userId]);
$totalOrders = $stOrders->fetchColumn();

$stRevenue = $pdo->prepare($sqlRevenue);
$stRevenue->execute([$userId]);
$totalRevenue = $stRevenue->fetchColumn();

$stPending = $pdo->prepare($sqlPending);
$stPending->execute([$userId]);
$pendingOrders = $stPending->fetchColumn();

$stCompleted = $pdo->prepare($sqlCompleted);
$stCompleted->execute([$userId]);
$completedOrders = $stCompleted->fetchColumn();

// Farmer-specific
$totalProducts     = 0;
$availableProducts = 0;
$products          = [];

// ── Farmer premium status ─────────────────────────────────────────────────────
$isFarmerPremium = false;
if ($isFarmer) {
    $premStmt = $pdo->prepare("SELECT is_premium, premium_until FROM farmers WHERE user_id = ?");
    $premStmt->execute([$userId]);
    $premRow = $premStmt->fetch();
    $isFarmerPremium = !empty($premRow['is_premium'])
        && !empty($premRow['premium_until'])
        && strtotime($premRow['premium_until']) > time();
}

if ($isFarmer) {
    $stProd = $pdo->prepare("SELECT COUNT(*) FROM products WHERE farmer_id = ?");
    $stProd->execute([$userId]);
    $totalProducts = $stProd->fetchColumn();

    $stAvail = $pdo->prepare("SELECT COUNT(*) FROM products WHERE farmer_id = ? AND is_available = 1");
    $stAvail->execute([$userId]);
    $availableProducts = $stAvail->fetchColumn();

    $stProducts = $pdo->prepare("
        SELECT p.*,
               COALESCE((SELECT SUM(oi.quantity_kg) FROM order_items oi JOIN orders o ON oi.order_id=o.id WHERE oi.product_id=p.id AND o.status='completed'),0) as sold_kg,
               COALESCE((SELECT COUNT(DISTINCT o.id) FROM order_items oi JOIN orders o ON oi.order_id=o.id WHERE oi.product_id=p.id),0) as order_count
        FROM products p
        WHERE p.farmer_id = ?
        ORDER BY p.created_at DESC
        LIMIT 6
    ");
    $stProducts->execute([$userId]);
    $products = $stProducts->fetchAll();
}

// Recent orders
if ($isFarmer) {
    $sqlRecentOrders = "SELECT o.*, ub.name as buyer_name, ub.profile_image as buyer_photo
                FROM orders o JOIN users ub ON o.buyer_id = ub.id
                WHERE o.farmer_id = ? ORDER BY o.created_at DESC LIMIT 8";

} else {
    $sqlRecentOrders = "SELECT o.*, uf.name as farmer_name, uf.profile_image as farmer_photo
                FROM orders o JOIN users uf ON o.farmer_id = uf.id
                WHERE o.buyer_id = ? ORDER BY o.created_at DESC LIMIT 8";
}
$stRecentOrders = $pdo->prepare($sqlRecentOrders);
$stRecentOrders->execute([$userId]);
$recentOrders = $stRecentOrders->fetchAll();

// Monthly revenue for the past 6 months
if ($isFarmer) {
    $sqlMonthly = "SELECT DATE_FORMAT(created_at,'%b') as month, COALESCE(SUM(total_amount),0) as revenue
                   FROM orders WHERE farmer_id = ? AND status='completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                   GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY MIN(created_at) ASC";
} else {
    $sqlMonthly = "SELECT DATE_FORMAT(created_at,'%b') as month, COALESCE(SUM(total_amount),0) as revenue
                   FROM orders WHERE buyer_id = ? AND status='completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                   GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY MIN(created_at) ASC";
}
$stMonthly = $pdo->prepare($sqlMonthly);
$stMonthly->execute([$userId]);
$monthlyData  = $stMonthly->fetchAll();
$chartLabels  = array_column($monthlyData, 'month');
$chartRevenue = array_map('floatval', array_column($monthlyData, 'revenue'));

// Order status breakdown
if ($isFarmer) {
    $sqlStatusBreak = "SELECT status, COUNT(*) as cnt FROM orders WHERE farmer_id = ? GROUP BY status";
} else {
    $sqlStatusBreak = "SELECT status, COUNT(*) as cnt FROM orders WHERE buyer_id = ? GROUP BY status";
}
$stStatusBreak = $pdo->prepare($sqlStatusBreak);
$stStatusBreak->execute([$userId]);
$statusBreakdown = [];
foreach ($stStatusBreak->fetchAll() as $row) {
    $statusBreakdown[$row['status']] = $row['cnt'];
}

$catEmoji     = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕','Others'=>'📦'];
$statusColors = [
    'pending'    => 'status-pending',
    'confirmed'  => 'status-confirmed',
    'processing' => 'status-processing',
    'shipped'    => 'status-shipped',
    'completed'  => 'status-completed',
    'cancelled'  => 'status-cancelled',
];
$roleColors = ['farmer' => 'status-completed', 'buyer' => 'status-confirmed'];

$page_title = 'View User — ' . htmlspecialchars($user['name']);
?>

<style>
/* ── Shared helpers ── */
.admin-section-title{font-weight:800;font-size:.95rem;margin:0;}
.action-btn{display:inline-flex;align-items:center;gap:5px;padding:.32rem .8rem;border-radius:var(--radius);font-size:.76rem;font-weight:700;border:none;cursor:pointer;transition:all .15s;font-family:inherit;text-decoration:none;}
.action-btn-danger{background:#fee2e2;color:#dc2626;}.action-btn-danger:hover{background:#dc2626;color:white;}
.action-btn-warn{background:#fff7ed;color:#ea580c;}.action-btn-warn:hover{background:#ea580c;color:white;}
.action-btn-primary{background:var(--pale-green);color:var(--primary);}.action-btn-primary:hover{background:var(--primary);color:white;}
.action-btn-gray{background:#f1f5f9;color:#64748b;}.action-btn-gray:hover{background:#64748b;color:white;}
.alert-flash{padding:.75rem 1.1rem;border-radius:var(--radius);margin-bottom:1rem;font-size:.83rem;font-weight:700;display:flex;align-items:center;gap:.5rem;}
.alert-success{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0;}
.alert-error{background:#fee2e2;color:#dc2626;border:1px solid #fecaca;}

/* ── Profile hero ── */
.profile-hero{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:1.5rem;box-shadow:var(--shadow-sm);}
.profile-cover{height:110px;position:relative;}
.profile-cover-farmer{background:linear-gradient(135deg, #3E7C3F, #639922, #a3d977);}
.profile-cover-buyer{background:linear-gradient(135deg, #1d4ed8, #3b82f6, #93c5fd);}
.profile-avatar-wrap{position:absolute;bottom:-44px;left:1.5rem;}
.profile-avatar-lg{width:88px;height:88px;border-radius:50%;border:4px solid white;object-fit:cover;background:var(--pale-green);display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:800;color:var(--primary);overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.12);}
.profile-info-row{padding:3.25rem 1.5rem 1.25rem;}
.profile-name{font-size:1.2rem;font-weight:800;color:var(--text);}
.profile-role-badge{display:inline-flex;align-items:center;gap:4px;border-radius:99px;padding:3px 10px;font-size:.72rem;font-weight:800;margin-top:4px;}
.profile-role-farmer{background:var(--pale-green);color:var(--primary);}
.profile-role-buyer{background:#dbeafe;color:#1d4ed8;}
.profile-bio{font-size:.82rem;color:var(--text-muted);margin-top:.5rem;line-height:1.55;}
.profile-meta{display:flex;gap:1.25rem;margin-top:.75rem;flex-wrap:wrap;}
.profile-meta span{font-size:.78rem;color:var(--text-muted);}
.profile-stats-row{display:grid;grid-template-columns:repeat(4,1fr);border-top:1px solid var(--border);}
.profile-stat{padding:.85rem 1rem;text-align:center;border-right:1px solid var(--border);}
.profile-stat:last-child{border-right:none;}
.profile-stat-val{font-size:1.1rem;font-weight:800;color:var(--primary);}
.profile-stat-lbl{font-size:.68rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;}

/* ── Info card ── */
.info-card{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;margin-bottom:1.25rem;box-shadow:var(--shadow-sm);}
.info-card-title{font-size:.82rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.9rem;display:flex;align-items:center;gap:.4rem;}
.info-row{display:flex;justify-content:space-between;align-items:flex-start;padding:.5rem 0;border-bottom:1px solid var(--border);}
.info-row:last-child{border-bottom:none;padding-bottom:0;}
.info-label{font-size:.78rem;color:var(--text-muted);font-weight:600;}
.info-value{font-size:.82rem;font-weight:700;color:var(--text);text-align:right;max-width:60%;}

/* ── Status breakdown ── */
.status-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;}
.status-tile{background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:.6rem .75rem;text-align:center;}
.status-tile-val{font-size:1rem;font-weight:800;}
.status-tile-lbl{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted);margin-top:1px;}

/* ── Product mini card ── */
.product-mini{display:flex;align-items:center;gap:10px;padding:.65rem 0;border-bottom:1px solid var(--border);}
.product-mini:last-child{border-bottom:none;}
.product-mini-img{width:40px;height:40px;border-radius:var(--radius);object-fit:cover;background:var(--pale-green);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;overflow:hidden;}
.product-mini-img img{width:100%;height:100%;object-fit:cover;}
.stk-bar{background:#e5e7eb;border-radius:99px;height:4px;overflow:hidden;margin-top:4px;}
.stk-fill{height:100%;border-radius:99px;}
.stk-good{background:var(--primary);}.stk-low{background:#f97316;}.stk-crit{background:#ef4444;}

/* ── Modal ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal-box{background:white;border-radius:var(--radius-lg);padding:1.75rem;max-width:420px;width:90%;box-shadow:0 25px 60px rgba(0,0,0,.18);transform:scale(.95);transition:transform .2s;}
.modal-overlay.open .modal-box{transform:scale(1);}

@media(max-width:768px){
    .profile-stats-row{grid-template-columns:repeat(2,1fr);}
    .status-grid{grid-template-columns:repeat(2,1fr);}
}
</style>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">

    <div class="page-header">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h1>
                        <i class="fa-solid fa-<?= $isFarmer ? 'tractor' : 'bag-shopping' ?> text-green me-2"></i>
                        <?= sanitize($user['name']) ?>
                    </h1>
                    <div class="page-breadcrumb">
                        <a href="users.php" style="color:var(--primary);text-decoration:none;">Users</a>
                        &rsaquo; <strong><?= sanitize($user['name']) ?></strong>
                        &rsaquo; <?= ucfirst($user['role']) ?> #<?= $user['id'] ?>
                    </div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="users.php" class="btn-outline-green" style="padding:.45rem 1rem;font-size:.82rem;">
                        <i class="fa-solid fa-arrow-left"></i> Back
                    </a>
                    <button onclick="document.getElementById('deleteModal').classList.add('open')" class="action-btn action-btn-danger" style="padding:.45rem 1rem;font-size:.82rem;">
                        <i class="fa-solid fa-trash"></i> Delete User
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="row g-4">

            <!-- Sidebar -->
            <div class="col-lg-3">
                <div class="gl-sidebar">
                    <div class="gl-sidebar-header">
                        <?php $adminImgUrl = userImgUrl($admin); ?>
                        <?php if ($adminImgUrl): ?>
                            <img src="<?= $adminImgUrl ?>" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid white;" alt="Profile"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <div class="user-avatar" style="display:none;">A</div>
                        <?php else: ?>
                            <div class="user-avatar">A</div>
                        <?php endif; ?>
                        <div class="user-name"><?= htmlspecialchars($admin['name']) ?></div>
                        <div class="user-role">⚙️ Administrator</div>
                    </div>
                    <nav class="gl-sidebar-nav">
                        <a href="dashboard.php"><i class="fa-solid fa-grid-2"></i> Dashboard</a>
                        <a href="users.php" class="active"><i class="fa-solid fa-users"></i> Manage Users</a>
                        <a href="products.php"><i class="fa-solid fa-seedling"></i> All Products</a>
                        <a href="orders.php"><i class="fa-solid fa-box"></i> All Orders</a>
                        <a href="<?= BASE_URL ?>/admin/marketprices.php"><i class="fa-solid fa-chart-line"></i> Market Prices</a>
                        <div class="nav-divider"></div>
                        <a href="../auth/logout.php" style="color:#E53E3E;"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                    </nav>

                    <!-- Quick user info in sidebar -->
                    <div style="padding:1rem;border-top:1px solid var(--border);margin-top:.5rem;">
                        <div style="font-size:.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem;">This User</div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;">
                            <span style="font-size:.78rem;color:var(--text-muted);">Role</span>
                            <span class="status-badge <?= $roleColors[$user['role']] ?? '' ?>" style="font-size:.68rem;"><?= ucfirst($user['role']) ?></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;">
                            <span style="font-size:.78rem;color:var(--text-muted);">Orders</span>
                            <strong style="font-size:.78rem;color:var(--primary);"><?= $totalOrders ?></strong>
                        </div>
                        <?php if ($isFarmer): ?>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;">
                            <span style="font-size:.78rem;color:var(--text-muted);">Products</span>
                            <strong style="font-size:.78rem;color:var(--primary);"><?= $totalProducts ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span style="font-size:.78rem;color:var(--text-muted);">Earned</span>
                            <strong style="font-size:.78rem;color:var(--primary);">₱<?= number_format($totalRevenue, 0) ?></strong>
                        </div>
                        <?php else: ?>
                        <div style="display:flex;justify-content:space-between;">
                            <span style="font-size:.78rem;color:var(--text-muted);">Spent</span>
                            <strong style="font-size:.78rem;color:var(--primary);">₱<?= number_format($totalRevenue, 0) ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-lg-9">

                <?php if ($msg): ?>
                <div class="alert-flash alert-<?= $msgType ?>">
                    <i class="fa-solid fa-<?= $msgType==='success'?'circle-check':'circle-exclamation' ?>"></i>
                    <?= htmlspecialchars($msg) ?>
                </div>
                <?php endif; ?>

                <!-- Profile Hero Card -->
                <div class="profile-hero" style="<?= $isFarmerPremium ? 'border-color:#f59e0b;box-shadow:0 4px 18px rgba(245,158,11,.18);' : '' ?>">
<div class="profile-cover <?= $isFarmerPremium ? '' : 'profile-cover-'.$user['role'] ?>" style="<?= $isFarmerPremium ? 'background:linear-gradient(135deg,#78350f,#d97706,#fbbf24);' : '' ?>">
                        <div class="profile-avatar-wrap">
                            <?php $uImgUrl = userImgUrl($user); ?>
                            <?php if ($uImgUrl): ?>
                                <img src="<?= $uImgUrl ?>" class="profile-avatar-lg" alt=""
                                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                <div class="profile-avatar-lg" style="display:none;"><?= strtoupper(substr($user['name'],0,1)) ?></div>
                            <?php else: ?>
                                <div class="profile-avatar-lg"><?= strtoupper(substr($user['name'],0,1)) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Toggle status button top-right of cover -->
                        <div style="position:absolute;top:.75rem;right:.75rem;">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle_status">
                                <?php
                                    $isActive = $user['is_active'] ?? 1;
                                    $toggleLabel = $isActive ? 'Deactivate' : 'Activate';
                                    $toggleIcon  = $isActive ? 'ban' : 'circle-check';
                                    $toggleClass = $isActive ? 'action-btn-warn' : 'action-btn-primary';
                                ?>
                                <button type="submit" class="action-btn <?= $toggleClass ?>" style="font-size:.75rem;">
                                    <i class="fa-solid fa-<?= $toggleIcon ?>"></i> <?= $toggleLabel ?>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="profile-info-row">
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
                            <div>
<div class="profile-name">
                                    <?= sanitize($user['name']) ?>
                                    <?php if ($isFarmerPremium): ?>
                                    <span style="display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.6rem;font-weight:800;padding:3px 9px;border-radius:99px;letter-spacing:.04em;vertical-align:middle;margin-left:6px;">⭐ PREMIUM</span>
                                    <?php endif; ?>
                                </div>                                <div class="profile-role-badge profile-role-<?= $user['role'] ?>">
                                    <i class="fa-solid fa-<?= $isFarmer ? 'tractor' : 'bag-shopping' ?>"></i>
                                    <?= ucfirst($user['role']) ?>
                                    <?php if (!($user['is_active'] ?? 1)): ?>
                                        &nbsp;<span style="background:#fee2e2;color:#dc2626;border-radius:99px;padding:1px 7px;font-size:.65rem;">Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="font-size:.72rem;color:var(--text-muted);text-align:right;">
                                User #<?= $user['id'] ?><br>
                                Joined <?= date('F j, Y', strtotime($user['created_at'])) ?>
                            </div>
                        </div>

                        <?php if (!empty($user['bio'])): ?>
                            <div class="profile-bio"><?= sanitize($user['bio']) ?></div>
                        <?php else: ?>
                            <div class="profile-bio" style="font-style:italic;opacity:.6;">No bio provided.</div>
                        <?php endif; ?>

                        <div class="profile-meta">
                            <?php if (!empty($user['email'])): ?>
                            <span><i class="fa-solid fa-envelope me-1"></i><?= sanitize($user['email']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($user['phone'])): ?>
                            <span><i class="fa-solid fa-phone me-1"></i><?= sanitize($user['phone']) ?></span>
                            <?php endif; ?>
                           <?php if (!empty($user['location'])): ?>
                            <span><i class="fa-solid fa-location-dot me-1"></i><?= sanitize($user['location']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

<!-- Stats row -->
                    <div class="profile-stats-row" style="<?= $isFarmerPremium ? 'background:linear-gradient(135deg,#fffbeb,#fef3c7,#fde68a);border-top-color:#f59e0b;' : '' ?>">
                        <div class="profile-stat">
                            <div class="profile-stat-val">₱<?= number_format($totalRevenue, 0) ?></div>
                            <div class="profile-stat-lbl"><?= $isFarmer ? 'Earned' : 'Spent' ?></div>
                        </div>
                        <div class="profile-stat">
                            <div class="profile-stat-val"><?= $totalOrders ?></div>
                            <div class="profile-stat-lbl">Orders</div>
                        </div>
                        <div class="profile-stat">
                            <div class="profile-stat-val"><?= $completedOrders ?></div>
                            <div class="profile-stat-lbl">Completed</div>
                        </div>
                        <div class="profile-stat">
                            <?php if ($isFarmer): ?>
                                <div class="profile-stat-val"><?= $totalProducts ?></div>
                                <div class="profile-stat-lbl">Products</div>
                            <?php else: ?>
                                <div class="profile-stat-val"><?= $pendingOrders ?></div>
                                <div class="profile-stat-lbl">Pending</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Two-column: Account Info + Order Status Breakdown -->
                <div class="row g-3 mb-3">
                    <div class="col-md-5">
                        <div class="info-card">
                            <div class="info-card-title"><i class="fa-solid fa-circle-info text-green"></i> Account Details</div>
                            <div class="info-row">
                                <span class="info-label">Full Name</span>
                                <span class="info-value"><?= sanitize($user['name']) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Email</span>
                                <span class="info-value" style="word-break:break-all;"><?= sanitize($user['email']) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Phone</span>
                                <span class="info-value"><?= !empty($user['phone']) ? sanitize($user['phone']) : '—' ?></span>
                            </div>
                           <div class="info-row">
                                <span class="info-label">Location</span>
                                <span class="info-value"><?= !empty($user['location']) ? sanitize($user['location']) : '—' ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Role</span>
                                <span class="info-value"><span class="status-badge <?= $roleColors[$user['role']] ?? '' ?>"><?= ucfirst($user['role']) ?></span></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Status</span>
                                <span class="info-value">
                                    <?php $isActive = $user['is_active'] ?? 1; ?>
                                    <span class="status-badge <?= $isActive ? 'status-completed' : 'status-cancelled' ?>">
                                        <?= $isActive ? 'Active' : 'Inactive' ?>
                                    </span>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Member Since</span>
                                <span class="info-value"><?= date('M j, Y', strtotime($user['created_at'])) ?></span>
                            </div>
                            <?php if (!empty($user['updated_at']) && $user['updated_at'] !== $user['created_at']): ?>
                            <div class="info-row">
                                <span class="info-label">Last Updated</span>
                                <span class="info-value"><?= date('M j, Y', strtotime($user['updated_at'])) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-md-7">
                        <!-- Order status breakdown -->
                        <div class="info-card" style="margin-bottom:.75rem;">
                            <div class="info-card-title"><i class="fa-solid fa-chart-pie text-green"></i> Order Breakdown</div>
                            <?php
                            $allStatuses = ['pending','confirmed','processing','shipped','completed','cancelled'];
                            ?>
                            <div class="status-grid">
                                <?php foreach ($allStatuses as $st):
                                    $cnt  = $statusBreakdown[$st] ?? 0;
                                    $cols = [
                                        'pending'    => ['#f97316','#fff7ed'],
                                        'confirmed'  => ['#3b82f6','#dbeafe'],
                                        'processing' => ['#8b5cf6','#ede9fe'],
                                        'shipped'    => ['#0891b2','#cffafe'],
                                        'completed'  => ['#16a34a','#dcfce7'],
                                        'cancelled'  => ['#dc2626','#fee2e2'],
                                    ];
                                    [$textCol, $bgCol] = $cols[$st] ?? ['#64748b','#f1f5f9'];
                                ?>
                                <div class="status-tile" style="background:<?= $bgCol ?>;">
                                    <div class="status-tile-val" style="color:<?= $textCol ?>;"><?= $cnt ?></div>
                                    <div class="status-tile-lbl"><?= ucfirst($st) ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Revenue / spend chart -->
                        <div class="info-card">
                            <div class="info-card-title">
                                <i class="fa-solid fa-chart-line text-green"></i>
                                <?= $isFarmer ? 'Revenue' : 'Spending' ?> (Last 6 Months)
                            </div>
                            <div style="position:relative;height:140px;">
                                <canvas id="userChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Farmer: Products Section -->
                <?php if ($isFarmer && !empty($products)): ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 style="font-weight:800;margin:0;font-size:.95rem;">
                            <i class="fa-solid fa-seedling text-green me-2"></i>Products by <?= sanitize($user['name']) ?>
                        </h5>
                        <a href="products.php?search=<?= urlencode($user['name']) ?>" class="btn-outline-green" style="padding:.3rem .85rem;font-size:.78rem;">View All</a>
                    </div>
                    <div class="gl-card"><div class="gl-card-body" style="padding:.5rem 1rem;">
                        <?php foreach ($products as $p):
                            $pct = min(100, ($p['stock_kg'] / 500) * 100);
                            $cls = $pct > 50 ? 'stk-good' : ($pct > 20 ? 'stk-low' : 'stk-crit');
                            $lcol= $pct > 50 ? '#16a34a' : ($pct > 20 ? '#f97316' : '#ef4444');
                            $pImgUrl = productImgUrl($p);
                        ?>
                        <div class="product-mini">
                            <div class="product-mini-img">
                                <?php if ($pImgUrl): ?>
                                    <img src="<?= $pImgUrl ?>" alt=""
                                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                    <span style="display:none;width:100%;height:100%;align-items:center;justify-content:center;font-size:1rem;"><?= $catEmoji[$p['category']] ?? '📦' ?></span>
                                <?php else: ?>
                                    <?= $catEmoji[$p['category']] ?? '📦' ?>
                                <?php endif; ?>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <strong style="font-size:.82rem;"><?= sanitize($p['name']) ?></strong>
                                    <span class="status-badge <?= $p['is_available']?'status-completed':'status-cancelled' ?>" style="font-size:.65rem;"><?= $p['is_available']?'Active':'Hidden' ?></span>
                                </div>
                                <div style="display:flex;gap:1rem;margin-top:2px;">
                                    <span style="font-size:.72rem;color:var(--text-muted);"><?= $catEmoji[$p['category']]??'📦' ?> <?= $p['category'] ?></span>
                                    <span style="font-size:.72rem;color:var(--primary);font-weight:700;">₱<?= number_format($p['price_per_kg'],2) ?>/kg</span>
                                    <span style="font-size:.72rem;color:var(--text-muted);"><?= number_format($p['stock_kg'],1) ?> kg left</span>
                                    <span style="font-size:.72rem;color:var(--text-muted);"><?= number_format($p['sold_kg'],1) ?> kg sold</span>
                                </div>
                                <div class="stk-bar" style="max-width:200px;">
                                    <div class="stk-fill <?= $cls ?>" style="width:<?= $pct ?>%;"></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div></div>
                </div>
                <?php elseif ($isFarmer): ?>
                <div class="gl-card mb-3"><div class="gl-card-body" style="text-align:center;padding:1.5rem;color:var(--text-muted);">
                    <i class="fa-solid fa-seedling" style="font-size:1.4rem;opacity:.3;display:block;margin-bottom:.4rem;"></i>
                    This farmer has no products yet.
                </div></div>
                <?php endif; ?>

                <!-- Recent Orders -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 style="font-weight:800;margin:0;font-size:.95rem;">
                            <i class="fa-solid fa-box text-green me-2"></i>
                            Recent Orders <?= $isFarmer ? 'Received' : 'Placed' ?>
                        </h5>
                        <a href="orders.php?<?= $isFarmer ? 'farmer' : 'buyer' ?>_id=<?= $user['id'] ?>" class="btn-outline-green" style="padding:.3rem .85rem;font-size:.78rem;">View All</a>
                    </div>

                    <?php if (!empty($recentOrders)): ?>
                    <div class="gl-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th><?= $isFarmer ? 'Buyer' : 'Farmer' ?></th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentOrders as $o): ?>
                            <tr>
                                <td><strong style="color:var(--primary);">#<?= $o['id'] ?></strong></td>
                                <td>
                                    <?php if ($isFarmer): ?>
                                        <div style="display:flex;align-items:center;gap:7px;">
                                            <?php
                                                $counterpart = ['name' => $o['buyer_name'], 'profile_image' => $o['buyer_photo'] ?? '', 'created_at' => $o['created_at'], 'updated_at' => $o['created_at']];
                                                $cpUrl = userImgUrl($counterpart);
                                            ?>
                                            <div style="width:26px;height:26px;border-radius:50%;background:var(--pale-green);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:800;overflow:hidden;flex-shrink:0;">
                                                <?php if ($cpUrl): ?>
                                                    <img src="<?= $cpUrl ?>" style="width:100%;height:100%;object-fit:cover;" alt=""
                                                         onerror="this.style.display='none';this.parentElement.textContent='<?= strtoupper(substr($o['buyer_name'],0,1)) ?>';">
                                                <?php else: ?>
                                                    <?= strtoupper(substr($o['buyer_name'],0,1)) ?>
                                                <?php endif; ?>
                                            </div>
                                            <span style="font-size:.8rem;font-weight:600;"><?= sanitize($o['buyer_name']) ?></span>
                                        </div>
                                    <?php else: ?>
                                        <div style="display:flex;align-items:center;gap:7px;">
                                            <?php
$counterpart = ['name' => $o['farmer_name'], 'profile_image' => $o['farmer_photo'] ?? '', 'created_at' => $o['created_at'], 'updated_at' => $o['created_at']];
                                                $cpUrl = userImgUrl($counterpart);
                                            ?>
                                            <div style="width:26px;height:26px;border-radius:50%;background:var(--pale-green);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:800;overflow:hidden;flex-shrink:0;">
                                                <?php if ($cpUrl): ?>
                                                    <img src="<?= $cpUrl ?>" style="width:100%;height:100%;object-fit:cover;" alt=""
                                                         onerror="this.style.display='none';this.parentElement.textContent='<?= strtoupper(substr($o['farmer_name'],0,1)) ?>';">
                                                <?php else: ?>
                                                    <?= strtoupper(substr($o['farmer_name'],0,1)) ?>
                                                <?php endif; ?>
                                            </div>
                                            <span style="font-size:.8rem;font-weight:600;"><?= sanitize($o['farmer_name']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><strong style="color:var(--primary);">₱<?= number_format($o['total_amount'],2) ?></strong></td>
                                <td><span class="status-badge <?= $statusColors[$o['status']] ?? '' ?>"><?= ucfirst($o['status']) ?></span></td>
                                <td style="font-size:.76rem;color:var(--text-muted);"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
                                <td>
                                    <a href="order_detail.php?id=<?= $o['id'] ?>" class="action-btn action-btn-primary" style="padding:.22rem .6rem;">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="gl-card"><div class="gl-card-body" style="text-align:center;padding:1.5rem;color:var(--text-muted);">
                        <i class="fa-solid fa-box-open" style="font-size:1.4rem;opacity:.3;display:block;margin-bottom:.4rem;"></i>
                        No orders yet.
                    </div></div>
                    <?php endif; ?>
                </div>

            </div><!-- /col-lg-9 -->
        </div><!-- /row -->
    </div><!-- /container -->
</div>

<!-- Delete Confirm Modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <div style="text-align:center;margin-bottom:1.25rem;">
            <div style="width:52px;height:52px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem;">
                <i class="fa-solid fa-trash" style="color:#dc2626;font-size:1.2rem;"></i>
            </div>
            <h5 style="font-weight:800;margin-bottom:.4rem;">Delete User?</h5>
            <p style="font-size:.83rem;color:var(--text-muted);margin:0;">
                You're about to permanently delete <strong><?= sanitize($user['name']) ?></strong>.<br>
                This will also remove all associated data. This cannot be undone.
            </p>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <div style="display:flex;gap:.75rem;">
                <button type="button" onclick="document.getElementById('deleteModal').classList.remove('open')"
                        style="flex:1;padding:.6rem;border:1.5px solid var(--border);border-radius:var(--radius);background:white;font-weight:700;font-size:.83rem;cursor:pointer;font-family:inherit;">
                    Cancel
                </button>
                <button type="submit"
                        style="flex:1;padding:.6rem;border:none;border-radius:var(--radius);background:#dc2626;color:white;font-weight:800;font-size:.83rem;cursor:pointer;font-family:inherit;">
                    Delete
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Nunito', sans-serif";
Chart.defaults.color = '#94a3b8';

const isFarmer    = <?= $isFarmer ? 'true' : 'false' ?>;
const primaryColor = isFarmer ? '#3E7C3F' : '#1d4ed8';
const bgColor      = isFarmer ? 'rgba(62,124,63,0.08)' : 'rgba(29,78,216,0.08)';

new Chart(document.getElementById('userChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($chartLabels ?: ['No Data']) ?>,
        datasets: [{
            label: isFarmer ? 'Revenue' : 'Spending',
            data: <?= json_encode($chartRevenue ?: [0]) ?>,
            borderColor: primaryColor,
            backgroundColor: bgColor,
            borderWidth: 2.5,
            pointBackgroundColor: primaryColor,
            pointRadius: 4,
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1e293b',
                borderColor: '#334155',
                borderWidth: 1,
                titleColor: '#f1f5f9',
                bodyColor: '#94a3b8',
                padding: 10,
                callbacks: { label: c => '₱' + c.parsed.y.toLocaleString() }
            }
        },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 11 } } },
            y: {
                grid: { color: 'rgba(0,0,0,0.06)' },
                ticks: { font: { size: 11 }, callback: v => '₱' + v.toLocaleString() }
            }
        }
    }
});

// Close modal on backdrop click
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>