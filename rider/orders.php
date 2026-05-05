<?php
$page_title = 'All Orders';
$hide_navbar = true;
require_once __DIR__ . '/../includes/header.php';
requireRole('rider');

$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];

// Fetch rider profile
$rider = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$rider->execute([$userId]);
$rider = $rider->fetch();

// Handle delivery confirmation (mark as completed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_order'])) {
    $orderId = intval($_POST['order_id']);
    $stmt = $pdo->prepare("
        UPDATE orders
        SET status = 'completed'
        WHERE id = ?
          AND rider_id = ?
          AND status = 'shipped'
    ");
    $stmt->execute([$orderId, $userId]);

    if ($stmt->rowCount() > 0) {
        setFlash('success', "Order #$orderId marked as delivered! Great job! 🎉");
    } else {
        setFlash('error', "Could not complete Order #$orderId.");
    }
    header("Location: orders.php"); exit();
}

// Filters
$allowedStatuses = ['all', 'confirmed', 'shipped', 'completed', 'cancelled'];
$filterStatus = isset($_GET['status']) && in_array($_GET['status'], $allowedStatuses)
    ? $_GET['status'] : 'all';

$search = trim($_GET['search'] ?? '');
$targetOrderId = isset($_GET['id']) ? intval($_GET['id']) : null;

// Build query
$whereClauses = ['o.rider_id = ?'];
$params = [$userId];

