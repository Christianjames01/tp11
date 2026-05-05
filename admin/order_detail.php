<?php
$page_title = 'Order Detail';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin');

$pdo = getDBConnection();

// ── Admin profile ─────────────────────────────────────────────────────────────
$adminId = $_SESSION['user_id'];
$admin   = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$admin->execute([$adminId]);
$admin   = $admin->fetch();

// ── Sidebar quick stats ───────────────────────────────────────────────────────
$totalFarmers      = $pdo->query("SELECT COUNT(*) FROM users WHERE role='farmer'")->fetchColumn();
$totalBuyers       = $pdo->query("SELECT COUNT(*) FROM users WHERE role='buyer'")->fetchColumn();
$availableProducts = $pdo->query("SELECT COUNT(*) FROM products WHERE is_available=1")->fetchColumn();
$pendingOrders     = $pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();

// ── Validate order ────────────────────────────────────────────────────────────
$orderId = intval($_GET['id'] ?? 0);
if (!$orderId) {
    header('Location: orders.php');
    exit;
}

// Detect which optional columns exist in users table
$userCols     = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
$hasPhone     = in_array('phone',    $userCols);
$hasLocation  = in_array('location', $userCols);
$hasAddress   = in_array('address',  $userCols);

$buyerPhone   = $hasPhone    ? 'ub.phone'    : "'' ";
$buyerAddr    = $hasLocation ? 'ub.location' : ($hasAddress ? 'ub.address' : "'' ");
$farmerPhone  = $hasPhone    ? 'uf.phone'    : "'' ";
$farmerAddr   = $hasLocation ? 'uf.location' : ($hasAddress ? 'uf.address' : "'' ");

