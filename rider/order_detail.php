<?php
$page_title = 'Order Details';
$hide_navbar = true;
require_once __DIR__ . '/../includes/header.php';
requireRole('rider');

require_once __DIR__ . '/../config/delivery.php';

$pdo     = getDBConnection();
$userId  = $_SESSION['user_id'];
$orderId = intval($_GET['id'] ?? 0);

if (!$orderId) { header('Location: orders.php'); exit(); }

// Fetch order — only if assigned to this rider
$order = $pdo->prepare("
    SELECT o.*,
           ub.name           AS buyer_name,
           ub.phone          AS buyer_phone,
           ub.location       AS buyer_location,
           ub.profile_image  AS buyer_image,
           uf.name           AS farmer_name,
           uf.phone          AS farmer_phone,
           uf.location       AS farmer_location,
           uf.profile_image  AS farmer_image,
           uf.latitude       AS farmer_lat,
           uf.longitude      AS farmer_lng,
           ub.latitude       AS buyer_lat,
           ub.longitude      AS buyer_lng
    FROM orders o
    JOIN users ub ON o.buyer_id  = ub.id
    JOIN users uf ON o.farmer_id = uf.id
    WHERE o.id = ? AND o.rider_id = ?
");
$order->execute([$orderId, $userId]);
$o = $order->fetch();

if (!$o) { header('Location: orders.php?error=not_found'); exit(); }

// Order items
$items = $pdo->prepare("
    SELECT oi.*, p.name AS product_name, p.image AS product_image, p.category
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$items->execute([$orderId]);
$items = $items->fetchAll();

// Handle mark as delivered
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_order'])) {
    $stmt = $pdo->prepare("UPDATE orders SET status='completed' WHERE id=? AND rider_id=? AND status='shipped'");
    $stmt->execute([$orderId, $userId]);
    if ($stmt->rowCount() > 0) {
        setFlash('success', "Order #$orderId marked as delivered! Great job! 🎉");
    }
    header("Location: order_detail.php?id=$orderId"); exit();
}

// Handle confirm pickup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pickup_order'])) {
    $stmt = $pdo->prepare("UPDATE orders SET status='shipped' WHERE id=? AND rider_id=? AND status='confirmed'");
    $stmt->execute([$orderId, $userId]);
    if ($stmt->rowCount() > 0) {
        setFlash('success', "Order #$orderId picked up! It's now in transit. 🛵");
        header("Location: order_detail.php?id=$orderId"); exit();
    }
}

$flash = getFlash();

$statusConfig = [
    'confirmed'  => ['label'=>'Pickup Ready',      'color'=>'#C2410C', 'bg'=>'#FFF7ED', 'border'=>'#FED7AA', 'icon'=>'fa-box-open'],
    'shipped'    => ['label'=>'Out for Delivery',   'color'=>'#0E7490', 'bg'=>'#ECFEFF', 'border'=>'#A5F3FC', 'icon'=>'fa-truck-fast'],
    'completed'  => ['label'=>'Delivered',          'color'=>'#15803D', 'bg'=>'#F0FDF4', 'border'=>'#BBF7D0', 'icon'=>'fa-circle-check'],
    'cancelled'  => ['label'=>'Cancelled',          'color'=>'#B91C1C', 'bg'=>'#FEF2F2', 'border'=>'#FECACA', 'icon'=>'fa-ban'],
    'pending'    => ['label'=>'Pending',            'color'=>'#B45309', 'bg'=>'#FFFBEB', 'border'=>'#FDE68A', 'icon'=>'fa-clock'],
    'processing' => ['label'=>'Processing',         'color'=>'#6D28D9', 'bg'=>'#F5F3FF', 'border'=>'#DDD6FE', 'icon'=>'fa-gear'],
];
$cfg      = $statusConfig[$o['status']] ?? $statusConfig['pending'];
$subtotal = array_sum(array_map(fn($i) => floatval($i['subtotal'] ?? 0), $items));
$delivFee = floatval($o['delivery_fee'] ?? 0);
$distKm   = $o['distance_km'] ? floatval($o['distance_km']) : null;
$hasMap   = !empty($o['farmer_lat']) && !empty($o['buyer_lat']);

$emojis = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕','Livestock'=>'🐄','Seafood'=>'🐟','Others'=>'📦'];

$steps = ['confirmed','shipped','completed'];
$currentIdx = array_search($o['status'], $steps);
?>
<!DOCTYPE html>
<html>
<head>
<style>
    .rider-pulse-wrap{position:relative;width:50px;height:50px;display:flex;align-items:center;justify-content:center;}