if ($targetOrderId) {
    $whereClauses[] = 'o.id = ?';
    $params[] = $targetOrderId;
} else {
    if ($filterStatus !== 'all') {
        $whereClauses[] = 'o.status = ?';
        $params[] = $filterStatus;
    }

    if ($search !== '') {
        $whereClauses[] = '(ub.name LIKE ? OR uf.name LIKE ? OR p.name LIKE ? OR CAST(o.id AS CHAR) LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
}

$whereSQL = implode(' AND ', $whereClauses);

$orders = $pdo->prepare("
    SELECT o.*,
           ub.name        AS buyer_name,
           ub.phone       AS buyer_phone,
           ub.location    AS buyer_location,
           uf.name        AS farmer_name,
           uf.phone       AS farmer_phone,
           uf.location    AS farmer_location,
           p.name         AS product_name,
           p.image        AS product_image,
           p.category     AS product_category,
           oi.quantity_kg,
           oi.price_per_kg
    FROM orders o
    JOIN users ub            ON o.buyer_id    = ub.id
    JOIN users uf            ON o.farmer_id   = uf.id
    LEFT JOIN order_items oi ON oi.order_id   = o.id
    LEFT JOIN products p     ON oi.product_id = p.id
   WHERE $whereSQL
    GROUP BY o.id
    ORDER BY o.updated_at DESC
");
$orders->execute($params);
$orders = $orders->fetchAll();

// Stats
$stats = $pdo->prepare("
    SELECT
        SUM(status = 'confirmed')  AS pickup_ready,
        SUM(status = 'shipped')    AS in_transit,
        SUM(status = 'completed')  AS delivered,
        SUM(status = 'cancelled')  AS cancelled,
        COUNT(*)                   AS total,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN delivery_fee ELSE 0 END), 0) AS total_earned
    FROM orders WHERE rider_id = ?
");
$stats->execute([$userId]);
$stats = $stats->fetch();

$flash = getFlash();
$emojis = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕','Livestock'=>'🐄','Seafood'=>'🐟','Others'=>'📦'];

$statusConfig = [
    'confirmed'  => ['label'=>'Pickup Ready', 'color'=>'#F97316', 'bg'=>'#FFF7ED', 'border'=>'#FED7AA', 'icon'=>'fa-box-open',      'badge_bg'=>'rgba(249,115,22,.15)',  'badge_color'=>'#C2410C'],
    'shipped'    => ['label'=>'Out for Delivery', 'color'=>'#0E7490', 'bg'=>'#ECFEFF', 'border'=>'#A5F3FC', 'icon'=>'fa-truck-fast', 'badge_bg'=>'rgba(14,116,144,.12)', 'badge_color'=>'#0E7490'],
    'completed'  => ['label'=>'Delivered',    'color'=>'#16A34A', 'bg'=>'#F0FDF4', 'border'=>'#BBF7D0', 'icon'=>'fa-circle-check',  'badge_bg'=>'rgba(22,163,74,.12)',   'badge_color'=>'#15803D'],
    'cancelled'  => ['label'=>'Cancelled',    'color'=>'#DC2626', 'bg'=>'#FEF2F2', 'border'=>'#FECACA', 'icon'=>'fa-ban',           'badge_bg'=>'rgba(220,38,38,.1)',    'badge_color'=>'#B91C1C'],
    'pending'    => ['label'=>'Pending',      'color'=>'#D97706', 'bg'=>'#FFFBEB', 'border'=>'#FDE68A', 'icon'=>'fa-clock',         'badge_bg'=>'rgba(217,119,6,.1)',    'badge_color'=>'#B45309'],
];
?>

<style>
:root {
    --rider-primary: #1B5E20;
    --rider-accent:  #4CAF50;
    --rider-orange:  #F97316;
    --rider-blue:    #3B82F6;
    --rider-bg:      #F0F7F0;
}

.orders-page { background: var(--rider-bg); min-height: 100vh; padding-bottom: 3rem; }

/* Stat cards */
.rider-stat { background: white; border-radius: 16px; padding: 1.1rem 1.2rem; border: 1px solid #E2E8F0; box-shadow: 0 2px 8px rgba(0,0,0,.05); display: flex; align-items: center; gap: 14px; }
.rider-stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
.rider-stat-val  { font-size: 1.4rem; font-weight: 800; line-height: 1; }
.rider-stat-lbl  { font-size: .7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; margin-top: 3px; }

/* Filter bar */
.filter-bar { background: white; border-radius: 16px; border: 1px solid #E2E8F0; padding: 1rem 1.25rem; margin-bottom: 1.25rem; display: flex; gap: .6rem; flex-wrap: wrap; align-items: center; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
.filter-btn { border: 1.5px solid #E2E8F0; background: #F8FAFC; color: var(--text-muted); border-radius: 10px; padding: .45rem 1rem; font-size: .78rem; font-weight: 700; cursor: pointer; transition: all .15s; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
.filter-btn:hover  { border-color: var(--rider-primary); color: var(--rider-primary); background: #F0FDF4; }
.filter-btn.active { border-color: var(--rider-primary); color: var(--rider-primary); background: #DCFCE7; }
.search-box { border: 1.5px solid #E2E8F0; border-radius: 10px; padding: .45rem 1rem .45rem 2.1rem; font-size: .82rem; outline: none; background: #F8FAFC; transition: border .15s; min-width: 180px; font-family: inherit; }
.search-box:focus { border-color: var(--rider-primary); background: white; }
.search-wrap { position: relative; }
.search-wrap i { position: absolute; left: .7rem; top: 50%; transform: translateY(-50%); color: #94A3B8; font-size: .8rem; pointer-events: none; }

/* Order cards */
.order-card { background: white; border-radius: 18px; border: 2px solid #E2E8F0; box-shadow: 0 2px 10px rgba(0,0,0,.06); overflow: hidden; margin-bottom: 1rem; transition: box-shadow .2s, transform .15s; }
.order-card:hover { box-shadow: 0 8px 28px rgba(0,0,0,.1); transform: translateY(-2px); }
.order-card-header { padding: 1rem 1.25rem; border-bottom: 1px solid #F1F5F9; display: flex; justify-content: space-between; align-items: center; }
.order-card-body   { padding: 1.25rem; }

/* Route labels */
.route-label { font-size: .68rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 2px; }
.route-name  { font-size: .85rem; font-weight: 700; color: var(--text); }
.route-addr  { font-size: .75rem; color: var(--text-muted); }
.route-phone { font-size: .72rem; color: var(--rider-primary); font-weight: 700; margin-top: 2px; }

/* Status badge */
.status-badge { display: inline-flex; align-items: center; gap: 5px; border-radius: 99px; padding: 3px 11px; font-size: .7rem; font-weight: 800; letter-spacing: .03em; }

/* Deliver button */
.btn-deliver {
    background: linear-gradient(135deg, #16A34A, #15803D);
    color: white;
    border: none;
    border-radius: 12px;
    padding: .7rem 1.5rem;
    font-weight: 800;
    font-size: .85rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all .2s;
    font-family: inherit;
    box-shadow: 0 4px 14px rgba(22,163,74,.3);
}
.btn-deliver:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(22,163,74,.45); }

/* Pickup button */
.btn-pickup {
    background: linear-gradient(135deg, #F97316, #EA580C);
    color: white;
    border: none;
    border-radius: 12px;
    padding: .7rem 1.5rem;
    font-weight: 800;
    font-size: .85rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all .2s;
    font-family: inherit;
    box-shadow: 0 4px 14px rgba(249,115,22,.3);
}
.btn-pickup:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(249,115,22,.45); }

.btn-msg { background: var(--pale-green); color: var(--rider-primary); border: none; border-radius: 12px; padding: .65rem 1rem; font-weight: 700; font-size: .8rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; transition: all .15s; }
.btn-msg:hover { background: var(--rider-primary); color: white; }

/* Animations */
@keyframes pulse-ring { 0%{box-shadow:0 0 0 0 rgba(249,115,22,.5)} 70%{box-shadow:0 0 0 10px rgba(249,115,22,0)} 100%{box-shadow:0 0 0 0 rgba(249,115,22,0)} }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.4} }
.pulse { animation: pulse-ring 1.8s infinite; }
.blink { animation: blink 1.4s ease-in-out infinite; }

/* Flash */
.flash-success { background: #DCFCE7; border: 1px solid #BBF7D0; color: #16A34A; border-radius: 12px; padding: .8rem 1.1rem; font-weight: 700; font-size: .85rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 8px; }
.flash-error   { background: #FEE2E2; border: 1px solid #FECACA; color: #DC2626; border-radius: 12px; padding: .8rem 1.1rem; font-weight: 700; font-size: .85rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 8px; }

/* Empty state */
.empty-state { text-align: center; padding: 4rem 2rem; color: var(--text-muted); }
.empty-state .icon { font-size: 4rem; margin-bottom: 1rem; opacity: .6; }

/* Step bar */
.step-bar { display: flex; align-items: center; gap: 0; margin-bottom: 1rem; }
.step-item { display: flex; align-items: center; gap: 6px; }
.step-dot  { width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .65rem; font-weight: 800; flex-shrink: 0; }
.step-dot-active   { background: #F97316; color: white; }
.step-dot-inactive { background: #E2E8F0; color: #94A3B8; }
.step-dot-done     { background: #16A34A; color: white; }
.step-line { flex: 1; height: 2px; background: #E2E8F0; margin: 0 4px; min-width: 24px; }
.step-label { font-size: .65rem; font-weight: 700; color: var(--text-muted); white-space: nowrap; }

/* Earnings chip */
.earned-chip { display: inline-flex; align-items: center; gap: 6px; background: #DCFCE7; border: 1px solid #BBF7D0; border-radius: 12px; padding: .6rem 1rem; font-size: .82rem; font-weight: 800; color: #15803D; }

/* Sort label */
.results-meta { font-size: .78rem; color: var(--text-muted); font-weight: 600; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; }
</style>

<div class="orders-page">

    <!-- Header -->
    <div style="background:linear-gradient(135deg,#0D3B13 0%,#1B5E20 45%,#2E7D32 100%);padding:1.5rem 0;position:relative;overflow:hidden;">
        <div class="container" style="position:relative;">
            <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">

                <!-- Left: Avatar + Greeting -->
                <div style="display:flex;align-items:center;gap:14px;">
                    <?php if (!empty($rider['profile_image'])): ?>
                        <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($rider['profile_image']) ?>"
                             style="width:52px;height:52px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.3);flex-shrink:0;">
                    <?php else: ?>
                        <div style="width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:800;color:white;border:3px solid rgba(255,255,255,.25);flex-shrink:0;">
                            <?= strtoupper(substr($rider['name'],0,1)) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <div style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:white;line-height:1.1;">
                            All Orders
                        </div>
                        <div style="font-size:.82rem;color:rgba(255,255,255,.65);margin-top:.2rem;">
                            🛵 <?= sanitize($rider['name']) ?> · <?= sanitize($rider['location'] ?? 'Mindanao') ?>
                        </div>
                        <?php if ($stats['pickup_ready'] > 0): ?>
                        <div style="margin-top:.5rem;">
                            <span style="background:rgba(249,115,22,.25);border:1px solid rgba(249,115,22,.4);border-radius:99px;padding:.3rem .85rem;font-size:.72rem;font-weight:600;color:white;display:inline-flex;align-items:center;gap:5px;" class="blink">
                                🔥 <?= $stats['pickup_ready'] ?> pickup<?= $stats['pickup_ready'] > 1 ? 's' : '' ?> waiting
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right: Nav -->
                <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;">
                    <a href="dashboard.php" style="display:flex;align-items:center;gap:6px;padding:.5rem 1rem;color:white;font-size:.8rem;font-weight:700;text-decoration:none;border-radius:10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);">
                        <i class="fa-solid fa-gauge"></i> Dashboard
                    </a>
                    <a href="pickup.php" style="display:flex;align-items:center;gap:6px;padding:.5rem 1rem;color:white;font-size:.8rem;font-weight:700;text-decoration:none;border-radius:10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);">
                        <i class="fa-solid fa-box-open"></i> Pickup
                    </a>
                    <a href="orders.php" style="display:flex;align-items:center;gap:6px;padding:.5rem 1rem;color:white;font-size:.8rem;font-weight:700;text-decoration:none;border-radius:10px;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.3);">
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

    <div class="container" style="padding-top:1.5rem;">

        <?php if ($flash): ?>
        <div class="<?= $flash['type'] === 'success' ? 'flash-success' : 'flash-error' ?>">
            <i class="fa-solid fa-<?= $flash['type'] === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
            <?= sanitize($flash['message']) ?>
        </div>
        <?php endif; ?>

        <!-- Quick Stats Row -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="rider-stat" style="border-color:#FED7AA;">
                    <div class="rider-stat-icon" style="background:#FFF7ED;">
                        <i class="fa-solid fa-box-open" style="color:#F97316;"></i>
                    </div>
                    <div>
                        <div class="rider-stat-val" style="color:#F97316;"><?= $stats['pickup_ready'] ?></div>
                        <div class="rider-stat-lbl">Pickup Ready</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="rider-stat">
                    <div class="rider-stat-icon" style="background:#CFFAFE;">
                        <i class="fa-solid fa-truck-fast" style="color:#0E7490;"></i>
                    </div>
                    <div>
                        <div class="rider-stat-val" style="color:#0E7490;"><?= $stats['in_transit'] ?></div>
                        <div class="rider-stat-lbl">Out for Delivery</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="rider-stat">
                    <div class="rider-stat-icon" style="background:#DCFCE7;">
                        <i class="fa-solid fa-circle-check" style="color:#16A34A;"></i>
                    </div>
                    <div>
                        <div class="rider-stat-val" style="color:#16A34A;"><?= $stats['delivered'] ?></div>
                        <div class="rider-stat-lbl">Delivered</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="rider-stat" style="border-color:#BBF7D0;">
                    <div class="rider-stat-icon" style="background:#DCFCE7;">
                        <i class="fa-solid fa-peso-sign" style="color:#15803D;"></i>
                    </div>
                    <div>
                        <div class="rider-stat-val" style="color:#15803D;">₱<?= number_format($stats['total_earned'], 0) ?></div>
                        <div class="rider-stat-lbl">Total Earned</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter & Search Bar -->
        <form method="GET" class="filter-bar">
            <!-- Search -->
            <div class="search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="search" class="search-box" placeholder="Search orders, buyers, products…"
                       value="<?= htmlspecialchars($search) ?>">
            </div>

            <!-- Status filters -->
            <a href="?status=all<?= $search ? '&search='.urlencode($search) : '' ?>"
               class="filter-btn <?= $filterStatus === 'all' ? 'active' : '' ?>">
                <i class="fa-solid fa-layer-group"></i> All
                <span style="background:#E2E8F0;border-radius:99px;padding:1px 7px;font-size:.65rem;"><?= $stats['total'] ?></span>
            </a>
            <a href="?status=confirmed<?= $search ? '&search='.urlencode($search) : '' ?>"
               class="filter-btn <?= $filterStatus === 'confirmed' ? 'active' : '' ?>" style="<?= $filterStatus === 'confirmed' ? 'border-color:#F97316;color:#C2410C;background:#FFF7ED;' : '' ?>">
                <i class="fa-solid fa-box-open"></i> Pickup
                <?php if ($stats['pickup_ready'] > 0): ?>
                <span style="background:#F97316;color:white;border-radius:99px;padding:1px 7px;font-size:.65rem;"><?= $stats['pickup_ready'] ?></span>
                <?php endif; ?>
            </a>
            <a href="?status=shipped<?= $search ? '&search='.urlencode($search) : '' ?>"
               class="filter-btn <?= $filterStatus === 'shipped' ? 'active' : '' ?>" style="<?= $filterStatus === 'shipped' ? 'border-color:#0E7490;color:#0E7490;background:#ECFEFF;' : '' ?>">
                <i class="fa-solid fa-truck-fast"></i> Out for Delivery
            </a>
            <a href="?status=completed<?= $search ? '&search='.urlencode($search) : '' ?>"
               class="filter-btn <?= $filterStatus === 'completed' ? 'active' : '' ?>" style="<?= $filterStatus === 'completed' ? 'border-color:#16A34A;color:#15803D;background:#F0FDF4;' : '' ?>">
                <i class="fa-solid fa-circle-check"></i> Delivered
            </a>
            <a href="?status=cancelled<?= $search ? '&search='.urlencode($search) : '' ?>"
               class="filter-btn <?= $filterStatus === 'cancelled' ? 'active' : '' ?>" style="<?= $filterStatus === 'cancelled' ? 'border-color:#DC2626;color:#B91C1C;background:#FEF2F2;' : '' ?>">
                <i class="fa-solid fa-ban"></i> Cancelled
            </a>

            <?php if ($search): ?>
            <a href="?status=<?= $filterStatus ?>" class="filter-btn" style="border-color:#DC2626;color:#DC2626;">
                <i class="fa-solid fa-xmark"></i> Clear Search
            </a>
            <?php endif; ?>
        </form>

        <!-- Results meta -->
        <div class="results-meta">
            <span>
                Showing <strong><?= count($orders) ?></strong> order<?= count($orders) !== 1 ? 's' : '' ?>
                <?php if ($filterStatus !== 'all'): ?>
                · filtered by <strong><?= ucfirst($filterStatus) ?></strong>
                <?php endif; ?>
                <?php if ($search): ?>
                · matching "<strong><?= htmlspecialchars($search) ?></strong>"
                <?php endif; ?>
            </span>
            <?php if ($stats['delivered'] > 0): ?>
            <span class="earned-chip">
                <i class="fa-solid fa-sack-dollar"></i>
                ₱<?= number_format($stats['total_earned'], 2) ?> earned
            </span>
            <?php endif; ?>
        </div>

        <!-- Order Cards -->
        <?php if (empty($orders)): ?>
        <div class="order-card" style="border-color:#E2E8F0;">
            <div class="empty-state">
                <div class="icon">📋</div>
                <div style="font-weight:800;font-size:1.05rem;color:var(--text);margin-bottom:.5rem;">
                    No orders found
                </div>
                <div style="font-size:.85rem;margin-bottom:1.25rem;">
                    <?php if ($search || $filterStatus !== 'all'): ?>
                        Try adjusting your filters or search term.
                    <?php else: ?>
                        You have no orders assigned yet.
                    <?php endif; ?>
                </div>
                <a href="orders.php" style="display:inline-flex;align-items:center;gap:8px;background:var(--pale-green);color:var(--rider-primary);border-radius:10px;padding:.6rem 1.25rem;font-weight:700;font-size:.85rem;text-decoration:none;">
                    <i class="fa-solid fa-rotate-left"></i> Reset Filters
                </a>
            </div>
        </div>

        <?php else: ?>

        <?php foreach ($orders as $o):
            $cfg = $statusConfig[$o['status']] ?? $statusConfig['pending'];
        ?>

        <!-- Order card — border color by status -->
        <div class="order-card" id="order-<?= $o['id'] ?>" style="border-color:<?= $cfg['border'] ?>;<?= ($targetOrderId === $o['id']) ? 'box-shadow:0 0 0 3px #F97316,0 8px 28px rgba(249,115,22,.25);' : '' ?>">

            <!-- Card Header -->
            <div class="order-card-header" style="background:<?= $cfg['bg'] ?>;border-bottom-color:<?= $cfg['border'] ?>;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <div style="font-weight:800;color:<?= $cfg['color'] ?>;font-size:.98rem;">Order #<?= $o['id'] ?></div>

                    <!-- Status badge -->
                    <span class="status-badge <?= $o['status'] === 'confirmed' ? 'pulse' : '' ?>"
                          style="background:<?= $cfg['badge_bg'] ?>;color:<?= $cfg['badge_color'] ?>;">
                        <i class="fa-solid <?= $cfg['icon'] ?>" style="font-size:.65rem;"></i>
                        <?= $cfg['label'] ?>
                    </span>

                    <span style="font-size:.72rem;color:var(--text-muted);font-weight:700;">
                        📅 <?= date('M j, Y · g:ia', strtotime($o['created_at'])) ?>
                    </span>
                </div>
                <div style="text-align:right;">
                    <div style="font-weight:800;color:var(--rider-primary);font-size:1rem;">₱<?= number_format($o['delivery_fee'], 2) ?></div>
                    <div style="font-size:.68rem;color:var(--text-muted);">delivery fee</div>
                </div>
            </div>

            <!-- Card Body -->
            <div class="order-card-body">

                <!-- Progress Steps -->
                <?php
                $stepStatus = $o['status'];
                $placedDone   = true;
                $pickupDone   = in_array($stepStatus, ['shipped','completed']);
                $pickupActive = $stepStatus === 'confirmed';
                $transitDone  = $stepStatus === 'completed';
                $transitActive= $stepStatus === 'shipped';
                $delivDone    = $stepStatus === 'completed';
                ?>
                <div class="step-bar">
                    <div class="step-item">
                        <div class="step-dot step-dot-done"><i class="fa-solid fa-check" style="font-size:.55rem;"></i></div>
                        <div class="step-label" style="color:#16A34A;">Order Placed</div>
                    </div>
                    <div class="step-line" style="background:<?= ($pickupDone || $pickupActive) ? 'linear-gradient(to right,#16A34A,#F97316)' : '#E2E8F0' ?>;"></div>
                    <div class="step-item">
                        <div class="step-dot <?= $pickupDone ? 'step-dot-done' : ($pickupActive ? 'step-dot-active' : 'step-dot-inactive') ?>">
                            <?= $pickupDone ? '<i class="fa-solid fa-check" style="font-size:.55rem;"></i>' : '2' ?>
                        </div>
                        <div class="step-label" style="<?= $pickupActive ? 'color:#F97316;font-weight:800;' : ($pickupDone ? 'color:#16A34A;' : '') ?>">Pickup</div>
                    </div>
                    <div class="step-line" style="background:<?= $transitDone ? '#16A34A' : ($transitActive ? 'linear-gradient(to right,#F97316,#0E7490)' : '#E2E8F0') ?>;"></div>
                    <div class="step-item">
                        <div class="step-dot <?= $transitDone ? 'step-dot-done' : ($transitActive ? 'step-dot-active' : 'step-dot-inactive') ?>"
                             style="<?= $transitActive ? 'background:#0E7490;' : '' ?>">
                            <?= $transitDone ? '<i class="fa-solid fa-check" style="font-size:.55rem;"></i>' : '3' ?>
                        </div>
                        <div class="step-label" style="<?= $transitActive ? 'color:#0E7490;font-weight:800;' : ($transitDone ? 'color:#16A34A;' : '') ?>">On the Way</div>
                    </div>
                    <div class="step-line" style="background:<?= $delivDone ? '#16A34A' : '#E2E8F0' ?>;"></div>
                    <div class="step-item">
                        <div class="step-dot <?= $delivDone ? 'step-dot-done' : 'step-dot-inactive' ?>">
                            <?= $delivDone ? '<i class="fa-solid fa-check" style="font-size:.55rem;"></i>' : '4' ?>
                        </div>
                        <div class="step-label" style="<?= $delivDone ? 'color:#16A34A;' : '' ?>">Delivered</div>
                    </div>
                </div>

                <!-- Product Info -->
                <div style="display:flex;align-items:center;gap:12px;padding:.8rem;background:<?= $cfg['bg'] ?>;border-radius:12px;margin-bottom:1rem;border:1px solid <?= $cfg['border'] ?>;">
                    <div style="width:50px;height:50px;background:white;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">
                        <?php if ($o['product_image']): ?>
                            <img src="<?= BASE_URL ?>/assets/images/products/<?= sanitize($o['product_image']) ?>" style="width:50px;height:50px;object-fit:cover;border-radius:10px;">
                        <?php else: ?>
                            <?= $emojis[$o['product_category']] ?? '🌾' ?>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;">
                        <div style="font-weight:800;font-size:.92rem;color:var(--text);"><?= sanitize($o['product_name'] ?? 'Product') ?></div>
                        <div style="font-size:.75rem;color:var(--text-muted);">
                            <?= number_format($o['quantity_kg'], 1) ?>kg × ₱<?= number_format($o['price_per_kg'], 2) ?>/kg
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:.68rem;color:var(--text-muted);">Order Total</div>
                        <div style="font-weight:800;color:var(--rider-primary);font-size:.95rem;">₱<?= number_format($o['total_amount'], 2) ?></div>
                    </div>
                </div>

                <!-- Route: Farmer → Buyer -->
                <div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:1rem;">
                    <div style="flex:1;background:#EFF6FF;border-radius:12px;padding:.85rem;border:1px solid #BFDBFE;">
                        <div class="route-label" style="color:#1D4ED8;"><i class="fa-solid fa-warehouse me-1"></i>Pickup From</div>
                        <div class="route-name"><?= sanitize($o['farmer_name']) ?></div>
                        <div class="route-addr">📍 <?= sanitize($o['farmer_location']) ?></div>
                        <?php if ($o['farmer_phone']): ?>
                        <a href="tel:<?= sanitize($o['farmer_phone']) ?>" class="route-phone" style="display:inline-flex;align-items:center;gap:4px;text-decoration:none;">
                            <i class="fa-solid fa-phone"></i><?= sanitize($o['farmer_phone']) ?>
                        </a>
                        <?php endif; ?>
                    </div>

                    <div style="display:flex;flex-direction:column;align-items:center;padding-top:16px;gap:3px;flex-shrink:0;">
                        <div style="width:8px;height:8px;border-radius:50%;background:#94A3B8;"></div>
                        <div style="width:2px;height:22px;background:repeating-linear-gradient(to bottom,#94A3B8 0,#94A3B8 3px,transparent 3px,transparent 6px);"></div>
                        <i class="fa-solid fa-arrow-down" style="color:#94A3B8;font-size:.75rem;"></i>
                    </div>

                    <div style="flex:1;background:#F0FDF4;border-radius:12px;padding:.85rem;border:1px solid #BBF7D0;">
                        <div class="route-label" style="color:#16A34A;"><i class="fa-solid fa-location-dot me-1"></i>Deliver To</div>
                        <div class="route-name"><?= sanitize($o['buyer_name']) ?></div>
                        <div class="route-addr">📍 <?= sanitize($o['delivery_address'] ?: $o['buyer_location']) ?></div>
                        <?php if ($o['buyer_phone']): ?>
                        <a href="tel:<?= sanitize($o['buyer_phone']) ?>" class="route-phone" style="display:inline-flex;align-items:center;gap:4px;text-decoration:none;">
                            <i class="fa-solid fa-phone"></i><?= sanitize($o['buyer_phone']) ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Distance / Fee chips -->
                <?php if ($o['distance_km']): ?>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;">
                    <span style="background:#DBEAFE;color:#1D4ED8;border-radius:99px;padding:3px 10px;font-size:.72rem;font-weight:700;">
                        <i class="fa-solid fa-route me-1"></i><?= number_format($o['distance_km'], 1) ?> km
                    </span>
                    <span style="background:#FFF7ED;color:#C2410C;border-radius:99px;padding:3px 10px;font-size:.72rem;font-weight:700;">
                        <i class="fa-solid fa-peso-sign me-1"></i>₱<?= number_format($o['delivery_fee'], 2) ?> delivery fee
                    </span>
                </div>
                <?php endif; ?>

                <!-- Notes -->
                <?php if ($o['notes']): ?>
                <div style="background:#FEF3C7;border-left:3px solid #F59E0B;border-radius:0 8px 8px 0;padding:.55rem .85rem;font-size:.78rem;color:#92400E;margin-bottom:1rem;">
                    <i class="fa-solid fa-note-sticky me-1"></i><?= sanitize($o['notes']) ?>
                </div>
                <?php endif; ?>

                <!-- Actions -->
                <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;padding-top:.25rem;border-top:1px dashed <?= $cfg['border'] ?>;">

                    <?php if ($o['status'] === 'confirmed'): ?>
                    <!-- Go to pickup page -->
                    <a href="pickup.php" class="btn-pickup pulse">
                        <i class="fa-solid fa-box-open"></i> Go to Pickup
                    </a>

                    <?php elseif ($o['status'] === 'shipped'): ?>
                    <!-- Mark as delivered -->
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                        <button type="submit" name="complete_order" class="btn-deliver"
                                onclick="return confirm('Mark Order #<?= $o['id'] ?> as delivered?\n\nOnly confirm once the buyer has received the package.')">
                            <i class="fa-solid fa-circle-check"></i> Mark Delivered
                        </button>
                    </form>

                    <?php elseif ($o['status'] === 'completed'): ?>
                    <span style="display:inline-flex;align-items:center;gap:6px;background:#DCFCE7;border:1px solid #BBF7D0;border-radius:12px;padding:.65rem 1.1rem;font-size:.82rem;font-weight:800;color:#15803D;">
                        <i class="fa-solid fa-circle-check"></i> Delivered ✔
                    </span>

                    <?php elseif ($o['status'] === 'cancelled'): ?>
                    <span style="display:inline-flex;align-items:center;gap:6px;background:#FEE2E2;border:1px solid #FECACA;border-radius:12px;padding:.65rem 1.1rem;font-size:.82rem;font-weight:800;color:#B91C1C;">
                        <i class="fa-solid fa-ban"></i> Cancelled
                    </span>
                    <?php endif; ?>

                                        <!-- View Details — always visible -->
                    <a href="order_detail.php?id=<?= $o['id'] ?>" 
                    style="display:inline-flex;align-items:center;gap:6px;background:#F1F5F9;color:#334155;border:1.5px solid #E2E8F0;border-radius:12px;padding:.65rem 1rem;font-weight:700;font-size:.8rem;text-decoration:none;transition:all .15s;"
                    onmouseover="this.style.background='#1B5E20';this.style.color='white';this.style.borderColor='#1B5E20'"
                    onmouseout="this.style.background='#F1F5F9';this.style.color='#334155';this.style.borderColor='#E2E8F0'">
                        <i class="fa-solid fa-eye"></i> View Details
                    </a>

                    <?php if (!in_array($o['status'], ['cancelled'])): ?>
                    <a href="messages.php?to=<?= $o['farmer_id'] ?>" class="btn-msg">
                        <i class="fa-solid fa-tractor"></i> Message Farmer
                    </a>
                    <a href="messages.php?to=<?= $o['buyer_id'] ?>" class="btn-msg">
                        <i class="fa-solid fa-comments"></i> Message Buyer
                    </a>
                    <?php endif; ?>

                </div>

            </div><!-- /card-body -->
        </div><!-- /order-card -->
        <?php endforeach; ?>
        <?php endif; ?>

    </div>
</div>

<?php if ($targetOrderId): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('order-<?= $targetOrderId ?>');
    if (el) {
        setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'center' }), 200);
    }
});
</script>
<?php endif; ?>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>