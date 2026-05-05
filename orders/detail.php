<?php
$page_title = 'Order Detail';
require_once __DIR__ . '/../includes/header.php';
requireLogin();

require_once __DIR__ . '/../config/delivery.php';

$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];
$role   = $_SESSION['role'];
$orderId = intval($_GET['id'] ?? 0);

if (!$orderId) {
    header('Location: index.php'); exit();
}

// Detect columns
$orderCols      = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
$hasTxFee       = in_array('transaction_fee', $orderCols);
$hasDeliveryFee = in_array('delivery_fee',    $orderCols);
$hasDistanceKm  = in_array('distance_km',     $orderCols);
$hasNotes       = in_array('notes',           $orderCols);
$hasRiderId     = in_array('rider_id',        $orderCols);

$txFeeCol   = $hasTxFee       ? 'o.transaction_fee' : '0 as transaction_fee';
$delivFeeCol= $hasDeliveryFee ? 'o.delivery_fee'    : '0 as delivery_fee';
$distCol    = $hasDistanceKm  ? 'o.distance_km'     : 'NULL as distance_km';
$notesCol   = $hasNotes       ? 'o.notes'           : 'NULL as notes';
$riderCol   = $hasRiderId     ? 'o.rider_id'        : 'NULL as rider_id';

// Fetch order — only if this user owns it (as buyer or farmer)
$order = $pdo->prepare("
    SELECT o.*, $txFeeCol, $delivFeeCol, $distCol, $notesCol, $riderCol,
           b.name  as buyer_name,  b.email  as buyer_email,  b.phone  as buyer_phone,
           b.location as buyer_location, b.profile_image as buyer_image,
           f.name  as farmer_name, f.email  as farmer_email, f.phone  as farmer_phone,
           f.location as farmer_location, f.profile_image as farmer_image
    FROM orders o
    JOIN users b ON o.buyer_id  = b.id
    JOIN users f ON o.farmer_id = f.id
    WHERE o.id = ?
      AND (o.buyer_id = ? OR o.farmer_id = ?)
");
$order->execute([$orderId, $userId, $userId]);
$o = $order->fetch();

if (!$o) {
    header('Location: index.php?error=not_found'); exit();
}

// Fetch order items
$itemsCols = $pdo->query("SHOW COLUMNS FROM order_items")->fetchAll(PDO::FETCH_COLUMN);
$hasUnit   = in_array('unit', $itemsCols);
$unitCol   = $hasUnit ? 'oi.unit' : "'kg' as unit";

$items = $pdo->prepare("
    SELECT oi.*, $unitCol, p.name as product_name, p.image as product_image,
           p.category, p.description as product_desc
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$items->execute([$orderId]);
$items = $items->fetchAll();

// Rider info
$rider = null;
if ($hasRiderId && $o['rider_id']) {
    $rStmt = $pdo->prepare("SELECT id, name, phone, profile_image, location FROM users WHERE id = ?");
    $rStmt->execute([$o['rider_id']]);
    $rider = $rStmt->fetch();
}

$statusColors = [
    'pending'    => ['bg'=>'#FFF7ED','color'=>'#EA580C','border'=>'#FED7AA'],
    'confirmed'  => ['bg'=>'#EFF6FF','color'=>'#2563EB','border'=>'#BFDBFE'],
    'processing' => ['bg'=>'#F5F3FF','color'=>'#7C3AED','border'=>'#DDD6FE'],
    'shipped'    => ['bg'=>'#F0FDF4','color'=>'#16A34A','border'=>'#BBF7D0'],
    'completed'  => ['bg'=>'#F0FDF4','color'=>'#15803D','border'=>'#86EFAC'],
    'cancelled'  => ['bg'=>'#FFF1F2','color'=>'#E11D48','border'=>'#FECDD3'],
];
$statusLabels = [
    'pending'    => 'Pending',
    'confirmed'  => 'Confirmed',
    'processing' => 'Processing',
    'shipped'    => 'Out for Delivery',
    'completed'  => 'Delivered',
    'cancelled'  => 'Cancelled',
];
$statusEmoji = [
    'pending'    => '⏳',
    'confirmed'  => '✅',
    'processing' => '📦',
    'shipped'    => '🚚',
    'completed'  => '🎉',
    'cancelled'  => '❌',
];
$sc  = $statusColors[$o['status']] ?? $statusColors['pending'];
$txFee    = floatval($o['transaction_fee'] ?? 0);
$delivFee = floatval($o['delivery_fee']    ?? $o['shipping_fee'] ?? 0);
$distKm   = $o['distance_km'] ? floatval($o['distance_km']) : null;
$subtotal = array_sum(array_map(fn($i) => floatval($i['subtotal'] ?? 0), $items));

// Fee breakdown
$serviceFee  = floatval($o['service_fee']  ?? 0);
$platformFee = floatval($o['platform_fee'] ?? 0);
if ($platformFee == 0) $platformFee = round($subtotal * 0.05, 2);
if ($serviceFee  == 0) {
    if ($subtotal < 500)  $serviceFee = 50;
    elseif ($subtotal < 2000) $serviceFee = 100;
    else $serviceFee = 150;
}
$farmerPayout = floatval($o['farmer_payout'] ?? round($subtotal - $platformFee, 2));

// Map coordinates
$mapBuyerStmt = $pdo->prepare("SELECT latitude, longitude FROM users WHERE id = ?");
$mapBuyerStmt->execute([$o['buyer_id']]);
$buyerCoords  = $mapBuyerStmt->fetch();
$mapFarmerStmt = $pdo->prepare("SELECT latitude, longitude FROM users WHERE id = ?");
$mapFarmerStmt->execute([$o['farmer_id']]);
$farmerCoords  = $mapFarmerStmt->fetch();
$hasMap = $buyerCoords  && !empty($buyerCoords['latitude'])
       && $farmerCoords && !empty($farmerCoords['latitude']);

// Rider coords — fetched early so Delivery Map can use them
$riderCoords = null;
if ($rider) {
    $rcStmt = $pdo->prepare("SELECT latitude, longitude FROM users WHERE id = ?");
    $rcStmt->execute([$rider['id']]);
    $rc = $rcStmt->fetch();
    if ($rc && !empty($rc['latitude'])) $riderCoords = $rc;
}
?>

<style>


    .rider-pulse-wrap{position:relative;width:50px;height:50px;display:flex;align-items:center;justify-content:center;}
.rider-pulse-ring{position:absolute;width:50px;height:50px;border-radius:50%;background:rgba(249,115,22,.25);animation:riderHeartbeat 1.4s ease-out infinite;}
.rider-pulse-ring::after{content:'';position:absolute;inset:6px;border-radius:50%;background:rgba(249,115,22,.2);animation:riderHeartbeat 1.4s ease-out infinite .2s;}
.rider-pulse-dot{position:relative;z-index:2;width:36px;height:36px;border-radius:50%;background:#F97316;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 3px 12px rgba(249,115,22,.6);font-size:16px;}
@keyframes riderHeartbeat{0%{transform:scale(1);opacity:.8;}50%{transform:scale(1.5);opacity:.3;}100%{transform:scale(1.9);opacity:0;}}
.detail-wrap{background:var(--bg);min-height:100vh;padding-bottom:3rem;}
.detail-grid{display:grid;grid-template-columns:1fr 340px;gap:1.25rem;align-items:start;}
.dcard{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);overflow:hidden;margin-bottom:1.25rem;}
.dcard-head{padding:.85rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.5rem;}
.dcard-head h6{margin:0;font-weight:800;font-size:.9rem;}
.dcard-body{padding:1.1rem 1.25rem;}
.info-row{display:flex;justify-content:space-between;align-items:center;padding:.45rem 0;border-bottom:1px solid var(--border);}
.info-row:last-child{border-bottom:none;}
.info-label{font-size:.78rem;color:var(--text-muted);font-weight:700;}
.info-val{font-size:.85rem;font-weight:700;color:var(--text);}
.party-card{display:flex;align-items:center;gap:.85rem;padding:.85rem 1.25rem;}
.party-avatar{width:46px;height:46px;border-radius:50%;background:var(--pale-green);color:var(--primary);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.1rem;flex-shrink:0;}
.party-meta{font-size:.75rem;color:var(--text-muted);margin-top:2px;}
.item-row{display:flex;align-items:center;gap:.85rem;padding:.65rem 0;border-bottom:1px solid var(--border);}
.item-row:last-child{border-bottom:none;}
.item-img{width:46px;height:46px;border-radius:var(--radius);object-fit:cover;background:#f3f4f6;flex-shrink:0;}
.item-img-placeholder{width:46px;height:46px;border-radius:var(--radius);background:var(--pale-green);display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;}
.total-row{display:flex;justify-content:space-between;padding:.4rem 0;font-size:.85rem;}
.total-row.grand{font-size:1rem;font-weight:800;color:var(--primary);border-top:2px solid var(--border);padding-top:.65rem;margin-top:.25rem;}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:.35rem .85rem;border-radius:99px;font-size:.82rem;font-weight:800;border:1px solid;}
.timeline{list-style:none;padding:0;margin:0;position:relative;}
.timeline::before{content:'';position:absolute;left:14px;top:0;bottom:0;width:2px;background:var(--border);}
.tl-item{display:flex;gap:.85rem;padding:.55rem 0;position:relative;}
.tl-dot{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0;z-index:1;}
.tl-dot.done{background:var(--primary);color:white;}
.tl-dot.curr{background:#f59e0b;color:white;}
.tl-dot.todo{background:var(--border);color:var(--text-muted);}
.action-btn{display:inline-flex;align-items:center;gap:6px;padding:.5rem 1.1rem;border-radius:var(--radius);font-size:.82rem;font-weight:700;cursor:pointer;text-decoration:none;border:none;transition:opacity .15s;}
.action-btn:hover{opacity:.85;}
@media(max-width:900px){.detail-grid{grid-template-columns:1fr;}}
</style>

<div class="detail-wrap">
    <div class="page-header">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h1><i class="fa-solid fa-receipt text-green me-2"></i>Order #<?= $o['id'] ?></h1>
                    <div class="page-breadcrumb">
                        <a href="index.php" style="color:var(--primary);text-decoration:none;">My Orders</a>
                        &rsaquo; Order #<?= $o['id'] ?>
                    </div>
                </div>
                <span class="status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;border-color:<?= $sc['border'] ?>;">
                    <?= $statusEmoji[$o['status']] ?? '' ?> <?= $statusLabels[$o['status']] ?? ucfirst($o['status']) ?>
                </span>
            </div>
        </div>
    </div>

    <div class="container" style="padding-top:1.5rem;">
        <div class="detail-grid">

            <!-- LEFT COLUMN -->
            <div>

                <!-- Order Items -->
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-basket-shopping text-green"></i>
                        <h6>Order Items (<?= count($items) ?>)</h6>
                    </div>
                    <div class="dcard-body" style="padding-top:.5rem;padding-bottom:.5rem;">
                        <?php foreach ($items as $item): ?>
                        <div class="item-row">
                            <?php if (!empty($item['product_image'])): ?>
                                <img src="<?= BASE_URL ?>/assets/images/products/<?= htmlspecialchars($item['product_image']) ?>"
                                     class="item-img">
                            <?php else: ?>
                                <div class="item-img-placeholder">🌿</div>
                            <?php endif; ?>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:800;font-size:.88rem;"><?= sanitize($item['product_name']) ?></div>
                                <div style="font-size:.75rem;color:var(--text-muted);">
                                    <?= sanitize($item['category'] ?? '') ?>
                                </div>
                                <div style="font-size:.78rem;color:var(--text-muted);margin-top:2px;">
                                    ₱<?= number_format($item['price_per_kg'], 2) ?>/kg × <?= number_format($item['quantity_kg'], 2) ?> <?= htmlspecialchars($item['unit'] ?? 'kg') ?>
                                </div>
                            </div>
                            <div style="text-align:right;flex-shrink:0;">
                                <div style="font-weight:800;color:var(--primary);">
                                    ₱<?= number_format($item['subtotal'], 2) ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Price Breakdown -->
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-calculator text-green"></i>
                        <h6>Price Breakdown</h6>
                    </div>
                    <div class="dcard-body">
                        <div class="total-row">
                            <span style="color:var(--text-muted);">Subtotal</span>
                            <span>₱<?= number_format($subtotal, 2) ?></span>
                        </div>
                        <?php if ($delivFee > 0): ?>
                        <div class="total-row">
                            <span style="color:var(--text-muted);display:flex;align-items:center;gap:4px;">
                                <i class="fa-solid fa-truck" style="font-size:.75rem;color:#3b82f6;"></i>
                                Delivery<?= $distKm ? ' ('.number_format($distKm,1).' km)' : '' ?>
                            </span>
                            <span style="color:#3b82f6;">+₱<?= number_format($delivFee, 2) ?></span>
                        </div>
                        <?php elseif ($distKm): ?>
                        <div class="total-row">
                            <span style="color:var(--text-muted);display:flex;align-items:center;gap:4px;">
                                <i class="fa-solid fa-truck" style="font-size:.75rem;color:#16a34a;"></i>
                                Delivery (<?= number_format($distKm,1) ?> km)
                            </span>
                            <span style="color:#16a34a;">Free</span>
                        </div>
                        <?php endif; ?>
                        <div class="total-row">
                            <span style="color:var(--text-muted);display:flex;align-items:center;gap:4px;">
                                <i class="fa-solid fa-handshake" style="font-size:.75rem;color:#7c3aed;"></i>
                                Service fee
                                <span style="font-size:.65rem;color:#94a3b8;font-style:italic;">(coordination &amp; handling)</span>
                            </span>
                            <span style="color:#7c3aed;">+₱<?= number_format($serviceFee, 2) ?></span>
                        </div>
                        <div class="total-row" style="opacity:.75;font-size:.78rem;">
                            <span style="color:var(--text-muted);display:flex;align-items:center;gap:4px;">
                                <i class="fa-solid fa-seedling" style="font-size:.72rem;color:var(--primary);"></i>
                                Farmer commission (5%)
                            </span>
                            <span style="color:var(--primary);">₱<?= number_format($platformFee, 2) ?></span>
                        </div>
                        <div style="border-top:1px dashed var(--border);margin:.3rem 0;"></div>
                        <div class="total-row" style="font-size:.78rem;">
                            <span style="color:var(--text-muted);display:flex;align-items:center;gap:4px;">
                                <i class="fa-solid fa-tractor" style="font-size:.72rem;color:#16a34a;"></i>
                                Farmer receives
                            </span>
                            <span style="color:#16a34a;font-weight:700;">₱<?= number_format($farmerPayout, 2) ?></span>
                        </div>
                        <div class="total-row grand">
                            <span>Total you pay</span>
                            <span>₱<?= number_format($o['total_amount'], 2) ?></span>
                        </div>
                        <div style="margin-top:.75rem;padding-top:.6rem;border-top:1px solid rgba(0,0,0,.06);display:flex;flex-direction:column;gap:3px;">
                            <div style="font-size:.65rem;color:#64748b;line-height:1.5;">
                                <i class="fa-solid fa-circle-info" style="color:#7c3aed;"></i>
                                <strong>Service fee</strong> covers coordination &amp; handling:
                                ₱50 (under ₱500) · ₱100 (₱500–₱1,999) · ₱150 (₱2,000+)
                            </div>
                            <div style="font-size:.65rem;color:#64748b;line-height:1.5;">
                                <i class="fa-solid fa-circle-info" style="color:var(--primary);"></i>
                                <strong>Farmer commission</strong> (5%) is deducted from the farmer's payout — not an extra charge to you.
                            </div>
                        </div>
                    </div>
                </div>


                <!-- Proof of Payment -->
                <?php
                $hasProof   = in_array('proof_of_payment',   $orderCols);
                $hasPayRef  = in_array('payment_reference',  $orderCols);
                $proofFile  = $hasProof  ? ($o['proof_of_payment']  ?? '') : '';
                $payRef     = $hasPayRef ? ($o['payment_reference']  ?? '') : '';
                $isCod      = strtolower($o['payment_method'] ?? '') === 'cash on delivery';
                if (!$isCod && ($proofFile || $payRef)):
                ?>
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-receipt text-green"></i>
                        <h6>Proof of Payment</h6>
                    </div>
                    <div class="dcard-body">
                        <?php
                        $pm     = $o['payment_method'] ?? '';
                        $pmLow  = strtolower($pm);
                        $isGcash= str_contains($pmLow, 'gcash');
                        $isMaya = str_contains($pmLow, 'maya');
                        $isBank = str_contains($pmLow, 'bank');
                        $pmBg   = $isGcash ? '#EEF5FF' : ($isMaya ? '#EBF9F1' : '#EEF0FF');
                        $pmCol  = $isGcash ? '#0070F0' : ($isMaya ? '#00B14F' : '#1d4ed8');
                        $pmBdr  = $isGcash ? '#C5DEFF' : ($isMaya ? '#B3EAC9' : '#C5CCFF');
                        $pmIcon = $isGcash ? 'fa-mobile-screen' : ($isMaya ? 'fa-mobile-screen' : 'fa-building-columns');
                        ?>
                        <div style="background:<?= $pmBg ?>;border:1.5px solid <?= $pmBdr ?>;border-radius:12px;padding:.85rem 1rem;margin-bottom:.85rem;">
                            <div style="font-size:.7rem;font-weight:800;color:<?= $pmCol ?>;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;display:flex;align-items:center;gap:5px;">
                                <i class="fa-solid <?= $pmIcon ?>"></i> <?= sanitize($pm) ?>
                            </div>
                            <?php if ($payRef): ?>
                            <div style="display:flex;justify-content:space-between;align-items:center;font-size:.82rem;">
                                <span style="color:#64748b;">Reference #</span>
                                <strong style="font-family:monospace;font-size:.9rem;color:<?= $pmCol ?>;"><?= sanitize($payRef) ?></strong>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($proofFile): ?>
                        <?php $proofUrl = BASE_URL . '/assets/images/proofs/' . htmlspecialchars($proofFile); ?>
                        <div style="font-size:.75rem;font-weight:700;color:var(--text-muted);margin-bottom:.5rem;">
                            <i class="fa-solid fa-image me-1"></i> Payment Screenshot
                        </div>
                        <a href="<?= $proofUrl ?>" target="_blank" style="display:block;position:relative;">
                            <img src="<?= $proofUrl ?>"
                                 alt="Proof of payment"
                                 style="width:100%;border-radius:10px;border:1.5px solid <?= $pmBdr ?>;object-fit:cover;max-height:280px;cursor:zoom-in;">
                            <div style="position:absolute;top:8px;right:8px;background:rgba(0,0,0,.5);color:white;border-radius:6px;padding:3px 8px;font-size:.65rem;font-weight:700;">
                                <i class="fa-solid fa-magnifying-glass-plus me-1"></i>View Full
                            </div>
                        </a>
                        <?php else: ?>
                        <div style="background:#f8fafc;border:1.5px dashed var(--border);border-radius:10px;padding:1.25rem;text-align:center;">
                            <i class="fa-solid fa-image" style="font-size:1.5rem;color:var(--text-muted);opacity:.3;display:block;margin-bottom:.4rem;"></i>
                            <div style="font-size:.78rem;color:var(--text-muted);">No screenshot uploaded</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>


                <!-- Delivery Map -->
                <?php if ($hasMap): ?>
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-map text-green"></i>
                        <h6>Delivery Map</h6>
                    </div>
                    <div class="dcard-body">
                        <?php if ($distKm): ?>
                        <div style="display:flex;gap:.5rem;margin-bottom:.75rem;flex-wrap:wrap;">
                            <span style="background:#dbeafe;color:#1d4ed8;border-radius:99px;padding:3px 10px;font-size:.72rem;font-weight:800;">
                                <i class="fa-solid fa-route me-1"></i><?= number_format($distKm,1) ?> km
                            </span>
                            <span style="background:<?= $delivFee > 0 ? '#fff7ed' : '#dcfce7' ?>;color:<?= $delivFee > 0 ? '#ea580c' : '#16a34a' ?>;border-radius:99px;padding:3px 10px;font-size:.72rem;font-weight:800;">
                                <i class="fa-solid fa-truck me-1"></i><?= $delivFee > 0 ? '₱'.number_format($delivFee,2) : 'Free delivery' ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
                        <div id="orderDetailMap" style="height:240px;border-radius:var(--radius-lg);border:1.5px solid var(--border);"></div>
                        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                        <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            const bLat = <?= floatval($buyerCoords['latitude']) ?>;
                            const bLng = <?= floatval($buyerCoords['longitude']) ?>;
                            const fLat = <?= floatval($farmerCoords['latitude']) ?>;
                            const fLng = <?= floatval($farmerCoords['longitude']) ?>;
                            const map = L.map('orderDetailMap').setView([(bLat+fLat)/2,(bLng+fLng)/2], 9);
                            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(map);
                            const buyerIcon = L.divIcon({html:'<div style="background:#3b82f6;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.3);font-size:15px;">🛒</div>',className:'',iconSize:[34,34],iconAnchor:[17,17],popupAnchor:[0,-20]});
                            const farmerIcon = L.divIcon({html:'<div style="background:#16a34a;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.3);font-size:15px;">🌾</div>',className:'',iconSize:[34,34],iconAnchor:[17,17],popupAnchor:[0,-20]});
                         L.marker([bLat,bLng],{icon:buyerIcon}).addTo(map).bindPopup('<strong>🛒 Buyer</strong><br><?= addslashes(sanitize($o['buyer_name'])) ?><br><small>Delivery destination</small>').openPopup();
L.marker([fLat,fLng],{icon:farmerIcon}).addTo(map).bindPopup('<strong>🌾 Farmer</strong><br><?= addslashes(sanitize($o['farmer_name'])) ?><br><small>Pickup origin</small>');
<?php if ($riderCoords): ?>
L.polyline([[fLat,fLng],[bLat,bLng]],{color:'#94a3b8',weight:2,dashArray:'7,5',opacity:.4}).addTo(map);
const riderIconDM = L.divIcon({html:'<div class="rider-pulse-wrap"><div class="rider-pulse-ring"></div><div class="rider-pulse-dot">🛵</div></div>',className:'',iconSize:[50,50],iconAnchor:[25,25],popupAnchor:[0,-28]});
const rLat = <?= floatval($riderCoords['latitude']) ?>;
const rLng = <?= floatval($riderCoords['longitude']) ?>;
L.marker([rLat,rLng],{icon:riderIconDM,zIndexOffset:1000}).addTo(map).bindPopup('<strong>🛵 Rider</strong><br><?= addslashes(sanitize($rider['name'])) ?><?= $rider['phone'] ? "<br><small>📞 ".sanitize($rider['phone'])."</small>" : "" ?>');
L.polyline([[rLat,rLng],[bLat,bLng]],{color:'#F97316',weight:2.5,dashArray:'6,4',opacity:.8}).addTo(map);
map.fitBounds([[bLat,bLng],[fLat,fLng],[rLat,rLng]],{padding:[30,30]});
<?php else: ?>
L.polyline([[fLat,fLng],[bLat,bLng]],{color:'#3b82f6',weight:2.5,dashArray:'7,5',opacity:.7}).addTo(map);
map.fitBounds([[bLat,bLng],[fLat,fLng]],{padding:[30,30]});
<?php endif; ?>
                        });
                        </script>
                        <div style="display:flex;gap:.5rem;margin-top:.6rem;flex-wrap:wrap;">
<span style="font-size:.72rem;color:#16a34a;font-weight:700;">🌾 <?= sanitize($o['farmer_name']) ?> (Farmer)</span>
<span style="font-size:.72rem;color:#3b82f6;font-weight:700;">🛒 <?= sanitize($o['buyer_name']) ?> (Buyer)</span>
<?php if ($riderCoords && $rider): ?>
<span style="font-size:.72rem;color:#F97316;font-weight:700;">🛵 <?= sanitize($rider['name']) ?> (Rider)</span>
<?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Notes -->
                <?php if ($hasNotes && !empty($o['notes'])): ?>
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-note-sticky text-green"></i>
                        <h6>Order Notes</h6>
                    </div>
                    <div class="dcard-body">
                        <p style="font-size:.88rem;color:var(--text);margin:0;line-height:1.6;">
                            <?= nl2br(sanitize($o['notes'])) ?>
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Actions -->
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-bolt text-green"></i>
                        <h6>Actions</h6>
                    </div>
                    <div class="dcard-body" style="display:flex;gap:.65rem;flex-wrap:wrap;">
                        <a href="index.php" class="action-btn" style="background:var(--pale-green);color:var(--primary);">
                            <i class="fa-solid fa-arrow-left"></i> Back to Orders
                        </a>
                        <?php if ($role === 'farmer' && $o['status'] === 'pending'): ?>
                        <a href="update_status.php?id=<?= $o['id'] ?>&status=confirmed"
                           class="action-btn" style="background:#dbeafe;color:#1d4ed8;">
                            <i class="fa-solid fa-check"></i> Confirm Order
                        </a>
                        <?php endif; ?>
                        <?php if ($role === 'farmer' && $o['status'] === 'confirmed'): ?>
                        <a href="update_status.php?id=<?= $o['id'] ?>&status=processing"
                           class="action-btn" style="background:#f5f3ff;color:#7c3aed;">
                            <i class="fa-solid fa-box"></i> Mark Processing
                        </a>
                        <?php endif; ?>
                        <?php if ($role === 'farmer' && $o['status'] === 'processing'): ?>
<?php
// Fetch nearby riders for assignment
$farmerCoordsForRiders = $farmerCoords;
$riderOptions = [];
$roStmt = $pdo->prepare("
    SELECT id, name, phone, profile_image, location, latitude, longitude
    FROM users
    WHERE role IN ('rider', 'delivery')
      AND latitude IS NOT NULL AND longitude IS NOT NULL
      AND latitude != 0 AND longitude != 0
    ORDER BY name ASC
");
$roStmt->execute();
$allRiderOptions = $roStmt->fetchAll();
foreach ($allRiderOptions as $ro) {
    $dist = null;
    if ($farmerCoordsForRiders && !empty($farmerCoordsForRiders['latitude'])) {
        $dist = round(haversineDistance(
            floatval($farmerCoordsForRiders['latitude']), floatval($farmerCoordsForRiders['longitude']),
            floatval($ro['latitude']), floatval($ro['longitude'])
        ), 1);
    }
    $ro['dist_km'] = $dist;
    $riderOptions[] = $ro;
}
usort($riderOptions, fn($a,$b) => ($a['dist_km'] ?? 999) <=> ($b['dist_km'] ?? 999));
?>

<!-- Assign Rider Panel -->
<div style="width:100%;margin-top:.5rem;">
    <div style="background:#F0FDF4;border:1.5px solid #BBF7D0;border-radius:14px;padding:1rem;">
        <div style="font-weight:800;font-size:.85rem;color:#15803D;margin-bottom:.75rem;display:flex;align-items:center;gap:6px;">
            <i class="fa-solid fa-motorcycle"></i> Assign a Rider for Pickup
        </div>

        <?php if (empty($riderOptions)): ?>
        <div style="font-size:.82rem;color:var(--text-muted);padding:.5rem 0;">
            No riders with location available yet. Riders must open their dashboard to share location.
        </div>
        <?php else: ?>

        <!-- Rider Map -->
        <?php if ($farmerCoordsForRiders && !empty($farmerCoordsForRiders['latitude'])): ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
        <div id="assignRiderMap" style="height:220px;border-radius:10px;border:1.5px solid #BBF7D0;margin-bottom:.85rem;"></div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script>
        function initAssignMap() {
            const mapEl = document.getElementById('assignRiderMap');
            if (!mapEl || mapEl._leaflet_id) return;

            const fLat = <?= floatval($farmerCoordsForRiders['latitude']) ?>;
            const fLng = <?= floatval($farmerCoordsForRiders['longitude']) ?>;
            const map = L.map('assignRiderMap').setView([fLat, fLng], 11);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution:'© OpenStreetMap'
            }).addTo(map);

            L.marker([fLat, fLng], {icon: L.divIcon({
                html:'<div style="background:#16a34a;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.3);font-size:14px;">🌾</div>',
                className:'', iconSize:[34,34], iconAnchor:[17,17], popupAnchor:[0,-18]
            })}).addTo(map).bindPopup('<strong>🌾 Your Farm</strong>').openPopup();

            const bounds = [[fLat, fLng]];

            <?php foreach ($riderOptions as $ro): ?>
            (function() {
                const rLat = <?= floatval($ro['latitude']) ?>;
                const rLng = <?= floatval($ro['longitude']) ?>;
                bounds.push([rLat, rLng]);
                L.marker([rLat, rLng], {icon: L.divIcon({
                    html:'<div style="background:#F97316;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2.5px solid white;box-shadow:0 2px 8px rgba(0,0,0,.25);font-size:13px;">🛵</div>',
                    className:'', iconSize:[32,32], iconAnchor:[16,16], popupAnchor:[0,-16]
                })}).addTo(map)
                .bindPopup('<strong>🛵 <?= addslashes(sanitize($ro['name'])) ?></strong><?= $ro['dist_km'] !== null ? "<br><small style=\\'color:#F97316;font-weight:700;\\'>📏 {$ro['dist_km']} km from farm</small>" : "" ?><?= $ro['phone'] ? "<br><small>📞 ".sanitize($ro['phone'])."</small>" : "" ?>');
            })();
            <?php endforeach; ?>

            if (bounds.length > 1) map.fitBounds(bounds, {padding:[25,25]});

            setTimeout(() => map.invalidateSize(), 300);
        }

        if (document.readyState === 'complete') {
            initAssignMap();
        } else {
            window.addEventListener('load', initAssignMap);
        }
        </script>        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.85rem;">
            <span style="font-size:.7rem;font-weight:700;color:#16a34a;">🌾 Your Farm</span>
            <span style="font-size:.7rem;font-weight:700;color:#F97316;">🛵 Available Riders</span>
        </div>
        <?php endif; ?>

        <!-- Rider List with Assign buttons -->
        <div style="display:flex;flex-direction:column;gap:.5rem;">
            <?php foreach ($riderOptions as $ro): ?>
            <form method="POST" action="assign_rider.php" style="display:contents;">
                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                <input type="hidden" name="rider_id" value="<?= $ro['id'] ?>">
                <div style="display:flex;align-items:center;gap:10px;background:white;border-radius:10px;padding:.6rem .85rem;border:1.5px solid #E2E8F0;">
                    <?php if (!empty($ro['profile_image'])): ?>
                        <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($ro['profile_image']) ?>"
                             style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid #FED7AA;flex-shrink:0;">
                    <?php else: ?>
                        <div style="width:36px;height:36px;border-radius:50%;background:#FFF7ED;color:#EA580C;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.9rem;border:2px solid #FED7AA;flex-shrink:0;">
                            <?= strtoupper(substr($ro['name'],0,1)) ?>
                        </div>
                    <?php endif; ?>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:800;font-size:.82rem;color:var(--text);"><?= sanitize($ro['name']) ?></div>
                        <div style="font-size:.7rem;color:var(--text-muted);">📍 <?= sanitize($ro['location'] ?? '—') ?></div>
                    </div>
                    <?php if ($ro['dist_km'] !== null): ?>
                    <span style="font-size:.75rem;font-weight:800;color:#1D4ED8;flex-shrink:0;">📏 <?= $ro['dist_km'] ?> km</span>
                    <?php endif; ?>
                    <?php if ($ro['phone']): ?>
                    <a href="tel:<?= sanitize($ro['phone']) ?>" style="font-size:.7rem;color:var(--primary);font-weight:700;text-decoration:none;flex-shrink:0;">
                        <i class="fa-solid fa-phone"></i>
                    </a>
                    <?php endif; ?>
                    <button type="submit" style="background:linear-gradient(135deg,#F97316,#EA580C);color:white;border:none;border-radius:8px;padding:.4rem .9rem;font-size:.75rem;font-weight:800;cursor:pointer;flex-shrink:0;white-space:nowrap;"
                            onclick="return confirm('Assign <?= addslashes(sanitize($ro['name'])) ?> to deliver Order #<?= $o['id'] ?>?')">
                        <i class="fa-solid fa-motorcycle me-1"></i> Assign
                    </button>
                </div>
            </form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
                        <?php if (in_array($o['status'], ['pending','confirmed'])): ?>
                        <a href="update_status.php?id=<?= $o['id'] ?>&status=cancelled"
                           onclick="return confirm('Cancel this order?')"
                           class="action-btn" style="background:#fff1f2;color:#e11d48;">
                            <i class="fa-solid fa-xmark"></i> Cancel Order
                        </a>
                        <?php endif; ?>
                        <?php
                        $msgTarget = ($role === 'farmer') ? $o['buyer_id'] : $o['farmer_id'];
                        ?>
                        <a href="../messages/index.php?to=<?= $msgTarget ?>"
                           class="action-btn" style="background:#f0f9ff;color:#0369a1;">
                            <i class="fa-solid fa-comment"></i> Send Message
                        </a>
                    </div>
                </div>

            </div><!-- end left -->

            <!-- RIGHT COLUMN -->
            <div>

                <!-- Order Info -->
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-circle-info text-green"></i>
                        <h6>Order Info</h6>
                    </div>
                    <div class="dcard-body" style="padding-top:.25rem;padding-bottom:.25rem;">
                        <div class="info-row">
                            <span class="info-label">Order ID</span>
                            <span class="info-val" style="color:var(--primary);">#<?= $o['id'] ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Status</span>
                            <span class="status-pill" style="font-size:.72rem;padding:.2rem .65rem;background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;border-color:<?= $sc['border'] ?>;">
                                <?= $statusEmoji[$o['status']] ?? '' ?> <?= $statusLabels[$o['status']] ?? ucfirst($o['status']) ?>
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

                <!-- Buyer Info -->
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-user text-green"></i>
                        <h6>Buyer</h6>
                    </div>
                    <div class="party-card">
                        <?php if (!empty($o['buyer_image'])): ?>
                            <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($o['buyer_image']) ?>"
                                 style="width:46px;height:46px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                        <?php else: ?>
                            <div class="party-avatar">
                                <?= strtoupper(substr($o['buyer_name'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div style="font-weight:800;font-size:.9rem;"><?= sanitize($o['buyer_name']) ?></div>
                            <?php if (!empty($o['buyer_phone'])): ?>
                            <div class="party-meta"><i class="fa-solid fa-phone fa-xs"></i> <?= sanitize($o['buyer_phone']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($o['buyer_email'])): ?>
                            <div class="party-meta"><i class="fa-solid fa-envelope fa-xs"></i> <?= sanitize($o['buyer_email']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($o['buyer_location'])): ?>
                            <div class="party-meta"><i class="fa-solid fa-location-dot fa-xs"></i> <?= sanitize($o['buyer_location']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Farmer Info -->
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-seedling text-green"></i>
                        <h6>Farmer</h6>
                    </div>
                    <div class="party-card">
                        <?php if (!empty($o['farmer_image'])): ?>
                            <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($o['farmer_image']) ?>"
                                 style="width:46px;height:46px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                        <?php else: ?>
                            <div class="party-avatar" style="background:#dcfce7;color:#16a34a;">
                                <?= strtoupper(substr($o['farmer_name'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div style="font-weight:800;font-size:.9rem;"><?= sanitize($o['farmer_name']) ?></div>
                            <?php if (!empty($o['farmer_phone'])): ?>
                            <div class="party-meta"><i class="fa-solid fa-phone fa-xs"></i> <?= sanitize($o['farmer_phone']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($o['farmer_email'])): ?>
                            <div class="party-meta"><i class="fa-solid fa-envelope fa-xs"></i> <?= sanitize($o['farmer_email']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($o['farmer_location'])): ?>
                            <div class="party-meta"><i class="fa-solid fa-location-dot fa-xs"></i> <?= sanitize($o['farmer_location']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
<!-- Rider Info -->
                <?php if ($rider):
                    // Fetch vehicle info from riders table
                    $riderVehicle = null;
                    $rvCheck = $pdo->query("SHOW TABLES LIKE 'riders'")->fetchAll();
                    if (!empty($rvCheck)) {
                        $rvStmt = $pdo->prepare("SELECT vehicle_type, plate_number FROM riders WHERE user_id = ?");
                        $rvStmt->execute([$rider['id']]);
                        $riderVehicle = $rvStmt->fetch();
                    }
                    $vehicleEmoji = ['motorcycle'=>'🏍️','scooter'=>'🛵','bicycle'=>'🚲','tricycle'=>'🛺','van'=>'🚐'];
                    $vType = strtolower($riderVehicle['vehicle_type'] ?? '');
                    $vEmoji = $vehicleEmoji[$vType] ?? '🛵';
                ?>
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-motorcycle text-green"></i>
                        <h6>Delivery Rider</h6>
                        <?php if ($o['status'] === 'shipped'): ?>
                        <span style="margin-left:auto;background:#dcfce7;color:#15803d;border-radius:99px;padding:2px 10px;font-size:.65rem;font-weight:800;border:1px solid #86efac;">
                            🚚 On the way
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="party-card">
                        <?php if (!empty($rider['profile_image'])): ?>
                            <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($rider['profile_image']) ?>"
                                 style="width:52px;height:52px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid #FED7AA;">
                        <?php else: ?>
                            <div class="party-avatar" style="width:52px;height:52px;background:#fff7ed;color:#ea580c;font-size:1.3rem;">
                                <?= strtoupper(substr($rider['name'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <div style="flex:1;min-width:0;">
                            <div style="font-weight:800;font-size:.9rem;"><?= sanitize($rider['name']) ?></div>
                            <?php if (!empty($rider['phone'])): ?>
                            <div class="party-meta"><i class="fa-solid fa-phone fa-xs"></i> <?= sanitize($rider['phone']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($rider['location'])): ?>
                            <div class="party-meta"><i class="fa-solid fa-location-dot fa-xs"></i> <?= sanitize($rider['location']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($riderVehicle['vehicle_type'])): ?>
                            <div class="party-meta" style="margin-top:4px;">
                                <?= $vEmoji ?> <?= sanitize(ucfirst($riderVehicle['vehicle_type'])) ?>
                                <?php if (!empty($riderVehicle['plate_number'])): ?>
                                · <span style="font-family:monospace;font-weight:800;color:var(--text);background:#f1f5f9;padding:1px 6px;border-radius:5px;font-size:.72rem;"><?= sanitize(strtoupper($riderVehicle['plate_number'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <div style="margin-top:.5rem;">
                             <a href="../rider/public_profile.php?id=<?= $rider['id'] ?>"
                                   target="_blank"
                                   style="display:inline-flex;align-items:center;gap:5px;background:#fff7ed;color:#ea580c;border:1px solid #fed7aa;border-radius:8px;padding:4px 10px;font-size:.72rem;font-weight:800;text-decoration:none;">
                                    <i class="fa-solid fa-id-card fa-xs"></i> View Rider Profile
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Rider Location Map -->
<?php


// Nearby riders (all delivery users with coordinates, within ~50km)
$nearbyRiders = [];
$nrStmt = $pdo->prepare("
    SELECT id, name, phone, profile_image, location, latitude, longitude
    FROM users
    WHERE role IN ('rider', 'delivery')
      AND latitude IS NOT NULL
      AND longitude IS NOT NULL
      AND latitude != 0
      AND longitude != 0
");
$nrStmt->execute();
$allRiders = $nrStmt->fetchAll();

// Filter within 50km of farmer
foreach ($allRiders as $nr) {
    if (!$farmerCoords) break;
    $d = haversineDistance(
        floatval($farmerCoords['latitude']),  floatval($farmerCoords['longitude']),
        floatval($nr['latitude']),            floatval($nr['longitude'])
    );
    if ($d <= 50) {
        $nr['dist_km'] = round($d, 1);
        $nearbyRiders[] = $nr;
    }
}
usort($nearbyRiders, fn($a,$b) => $a['dist_km'] <=> $b['dist_km']);

$showRiderMap = $farmerCoords && ($riderCoords || count($nearbyRiders) > 0);
?>

<?php if ($showRiderMap && $role === 'farmer'): ?>
        <div class="dcard">
    <div class="dcard-head">
        <i class="fa-solid fa-motorcycle text-green"></i>
       <h6><?= $role === 'buyer' ? '🚚 On the Way to You' : 'Rider Map' ?>
            <?php if ($role === 'farmer' && count($nearbyRiders) > 0): ?>
            <span style="background:#FFF7ED;color:#EA580C;border:1px solid #FED7AA;border-radius:99px;padding:2px 10px;font-size:.68rem;font-weight:800;margin-left:6px;">
                <?= count($nearbyRiders) ?> nearby
            </span>
            <?php endif; ?>
        </h6>
    </div>
    <div class="dcard-body">

      <!-- Chips -->
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.75rem;">
            <?php if ($role === 'buyer'): ?>
            <span style="background:#FFF7ED;color:#EA580C;border-radius:99px;padding:3px 10px;font-size:.72rem;font-weight:700;border:1px solid #FED7AA;">
                <i class="fa-solid fa-motorcycle me-1"></i><?= sanitize($rider['name']) ?> is on the way
            </span>
            <?php if ($rider['phone']): ?>
            <a href="tel:<?= sanitize($rider['phone']) ?>" style="background:#dcfce7;color:#15803d;border-radius:99px;padding:3px 10px;font-size:.72rem;font-weight:700;border:1px solid #86efac;text-decoration:none;">
                <i class="fa-solid fa-phone me-1"></i>Call Rider
            </a>
            <?php endif; ?>
            <?php else: ?>
            <?php if ($riderCoords): ?>
            <span style="background:#FFF7ED;color:#EA580C;border-radius:99px;padding:3px 10px;font-size:.72rem;font-weight:700;border:1px solid #FED7AA;">
                <i class="fa-solid fa-motorcycle me-1"></i>Assigned rider on map
            </span>
            <?php endif; ?>
            <?php if (count($nearbyRiders) > 0): ?>
            <span style="background:#EFF6FF;color:#1D4ED8;border-radius:99px;padding:3px 10px;font-size:.72rem;font-weight:700;border:1px solid #BFDBFE;">
                <i class="fa-solid fa-location-dot me-1"></i><?= count($nearbyRiders) ?> rider<?= count($nearbyRiders) > 1 ? 's' : '' ?> within 50 km
            </span>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
        <div id="riderMap" style="height:300px;border-radius:14px;border:1.5px solid var(--border);"></div>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function () {

            const farmerLat = <?= floatval($farmerCoords['latitude']) ?>;
            const farmerLng = <?= floatval($farmerCoords['longitude']) ?>;

            const map = L.map('riderMap').setView([farmerLat, farmerLng], 10);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap'
            }).addTo(map);

            // Farmer marker
            const farmerIcon = L.divIcon({
                html: '<div style="background:#16a34a;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.3);font-size:16px;">🌾</div>',
                className: '', iconSize:[36,36], iconAnchor:[18,18], popupAnchor:[0,-20]
            });
            L.marker([farmerLat, farmerLng], {icon: farmerIcon})
                .addTo(map)
                .bindPopup('<strong>🌾 Your Farm</strong><br><small><?= addslashes(sanitize($o['farmer_name'])) ?></small>');

            // Buyer marker
            <?php if ($buyerCoords): ?>
            const buyerIcon = L.divIcon({
                html: '<div style="background:#3b82f6;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.3);font-size:16px;">🛒</div>',
                className: '', iconSize:[36,36], iconAnchor:[18,18], popupAnchor:[0,-20]
            });
            L.marker([<?= floatval($buyerCoords['latitude']) ?>, <?= floatval($buyerCoords['longitude']) ?>], {icon: buyerIcon})
                .addTo(map)
                .bindPopup('<strong>🛒 Buyer</strong><br><small><?= addslashes(sanitize($o['buyer_name'])) ?></small>');
            L.polyline([[farmerLat, farmerLng],[<?= floatval($buyerCoords['latitude']) ?>, <?= floatval($buyerCoords['longitude']) ?>]],
                {color:'#3b82f6', weight:2, dashArray:'7,5', opacity:.6}).addTo(map);
            <?php endif; ?>

            // Assigned rider marker (orange, bigger)
            <?php if ($riderCoords): ?>
           const assignedIcon = L.divIcon({
                html: '<div class="rider-pulse-wrap"><div class="rider-pulse-ring"></div><div class="rider-pulse-dot">🛵</div></div>',
                className: '', iconSize:[50,50], iconAnchor:[25,25], popupAnchor:[0,-28]
            });
            L.marker([<?= floatval($riderCoords['latitude']) ?>, <?= floatval($riderCoords['longitude']) ?>], {icon: assignedIcon, zIndexOffset: 1000})
                .addTo(map)
                .bindPopup('<strong>🛵 Assigned Rider</strong><br><small><?= addslashes(sanitize($rider['name'])) ?></small><?= $rider['phone'] ? "<br><small>📞 ".sanitize($rider['phone'])."</small>" : "" ?>')
                .openPopup();
            <?php endif; ?>

            // Nearby riders (blue-grey dots)
            <?php foreach ($nearbyRiders as $nr):
                // Skip if this is the already-assigned rider (shown in orange)
                if ($rider && $nr['id'] == $rider['id']) continue;
            ?>
            (function() {
                const nIcon = L.divIcon({
                    html: '<div style="background:#64748B;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2.5px solid white;box-shadow:0 2px 8px rgba(0,0,0,.25);font-size:14px;">🛵</div>',
                    className: '', iconSize:[32,32], iconAnchor:[16,16], popupAnchor:[0,-18]
                });
                L.marker([<?= floatval($nr['latitude']) ?>, <?= floatval($nr['longitude']) ?>], {icon: nIcon})
                    .addTo(map)
                    .bindPopup('<strong>🛵 <?= addslashes(sanitize($nr['name'])) ?></strong><br><small>📍 <?= addslashes(sanitize($nr['location'] ?? '')) ?></small><br><small style="color:#F97316;font-weight:700;">📏 <?= $nr['dist_km'] ?> km from farm</small><?= $nr['phone'] ? "<br><small>📞 ".sanitize($nr['phone'])."</small>" : "" ?>');
            })();
            <?php endforeach; ?>

            // Fit all markers in view
            const bounds = [];
            bounds.push([farmerLat, farmerLng]);
            <?php if ($buyerCoords): ?>bounds.push([<?= floatval($buyerCoords['latitude']) ?>, <?= floatval($buyerCoords['longitude']) ?>]);<?php endif; ?>
            <?php if ($riderCoords): ?>bounds.push([<?= floatval($riderCoords['latitude']) ?>, <?= floatval($riderCoords['longitude']) ?>]);<?php endif; ?>
            <?php foreach ($nearbyRiders as $nr): ?>bounds.push([<?= floatval($nr['latitude']) ?>, <?= floatval($nr['longitude']) ?>]);<?php endforeach; ?>
            if (bounds.length > 1) map.fitBounds(bounds, {padding:[30,30]});
        });
        </script>

     <!-- Legend -->
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:.65rem;padding-top:.6rem;border-top:1px solid var(--border);">
            <?php if ($role === 'buyer'): ?>
            <span style="font-size:.72rem;font-weight:700;color:#F97316;display:flex;align-items:center;gap:4px;">🛵 <?= sanitize($rider['name']) ?> (Rider)</span>
            <span style="font-size:.72rem;font-weight:700;color:#16a34a;display:flex;align-items:center;gap:4px;">🌾 <?= sanitize($o['farmer_name']) ?> (Farmer)</span>
            <span style="font-size:.72rem;font-weight:700;color:#3b82f6;display:flex;align-items:center;gap:4px;">🛒 Your Location</span>
            <?php else: ?>
            <span style="font-size:.72rem;font-weight:700;color:#16a34a;display:flex;align-items:center;gap:4px;">🌾 Your Farm</span>
            <?php if ($buyerCoords): ?>
            <span style="font-size:.72rem;font-weight:700;color:#3b82f6;display:flex;align-items:center;gap:4px;">🛒 Buyer</span>
            <?php endif; ?>
            <?php if ($riderCoords): ?>
            <span style="font-size:.72rem;font-weight:700;color:#F97316;display:flex;align-items:center;gap:4px;">🛵 Assigned Rider</span>
            <?php endif; ?>
            <?php if (count($nearbyRiders) > ($rider ? 1 : 0)): ?>
            <span style="font-size:.72rem;font-weight:700;color:#64748B;display:flex;align-items:center;gap:4px;">🛵 Nearby Riders</span>
            <?php endif; ?>
            <?php endif; ?>
        </div>

<!-- Nearby Riders List -->
        <?php if ($role === 'farmer' && count($nearbyRiders) > 0): ?>
        <div style="margin-top:.85rem;">
            <div style="font-size:.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem;">
                Riders Near Your Farm
            </div>
            <?php foreach (array_slice($nearbyRiders, 0, 5) as $nr): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:.55rem 0;border-bottom:1px solid var(--border);">
                <?php if (!empty($nr['profile_image'])): ?>
                    <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($nr['profile_image']) ?>"
                         style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid #FED7AA;flex-shrink:0;">
                <?php else: ?>
                    <div style="width:36px;height:36px;border-radius:50%;background:#FFF7ED;color:#EA580C;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.9rem;border:2px solid #FED7AA;flex-shrink:0;">
                        <?= strtoupper(substr($nr['name'],0,1)) ?>
                    </div>
                <?php endif; ?>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:800;font-size:.82rem;color:var(--text);">
                        <?= sanitize($nr['name']) ?>
                        <?php if ($rider && $nr['id'] == $rider['id']): ?>
                        <span style="background:#FFF7ED;color:#EA580C;border:1px solid #FED7AA;border-radius:99px;padding:1px 7px;font-size:.62rem;font-weight:800;margin-left:4px;">Assigned</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:.7rem;color:var(--text-muted);">📍 <?= sanitize($nr['location'] ?? '—') ?></div>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <div style="font-size:.78rem;font-weight:800;color:#1D4ED8;">📏 <?= $nr['dist_km'] ?> km</div>
                    <?php if ($nr['phone']): ?>
                    <a href="tel:<?= sanitize($nr['phone']) ?>" style="font-size:.68rem;color:var(--primary);font-weight:700;text-decoration:none;">
                        <i class="fa-solid fa-phone"></i> Call
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php endif; ?>

                <!-- Order Timeline -->
                <div class="dcard">
                    <div class="dcard-head">
                        <i class="fa-solid fa-timeline text-green"></i>
                        <h6>Order Timeline</h6>
                    </div>
                    <div class="dcard-body">
                        <?php
                        $steps = ['pending','confirmed','processing','shipped','completed'];
                        $currentIdx = array_search($o['status'], $steps);
                        $stepLabels = [
                            'pending'    => 'Order Placed',
                            'confirmed'  => 'Confirmed',
                            'processing' => 'Processing',
                            'shipped'    => 'Out for Delivery',
                            'completed'  => 'Delivered',
                        ];
                        ?>
                        <ul class="timeline">
                            <?php foreach ($steps as $idx => $step):
                                if ($o['status'] === 'cancelled') {
                                    $dotClass = 'todo';
                                } elseif ($idx < $currentIdx) {
                                    $dotClass = 'done';
                                } elseif ($idx === $currentIdx) {
                                    $dotClass = 'curr';
                                } else {
                                    $dotClass = 'todo';
                                }
                            ?>
                            <li class="tl-item">
                                <div class="tl-dot <?= $dotClass ?>">
                                    <?php if ($dotClass === 'done'): ?>
                                        <i class="fa-solid fa-check" style="font-size:.6rem;"></i>
                                    <?php elseif ($dotClass === 'curr'): ?>
                                        <i class="fa-solid fa-circle" style="font-size:.45rem;"></i>
                                    <?php else: ?>
                                        <i class="fa-regular fa-circle" style="font-size:.6rem;"></i>
                                    <?php endif; ?>
                                </div>
                                <div style="padding-top:4px;">
                                    <div style="font-size:.82rem;font-weight:<?= $dotClass === 'todo' ? '600' : '800' ?>;color:<?= $dotClass === 'todo' ? 'var(--text-muted)' : 'var(--text)' ?>;">
                                        <?= $stepLabels[$step] ?>
                                    </div>
                                    <?php if ($dotClass === 'curr' && $o['status'] !== 'cancelled'): ?>
                                    <div style="font-size:.72rem;color:#f59e0b;font-weight:700;">Current status</div>
                                    <?php endif; ?>
                                </div>
                            </li>
                            <?php endforeach; ?>
                            <?php if ($o['status'] === 'cancelled'): ?>
                            <li class="tl-item">
                                <div class="tl-dot" style="background:#fee2e2;color:#e11d48;">
                                    <i class="fa-solid fa-xmark" style="font-size:.65rem;"></i>
                                </div>
                                <div style="padding-top:4px;">
                                    <div style="font-size:.82rem;font-weight:800;color:#e11d48;">Order Cancelled</div>
                                </div>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

            </div><!-- end right -->
        </div><!-- end grid -->
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>