$orderStmt = $pdo->prepare("
    SELECT o.*,
           ub.id    as buyer_id,
           ub.name  as buyer_name,
           ub.email as buyer_email,
           $buyerPhone  as buyer_phone,
           $buyerAddr   as buyer_address,
           uf.id    as farmer_id,
           uf.name  as farmer_name,
           uf.email as farmer_email,
           $farmerPhone as farmer_phone,
           $farmerAddr  as farmer_address
    FROM orders o
    JOIN users ub ON o.buyer_id  = ub.id
    JOIN users uf ON o.farmer_id = uf.id
    WHERE o.id = ?
");
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch();

if (!$order) {
    header('Location: orders.php');
    exit;
}

// ── Order items ───────────────────────────────────────────────────────────────
$itemsStmt = $pdo->prepare("
    SELECT oi.*,
           p.name        as product_name,
           p.category    as product_category,
           p.image       as product_image,
           p.price_per_kg as unit_price
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll();

// ── Farmer premium status ─────────────────────────────────────────────────────
$farmerPremiumStmt = $pdo->prepare("
    SELECT is_premium, premium_until FROM farmers WHERE user_id = ?
");
$farmerPremiumStmt->execute([$order['farmer_id']]);
$farmerPremium = $farmerPremiumStmt->fetch();
$isFarmerPremium = !empty($farmerPremium['is_premium'])
    && !empty($farmerPremium['premium_until'])
    && strtotime($farmerPremium['premium_until']) > time();

// ── Buyer premium status ──────────────────────────────────────────────────────
$buyerPremiumStmt = $pdo->prepare("
    SELECT is_premium, premium_until FROM users WHERE id = ?
");
$buyerPremiumStmt->execute([$order['buyer_id']]);
$buyerPremium = $buyerPremiumStmt->fetch();
$isBuyerPremium = !empty($buyerPremium['is_premium'])
    && !empty($buyerPremium['premium_until'])
    && strtotime($buyerPremium['premium_until']) > time();
// ── Helpers ───────────────────────────────────────────────────────────────────
$catEmoji = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕','Others'=>'📦'];

// ── Fee calculations ──────────────────────────────────────────────────────────
$orderColsList  = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
$hasDeliveryFee = in_array('delivery_fee',    $orderColsList);
$hasPlatformFee = in_array('platform_fee',    $orderColsList);
$hasTxFee       = in_array('transaction_fee', $orderColsList);
$hasFarmerPay   = in_array('farmer_payout',   $orderColsList);
$hasDistanceKm  = in_array('distance_km',     $orderColsList);

$rawSubtotal    = floatval($order['total_amount']);
$deliveryFee    = $hasDeliveryFee ? floatval($order['delivery_fee']    ?? 0) : floatval($order['shipping_fee'] ?? 0);
$platformFee    = $hasPlatformFee ? floatval($order['platform_fee']    ?? 0)
                : ($hasTxFee      ? floatval($order['transaction_fee'] ?? 0) : 0);
$farmerPayout   = $hasFarmerPay   ? floatval($order['farmer_payout']   ?? 0) : 0;
$distanceKm     = $hasDistanceKm  && !empty($order['distance_km']) ? floatval($order['distance_km']) : null;

// Compute on-the-fly if not stored
$productSubtotal = 0;
foreach ($items as $it) {
    $productSubtotal += floatval($it['quantity_kg']) * floatval($it['unit_price'] ?? $it['price_per_kg'] ?? 0);
}
if ($platformFee == 0) {
    $platformFee = round($productSubtotal * 0.05, 2);
}
if ($deliveryFee == 0 && $distanceKm !== null) {
    $deliveryFee = $distanceKm > 5 ? round(($distanceKm - 5) * 15, 2) : 0;
}
$farmerPayout = $farmerPayout > 0 ? $farmerPayout : round($productSubtotal - $platformFee, 2);
$grandTotal   = $productSubtotal + $deliveryFee;

$allStatuses = ['pending','confirmed','processing','shipped','completed','cancelled'];

$statusColors = [
    'pending'    => 'status-pending',
    'confirmed'  => 'status-confirmed',
    'processing' => 'status-processing',
    'shipped'    => 'status-shipped',
    'completed'  => 'status-completed',
    'cancelled'  => 'status-cancelled',
];
$statusBg = [
    'pending'    => '#fff7ed',
    'confirmed'  => '#dbeafe',
    'processing' => '#ede9fe',
    'shipped'    => '#cffafe',
    'completed'  => '#dcfce7',
    'cancelled'  => '#fee2e2',
];
$statusFg = [
    'pending'    => '#ea580c',
    'confirmed'  => '#1d4ed8',
    'processing' => '#7c3aed',
    'shipped'    => '#0e7490',
    'completed'  => '#16a34a',
    'cancelled'  => '#dc2626',
];
$statusIcons = [
    'pending'    => 'clock',
    'confirmed'  => 'circle-check',
    'processing' => 'gear',
    'shipped'    => 'truck',
    'completed'  => 'badge-check',
    'cancelled'  => 'circle-xmark',
];

// Timeline steps in order
$timelineSteps = ['pending','confirmed','processing','shipped','completed'];
$currentStatus = $order['status'];
$isCancelled   = $currentStatus === 'cancelled';
$currentIdx    = array_search($currentStatus, $timelineSteps);

function productImgUrl(array $item): ?string {
    if (empty($item['product_image'])) return null;
    $img = ltrim($item['product_image'], '/');
    if (!str_contains($img, '/')) {
        $img = 'assets/images/products/' . $img;
    }
    return BASE_URL . '/' . htmlspecialchars($img) . '?v=' . time();
}

$page_title = 'Order #' . $orderId . ' — Detail';
?>

<style>
/* ── Shared ── */
.action-btn{display:inline-flex;align-items:center;gap:5px;padding:.32rem .8rem;border-radius:var(--radius);font-size:.76rem;font-weight:700;border:none;cursor:pointer;transition:all .15s;font-family:inherit;text-decoration:none;}
.action-btn-primary{background:var(--pale-green);color:var(--primary);}.action-btn-primary:hover{background:var(--primary);color:white;}
.action-btn-danger{background:#fee2e2;color:#dc2626;}.action-btn-danger:hover{background:#dc2626;color:white;}
.action-btn-gray{background:#f1f5f9;color:#64748b;}.action-btn-gray:hover{background:#64748b;color:white;}

.alert-flash{padding:.75rem 1.1rem;border-radius:var(--radius);margin-bottom:1.25rem;font-size:.83rem;font-weight:700;display:flex;align-items:center;gap:.5rem;}
.alert-success{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0;}
.alert-error  {background:#fee2e2;color:#dc2626;border:1px solid #fecaca;}

/* ── Info card ── */
.info-card{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;box-shadow:var(--shadow-sm);}
.info-card-title{font-size:.78rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.9rem;display:flex;align-items:center;gap:.4rem;}
.info-row{display:flex;justify-content:space-between;align-items:flex-start;padding:.45rem 0;border-bottom:1px solid var(--border);}
.info-row:last-child{border-bottom:none;padding-bottom:0;}
.info-label{font-size:.78rem;color:var(--text-muted);font-weight:600;}
.info-value{font-size:.82rem;font-weight:700;color:var(--text);text-align:right;max-width:65%;word-break:break-word;}
/* Premium farmer card row borders */
.info-card.premium-card .info-row {
    border-bottom-color: rgba(245, 158, 11, 0.25);
}

/* ── Order hero banner ── */
.order-hero{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:1.25rem;box-shadow:var(--shadow-sm);}
.order-hero-cover{height:80px;background:linear-gradient(135deg,#3E7C3F,#639922,#a3d977);position:relative;}
.order-hero-body{padding:1.25rem;}
.order-id-badge{font-size:1.3rem;font-weight:800;color:var(--text);}
.order-amount{font-size:1.5rem;font-weight:800;color:var(--primary);}

/* ── Status timeline ── */
.timeline{display:flex;align-items:center;gap:0;margin:1.25rem 0 .5rem;}
.timeline-step{display:flex;flex-direction:column;align-items:center;flex:1;position:relative;}
.timeline-step:not(:last-child)::after{content:'';position:absolute;top:16px;left:50%;width:100%;height:2px;z-index:0;}
.timeline-step-dot{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:800;border:2px solid transparent;position:relative;z-index:1;transition:all .2s;}
.timeline-step-lbl{font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em;margin-top:5px;text-align:center;}
.step-done{background:var(--primary);border-color:var(--primary);color:white;}
.step-active{background:white;border-color:var(--primary);color:var(--primary);}
.step-future{background:#f1f5f9;border-color:#e2e8f0;color:#94a3b8;}

/* ── Delivery map ── */
#adminDeliveryMap{height:300px;border-radius:var(--radius-lg);border:1.5px solid var(--border);z-index:0;margin-bottom:1rem;}

/* ── Items table ── */
.item-img{width:44px;height:44px;border-radius:var(--radius);object-fit:cover;background:var(--pale-green);display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;overflow:hidden;}
.item-img img{width:100%;height:100%;object-fit:cover;}

/* ── Summary totals ── */
.totals-row{display:flex;justify-content:space-between;padding:.45rem 0;font-size:.85rem;}
.totals-row.grand{border-top:2px solid var(--border);margin-top:.25rem;padding-top:.65rem;font-size:1rem;font-weight:800;color:var(--primary);}

/* ── Modal ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal-box{background:white;border-radius:var(--radius-lg);padding:1.75rem;max-width:420px;width:90%;box-shadow:0 25px 60px rgba(0,0,0,.18);transform:scale(.95);transition:transform .2s;}
.modal-overlay.open .modal-box{transform:scale(1);}

/* ── Person card ── */
.person-card{display:flex;align-items:center;gap:.85rem;}
.person-avatar{width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:800;flex-shrink:0;}

/* ── Premium badge ── */
.premium-badge{display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.58rem;font-weight:800;padding:3px 9px;border-radius:99px;letter-spacing:.04em;box-shadow:0 2px 6px rgba(217,119,6,.35);vertical-align:middle;}

@media(max-width:640px){
    .timeline-step-lbl{font-size:.55rem;}
    .timeline-step-dot{width:26px;height:26px;font-size:.68rem;}
}
</style>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">

    <div class="page-header">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h1><i class="fa-solid fa-box text-green me-2"></i>Order #<?= $orderId ?></h1>
                    <div class="page-breadcrumb">
                        <a href="dashboard.php" style="color:var(--primary);text-decoration:none;">Dashboard</a>
                        &rsaquo; <a href="orders.php" style="color:var(--primary);text-decoration:none;">Orders</a>
                        &rsaquo; <strong>Order #<?= $orderId ?></strong>
                    </div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="orders.php" class="btn-outline-green" style="padding:.45rem 1rem;font-size:.82rem;">
                        <i class="fa-solid fa-arrow-left"></i> Back to Orders
                    </a>
                    <button onclick="document.getElementById('deleteModal').classList.add('open')"
                            class="action-btn action-btn-danger" style="padding:.45rem 1rem;font-size:.82rem;">
                        <i class="fa-solid fa-trash"></i> Delete Order
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="row g-4">

            <!-- ── Sidebar ── -->
            <div class="col-lg-3">
                <div class="gl-sidebar">
                    <div class="gl-sidebar-header">
                    <?php if (!empty($admin['profile_image'])): ?>
    <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($admin['profile_image']) ?>"
         style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid white;" alt="">
<?php else: ?>
    <div class="user-avatar"><?= strtoupper(substr($admin['name'], 0, 1)) ?></div>
<?php endif; ?>
                        <div class="user-name"><?= htmlspecialchars($admin['name']) ?></div>
                        <div class="user-role">⚙️ Administrator</div>
                    </div>
                    <nav class="gl-sidebar-nav">
                        <a href="dashboard.php"><i class="fa-solid fa-grid-2"></i> Dashboard</a>
                        <a href="users.php"><i class="fa-solid fa-users"></i> Manage Users</a>
                        <a href="products.php"><i class="fa-solid fa-seedling"></i> All Products</a>
                        <a href="orders.php" class="active"><i class="fa-solid fa-box"></i> All Orders</a>
                        <a href="<?= BASE_URL ?>/admin/marketprices.php"><i class="fa-solid fa-chart-line"></i> Market Prices</a>
                        <div class="nav-divider"></div>
                        <a href="../auth/logout.php" style="color:#E53E3E;"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                    </nav>
                    <div style="padding:1rem;border-top:1px solid var(--border);margin-top:.5rem;">
                        <div style="font-size:.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem;">Quick Stats</div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;"><span style="font-size:.78rem;color:var(--text-muted);">Farmers</span><strong style="font-size:.78rem;color:var(--primary);"><?= $totalFarmers ?></strong></div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;"><span style="font-size:.78rem;color:var(--text-muted);">Buyers</span><strong style="font-size:.78rem;color:var(--primary);"><?= $totalBuyers ?></strong></div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;"><span style="font-size:.78rem;color:var(--text-muted);">Active Listings</span><strong style="font-size:.78rem;color:var(--primary);"><?= $availableProducts ?></strong></div>
                        <div style="display:flex;justify-content:space-between;"><span style="font-size:.78rem;color:var(--text-muted);">Pending Orders</span><strong style="font-size:.78rem;color:#f97316;"><?= $pendingOrders ?></strong></div>
                    </div>

                    <!-- This order summary in sidebar -->
                    <div style="padding:1rem;border-top:1px solid var(--border);">
                        <div style="font-size:.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem;">This Order</div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.45rem;">
                            <span style="font-size:.78rem;color:var(--text-muted);">Order #</span>
                            <strong style="font-size:.78rem;color:var(--primary);">#<?= $orderId ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.45rem;">
                            <span style="font-size:.78rem;color:var(--text-muted);">Items</span>
                            <strong style="font-size:.78rem;color:var(--primary);"><?= count($items) ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.45rem;">
                            <span style="font-size:.78rem;color:var(--text-muted);">Amount</span>
                            <strong style="font-size:.78rem;color:var(--primary);">₱<?= number_format($order['total_amount'], 2) ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.45rem;">
                            <span style="font-size:.78rem;color:var(--text-muted);">Status</span>
                            <span class="status-badge <?= $statusColors[$currentStatus] ?? '' ?>" style="font-size:.65rem;">
                                <?= ucfirst($currentStatus) ?>
                            </span>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span style="font-size:.78rem;color:var(--text-muted);">Date</span>
                            <strong style="font-size:.78rem;color:var(--text);"><?= date('M j, Y', strtotime($order['created_at'])) ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Main Content ── -->
            <div class="col-lg-9">

                <!-- Order Hero Banner -->
                <div class="order-hero">
                    <div class="order-hero-cover"></div>
                    <div class="order-hero-body">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.75rem;">
                            <div>
                                <div class="order-id-badge">Order #<?= $orderId ?></div>
                                <div style="font-size:.78rem;color:var(--text-muted);margin-top:3px;">
                                    <i class="fa-solid fa-calendar me-1"></i>
                                    Placed on <?= date('F j, Y \a\t g:i A', strtotime($order['created_at'])) ?>
                                </div>
                                <?php if (!empty($order['updated_at']) && $order['updated_at'] !== $order['created_at']): ?>
                                <div style="font-size:.72rem;color:var(--text-muted);margin-top:1px;">
                                    <i class="fa-solid fa-pen me-1"></i>
                                    Last updated <?= date('M j, Y \a\t g:i A', strtotime($order['updated_at'])) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div style="text-align:right;">
                                <div class="order-amount">₱<?= number_format($order['total_amount'], 2) ?></div>
                                <div style="margin-top:4px;">
                                    <span class="status-badge <?= $statusColors[$currentStatus] ?? '' ?>" style="font-size:.75rem;padding:4px 12px;">
                                        <i class="fa-solid fa-<?= $statusIcons[$currentStatus] ?? 'circle' ?> me-1"></i>
                                        <?= ucfirst($currentStatus) ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Status Timeline -->
                        <?php if (!$isCancelled): ?>
                        <div class="timeline">
                            <?php foreach ($timelineSteps as $idx => $step):
                                $isDone   = ($currentIdx !== false && $idx < $currentIdx);
                                $isActive = ($currentStatus === $step);
                                $dotClass = $isDone ? 'step-done' : ($isActive ? 'step-active' : 'step-future');
                            ?>
                            <div class="timeline-step">
                                <?php if ($idx < count($timelineSteps) - 1): ?>
                                    <div style="position:absolute;top:15px;left:50%;width:100%;height:2px;z-index:0;background:<?= $isDone ? 'var(--primary)' : '#e2e8f0' ?>;"></div>
                                <?php endif; ?>
                                <div class="timeline-step-dot <?= $dotClass ?>">
                                    <?php if ($isDone): ?>
                                        <i class="fa-solid fa-check" style="font-size:.6rem;"></i>
                                    <?php else: ?>
                                        <i class="fa-solid fa-<?= $statusIcons[$step] ?>" style="font-size:.62rem;"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="timeline-step-lbl" style="color:<?= ($isDone || $isActive) ? 'var(--primary)' : '#94a3b8' ?>;">
                                    <?= ucfirst($step) ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div style="display:flex;align-items:center;gap:.6rem;margin-top:1rem;padding:.6rem .9rem;background:#fee2e2;border-radius:var(--radius);border:1px solid #fecaca;">
                            <i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i>
                            <span style="font-size:.82rem;font-weight:700;color:#dc2626;">This order has been cancelled.</span>
                        </div>
                        <?php endif; ?>

                        <!-- Status display (read-only) -->
                        <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
                            <span style="font-size:.8rem;font-weight:700;color:var(--text-muted);">Current Status:</span>
                            <span class="status-badge <?= $statusColors[$currentStatus] ?? '' ?>" style="font-size:.78rem;padding:5px 14px;">
                                <i class="fa-solid fa-<?= $statusIcons[$currentStatus] ?? 'circle' ?> me-1"></i>
                                <?= ucfirst($currentStatus) ?>
                            </span>
                            <span style="font-size:.72rem;color:var(--text-muted);font-style:italic;">Status is managed by farmers and riders.</span>
                        </div>
                    </div>
                </div>

                <!-- Two-column: Buyer + Farmer -->
                <div class="row g-3 mb-3">

                    <!-- Buyer card -->
                    <div class="col-md-6">
                       <div class="info-card" style="<?= $isBuyerPremium ? 'background:linear-gradient(135deg,#eff6ff,#dbeafe,#bfdbfe);border-color:#3b82f6;box-shadow:0 4px 18px rgba(59,130,246,.18);' : '' ?>">
    <div class="info-card-title" style="justify-content:space-between;">
        <span style="display:flex;align-items:center;gap:.4rem;">
            <i class="fa-solid fa-bag-shopping text-green"></i> Buyer
        </span>
        <?php if ($isBuyerPremium): ?>
       <span style="display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#1e3a8a,#1d4ed8);color:white;font-size:.58rem;font-weight:800;padding:3px 9px;border-radius:99px;letter-spacing:.04em;box-shadow:0 2px 6px rgba(29,78,216,.35);">⭐ PREMIUM</span>
        <?php endif; ?>
    </div>
                            <div style="margin-bottom:.9rem;">
                                <div class="person-card">
                                    <?php
                                    $buyerImgStmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
                                    $buyerImgStmt->execute([$order['buyer_id']]);
                                    $buyerImg = $buyerImgStmt->fetchColumn();
                                    $buyerImgUrl = $buyerImg ? BASE_URL . '/assets/images/profiles/' . htmlspecialchars($buyerImg) : null;
                                    ?>
                                   <div style="position:relative;display:inline-block;flex-shrink:0;">
<div class="person-avatar" style="background:var(--pale-green);color:var(--primary);overflow:hidden;">
                                        <?php if ($buyerImgUrl): ?>
                                            <img src="<?= $buyerImgUrl ?>" style="width:100%;height:100%;object-fit:cover;"
                                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                            <span style="display:none;width:100%;height:100%;align-items:center;justify-content:center;">
                                                <?= strtoupper(substr($order['buyer_name'], 0, 1)) ?>
                                            </span>
                                    <?php else: ?>
                                            <?= strtoupper(substr($order['buyer_name'], 0, 1)) ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($isBuyerPremium): ?>
                                  <div style="position:absolute;bottom:-3px;right:-3px;width:16px;height:16px;background:linear-gradient(135deg,#1e3a8a,#1d4ed8);border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid white;font-size:.6rem;line-height:1;">⭐</div>
                                    <?php endif; ?>
                                    </div>
                                    <div>
                                        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                        <span style="font-weight:800;font-size:.92rem;"><?= sanitize($order['buyer_name']) ?></span>
                                        </div>
                                        <?php if ($isBuyerPremium && !empty($buyerPremium['premium_until'])): ?>
                                      <div style="font-size:.65rem;color:#1d4ed8;font-weight:700;margin-top:2px;">Until <?= date('M j, Y', strtotime($buyerPremium['premium_until'])) ?></div>
                                        <?php endif; ?>
                                        <a href="view_user.php?id=<?= $order['buyer_id'] ?>" class="action-btn action-btn-primary" style="padding:.2rem .6rem;font-size:.68rem;margin-top:4px;">
                                            <i class="fa-solid fa-arrow-up-right-from-square"></i> View Profile
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Email</span>
                                <span class="info-value" style="word-break:break-all;"><?= sanitize($order['buyer_email']) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Phone</span>
                                <span class="info-value"><?= !empty($order['buyer_phone']) ? sanitize($order['buyer_phone']) : '—' ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Address</span>
                                <span class="info-value">
                                    <?php
                                    $bAddr = $order['buyer_address'] ?? '';
                                    if (empty($bAddr)) $bAddr = $order['delivery_address'] ?? '';
                                    echo !empty($bAddr) ? sanitize($bAddr)
                                        : '<span style="color:#94a3b8;font-style:italic;font-weight:600;">Not set</span>';
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>

                  <!-- Farmer card -->
<div class="col-md-6">
    <div class="info-card" style="<?= $isFarmerPremium ? 'background:linear-gradient(135deg,#fffbeb,#fef3c7,#fde68a);border-color:#f59e0b;box-shadow:0 4px 18px rgba(245,158,11,.18);' : '' ?>">
                         <div class="info-card-title" style="justify-content:space-between;">
    <span style="display:flex;align-items:center;gap:.4rem;">
        <i class="fa-solid fa-tractor text-green"></i> Farmer
    </span>
    <?php if ($isFarmerPremium): ?>
    <span class="premium-badge">⭐ PREMIUM</span>
    <?php endif; ?>
</div>
                            <div style="margin-bottom:.9rem;">
                                <div class="person-card">
                                    <?php
                                    $farmerImgStmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
                                    $farmerImgStmt->execute([$order['farmer_id']]);
                                    $farmerImg = $farmerImgStmt->fetchColumn();
                                    $farmerImgUrl = $farmerImg ? BASE_URL . '/assets/images/profiles/' . htmlspecialchars($farmerImg) : null;
                                    ?>
                                    <div style="position:relative;display:inline-block;flex-shrink:0;">
                                        <div class="person-avatar" style="background:#dbeafe;color:#1d4ed8;overflow:hidden;">
                                            <?php if ($farmerImgUrl): ?>
                                                <img src="<?= $farmerImgUrl ?>" style="width:100%;height:100%;object-fit:cover;"
                                                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                                <span style="display:none;width:100%;height:100%;align-items:center;justify-content:center;">
                                                    <?= strtoupper(substr($order['farmer_name'], 0, 1)) ?>
                                                </span>
                                            <?php else: ?>
                                                <?= strtoupper(substr($order['farmer_name'], 0, 1)) ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($isFarmerPremium): ?>
                                        <div style="position:absolute;bottom:-3px;right:-3px;width:16px;height:16px;background:linear-gradient(135deg,#78350f,#d97706);border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid white;font-size:.6rem;line-height:1;">
                                            ⭐
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
    <span style="font-weight:800;font-size:.92rem;"><?= sanitize($order['farmer_name']) ?></span>
</div>
                                        <?php if ($isFarmerPremium && !empty($farmerPremium['premium_until'])): ?>
                                        <div style="font-size:.65rem;color:#d97706;font-weight:700;margin-top:2px;">
                                            Until <?= date('M j, Y', strtotime($farmerPremium['premium_until'])) ?>
                                        </div>
                                        <?php endif; ?>
                                        <a href="view_user.php?id=<?= $order['farmer_id'] ?>" class="action-btn action-btn-primary" style="padding:.2rem .6rem;font-size:.68rem;margin-top:4px;">
                                            <i class="fa-solid fa-arrow-up-right-from-square"></i> View Profile
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Email</span>
                                <span class="info-value" style="word-break:break-all;"><?= sanitize($order['farmer_email']) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Phone</span>
                                <span class="info-value"><?= !empty($order['farmer_phone']) ? sanitize($order['farmer_phone']) : '—' ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Address</span>
                                <span class="info-value">
                                    <?php
                                    $fAddr = $order['farmer_address'] ?? '';
                                    echo !empty($fAddr) ? sanitize($fAddr)
                                        : '<span style="color:#94a3b8;font-style:italic;font-weight:600;">Not set</span>';
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Order Items -->
                <div class="mb-3">
                    <h5 style="font-weight:800;margin:0 0 .75rem;font-size:.95rem;">
                        <i class="fa-solid fa-seedling text-green me-2"></i>Order Items
                        <span style="font-size:.78rem;color:var(--text-muted);font-weight:600;">(<?= count($items) ?> item<?= count($items) != 1 ? 's' : '' ?>)</span>
                    </h5>
                    <div class="info-card" style="padding:0;overflow:hidden;">
                        <?php if (!empty($items)):
                            $subtotal = 0;
                        ?>
                        <div class="gl-table" style="margin:0;border:none;box-shadow:none;">
                            <table style="margin:0;">
                                <thead>
                                    <tr>
                                        <th style="width:44px;"></th>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th style="text-align:right;">Unit Price</th>
                                        <th style="text-align:right;">Qty (kg)</th>
                                        <th style="text-align:right;">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($items as $item):
                                    $lineTotal = $item['quantity_kg'] * $item['unit_price'];
                                    $subtotal += $lineTotal;
                                    $imgUrl    = productImgUrl($item);
                                ?>
                                <tr>
                                    <td>
                                        <div class="item-img">
                                            <?php if ($imgUrl): ?>
                                                <img src="<?= $imgUrl ?>" alt=""
                                                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                                <span style="display:none;width:100%;height:100%;align-items:center;justify-content:center;">
                                                    <?= $catEmoji[$item['product_category']] ?? '📦' ?>
                                                </span>
                                            <?php else: ?>
                                                <?= $catEmoji[$item['product_category']] ?? '📦' ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><strong style="font-size:.85rem;"><?= sanitize($item['product_name']) ?></strong></td>
                                    <td style="font-size:.78rem;color:var(--text-muted);">
                                        <?= $catEmoji[$item['product_category']] ?? '📦' ?> <?= sanitize($item['product_category']) ?>
                                    </td>
                                    <td style="text-align:right;font-size:.82rem;color:var(--text-muted);">
                                        ₱<?= number_format($item['unit_price'], 2) ?>/kg
                                    </td>
                                    <td style="text-align:right;">
                                        <strong style="font-size:.85rem;"><?= number_format($item['quantity_kg'], 2) ?></strong>
                                    </td>
                                    <td style="text-align:right;">
                                        <strong style="color:var(--primary);font-size:.88rem;">₱<?= number_format($lineTotal, 2) ?></strong>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Totals -->
                        <div style="padding:1rem 1.25rem;border-top:1px solid var(--border);background:#fafafa;">
                            <div class="totals-row">
                                <span style="color:var(--text-muted);">Product Subtotal</span>
                                <span>₱<?= number_format($productSubtotal, 2) ?></span>
                            </div>
                            <div class="totals-row">
                                <span style="color:var(--text-muted);display:flex;align-items:center;gap:5px;">
                                    <i class="fa-solid fa-truck" style="color:#3b82f6;font-size:.78rem;"></i>
                                    Delivery Fee
                                    <?php if ($distanceKm): ?>
                                    <span style="background:#dbeafe;color:#1d4ed8;border-radius:99px;padding:0 7px;font-size:.65rem;font-weight:800;">
                                        <?= number_format($distanceKm, 1) ?> km
                                    </span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($deliveryFee > 0): ?>
                                <span style="color:#3b82f6;font-weight:700;">₱<?= number_format($deliveryFee, 2) ?></span>
                                <?php else: ?>
                                <span style="color:#16a34a;font-weight:700;">🎉 FREE</span>
                                <?php endif; ?>
                            </div>
                            <div class="totals-row">
                                <span style="color:var(--text-muted);display:flex;align-items:center;gap:5px;">
                                    <i class="fa-solid fa-percent" style="color:#ea580c;font-size:.75rem;"></i>
                                    Platform Fee
                                    <span style="background:#fff7ed;color:#ea580c;border-radius:99px;padding:0 7px;font-size:.65rem;font-weight:800;">5%</span>
                                </span>
                                <span style="color:#ea580c;font-weight:700;">₱<?= number_format($platformFee, 2) ?></span>
                            </div>
                            <?php
                            $orderColsAdmin  = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
                            $serviceFeeAdmin = in_array('service_fee', $orderColsAdmin) ? floatval($order['service_fee'] ?? 0) : 0;
                            ?>
                            <?php if ($serviceFeeAdmin > 0): ?>
                            <div class="totals-row">
                                <span style="color:var(--text-muted);display:flex;align-items:center;gap:5px;">
                                    <i class="fa-solid fa-handshake" style="color:#7c3aed;font-size:.78rem;"></i>
                                    Service Fee
                                    <span style="font-size:.65rem;color:#94a3b8;font-style:italic;">(coordination &amp; handling)</span>
                                </span>
                                <span style="color:#7c3aed;font-weight:700;">₱<?= number_format($serviceFeeAdmin, 2) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php $discount = floatval($order['discount'] ?? 0); ?>
                            <?php if ($discount > 0): ?>
                            <div class="totals-row">
                                <span style="color:#16a34a;">Discount</span>
                                <span style="color:#16a34a;">− ₱<?= number_format($discount, 2) ?></span>
                            </div>
                            <?php endif; ?>
                            <div style="border-top:1px dashed var(--border);margin:.4rem 0;"></div>
                            <div class="totals-row">
                                <span style="color:var(--text-muted);display:flex;align-items:center;gap:5px;">
                                    <i class="fa-solid fa-tractor" style="color:#16a34a;font-size:.78rem;"></i>
                                    Farmer Receives
                                    <span style="background:#dcfce7;color:#16a34a;border-radius:99px;padding:0 7px;font-size:.65rem;font-weight:800;">after fee</span>
                                </span>
                                <span style="color:#16a34a;font-weight:700;">₱<?= number_format($farmerPayout, 2) ?></span>
                            </div>
                            <div class="totals-row grand">
                                <span>Buyer Pays Total</span>
                                <span>₱<?= number_format($order['total_amount'], 2) ?></span>
                            </div>
                            <div style="margin-top:.75rem;padding-top:.6rem;border-top:1px solid rgba(0,0,0,.06);display:flex;flex-direction:column;gap:4px;">
                                <div style="font-size:.65rem;color:#64748b;line-height:1.5;">
                                    <i class="fa-solid fa-circle-info" style="color:#7c3aed;"></i>
                                    <strong>Buyers:</strong> ₱50–₱150 flat service fee per bulk order: ₱50 (under ₱500) · ₱100 (₱500–₱1,999) · ₱150 (₱2,000+)
                                </div>
                                <div style="font-size:.65rem;color:#64748b;line-height:1.5;">
                                    <i class="fa-solid fa-circle-info" style="color:var(--primary);"></i>
                                    <strong>Farmers:</strong> 5% commission on successful sales, deducted from payout.
                                </div>
                            </div>
                        </div>

                        <?php else: ?>
                        <div style="text-align:center;padding:2rem;color:var(--text-muted);">
                            <i class="fa-solid fa-box-open" style="font-size:1.5rem;opacity:.25;display:block;margin-bottom:.5rem;"></i>
                            No items found for this order.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Delivery Map -->
                <?php
                $mapBuyer = $pdo->prepare("SELECT latitude, longitude, name FROM users WHERE id = ?");
                $mapBuyer->execute([$order['buyer_id']]);
                $buyerLoc = $mapBuyer->fetch();

                $mapFarmer = $pdo->prepare("SELECT latitude, longitude, name FROM users WHERE id = ?");
                $mapFarmer->execute([$order['farmer_id']]);
                $farmerLoc = $mapFarmer->fetch();

                $hasBuyerCoords  = $buyerLoc  && !empty($buyerLoc['latitude'])  && !empty($buyerLoc['longitude']);
                $hasFarmerCoords = $farmerLoc && !empty($farmerLoc['latitude']) && !empty($farmerLoc['longitude']);
                $hasMap          = $hasBuyerCoords && $hasFarmerCoords;

                $distanceKm     = in_array('distance_km', $orderColsList) && !empty($order['distance_km'])
                                  ? floatval($order['distance_km']) : null;
                $deliveryFeeMap = floatval($order['shipping_fee'] ?? $order['delivery_fee'] ?? 0);
                ?>

                <div class="mb-3">
                    <h5 style="font-weight:800;margin:0 0 .75rem;font-size:.95rem;">
                        <i class="fa-solid fa-location-dot text-green me-2"></i>Delivery Map
                    </h5>
                    <div class="info-card">
                        <?php if ($hasMap): ?>
                        <?php if ($distanceKm): ?>
                        <div style="background:linear-gradient(135deg,#1d4ed8,#3b82f6);border-radius:var(--radius);padding:.9rem 1.1rem;margin-bottom:1rem;display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;">
                            <div style="display:flex;align-items:center;gap:.75rem;">
                                <div style="width:40px;height:40px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fa-solid fa-route" style="color:white;font-size:1rem;"></i>
                                </div>
                                <div>
                                    <div style="font-size:.62rem;font-weight:800;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.5px;">Distance</div>
                                    <div style="font-size:1.15rem;font-weight:800;color:white;"><?= number_format($distanceKm, 1) ?> km</div>
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:.62rem;font-weight:800;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.5px;">Delivery Fee</div>
                                <div style="font-size:1.15rem;font-weight:800;color:white;">
                                    <?= $deliveryFeeMap > 0 ? '₱'.number_format($deliveryFeeMap, 2) : '🎉 FREE' ?>
                                </div>
                                <?php if ($deliveryFeeMap == 0 && $distanceKm <= 5): ?>
                                <div style="font-size:.62rem;color:rgba(255,255,255,.7);">within 5 km free zone</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
                        <div id="adminDeliveryMap"></div>
                        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                        <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            const buyerLat  = <?= floatval($buyerLoc['latitude'])  ?>;
                            const buyerLng  = <?= floatval($buyerLoc['longitude']) ?>;
                            const farmerLat = <?= floatval($farmerLoc['latitude'])  ?>;
                            const farmerLng = <?= floatval($farmerLoc['longitude']) ?>;
                            const buyerName  = <?= json_encode(htmlspecialchars($order['buyer_name'],  ENT_QUOTES)) ?>;
                            const farmerName = <?= json_encode(htmlspecialchars($order['farmer_name'], ENT_QUOTES)) ?>;

                            const map = L.map('adminDeliveryMap').setView([
                                (buyerLat + farmerLat) / 2,
                                (buyerLng + farmerLng) / 2
                            ], 11);

                            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                attribution: '© OpenStreetMap contributors'
                            }).addTo(map);

                            const buyerIcon = L.divIcon({
                                html: '<div style="background:#3b82f6;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.3);font-size:16px;">🛒</div>',
                                className: '', iconSize: [36,36], iconAnchor: [18,18], popupAnchor: [0,-20]
                            });

                            const farmerIcon = L.divIcon({
                                html: '<div style="background:#16a34a;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.3);font-size:16px;">🌾</div>',
                                className: '', iconSize: [36,36], iconAnchor: [18,18], popupAnchor: [0,-20]
                            });

                            L.marker([buyerLat, buyerLng], {icon: buyerIcon})
                                .addTo(map)
                                .bindPopup('<strong>🛒 Buyer</strong><br>' + buyerName + '<br><small>Delivery destination</small>')
                                .openPopup();

                            L.marker([farmerLat, farmerLng], {icon: farmerIcon})
                                .addTo(map)
                                .bindPopup('<strong>🌾 Farmer</strong><br>' + farmerName + '<br><small>Pickup origin</small>');

                            L.polyline(
                                [[farmerLat, farmerLng], [buyerLat, buyerLng]],
                                {color:'#3b82f6', weight:2.5, dashArray:'8,6', opacity:.75}
                            ).addTo(map);

                            map.fitBounds([
                                [buyerLat, buyerLng],
                                [farmerLat, farmerLng]
                            ], {padding: [40, 40]});
                        });
                        </script>

                        <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                            <div style="display:flex;align-items:center;gap:6px;background:#dcfce7;border-radius:99px;padding:4px 12px;font-size:.75rem;font-weight:700;color:#16a34a;">
                                🌾 <?= sanitize($farmerLoc['name']) ?> <span style="font-weight:600;opacity:.75;">(Farmer)</span>
                            </div>
                            <div style="display:flex;align-items:center;gap:6px;background:#dbeafe;border-radius:99px;padding:4px 12px;font-size:.75rem;font-weight:700;color:#1d4ed8;">
                                🛒 <?= sanitize($buyerLoc['name']) ?> <span style="font-weight:600;opacity:.75;">(Buyer)</span>
                            </div>
                        </div>

                        <?php else: ?>
                        <div style="background:#f8fafc;border:1.5px dashed var(--border);border-radius:var(--radius);padding:2rem;text-align:center;">
                            <i class="fa-solid fa-map-location-dot" style="font-size:2rem;color:var(--text-muted);opacity:.35;display:block;margin-bottom:.5rem;"></i>
                            <div style="font-size:.84rem;font-weight:700;color:var(--text-muted);">Map unavailable</div>
                            <div style="font-size:.75rem;color:var(--text-muted);margin-top:4px;">Both the buyer and farmer need to set their location in their profiles.</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Order Meta / Notes -->
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="info-card">
                            <div class="info-card-title"><i class="fa-solid fa-circle-info text-green"></i> Order Details</div>
                            <div class="info-row">
                                <span class="info-label">Order ID</span>
                                <span class="info-value">#<?= $orderId ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Payment Status</span>
                                <span class="info-value">
                                    <?php
                                    $pm = $order['payment_method'] ?? '';
                                    $isCodPayment = ($pm === 'Cash on Delivery');
                                    $paidStatus = $isCodPayment ? 'Pay on Delivery' : 'Paid Online';
                                    $paidClass  = $isCodPayment ? 'status-pending' : 'status-completed';
                                    ?>
                                    <span class="status-badge <?= $paidClass ?>"><?= $paidStatus ?></span>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Payment Method</span>
                                <span class="info-value"><?= !empty($order['payment_method']) ? sanitize($order['payment_method']) : '—' ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Delivery Address</span>
                                <span class="info-value"><?= !empty($order['delivery_address']) ? sanitize($order['delivery_address']) : sanitize($order['buyer_address'] ?? '—') ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Placed On</span>
                                <span class="info-value"><?= date('M j, Y g:i A', strtotime($order['created_at'])) ?></span>
                            </div>
                            <?php if (!empty($order['updated_at']) && $order['updated_at'] !== $order['created_at']): ?>
                            <div class="info-row">
                                <span class="info-label">Last Updated</span>
                                <span class="info-value"><?= date('M j, Y g:i A', strtotime($order['updated_at'])) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($order['notes'])): ?>
                    <div class="col-md-6">
                        <div class="info-card" style="height:100%;">
                            <div class="info-card-title"><i class="fa-solid fa-note-sticky text-green"></i> Order Notes</div>
                            <p style="font-size:.84rem;color:var(--text);line-height:1.6;margin:0;">
                                <?= nl2br(sanitize($order['notes'])) ?>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Navigation between orders -->
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-top:.5rem;">
                    <?php
                    $prevOrder = $pdo->prepare("SELECT id FROM orders WHERE id < ? ORDER BY id DESC LIMIT 1");
                    $prevOrder->execute([$orderId]);
                    $prev = $prevOrder->fetchColumn();

                    $nextOrder = $pdo->prepare("SELECT id FROM orders WHERE id > ? ORDER BY id ASC LIMIT 1");
                    $nextOrder->execute([$orderId]);
                    $next = $nextOrder->fetchColumn();
                    ?>
                    <div>
                        <?php if ($prev): ?>
                        <a href="order_detail.php?id=<?= $prev ?>" class="action-btn action-btn-gray" style="padding:.45rem 1rem;font-size:.82rem;">
                            <i class="fa-solid fa-chevron-left"></i> Previous Order
                        </a>
                        <?php endif; ?>
                    </div>
                    <a href="orders.php" class="action-btn action-btn-primary" style="padding:.45rem 1rem;font-size:.82rem;">
                        <i class="fa-solid fa-list"></i> All Orders
                    </a>
                    <div>
                        <?php if ($next): ?>
                        <a href="order_detail.php?id=<?= $next ?>" class="action-btn action-btn-gray" style="padding:.45rem 1rem;font-size:.82rem;">
                            Next Order <i class="fa-solid fa-chevron-right"></i>
                        </a>
                        <?php endif; ?>
                    </div>
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
            <h5 style="font-weight:800;margin-bottom:.4rem;">Delete Order?</h5>
            <p style="font-size:.83rem;color:var(--text-muted);margin:0;">
                You're about to permanently delete <strong>Order #<?= $orderId ?></strong>
                from <strong><?= sanitize($order['buyer_name']) ?></strong>.<br>
                This cannot be undone.
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

<script>
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>