<?php
$page_title = 'Rider Dashboard';
$hide_navbar = true;
require_once __DIR__ . '/../includes/header.php';
requireRole('rider');

$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];

// Fetch rider profile
$rider = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$rider->execute([$userId]);
$rider = $rider->fetch();

// --- Stats ---
$pickupReady = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE rider_id = ? AND status = 'confirmed'");
$pickupReady->execute([$userId]); $pickupReady = $pickupReady->fetchColumn();

$inTransit = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE rider_id = ? AND status = 'shipped'");
$inTransit->execute([$userId]); $inTransit = $inTransit->fetchColumn();

$delivered = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE rider_id = ? AND status = 'completed'");
$delivered->execute([$userId]); $delivered = $delivered->fetchColumn();

$totalOrders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE rider_id = ?");
$totalOrders->execute([$userId]); $totalOrders = $totalOrders->fetchColumn();

// Earnings
$totalEarnings = $pdo->prepare("SELECT COALESCE(SUM(delivery_fee),0) FROM orders WHERE rider_id = ? AND status = 'completed'");
$totalEarnings->execute([$userId]); $totalEarnings = $totalEarnings->fetchColumn();

$monthEarnings = $pdo->prepare("SELECT COALESCE(SUM(delivery_fee),0) FROM orders WHERE rider_id = ? AND status = 'completed' AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())");
$monthEarnings->execute([$userId]); $monthEarnings = $monthEarnings->fetchColumn();

$weekEarnings = $pdo->prepare("SELECT COALESCE(SUM(delivery_fee),0) FROM orders WHERE rider_id = ? AND status = 'completed' AND YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1)");
$weekEarnings->execute([$userId]); $weekEarnings = $weekEarnings->fetchColumn();