.rider-pulse-ring{position:absolute;width:50px;height:50px;border-radius:50%;background:rgba(249,115,22,.25);animation:riderHeartbeat 1.4s ease-out infinite;}
.rider-pulse-ring::after{content:'';position:absolute;inset:6px;border-radius:50%;background:rgba(249,115,22,.2);animation:riderHeartbeat 1.4s ease-out infinite .2s;}
.rider-pulse-dot{position:relative;z-index:2;width:36px;height:36px;border-radius:50%;background:#F97316;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 3px 12px rgba(249,115,22,.6);font-size:16px;}
@keyframes riderHeartbeat{0%{transform:scale(1);opacity:.8;}50%{transform:scale(1.5);opacity:.3;}100%{transform:scale(1.9);opacity:0;}}
:root{--rider-primary:#1B5E20;--rider-bg:#F0F7F0;}
.detail-page{background:var(--rider-bg);min-height:100vh;padding-bottom:3rem;}
.dcard{background:white;border:1px solid #E2E8F0;border-radius:18px;box-shadow:0 2px 8px rgba(0,0,0,.05);overflow:hidden;margin-bottom:1.25rem;}
.dcard-head{padding:.85rem 1.25rem;border-bottom:1px solid #F1F5F9;display:flex;align-items:center;gap:.5rem;}
.dcard-head h6{margin:0;font-weight:800;font-size:.92rem;color:#1E293B;}
.dcard-body{padding:1.1rem 1.25rem;}
.info-row{display:flex;justify-content:space-between;align-items:center;padding:.45rem 0;border-bottom:1px solid #F8FAFC;}
.info-row:last-child{border-bottom:none;}
.info-label{font-size:.78rem;color:#64748B;font-weight:600;}
.info-val{font-size:.84rem;font-weight:700;color:#1E293B;}
.party-block{display:flex;align-items:center;gap:.85rem;padding:.85rem 1.25rem;}
.party-avatar{width:46px;height:46px;border-radius:50%;background:#DCFCE7;color:#16A34A;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.1rem;flex-shrink:0;}
.party-name{font-weight:800;font-size:.9rem;color:#1E293B;}
.party-meta{font-size:.75rem;color:#64748B;margin-top:2px;}
.item-row{display:flex;align-items:center;gap:.85rem;padding:.65rem 0;border-bottom:1px solid #F8FAFC;}
.item-row:last-child{border-bottom:none;}
.item-img{width:46px;height:46px;border-radius:10px;object-fit:cover;background:#F1F5F9;flex-shrink:0;}
.step-bar{display:flex;align-items:center;margin-bottom:1.25rem;}
.step-item{display:flex;flex-direction:column;align-items:center;gap:4px;}
.step-dot{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:800;flex-shrink:0;}
.step-line{flex:1;height:3px;margin:0 6px;border-radius:99px;}
.step-lbl{font-size:.62rem;font-weight:700;white-space:nowrap;}
.status-pill{display:inline-flex;align-items:center;gap:5px;border-radius:99px;padding:.3rem .85rem;font-size:.78rem;font-weight:800;}
.total-row{display:flex;justify-content:space-between;padding:.35rem 0;font-size:.84rem;}
.total-row.grand{font-weight:800;font-size:.98rem;color:var(--rider-primary);border-top:2px solid #E2E8F0;padding-top:.6rem;margin-top:.25rem;}
.btn-action{display:inline-flex;align-items:center;gap:7px;border-radius:12px;padding:.7rem 1.4rem;font-weight:800;font-size:.85rem;cursor:pointer;border:none;transition:all .2s;font-family:inherit;text-decoration:none;}
.btn-pickup{background:linear-gradient(135deg,#F97316,#EA580C);color:white;box-shadow:0 4px 14px rgba(249,115,22,.3);}
.btn-deliver{background:linear-gradient(135deg,#16A34A,#15803D);color:white;box-shadow:0 4px 14px rgba(22,163,74,.3);}
.btn-back{background:#F1F5F9;color:#475569;}
.btn-msg{background:#F0FDF4;color:var(--rider-primary);border:1.5px solid #BBF7D0;}
@keyframes pulse-ring{0%{box-shadow:0 0 0 0 rgba(249,115,22,.5)}70%{box-shadow:0 0 0 10px rgba(249,115,22,0)}100%{box-shadow:0 0 0 0 rgba(249,115,22,0)}}
.pulse{animation:pulse-ring 1.8s infinite;}
.flash-success{background:#DCFCE7;border:1px solid #BBF7D0;color:#16A34A;border-radius:12px;padding:.8rem 1.1rem;font-weight:700;font-size:.85rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:8px;}
.flash-error{background:#FEE2E2;border:1px solid #FECACA;color:#DC2626;border-radius:12px;padding:.8rem 1.1rem;font-weight:700;font-size:.85rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:8px;}
</style>
</head>
<body>
<div class="detail-page">

    <!-- Header -->
    <div style="background:linear-gradient(135deg,#0D3B13 0%,#1B5E20 45%,#2E7D32 100%);padding:1.25rem 0;">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                <div>
                    <div style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:white;">
                        Order #<?= $o['id'] ?>
                    </div>
                    <div style="font-size:.8rem;color:rgba(255,255,255,.6);margin-top:2px;">
                        <a href="orders.php" style="color:rgba(255,255,255,.7);text-decoration:none;">← All Orders</a>
                        &rsaquo; Order #<?= $o['id'] ?>
                    </div>
                </div>
                <span class="status-pill" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;border:1.5px solid <?= $cfg['border'] ?>;">
                    <i class="fa-solid <?= $cfg['icon'] ?>"></i> <?= $cfg['label'] ?>
                </span>
            </div>
        </div>
    </div>

    <div class="container" style="padding-top:1.5rem;">

        <?php if ($flash): ?>
        <div class="<?= $flash['type']==='success' ? 'flash-success' : 'flash-error' ?>">
            <i class="fa-solid fa-<?= $flash['type']==='success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
            <?= sanitize($flash['message']) ?>
        </div>
        <?php endif; ?>

        <div class="row g-3">

            <!-- LEFT COLUMN -->
            <div class="col-12 col-lg-7">

                <!-- Progress Steps -->
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-route" style="color:var(--rider-primary);"></i>
                        <h6>Delivery Progress</h6>
                    </div>
                    <div class="dcard-body">
                        <div class="step-bar">
                            <?php
                            $allSteps = [
                                ['key'=>'placed',    'label'=>'Placed',    'icon'=>'fa-file-circle-check'],
                                ['key'=>'confirmed', 'label'=>'Pickup',    'icon'=>'fa-box-open'],
                                ['key'=>'shipped',   'label'=>'In Transit','icon'=>'fa-truck-fast'],
                                ['key'=>'completed', 'label'=>'Delivered', 'icon'=>'fa-circle-check'],
                            ];
                            $statusOrder = ['pending'=>0,'confirmed'=>1,'shipped'=>2,'completed'=>3];
                            $currentLevel = $statusOrder[$o['status']] ?? 0;
                            foreach ($allSteps as $idx => $step):
                                $stepLevel = $idx; // placed=0, confirmed=1, shipped=2, completed=3
                                $isDone   = $currentLevel > $stepLevel;
                                $isActive = $currentLevel === $stepLevel;
                                $isLast   = $idx === count($allSteps) - 1;
                            ?>
                            <div class="step-item">
                                <div class="step-dot" style="background:<?= $isDone ? '#16A34A' : ($isActive ? '#F97316' : '#E2E8F0') ?>;color:<?= ($isDone||$isActive) ? 'white' : '#94A3B8' ?>;">
                                    <?php if ($isDone): ?>
                                        <i class="fa-solid fa-check" style="font-size:.6rem;"></i>
                                    <?php else: ?>
                                        <i class="fa-solid <?= $step['icon'] ?>" style="font-size:.6rem;"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="step-lbl" style="color:<?= $isDone ? '#16A34A' : ($isActive ? '#F97316' : '#94A3B8') ?>;font-weight:<?= $isActive ? '800' : '700' ?>;">
                                    <?= $step['label'] ?>
                                </div>
                            </div>
                            <?php if (!$isLast): ?>
                            <div class="step-line" style="background:<?= $isDone ? '#16A34A' : '#E2E8F0' ?>;"></div>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <!-- Action Buttons -->
                        <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
                            <?php if ($o['status'] === 'confirmed'): ?>
                            <form method="POST" style="display:inline;">
                                <button type="submit" name="pickup_order"
                                        class="btn-action btn-pickup pulse"
                                        onclick="return confirm('Confirm pickup for Order #<?= $o['id'] ?>?')">
                                    <i class="fa-solid fa-box-open"></i> Confirm Pickup
                                </button>
                            </form>
                            <?php elseif ($o['status'] === 'shipped'): ?>
                            <form method="POST" style="display:inline;">
                                <button type="submit" name="complete_order"
                                        class="btn-action btn-deliver"
                                        onclick="return confirm('Mark Order #<?= $o['id'] ?> as delivered?')">
                                    <i class="fa-solid fa-circle-check"></i> Mark Delivered
                                </button>
                            </form>
                            <?php elseif ($o['status'] === 'completed'): ?>
                            <span style="display:inline-flex;align-items:center;gap:6px;background:#DCFCE7;border:1px solid #BBF7D0;border-radius:12px;padding:.7rem 1.25rem;font-size:.85rem;font-weight:800;color:#15803D;">
                                <i class="fa-solid fa-circle-check"></i> Delivered ✔
                            </span>
                            <?php endif; ?>

                            <a href="orders.php" class="btn-action btn-back">
                                <i class="fa-solid fa-arrow-left"></i> Back
                            </a>
                            <a href="messages.php?to=<?= $o['farmer_id'] ?>" class="btn-action btn-msg">
                                <i class="fa-solid fa-tractor"></i> Message Farmer
                            </a>
                            <a href="messages.php?to=<?= $o['buyer_id'] ?>" class="btn-action btn-msg">
                                <i class="fa-solid fa-comments"></i> Message Buyer
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Route -->
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-route" style="color:#3B82F6;"></i>
                        <h6>Pickup & Delivery Route</h6>
                        <?php if ($distKm): ?>
                        <span style="margin-left:auto;background:#DBEAFE;color:#1D4ED8;border-radius:99px;padding:2px 10px;font-size:.7rem;font-weight:800;">
                            📏 <?= number_format($distKm,1) ?> km
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="dcard-body" style="padding-top:.75rem;">
                        <div style="display:flex;gap:10px;align-items:stretch;">

                            <!-- Pickup -->
                            <div style="flex:1;background:#EFF6FF;border-radius:14px;padding:.9rem 1rem;border:1.5px solid #BFDBFE;">
                                <div style="font-size:.65rem;font-weight:800;color:#1D4ED8;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.4rem;">
                                    <i class="fa-solid fa-warehouse me-1"></i>Pickup From
                                </div>
                                <div style="font-weight:800;font-size:.88rem;color:#1E293B;"><?= sanitize($o['farmer_name']) ?></div>
                                <div style="font-size:.76rem;color:#64748B;margin-top:2px;">📍 <?= sanitize($o['farmer_location']) ?></div>
                                <?php if ($o['farmer_phone']): ?>
                                <a href="tel:<?= sanitize($o['farmer_phone']) ?>" style="display:inline-flex;align-items:center;gap:4px;font-size:.73rem;font-weight:700;color:var(--rider-primary);text-decoration:none;margin-top:4px;">
                                    <i class="fa-solid fa-phone"></i> <?= sanitize($o['farmer_phone']) ?>
                                </a>
                                <?php endif; ?>
                            </div>

                            <!-- Arrow -->
                            <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;flex-shrink:0;">
                                <div style="width:6px;height:6px;border-radius:50%;background:#F97316;"></div>
                                <div style="width:2px;height:28px;background:repeating-linear-gradient(to bottom,#F97316 0,#F97316 4px,transparent 4px,transparent 8px);"></div>
                                <i class="fa-solid fa-arrow-down" style="color:#F97316;font-size:.8rem;"></i>
                            </div>

                            <!-- Delivery -->
                            <div style="flex:1;background:#F0FDF4;border-radius:14px;padding:.9rem 1rem;border:1.5px solid #BBF7D0;">
                                <div style="font-size:.65rem;font-weight:800;color:#16A34A;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.4rem;">
                                    <i class="fa-solid fa-location-dot me-1"></i>Deliver To
                                </div>
                                <div style="font-weight:800;font-size:.88rem;color:#1E293B;"><?= sanitize($o['buyer_name']) ?></div>
                                <div style="font-size:.76rem;color:#64748B;margin-top:2px;">📍 <?= sanitize($o['delivery_address'] ?: $o['buyer_location']) ?></div>
                                <?php if ($o['buyer_phone']): ?>
                                <a href="tel:<?= sanitize($o['buyer_phone']) ?>" style="display:inline-flex;align-items:center;gap:4px;font-size:.73rem;font-weight:700;color:var(--rider-primary);text-decoration:none;margin-top:4px;">
                                    <i class="fa-solid fa-phone"></i> <?= sanitize($o['buyer_phone']) ?>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Map — always show, rider location always tracked -->
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-map" style="color:#3B82F6;"></i>
                        <h6>Live Delivery Map</h6>
                        <!-- Navigation button — destination changes by status -->
                        <?php
                        $navStatus = $o['status'];
                        if ($navStatus === 'confirmed' && !empty($o['farmer_lat'])):
                            $navLat  = floatval($o['farmer_lat']);
                            $navLng  = floatval($o['farmer_lng']);
                            $navName = urlencode($o['farmer_name'].' - Pickup');
                            $navLabel= '🌾 Navigate to Farmer';
                            $navColor= '#16A34A';
                        elseif ($navStatus === 'shipped' && !empty($o['buyer_lat'])):
                            $navLat  = floatval($o['buyer_lat']);
                            $navLng  = floatval($o['buyer_lng']);
                            $navName = urlencode($o['buyer_name'].' - Delivery');
                            $navLabel= '🛒 Navigate to Buyer';
                            $navColor= '#1D4ED8';
                        else:
                            $navLat = $navLng = null;
                        endif;
                        ?>
                        <?php if ($navLat): ?>
                        <a href="https://www.google.com/maps/dir/?api=1&destination=<?= $navLat ?>,<?= $navLng ?>&travelmode=driving"
                           target="_blank"
                           style="margin-left:auto;display:inline-flex;align-items:center;gap:5px;background:<?= $navColor ?>;color:white;border-radius:99px;padding:.3rem .85rem;font-size:.7rem;font-weight:800;text-decoration:none;white-space:nowrap;">
                            <i class="fa-solid fa-diamond-turn-right"></i> <?= $navLabel ?>
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="dcard-body">

                        <!-- Status banner -->
                        <?php if ($o['status'] === 'confirmed'): ?>
                        <div style="background:#FFF7ED;border:1px solid #FED7AA;border-radius:10px;padding:.6rem 1rem;margin-bottom:.85rem;display:flex;align-items:center;gap:8px;font-size:.8rem;font-weight:700;color:#C2410C;">
                            <i class="fa-solid fa-box-open"></i>
                            Head to the <strong>farmer's location</strong> to pick up this order.
                            <span id="riderDistFarmer" style="margin-left:auto;font-size:.72rem;color:#92400E;"></span>
                        </div>
                        <?php elseif ($o['status'] === 'shipped'): ?>
                        <div style="background:#ECFEFF;border:1px solid #A5F3FC;border-radius:10px;padding:.6rem 1rem;margin-bottom:.85rem;display:flex;align-items:center;gap:8px;font-size:.8rem;font-weight:700;color:#0E7490;">
                            <i class="fa-solid fa-truck-fast"></i>
                            You're on the way! Deliver to the <strong>buyer's location</strong>.
                            <span id="riderDistBuyer" style="margin-left:auto;font-size:.72rem;color:#0E7490;"></span>
                        </div>
                        <?php elseif ($o['status'] === 'completed'): ?>
                        <div style="background:#DCFCE7;border:1px solid #BBF7D0;border-radius:10px;padding:.6rem 1rem;margin-bottom:.85rem;display:flex;align-items:center;gap:8px;font-size:.8rem;font-weight:700;color:#15803D;">
                            <i class="fa-solid fa-circle-check"></i> Order delivered successfully! ✔
                        </div>
                        <?php endif; ?>

                        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
                        <div id="riderDetailMap" style="height:300px;border-radius:14px;border:1.5px solid #E2E8F0;"></div>
                        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                        <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            const orderStatus = '<?= $o['status'] ?>';
                            const hasFarmer   = <?= (!empty($o['farmer_lat']) && !empty($o['farmer_lng'])) ? 'true' : 'false' ?>;
                            const hasBuyer    = <?= (!empty($o['buyer_lat'])  && !empty($o['buyer_lng']))  ? 'true' : 'false' ?>;

                            const fLat = <?= floatval($o['farmer_lat'] ?? 0) ?>;
                            const fLng = <?= floatval($o['farmer_lng'] ?? 0) ?>;
                            const bLat = <?= floatval($o['buyer_lat']  ?? 0) ?>;
                            const bLng = <?= floatval($o['buyer_lng']  ?? 0) ?>;

                            // Default center: midpoint of farmer & buyer, or Philippines
                            const defaultLat = hasFarmer ? (hasBuyer ? (fLat+bLat)/2 : fLat) : 8.0;
                            const defaultLng = hasFarmer ? (hasBuyer ? (fLng+bLng)/2 : fLng) : 124.0;

                            const map = L.map('riderDetailMap').setView([defaultLat, defaultLng], 11);
                            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                attribution: '© OpenStreetMap'
                            }).addTo(map);

                           // --- Icons ---
                            const farmerIcon = L.divIcon({
                                html: '<div style="background:#16a34a;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.3);font-size:16px;">🌾</div>',
                                className:'', iconSize:[36,36], iconAnchor:[18,18], popupAnchor:[0,-20]
                            });
                            const buyerIcon = L.divIcon({
                                html: '<div style="background:#3b82f6;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.3);font-size:16px;">🛒</div>',
                                className:'', iconSize:[36,36], iconAnchor:[18,18], popupAnchor:[0,-20]
                            });
                            const riderIcon = L.divIcon({
                                html: '<div class="rider-pulse-wrap"><div class="rider-pulse-ring"></div><div class="rider-pulse-dot">🛵</div></div>',
                                className:'', iconSize:[50,50], iconAnchor:[25,25], popupAnchor:[0,-28]
                            });

                            // --- Place farmer & buyer markers ---
                            let farmerMarker = null, buyerMarker = null;
                            if (hasFarmer) {
                                farmerMarker = L.marker([fLat, fLng], {icon: farmerIcon})
                                    .addTo(map)
                                    .bindPopup('<strong>🌾 <?= addslashes(sanitize($o['farmer_name'])) ?></strong><br><small style="color:#16a34a;">📦 Pickup here</small>');
                                if (orderStatus === 'confirmed') farmerMarker.openPopup();
                            }
                            if (hasBuyer) {
                                buyerMarker = L.marker([bLat, bLng], {icon: buyerIcon})
                                    .addTo(map)
                                    .bindPopup('<strong>🛒 <?= addslashes(sanitize($o['buyer_name'])) ?></strong><br><small style="color:#3b82f6;">📍 Deliver here</small>');
                                if (orderStatus === 'shipped') buyerMarker.openPopup();
                            }

                       // --- Route line between farmer and buyer (real road route) ---
                            let routeLine = null;
                            let riderLine = null;
                            let riderMarker = null;
                            let watchId = null;

                            async function drawRoute(fromLat, fromLng, toLat, toLng, color, lineRef) {
                                const osrmUrl = `https://router.project-osrm.org/route/v1/driving/${fromLng},${fromLat};${toLng},${toLat}?overview=full&geometries=geojson`;
                                try {
                                    const controller = new AbortController();
                                    const timer = setTimeout(() => controller.abort(), 6000);
                                    const res = await fetch(osrmUrl, { signal: controller.signal });
                                    clearTimeout(timer);
                                    const data = await res.json();
                                    if (data.routes && data.routes[0]) {
                                        if (lineRef === 'static') {
                                            if (routeLine) map.removeLayer(routeLine);
                                            routeLine = L.geoJSON(data.routes[0].geometry, {
                                                style: { color: color || '#94A3B8', weight: 4, opacity: 0.6, dashArray: '8,5' }
                                            }).addTo(map);
                                        } else {
                                            if (riderLine) map.removeLayer(riderLine);
                                            riderLine = L.geoJSON(data.routes[0].geometry, {
                                                style: { color: color || '#F97316', weight: 6, opacity: 0.9 }
                                            }).addTo(map);
                                            const route = data.routes[0];
                                            const km = (route.distance / 1000).toFixed(1);
                                            const mins = Math.round(route.duration / 60);
                                            const el = document.getElementById(orderStatus === 'confirmed' ? 'riderDistFarmer' : 'riderDistBuyer');
                                            if (el) el.textContent = `📏 ${km} km · ~${mins} min`;
                                        }
                                        return true;
                                    }
                                } catch(e) {
                                    console.warn('OSRM routing failed:', e.message);
                                }
                                return false;
                            }

                            // Draw static background route (farmer → buyer)
                            if (hasFarmer && hasBuyer) {
                                drawRoute(fLat, fLng, bLat, bLng, '#94A3B8', 'static');
                            }

                            function haversineKm(lat1, lng1, lat2, lng2) {
                                const R = 6371;
                                const dLat = (lat2-lat1)*Math.PI/180;
                                const dLng = (lng2-lng1)*Math.PI/180;
                                const a = Math.sin(dLat/2)*Math.sin(dLat/2) +
                                          Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*
                                          Math.sin(dLng/2)*Math.sin(dLng/2);
                                return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                            }

                            function updateRiderOnMap(lat, lng) {
                                if (!riderMarker) {
                                    riderMarker = L.marker([lat, lng], {icon: riderIcon, zIndexOffset:1000})
                                        .addTo(map)
                                        .bindPopup('<strong>🛵 You</strong><br><small>Your current location</small>');
                                } else {
                                    riderMarker.setLatLng([lat, lng]);
                                }

                                // Determine destination based on status
                                let destLat = null, destLng = null, lineColor = '#F97316';
                                if (orderStatus === 'confirmed' && hasFarmer) {
                                    destLat = fLat; destLng = fLng; lineColor = '#F97316';
                                } else if (orderStatus === 'shipped' && hasBuyer) {
                                    destLat = bLat; destLng = bLng; lineColor = '#0E7490';
                                }

                                if (destLat !== null) {
                                    // Try real road route, fallback to straight line
                                    (async () => {
                                        const success = await drawRoute(lat, lng, destLat, destLng, lineColor, 'rider');
                                        if (!success) {
                                            const pts = [[lat, lng], [destLat, destLng]];
                                            if (!riderLine) {
                                                riderLine = L.polyline(pts, { color: lineColor, weight: 4, dashArray: '8,4', opacity: .85 }).addTo(map);
                                            } else {
                                                riderLine.setLatLngs(pts).setStyle({ color: lineColor });
                                            }
                                            // Haversine distance as fallback label
                                            const distKm = haversineKm(lat, lng, destLat, destLng);
                                            const distStr = distKm < 1 ? Math.round(distKm*1000)+'m away' : distKm.toFixed(1)+' km away';
                                            const el = document.getElementById(orderStatus === 'confirmed' ? 'riderDistFarmer' : 'riderDistBuyer');
                                            if (el) el.textContent = '📏 ' + distStr;
                                        }
                                    })();
                                }

                                // Fit map bounds to show all points
                                const boundsPoints = [[lat, lng]];
                                if (hasFarmer) boundsPoints.push([fLat, fLng]);
                                if (hasBuyer)  boundsPoints.push([bLat, bLng]);
                                if (boundsPoints.length > 1) {
                                    map.fitBounds(boundsPoints, {padding:[30,30], maxZoom:14});
                                }

                                // Save rider location to server
                                fetch('<?= BASE_URL ?>/rider/update_location.php', {
                                    method:'POST',
                                    headers:{'Content-Type':'application/json'},
                                    body:JSON.stringify({lat, lng})
                                });
                            }

                            if (navigator.geolocation) {
                                navigator.geolocation.getCurrentPosition(
                                    pos => updateRiderOnMap(pos.coords.latitude, pos.coords.longitude),
                                    err => console.warn('GPS error:', err),
                                    {enableHighAccuracy:true, timeout:8000}
                                );
                                watchId = navigator.geolocation.watchPosition(
                                    pos => updateRiderOnMap(pos.coords.latitude, pos.coords.longitude),
                                    err => console.warn('GPS watch error:', err),
                                    {enableHighAccuracy:true, maximumAge:5000, timeout:10000}
                                );
                            } else {
                                const pts = [];
                                if (hasFarmer) pts.push([fLat, fLng]);
                                if (hasBuyer)  pts.push([bLat, bLng]);
                                if (pts.length > 1) map.fitBounds(pts, {padding:[30,30]});
                            }

                            window.addEventListener('beforeunload', () => {
                                if (watchId !== null) navigator.geolocation.clearWatch(watchId);
                            });
                        });
                        </script>

                        <!-- Legend -->
                        <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:.65rem;padding-top:.55rem;border-top:1px solid #F1F5F9;">
                            <span style="font-size:.72rem;font-weight:700;color:#F97316;display:flex;align-items:center;gap:4px;">🛵 You (live)</span>
                            <?php if (!empty($o['farmer_lat'])): ?>
                            <span style="font-size:.72rem;font-weight:700;color:#16a34a;display:flex;align-items:center;gap:4px;">🌾 <?= sanitize($o['farmer_name']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($o['buyer_lat'])): ?>
                            <span style="font-size:.72rem;font-weight:700;color:#3b82f6;display:flex;align-items:center;gap:4px;">🛒 <?= sanitize($o['buyer_name']) ?></span>
                            <?php endif; ?>
                            <?php if ($navLat ?? null): ?>
                            <a href="https://www.google.com/maps/dir/?api=1&destination=<?= $navLat ?>,<?= $navLng ?>&travelmode=driving"
                               target="_blank"
                               style="margin-left:auto;font-size:.72rem;font-weight:800;color:#1D4ED8;text-decoration:none;display:flex;align-items:center;gap:4px;">
                                <i class="fa-solid fa-diamond-turn-right"></i> Open in Google Maps
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Order Items -->
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-basket-shopping" style="color:var(--rider-primary);"></i>
                        <h6>Order Items (<?= count($items) ?>)</h6>
                    </div>
                    <div class="dcard-body" style="padding-top:.5rem;padding-bottom:.5rem;">
                        <?php foreach ($items as $item): ?>
                        <div class="item-row">
                            <?php if (!empty($item['product_image'])): ?>
                                <img src="<?= BASE_URL ?>/assets/images/products/<?= htmlspecialchars($item['product_image']) ?>" class="item-img">
                            <?php else: ?>
                                <div class="item-img" style="display:flex;align-items:center;justify-content:center;font-size:1.4rem;">
                                    <?= $emojis[$item['category']] ?? '🌾' ?>
                                </div>
                            <?php endif; ?>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:800;font-size:.87rem;color:#1E293B;"><?= sanitize($item['product_name']) ?></div>
                                <div style="font-size:.75rem;color:#64748B;">
                                    ₱<?= number_format($item['price_per_kg'],2) ?>/kg × <?= number_format($item['quantity_kg'],2) ?> kg
                                </div>
                            </div>
                            <div style="font-weight:800;color:var(--rider-primary);font-size:.88rem;flex-shrink:0;">
                                ₱<?= number_format($item['subtotal'],2) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Price Breakdown -->
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-calculator" style="color:var(--rider-primary);"></i>
                        <h6>Price Breakdown</h6>
                    </div>
                    <div class="dcard-body">
                        <div class="total-row">
                            <span style="color:#64748B;">Subtotal</span>
                            <span>₱<?= number_format($subtotal,2) ?></span>
                        </div>
                        <?php if ($delivFee > 0): ?>
                        <div class="total-row">
                            <span style="color:#64748B;display:flex;align-items:center;gap:4px;">
                                <i class="fa-solid fa-truck" style="color:#3b82f6;font-size:.75rem;"></i>
                                Delivery fee<?= $distKm ? ' ('.number_format($distKm,1).' km)' : '' ?>
                            </span>
                            <span style="color:#3b82f6;font-weight:700;">+₱<?= number_format($delivFee,2) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="total-row grand">
                            <span>Order Total</span>
                            <span>₱<?= number_format($o['total_amount'],2) ?></span>
                        </div>
                        <?php if ($delivFee > 0): ?>
                        <div style="margin-top:.75rem;background:#DCFCE7;border:1px solid #BBF7D0;border-radius:10px;padding:.7rem 1rem;display:flex;align-items:center;gap:8px;">
                            <i class="fa-solid fa-sack-dollar" style="color:#16A34A;"></i>
                            <span style="font-size:.82rem;font-weight:800;color:#15803D;">
                                Your delivery fee: ₱<?= number_format($delivFee,2) ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Notes -->
                <?php if (!empty($o['notes'])): ?>
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-note-sticky" style="color:#F59E0B;"></i>
                        <h6>Order Notes</h6>
                    </div>
                    <div class="dcard-body">
                        <p style="font-size:.88rem;color:#1E293B;line-height:1.6;margin:0;">
                            <?= nl2br(sanitize($o['notes'])) ?>
                        </p>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- /left col -->

            <!-- RIGHT COLUMN -->
            <div class="col-12 col-lg-5">

                <!-- Order Info -->
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-circle-info" style="color:var(--rider-primary);"></i>
                        <h6>Order Info</h6>
                    </div>
                    <div class="dcard-body" style="padding-top:.25rem;padding-bottom:.25rem;">
                        <div class="info-row">
                            <span class="info-label">Order ID</span>
                            <span class="info-val" style="color:var(--rider-primary);">#<?= $o['id'] ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Status</span>
                            <span class="status-pill" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;border:1px solid <?= $cfg['border'] ?>;font-size:.7rem;padding:.2rem .65rem;">
                                <i class="fa-solid <?= $cfg['icon'] ?>" style="font-size:.6rem;"></i>
                                <?= $cfg['label'] ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Date Placed</span>
                            <span class="info-val"><?= date('M j, Y', strtotime($o['created_at'])) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Time</span>
                            <span class="info-val"><?= date('g:i a', strtotime($o['created_at'])) ?></span>
                        </div>
                        <?php if (!empty($o['updated_at']) && $o['updated_at'] !== $o['created_at']): ?>
                        <div class="info-row">
                            <span class="info-label">Last Updated</span>
                            <span class="info-val"><?= date('M j, Y g:i a', strtotime($o['updated_at'])) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="info-row">
                            <span class="info-label">Payment</span>
                            <span class="info-val"><?= sanitize(ucfirst($o['payment_method'] ?? 'COD')) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Farmer -->
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-seedling" style="color:#16A34A;"></i>
                        <h6>Farmer (Pickup)</h6>
                    </div>
                    <div class="party-block">
                        <?php if (!empty($o['farmer_image'])): ?>
                            <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($o['farmer_image']) ?>"
                                 style="width:46px;height:46px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                        <?php else: ?>
                            <div class="party-avatar" style="background:#DCFCE7;color:#16A34A;">
                                <?= strtoupper(substr($o['farmer_name'],0,1)) ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div class="party-name"><?= sanitize($o['farmer_name']) ?></div>
                            <?php if ($o['farmer_phone']): ?>
                            <div class="party-meta"><i class="fa-solid fa-phone fa-xs"></i> <?= sanitize($o['farmer_phone']) ?></div>
                            <?php endif; ?>
                            <div class="party-meta"><i class="fa-solid fa-location-dot fa-xs"></i> <?= sanitize($o['farmer_location']) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Buyer -->
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-user" style="color:#3B82F6;"></i>
                        <h6>Buyer (Delivery)</h6>
                    </div>
                    <div class="party-block">
                        <?php if (!empty($o['buyer_image'])): ?>
                            <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($o['buyer_image']) ?>"
                                 style="width:46px;height:46px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                        <?php else: ?>
                            <div class="party-avatar" style="background:#DBEAFE;color:#1D4ED8;">
                                <?= strtoupper(substr($o['buyer_name'],0,1)) ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div class="party-name"><?= sanitize($o['buyer_name']) ?></div>
                            <?php if ($o['buyer_phone']): ?>
                            <div class="party-meta"><i class="fa-solid fa-phone fa-xs"></i> <?= sanitize($o['buyer_phone']) ?></div>
                            <?php endif; ?>
                            <div class="party-meta"><i class="fa-solid fa-location-dot fa-xs"></i> <?= sanitize($o['delivery_address'] ?: $o['buyer_location']) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Quick Contacts -->
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-phone" style="color:var(--rider-primary);"></i>
                        <h6>Quick Contact</h6>
                    </div>
                    <div class="dcard-body" style="display:flex;flex-direction:column;gap:.6rem;">
                        <?php if ($o['farmer_phone']): ?>
                        <a href="tel:<?= sanitize($o['farmer_phone']) ?>"
                           style="display:flex;align-items:center;gap:10px;background:#F0FDF4;border:1.5px solid #BBF7D0;border-radius:12px;padding:.75rem 1rem;text-decoration:none;">
                            <div style="width:36px;height:36px;border-radius:50%;background:#DCFCE7;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fa-solid fa-tractor" style="color:#16A34A;font-size:.85rem;"></i>
                            </div>
                            <div>
                                <div style="font-weight:800;font-size:.82rem;color:#15803D;">Call Farmer</div>
                                <div style="font-size:.72rem;color:#64748B;"><?= sanitize($o['farmer_phone']) ?></div>
                            </div>
                            <i class="fa-solid fa-phone" style="color:#16A34A;margin-left:auto;"></i>
                        </a>
                        <?php endif; ?>
                        <?php if ($o['buyer_phone']): ?>
                        <a href="tel:<?= sanitize($o['buyer_phone']) ?>"
                           style="display:flex;align-items:center;gap:10px;background:#EFF6FF;border:1.5px solid #BFDBFE;border-radius:12px;padding:.75rem 1rem;text-decoration:none;">
                            <div style="width:36px;height:36px;border-radius:50%;background:#DBEAFE;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fa-solid fa-user" style="color:#1D4ED8;font-size:.85rem;"></i>
                            </div>
                            <div>
                                <div style="font-weight:800;font-size:.82rem;color:#1D4ED8;">Call Buyer</div>
                                <div style="font-size:.72rem;color:#64748B;"><?= sanitize($o['buyer_phone']) ?></div>
                            </div>
                            <i class="fa-solid fa-phone" style="color:#1D4ED8;margin-left:auto;"></i>
                        </a>
                        <?php endif; ?>
                        <a href="messages.php?to=<?= $o['farmer_id'] ?>"
                           style="display:flex;align-items:center;gap:10px;background:#F5F3FF;border:1.5px solid #DDD6FE;border-radius:12px;padding:.75rem 1rem;text-decoration:none;">
                            <div style="width:36px;height:36px;border-radius:50%;background:#EDE9FE;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fa-solid fa-message" style="color:#7C3AED;font-size:.85rem;"></i>
                            </div>
                            <div style="font-weight:800;font-size:.82rem;color:#6D28D9;">Message Farmer</div>
                            <i class="fa-solid fa-chevron-right" style="color:#7C3AED;margin-left:auto;font-size:.75rem;"></i>
                        </a>
                        <a href="messages.php?to=<?= $o['buyer_id'] ?>"
                           style="display:flex;align-items:center;gap:10px;background:#F5F3FF;border:1.5px solid #DDD6FE;border-radius:12px;padding:.75rem 1rem;text-decoration:none;">
                            <div style="width:36px;height:36px;border-radius:50%;background:#EDE9FE;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fa-solid fa-message" style="color:#7C3AED;font-size:.85rem;"></i>
                            </div>
                            <div style="font-weight:800;font-size:.82rem;color:#6D28D9;">Message Buyer</div>
                            <i class="fa-solid fa-chevron-right" style="color:#7C3AED;margin-left:auto;font-size:.75rem;"></i>
                        </a>
                    </div>
                </div>

            </div><!-- /right col -->
        </div><!-- /row -->
    </div>
</div>
</body>
</html>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>