// Recent orders (last 6)
$recent = $pdo->prepare("
    SELECT o.*,
           ub.name       AS buyer_name,
           ub.location   AS buyer_location,
           uf.name       AS farmer_name,
           uf.location   AS farmer_location,
           p.name        AS product_name,
           p.category    AS product_category,
           p.image       AS product_image,   -- ✅ add this
           oi.quantity_kg
    FROM orders o
    JOIN users ub            ON o.buyer_id  = ub.id
    JOIN users uf            ON o.farmer_id = uf.id
    LEFT JOIN order_items oi ON oi.order_id  = o.id
    LEFT JOIN products p     ON oi.product_id = p.id
   WHERE o.rider_id = ?
    GROUP BY o.id
    ORDER BY o.updated_at DESC
    LIMIT 6
");
$recent->execute([$userId]);
$recent = $recent->fetchAll();

// Active (confirmed + shipped)
$activeOrders = $pdo->prepare("
    SELECT o.*,
           ub.name       AS buyer_name,
           uf.name       AS farmer_name,
           uf.location   AS farmer_location,
           p.name        AS product_name,
           p.category    AS product_category
    FROM orders o
    JOIN users ub            ON o.buyer_id  = ub.id
    JOIN users uf            ON o.farmer_id = uf.id
    LEFT JOIN order_items oi ON oi.order_id  = o.id
    LEFT JOIN products p     ON oi.product_id = p.id
 WHERE o.rider_id = ?
      AND o.status IN ('confirmed','shipped')
    GROUP BY o.id
    ORDER BY o.updated_at DESC
    LIMIT 4"
);
$activeOrders->execute([$userId]);
$activeOrders = $activeOrders->fetchAll();

$flash  = getFlash();
$emojis = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕','Livestock'=>'🐄','Seafood'=>'🐟','Others'=>'📦'];

$statusMeta = [
    'pending'   => ['label'=>'Pending',    'bg'=>'#FFF7ED', 'color'=>'#C2410C', 'icon'=>'clock'],
    'confirmed' => ['label'=>'Ready',      'bg'=>'#FFF7ED', 'color'=>'#F97316', 'icon'=>'box-open'],
    'shipped'   => ['label'=>'Out for Delivery', 'bg'=>'#EFF6FF', 'color'=>'#1D4ED8', 'icon'=>'truck-fast'],
    'completed' => ['label'=>'Delivered',  'bg'=>'#DCFCE7', 'color'=>'#16A34A', 'icon'=>'circle-check'],
    'cancelled' => ['label'=>'Cancelled',  'bg'=>'#FEE2E2', 'color'=>'#DC2626', 'icon'=>'circle-xmark'],
];

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

/* ── Layout ── */
.dash-page { background: var(--rider-bg); min-height: 100vh; padding-bottom: 3rem; }

/* ── Stat cards ── */
.rider-stat { background: white; border-radius: 16px; padding: 1.1rem 1.2rem; border: 1px solid #E2E8F0; box-shadow: 0 2px 8px rgba(0,0,0,.05); display: flex; align-items: center; gap: 14px; }
.rider-stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
.rider-stat-val  { font-size: 1.4rem; font-weight: 800; color: var(--text); line-height: 1; }
.rider-stat-lbl  { font-size: .7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; margin-top: 3px; }

/* ── Earnings card ── */
.earnings-card {
    background: linear-gradient(135deg, #0D3B13 0%, #1B5E20 55%, #2E7D32 100%);
    border-radius: 20px;
    padding: 1.5rem;
    color: white;
    position: relative;
    overflow: hidden;
    box-shadow: 0 8px 28px rgba(27,94,32,.35);
}
.earnings-card::before {
    content: '';
    position: absolute;
    top: -40px; right: -40px;
    width: 180px; height: 180px;
    border-radius: 50%;
    background: rgba(255,255,255,.05);
}
.earnings-card::after {
    content: '';
    position: absolute;
    bottom: -60px; left: 30%;
    width: 220px; height: 220px;
    border-radius: 50%;
    background: rgba(255,255,255,.04);
}

.earnings-main { font-size: 2.6rem; font-weight: 900; font-family: 'Syne', sans-serif; letter-spacing: -.02em; line-height: 1; }
.earnings-sub  { display: flex; gap: 1rem; margin-top: .75rem; flex-wrap: wrap; }
.earnings-chip { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.18); border-radius: 99px; padding: .35rem .85rem; font-size: .75rem; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }

/* ── Section headers ── */
.section-hd { font-family: 'Syne', sans-serif; font-size: 1rem; font-weight: 800; color: var(--text); margin-bottom: .85rem; display: flex; align-items: center; gap: 8px; }
.section-hd-badge { background: var(--rider-primary); color: white; border-radius: 99px; padding: 2px 10px; font-size: .68rem; font-weight: 800; }

/* ── Active order row ── */
.active-card { background: white; border-radius: 16px; border: 2px solid #FED7AA; box-shadow: 0 2px 10px rgba(249,115,22,.1); overflow: hidden; margin-bottom: .75rem; transition: box-shadow .2s, transform .15s; }
.active-card:hover { box-shadow: 0 8px 24px rgba(249,115,22,.18); transform: translateY(-2px); }

/* ── Recent order row ── */
.recent-row { display: flex; align-items: center; gap: 12px; padding: .75rem 1rem; border-bottom: 1px solid #F1F5F9; transition: background .15s; }
.recent-row:last-child { border-bottom: none; }
.recent-row:hover { background: #F8FAFC; }

/* ── Status pill ── */
.status-pill { display: inline-flex; align-items: center; gap: 4px; border-radius: 99px; padding: 3px 10px; font-size: .68rem; font-weight: 800; }

/* ── Quick action buttons ── */
.quick-btn {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 8px; padding: 1.1rem .5rem;
    border-radius: 16px; border: none; cursor: pointer;
    font-family: inherit; font-size: .75rem; font-weight: 800;
    text-decoration: none; transition: all .2s;
    flex: 1;
}
.quick-btn:hover { transform: translateY(-3px); }
.quick-btn .q-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }

/* ── Animations ── */
@keyframes pulse-ring  { 0%{box-shadow:0 0 0 0 rgba(249,115,22,.5)} 70%{box-shadow:0 0 0 10px rgba(249,115,22,0)} 100%{box-shadow:0 0 0 0 rgba(249,115,22,0)} }
@keyframes blink       { 0%,100%{opacity:1} 50%{opacity:.4} }
@keyframes fadeSlideUp { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }

.pulse     { animation: pulse-ring 1.8s infinite; }
.blink     { animation: blink 1.4s ease-in-out infinite; }
.fade-up   { animation: fadeSlideUp .45s ease both; }
.fade-up-1 { animation-delay: .08s; }
.fade-up-2 { animation-delay: .16s; }
.fade-up-3 { animation-delay: .24s; }
.fade-up-4 { animation-delay: .32s; }

/* ── Flash ── */
.flash-success { background:#DCFCE7;border:1px solid #BBF7D0;color:#16A34A;border-radius:12px;padding:.8rem 1.1rem;font-weight:700;font-size:.85rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:8px; }
.flash-error   { background:#FEE2E2;border:1px solid #FECACA;color:#DC2626;border-radius:12px;padding:.8rem 1.1rem;font-weight:700;font-size:.85rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:8px; }

/* ── Map placeholder ── */
.map-placeholder { background: linear-gradient(135deg,#EFF6FF,#DBEAFE); border-radius: 16px; border: 1px solid #BFDBFE; padding: 2rem; text-align: center; color: #1D4ED8; }

/* ── Step bar (reuse from pickup) ── */
.step-bar  { display:flex;align-items:center;gap:0; }
.step-item { display:flex;align-items:center;gap:4px; }
.step-dot  { width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:font-size:.6rem;font-weight:800;flex-shrink:0;font-size:.6rem; }
.step-dot-active   { background:#F97316;color:white;display:flex;align-items:center;justify-content:center; }
.step-dot-inactive { background:#E2E8F0;color:#94A3B8;display:flex;align-items:center;justify-content:center; }
.step-dot-done     { background:#16A34A;color:white;display:flex;align-items:center;justify-content:center; }
.step-line { flex:1;height:2px;background:#E2E8F0;margin:0 3px;min-width:16px; }
.step-label { font-size:.6rem;font-weight:700;color:var(--text-muted);white-space:nowrap; }
</style>

<div class="dash-page">

    <!-- ═══════════ HEADER ═══════════ -->
    <div style="background:linear-gradient(135deg,#0D3B13 0%,#1B5E20 45%,#2E7D32 100%);padding:1.5rem 0;position:relative;overflow:hidden;">
        <!-- Decorative orbs -->
        <div style="position:absolute;top:-60px;right:-60px;width:250px;height:250px;border-radius:50%;background:rgba(255,255,255,.03);pointer-events:none;"></div>
        <div style="position:absolute;bottom:-80px;left:20%;width:300px;height:300px;border-radius:50%;background:rgba(255,255,255,.025);pointer-events:none;"></div>

        <div class="container" style="position:relative;">
            <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">

                <!-- Left: Avatar + Greeting -->
                <div style="display:flex;align-items:center;gap:14px;">
                    <?php if (!empty($rider['profile_image'])): ?>
                       <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($rider['profile_image']) ?>?v=<?= time() ?>"
     style="width:52px;height:52px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.3);flex-shrink:0;"
     onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                    <?php else: ?>
                        <div style="width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:800;color:white;border:3px solid rgba(255,255,255,.25);flex-shrink:0;">
                            <?= strtoupper(substr($rider['name'],0,1)) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <div style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:white;line-height:1.1;">
                            <?= $greeting[1] ?> <?= $greeting[0] ?>, <span style="color:#86EFAC;"><?= sanitize(explode(' ',$rider['name'])[0]) ?></span>
                        </div>
                        <div style="font-size:.82rem;color:rgba(255,255,255,.65);margin-top:.2rem;">
                            🛵 Delivery Rider · <?= sanitize($rider['location'] ?? 'Mindanao') ?>
                        </div>
                        <?php if ($pickupReady > 0): ?>
                        <div style="margin-top:.5rem;">
                            <span style="background:rgba(249,115,22,.25);border:1px solid rgba(249,115,22,.4);border-radius:99px;padding:.3rem .85rem;font-size:.72rem;font-weight:600;color:white;display:inline-flex;align-items:center;gap:5px;" class="blink">
                                🔥 <?= $pickupReady ?> pickup<?= $pickupReady > 1 ? 's' : '' ?> waiting
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right: Nav -->
                <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;">
                    <a href="dashboard.php" style="display:flex;align-items:center;gap:6px;padding:.5rem 1rem;color:white;font-size:.8rem;font-weight:700;text-decoration:none;border-radius:10px;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.3);">
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

        <?php if ($flash): ?>
        <div class="<?= $flash['type'] === 'success' ? 'flash-success' : 'flash-error' ?>">
            <i class="fa-solid fa-<?= $flash['type'] === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
            <?= sanitize($flash['message']) ?>
        </div>
        <?php endif; ?>

        <!-- ── Row 1: Earnings card + Quick Actions ── -->
        <div class="row g-3 mb-3">

            <!-- Earnings -->
            <div class="col-12 col-md-7 fade-up">
                <div class="earnings-card">
                    <div style="position:relative;z-index:1;">
                        <div style="font-size:.72rem;font-weight:700;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.4rem;">Total Lifetime Earnings</div>
                        <div class="earnings-main">₱<?= number_format($totalEarnings, 2) ?></div>
                        <div class="earnings-sub">
                            <div class="earnings-chip">
                                <i class="fa-solid fa-calendar-week"></i> This Week: ₱<?= number_format($weekEarnings, 2) ?>
                            </div>
                            <div class="earnings-chip">
                                <i class="fa-solid fa-calendar"></i> This Month: ₱<?= number_format($monthEarnings, 2) ?>
                            </div>
                        </div>
                        <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid rgba(255,255,255,.12);display:flex;gap:1.5rem;flex-wrap:wrap;">
                            <div>
                                <div style="font-size:1.2rem;font-weight:900;color:#86EFAC;"><?= $delivered ?></div>
                                <div style="font-size:.68rem;color:rgba(255,255,255,.5);text-transform:uppercase;font-weight:700;letter-spacing:.04em;">Completed</div>
                            </div>
                            <div>
                                <div style="font-size:1.2rem;font-weight:900;color:#FCD34D;"><?= $inTransit ?></div>
                                <div style="font-size:.68rem;color:rgba(255,255,255,.5);text-transform:uppercase;font-weight:700;letter-spacing:.04em;">In Transit</div>
                            </div>
                            <div>
                                <div style="font-size:1.2rem;font-weight:900;color:#FCA5A5;"><?= $pickupReady ?></div>
                                <div style="font-size:.68rem;color:rgba(255,255,255,.5);text-transform:uppercase;font-weight:700;letter-spacing:.04em;">Awaiting Pickup</div>
                            </div>
                            <div>
                                <div style="font-size:1.2rem;font-weight:900;color:white;"><?= $totalOrders ?></div>
                                <div style="font-size:.68rem;color:rgba(255,255,255,.5);text-transform:uppercase;font-weight:700;letter-spacing:.04em;">All Time</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="col-12 col-md-5 fade-up fade-up-1">
                <div style="background:white;border-radius:20px;padding:1.25rem;border:1px solid #E2E8F0;box-shadow:0 2px 8px rgba(0,0,0,.05);height:100%;">
                    <div class="section-hd" style="margin-bottom:1rem;">⚡ Quick Actions</div>
                    <div style="display:flex;gap:.6rem;">
                        <a href="pickup.php" class="quick-btn <?= $pickupReady > 0 ? 'pulse' : '' ?>" style="background:#FFF7ED;color:#C2410C;border:2px solid <?= $pickupReady > 0 ? '#FED7AA' : '#FEF0E6' ?>;">
                            <div class="q-icon" style="background:#FED7AA;"><i class="fa-solid fa-box-open" style="color:#F97316;"></i></div>
                            <span>Pickup<br><span style="color:#F97316;font-size:.8rem;"><?= $pickupReady ?> ready</span></span>
                        </a>
                        <a href="orders.php?filter=shipped" class="quick-btn" style="background:#EFF6FF;color:#1D4ED8;border:2px solid #BFDBFE;">
                            <div class="q-icon" style="background:#DBEAFE;"><i class="fa-solid fa-truck-fast" style="color:#3B82F6;"></i></div>
                            <span>In Transit<br><span style="color:#3B82F6;font-size:.8rem;"><?= $inTransit ?> active</span></span>
                        </a>
                        <a href="orders.php" class="quick-btn" style="background:#F0FDF4;color:#166534;border:2px solid #BBF7D0;">
                            <div class="q-icon" style="background:#DCFCE7;"><i class="fa-solid fa-list" style="color:#16A34A;"></i></div>
                            <span>All Orders<br><span style="color:#16A34A;font-size:.8rem;"><?= $totalOrders ?> total</span></span>
                        </a>
                        <a href="messages.php" class="quick-btn" style="background:#F5F3FF;color:#6D28D9;border:2px solid #DDD6FE;">
                            <div class="q-icon" style="background:#EDE9FE;"><i class="fa-solid fa-comments" style="color:#7C3AED;"></i></div>
                            <span>Messages<br><span style="color:#7C3AED;font-size:.8rem;">Chat</span></span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Row 2: Stat mini-cards ── -->
        <div class="row g-3 mb-4 fade-up fade-up-2">
            <div class="col-6 col-md-3">
                <div class="rider-stat" style="border-color:#FED7AA;">
                    <div class="rider-stat-icon" style="background:#FFF7ED;">
                        <i class="fa-solid fa-box-open" style="color:#F97316;"></i>
                    </div>
                    <div>
                        <div class="rider-stat-val" style="color:#F97316;"><?= $pickupReady ?></div>
                        <div class="rider-stat-lbl">Pickup Ready</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="rider-stat" style="border-color:#BFDBFE;">
                    <div class="rider-stat-icon" style="background:#EFF6FF;">
                        <i class="fa-solid fa-truck-fast" style="color:#3B82F6;"></i>
                    </div>
                    <div>
                        <div class="rider-stat-val" style="color:#3B82F6;"><?= $inTransit ?></div>
                        <div class="rider-stat-lbl">In Transit</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="rider-stat" style="border-color:#BBF7D0;">
                    <div class="rider-stat-icon" style="background:#DCFCE7;">
                        <i class="fa-solid fa-circle-check" style="color:#16A34A;"></i>
                    </div>
                    <div>
                        <div class="rider-stat-val" style="color:#16A34A;"><?= $delivered ?></div>
                        <div class="rider-stat-lbl">Delivered</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="rider-stat" style="border-color:#C7D2FE;">
                    <div class="rider-stat-icon" style="background:#EEF2FF;">
                        <i class="fa-solid fa-receipt" style="color:#4F46E5;"></i>
                    </div>
                    <div>
                        <div class="rider-stat-val" style="color:#4F46E5;"><?= $totalOrders ?></div>
                        <div class="rider-stat-lbl">Total Orders</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Row 3: Active Orders + Recent Orders ── -->
        <div class="row g-3">

            <!-- Active Orders -->
            <div class="col-12 col-lg-6 fade-up fade-up-3">
                <div style="background:white;border-radius:20px;border:1px solid #E2E8F0;box-shadow:0 2px 8px rgba(0,0,0,.05);overflow:hidden;">
                    <div style="padding:1rem 1.25rem;border-bottom:1px solid #F1F5F9;display:flex;justify-content:space-between;align-items:center;">
                        <div class="section-hd" style="margin:0;">
                            🚦 Active Orders
                            <?php if (count($activeOrders)): ?>
                            <span class="section-hd-badge"><?= count($activeOrders) ?></span>
                            <?php endif; ?>
                        </div>
                        <a href="orders.php" style="font-size:.75rem;font-weight:700;color:var(--rider-primary);text-decoration:none;">View all →</a>
                    </div>

                    <?php if (empty($activeOrders)): ?>
                    <div style="padding:2.5rem;text-align:center;color:var(--text-muted);">
                        <div style="font-size:2.5rem;margin-bottom:.5rem;opacity:.5;">✅</div>
                        <div style="font-weight:700;font-size:.9rem;">No active orders right now.</div>
                        <div style="font-size:.8rem;margin-top:.25rem;">Sit tight — new pickups will appear here.</div>
                    </div>
                    <?php else: ?>
                    <div style="padding:1rem;">
                        <?php foreach ($activeOrders as $o):
                            $isPickup = $o['status'] === 'confirmed';
                            $meta = $statusMeta[$o['status']] ?? $statusMeta['pending'];
                        ?>
                        <div class="active-card" style="border-color:<?= $isPickup ? '#FED7AA' : '#BFDBFE' ?>;">
                            <div style="padding:.8rem 1rem;background:<?= $isPickup ? '#FFF7ED' : '#EFF6FF' ?>;border-bottom:1px solid <?= $isPickup ? '#FEF0E6' : '#DBEAFE' ?>;display:flex;justify-content:space-between;align-items:center;">
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <span style="font-weight:800;color:<?= $meta['color'] ?>;font-size:.9rem;">Order #<?= $o['id'] ?></span>
                                    <span class="status-pill" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>;">
                                        <i class="fa-solid fa-<?= $meta['icon'] ?>" style="font-size:.55rem;"></i>
                                        <?= $meta['label'] ?>
                                    </span>
                                    <?php if ($isPickup): ?>
                                    <span style="background:#F97316;color:white;border-radius:99px;padding:2px 8px;font-size:.62rem;font-weight:800;" class="blink">🔥 Now</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-weight:800;color:var(--rider-primary);font-size:.9rem;">₱<?= number_format($o['delivery_fee'],2) ?></div>
                            </div>
                            <div style="padding:.85rem 1rem;">
                                <!-- Progress steps -->
                                <div class="step-bar" style="margin-bottom:.8rem;">
                                    <div class="step-item">
                                        <div class="step-dot step-dot-done"><i class="fa-solid fa-check" style="font-size:.5rem;"></i></div>
                                        <div class="step-label" style="color:#16A34A;">Placed</div>
                                    </div>
                                    <div class="step-line" style="<?= $isPickup || $o['status']==='shipped' || $o['status']==='completed' ? 'background:linear-gradient(to right,#16A34A,#F97316)' : '' ?>;"></div>
                                    <div class="step-item">
                                        <div class="step-dot <?= $isPickup ? 'step-dot-active' : 'step-dot-done' ?>">
                                            <?= $isPickup ? '2' : '<i class="fa-solid fa-check" style="font-size:.5rem;"></i>' ?>
                                        </div>
                                        <div class="step-label" style="color:<?= $isPickup ? '#F97316' : '#16A34A' ?>;<?= $isPickup ? 'font-weight:800;' : '' ?>">Pickup</div>
                                    </div>
                                    <div class="step-line" style="<?= $o['status']==='shipped' || $o['status']==='completed' ? 'background:linear-gradient(to right,#16A34A,#3B82F6)' : '' ?>;"></div>
                                    <div class="step-item">
                                        <div class="step-dot <?= $o['status']==='shipped' ? 'step-dot-active' : ($o['status']==='completed' ? 'step-dot-done' : 'step-dot-inactive') ?>">
                                            <?= $o['status']==='completed' ? '<i class="fa-solid fa-check" style="font-size:.5rem;"></i>' : '3' ?>
                                        </div>
                                        <div class="step-label" style="color:<?= $o['status']==='shipped' ? '#3B82F6' : 'inherit' ?>;<?= $o['status']==='shipped' ? 'font-weight:800;' : '' ?>">Transit</div>
                                    </div>
                                    <div class="step-line"></div>
                                    <div class="step-item">
                                        <div class="step-dot step-dot-inactive">4</div>
                                        <div class="step-label">Done</div>
                                    </div>
                                </div>
                                <!-- Route mini -->
                                <div style="display:flex;gap:8px;align-items:center;font-size:.78rem;">
                                    <div style="background:#EFF6FF;border-radius:8px;padding:.4rem .6rem;flex:1;border:1px solid #DBEAFE;">
                                        <div style="font-size:.62rem;font-weight:800;color:#1D4ED8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:1px;">From</div>
                                        <div style="font-weight:700;color:var(--text);"><?= sanitize($o['farmer_name']) ?></div>
                                        <div style="font-size:.68rem;color:var(--text-muted);">📍 <?= sanitize($o['farmer_location']) ?></div>
                                    </div>
                                    <div style="font-size:1rem;color:#94A3B8;flex-shrink:0;">→</div>
                                    <div style="background:#F0FDF4;border-radius:8px;padding:.4rem .6rem;flex:1;border:1px solid #BBF7D0;">
                                        <div style="font-size:.62rem;font-weight:800;color:#16A34A;text-transform:uppercase;letter-spacing:.04em;margin-bottom:1px;">To</div>
                                        <div style="font-weight:700;color:var(--text);"><?= sanitize($o['buyer_name']) ?></div>
                                        <div style="font-size:.68rem;color:var(--text-muted);">📍 <?= sanitize($o['delivery_address'] ?: '') ?></div>
                                    </div>
                                </div>
                                <!-- Action -->
                                <div style="margin-top:.75rem;">
                                    <?php if ($isPickup): ?>
                                    <a href="pickup.php" style="display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#F97316,#EA580C);color:white;border-radius:10px;padding:.5rem 1rem;font-weight:800;font-size:.78rem;text-decoration:none;box-shadow:0 3px 10px rgba(249,115,22,.35);">
                                        <i class="fa-solid fa-box-open"></i> Go to Pickup
                                    </a>
                                    <?php else: ?>
                                    <a href="orders.php?id=<?= $o['id'] ?>" style="display:inline-flex;align-items:center;gap:6px;background:var(--pale-green);color:var(--rider-primary);border-radius:10px;padding:.5rem 1rem;font-weight:700;font-size:.78rem;text-decoration:none;">
                                        <i class="fa-solid fa-eye"></i> View Order
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Orders -->
            <div class="col-12 col-lg-6 fade-up fade-up-4">
                <div style="background:white;border-radius:20px;border:1px solid #E2E8F0;box-shadow:0 2px 8px rgba(0,0,0,.05);overflow:hidden;">
                    <div style="padding:1rem 1.25rem;border-bottom:1px solid #F1F5F9;display:flex;justify-content:space-between;align-items:center;">
                        <div class="section-hd" style="margin:0;">
                            🕐 Recent Orders
                        </div>
                        <a href="orders.php" style="font-size:.75rem;font-weight:700;color:var(--rider-primary);text-decoration:none;">View all →</a>
                    </div>

                    <?php if (empty($recent)): ?>
                    <div style="padding:2.5rem;text-align:center;color:var(--text-muted);">
                        <div style="font-size:2.5rem;margin-bottom:.5rem;opacity:.5;">📋</div>
                        <div style="font-weight:700;font-size:.9rem;">No order history yet.</div>
                    </div>
                    <?php else: ?>
                    <div>
                        <?php foreach ($recent as $o):
                            $meta = $statusMeta[$o['status']] ?? $statusMeta['pending'];
                        ?>
                        <div class="recent-row">
                            <!-- Product emoji -->
<?php
$pImg = $o['product_image'] ?? null;
$pImgUrl = null;
if ($pImg) {
    $pImg = ltrim($pImg, '/');
    if (!str_contains($pImg, '/')) {
        $pImg = 'assets/images/products/' . $pImg;
    }
    $pImgUrl = BASE_URL . '/' . htmlspecialchars($pImg) . '?v=' . time();
}
?>
<div style="width:40px;height:40px;border-radius:10px;background:#F8FAFC;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;border:1px solid #E2E8F0;overflow:hidden;">
    <?php if ($pImgUrl): ?>
        <img src="<?= $pImgUrl ?>" style="width:100%;height:100%;object-fit:cover;"
             onerror="this.style.display='none';this.parentElement.innerHTML='<?= $emojis[$o['product_category']] ?? '🌾' ?>';">
    <?php else: ?>
        <?= $emojis[$o['product_category']] ?? '🌾' ?>
    <?php endif; ?>
</div>
                            <!-- Info -->
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:800;font-size:.85rem;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    Order #<?= $o['id'] ?> · <?= sanitize($o['product_name'] ?? '—') ?>
                                </div>
                                <div style="font-size:.72rem;color:var(--text-muted);margin-top:1px;">
                                    📍 <?= sanitize($o['buyer_name']) ?> · <?= date('M j', strtotime($o['created_at'])) ?>
                                </div>
                            </div>
                            <!-- Status + Fee -->
                            <div style="text-align:right;flex-shrink:0;">
                                <div style="font-weight:800;color:var(--rider-primary);font-size:.88rem;">₱<?= number_format($o['delivery_fee'],2) ?></div>
                                <span class="status-pill" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>;margin-top:3px;display:inline-flex;">
                                    <i class="fa-solid fa-<?= $meta['icon'] ?>" style="font-size:.55rem;"></i>
                                    <?= $meta['label'] ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Rider profile card at bottom -->
                    <div style="margin:0 1rem 1rem;padding:1rem;background:linear-gradient(135deg,#F0F7F0,#E8F5E9);border-radius:14px;border:1px solid #C8E6C9;display:flex;align-items:center;gap:12px;">
                        <?php if (!empty($rider['profile_image'])): ?>
<img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($rider['profile_image']) ?>?v=<?= time() ?>"
     style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid #A5D6A7;flex-shrink:0;"
     onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <?php else: ?>
                            <div style="width:44px;height:44px;border-radius:50%;background:var(--rider-primary);display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:800;color:white;flex-shrink:0;">
                                <?= strtoupper(substr($rider['name'],0,1)) ?>
                            </div>
                        <?php endif; ?>
                        <div style="flex:1;">
                            <div style="font-weight:800;font-size:.88rem;color:var(--rider-primary);"><?= sanitize($rider['name']) ?></div>
                            <div style="font-size:.72rem;color:#558B2F;">🛵 Delivery Rider · <?= sanitize($rider['location'] ?? 'Mindanao') ?></div>
                            <?php if ($rider['phone']): ?>
                            <div style="font-size:.7rem;color:var(--text-muted);margin-top:1px;"><i class="fa-solid fa-phone" style="font-size:.6rem;"></i> <?= sanitize($rider['phone']) ?></div>
                            <?php endif; ?>
                        </div>
                        <a href="profile.php" style="background:var(--rider-primary);color:white;border-radius:10px;padding:.45rem .85rem;font-size:.75rem;font-weight:700;text-decoration:none;white-space:nowrap;">
                            Edit Profile
                        </a>
                    </div>
                </div>
            </div>
        </div><!-- /row -->

    </div><!-- /container -->
</div><!-- /dash-page -->

<script>
if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(function(pos) {
        fetch('<?= BASE_URL ?>/rider/update_location.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                lat: pos.coords.latitude,
                lng: pos.coords.longitude
            })
        });
    }, null, { enableHighAccuracy: true });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>