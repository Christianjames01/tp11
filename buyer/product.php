<?php
require_once __DIR__ . '/../config/delivery.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$pdo = getDBConnection();
$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: browse.php'); exit(); }

$stmt = $pdo->prepare("SELECT p.*, u.name as farmer_name, u.email as farmer_email, u.phone as farmer_phone, u.location as farmer_city, f.farm_name, f.bio as farm_bio, f.certification, f.rating FROM products p JOIN users u ON p.farmer_id=u.id LEFT JOIN farmers f ON f.user_id=p.farmer_id WHERE p.id=? AND p.is_available=1");
$stmt->execute([$id]);
$product = $stmt->fetch();
if (!$product) { header('Location: browse.php?error=notfound'); exit(); }

// ── Premium Buyer bulk discount on subtotal ───────────────────────────────────
function calcPremiumBulkDiscount(float $qty, float $subtotal, bool $isPremiumBuyer): array {
    if (!$isPremiumBuyer || $qty < 50) return ['discount' => 0.0, 'pct' => 0, 'label' => ''];
    if ($qty < 100)  { $pct = 5;  }
    elseif ($qty < 200) { $pct = 8;  }
    else             { $pct = 12; }
    $discount = round($subtotal * ($pct / 100), 2);
    return ['discount' => $discount, 'pct' => $pct, 'label' => "{$pct}% Premium bulk discount ({$qty}kg+)"];
}

// ── Handle order submission BEFORE any HTML output ───────────────────────────
$orderError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
if (empty($_SESSION['user_id'])) { header('Location: ../auth/login.php'); exit(); }
    if (!in_array($_SESSION['role'], ['buyer', 'farmer'])) { $orderError = 'Only buyers and farmers can place orders.'; }
    else if ($_SESSION['user_id'] == $product['farmer_id']) { $orderError = 'You cannot order your own product.'; }
    else {
        $qty     = floatval($_POST['quantity_kg'] ?? 0);
        $address = trim($_POST['delivery_address'] ?? '');
        $notes   = trim($_POST['notes'] ?? '');

        // Check if buyer is premium
        $premBuyerStmt = $pdo->prepare("SELECT is_premium, premium_until FROM users WHERE id=?");
        $premBuyerStmt->execute([$_SESSION['user_id']]);
        $premBuyerRow = $premBuyerStmt->fetch();
        $buyerIsPremium = $premBuyerRow && !empty($premBuyerRow['is_premium']) && strtotime($premBuyerRow['premium_until']) > time();

        if ($qty < $product['min_order_kg']) {
            $orderError = "Minimum order is {$product['min_order_kg']}kg.";
        } elseif ($qty > $product['stock_kg']) {
            $orderError = "Only {$product['stock_kg']}kg in stock.";
        } elseif (!$address) {
            $orderError = 'Please enter a delivery address.';
        } else {
            $subtotal   = $qty * $product['price_per_kg'];

            // Premium buyer bulk discount on subtotal
            $premiumBulk    = calcPremiumBulkDiscount($qty, $subtotal, $buyerIsPremium);
            $discountedSubtotal = $subtotal - $premiumBulk['discount'];

            $serviceFee    = calcBuyerServiceFee($discountedSubtotal);
            $platformFee   = round($discountedSubtotal * 0.05, 2);
            $farmerPayout  = round($discountedSubtotal - $platformFee, 2);

            $orderDistance    = null;
            $orderDeliveryFee = 0.0;
            $cStmt = $pdo->prepare("SELECT latitude, longitude FROM users WHERE id = ?");
            $cStmt->execute([$_SESSION['user_id']]);
            $bC = $cStmt->fetch();
            $cStmt->execute([$product['farmer_id']]);
            $fC = $cStmt->fetch();
            if ($bC && $fC && $bC['latitude'] && $fC['latitude']) {
                $orderDistance    = haversineDistance($bC['latitude'], $bC['longitude'], $fC['latitude'], $fC['longitude']);
                $rawDelivery      = calcDeliveryFee($orderDistance, $qty);
                $bulkInfo         = calcBulkDeliveryDiscount($qty, $rawDelivery);
                $orderDeliveryFee = $rawDelivery - $bulkInfo['discount'];
            }

            $grandTotal = $discountedSubtotal + $orderDeliveryFee + $serviceFee;

            $proofFilename = null;
            if (isset($_FILES['proof_of_payment']) && $_FILES['proof_of_payment']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
                $finfo   = finfo_open(FILEINFO_MIME_TYPE);
                $mime    = finfo_file($finfo, $_FILES['proof_of_payment']['tmp_name']);
                finfo_close($finfo);
                if (in_array($mime, $allowed) && $_FILES['proof_of_payment']['size'] <= 5 * 1024 * 1024) {
                    $ext           = pathinfo($_FILES['proof_of_payment']['name'], PATHINFO_EXTENSION);
                    $proofFilename = 'proof_' . time() . '_' . uniqid() . '.' . strtolower($ext);
                    $uploadDir     = __DIR__ . '/../assets/images/proofs/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    move_uploaded_file($_FILES['proof_of_payment']['tmp_name'], $uploadDir . $proofFilename);
                }
            }

            $pdo->beginTransaction();
            try {
                $paymentMethod    = trim($_POST['payment_method']    ?? 'Cash on Delivery');
                $paymentReference = trim($_POST['payment_reference'] ?? '');
                $o = $pdo->prepare("INSERT INTO orders (buyer_id, farmer_id, status, total_amount, platform_fee, farmer_payout, delivery_fee, service_fee, distance_km, delivery_address, notes, payment_method, payment_reference, proof_of_payment) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $o->execute([$_SESSION['user_id'], $product['farmer_id'], 'pending', $grandTotal, $platformFee, $farmerPayout, $orderDeliveryFee, $serviceFee, $orderDistance, $address, $notes, $paymentMethod, $paymentReference, $proofFilename]);
                $orderId = $pdo->lastInsertId();
                $oi = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity_kg, price_per_kg, subtotal) VALUES (?,?,?,?,?)");
                $oi->execute([$orderId, $product['id'], $qty, $product['price_per_kg'], $discountedSubtotal]);
                $pdo->prepare("UPDATE products SET stock_kg = stock_kg - ? WHERE id=?")->execute([$qty, $product['id']]);
                $pdo->commit();
                if (function_exists('setFlash')) setFlash('success', "Order #$orderId placed! The farmer will confirm soon. 🌾");
                else $_SESSION['flash']['success'] = "Order #$orderId placed! The farmer will confirm soon. 🌾";
                header("Location: ../orders/detail.php?id=$orderId"); exit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $orderError = 'Order failed: ' . $e->getMessage();
            }
        }
    }
}

$page_title = 'Product Details';
require_once __DIR__ . '/../includes/header.php';

$stmt = $pdo->prepare("SELECT p.*, u.name as farmer_name, u.email as farmer_email, u.phone as farmer_phone, u.location as farmer_city, f.farm_name, f.bio as farm_bio, f.certification, f.rating FROM products p JOIN users u ON p.farmer_id=u.id LEFT JOIN farmers f ON f.user_id=p.farmer_id WHERE p.id=? AND p.is_available=1");
$stmt->execute([$id]);
$product = $stmt->fetch();
if (!$product) { header('Location: browse.php?error=notfound'); exit(); }

function calcBuyerServiceFee(float $subtotal): float {
    if ($subtotal < 500)   return 50.0;
    if ($subtotal < 2000)  return 100.0;
    return 150.0;
}

// Check if current user can buy (buyer OR farmer)
$canBuy = isLoggedIn() && in_array($_SESSION['role'], ['buyer', 'farmer']);

// Check if current buyer is premium
$buyerIsPremium = false;
if ($canBuy) {
    $premCheck = $pdo->prepare("SELECT is_premium, premium_until FROM users WHERE id=?");
    $premCheck->execute([$_SESSION['user_id']]);
    $premRow = $premCheck->fetch();
    $buyerIsPremium = $premRow && !empty($premRow['is_premium']) && strtotime($premRow['premium_until']) > time();
}

$buyerCoords  = null;
$farmerCoords = null;
if ($canBuy) {
    $cStmt = $pdo->prepare("SELECT latitude, longitude FROM users WHERE id = ?");
    $cStmt->execute([$_SESSION['user_id']]);
    $bCoords = $cStmt->fetch();
    if ($bCoords && $bCoords['latitude'] && $bCoords['longitude']) {
        $buyerCoords = $bCoords;
    }
    $cStmt->execute([$product['farmer_id']]);
    $fCoords = $cStmt->fetch();
    if ($fCoords && $fCoords['latitude'] && $fCoords['longitude']) {
        $farmerCoords = $fCoords;
    }
}
$distanceKm  = null;
$deliveryFee = 0.0;
if ($buyerCoords && $farmerCoords) {
    $distanceKm  = haversineDistance(
        $buyerCoords['latitude'],  $buyerCoords['longitude'],
        $farmerCoords['latitude'], $farmerCoords['longitude']
    );
    $deliveryFee = calcDeliveryFee($distanceKm, $product['min_order_kg']);
}

$previewQty          = $product['min_order_kg'];
$previewSubtotal     = $previewQty * $product['price_per_kg'];
$previewPremiumBulk  = calcPremiumBulkDiscount($previewQty, $previewSubtotal, $buyerIsPremium);
$previewDiscSubtotal = $previewSubtotal - $previewPremiumBulk['discount'];
$previewServiceFee   = calcBuyerServiceFee($previewDiscSubtotal);
$previewPlatformFee  = round($previewDiscSubtotal * 0.05, 2);
$previewBulk         = calcBulkDeliveryDiscount($previewQty, $deliveryFee);
$previewDelivery     = $deliveryFee - $previewBulk['discount'];

$emojis = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Crops'=>'🌾','Coffee'=>'☕','Livestock'=>'🐄','Seafood'=>'🐟','Others'=>'📦'];
?>

<!-- Lightbox Modal -->
<div id="imgModal" onclick="closeModal()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:9999;align-items:center;justify-content:center;cursor:zoom-out;backdrop-filter:blur(6px);">
    <button onclick="modalNav(-1,event)" style="position:absolute;left:16px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);border:none;border-radius:50%;width:46px;height:46px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:white;font-size:1.1rem;transition:all .2s;z-index:10;"
            onmouseover="this.style.background='rgba(255,255,255,.3)'"
            onmouseout="this.style.background='rgba(255,255,255,.15)'">
        <i class="fa-solid fa-chevron-left"></i>
    </button>
    <div style="max-width:90vw;max-height:90vh;position:relative;" onclick="event.stopPropagation()">
        <div id="modalContent" style="border-radius:16px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.5);"></div>
        <div id="modalCaption" style="text-align:center;color:rgba(255,255,255,.7);font-size:.8rem;margin-top:.6rem;font-weight:600;"></div>
    </div>
    <button onclick="modalNav(1,event)" style="position:absolute;right:16px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);border:none;border-radius:50%;width:46px;height:46px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:white;font-size:1.1rem;transition:all .2s;z-index:10;"
            onmouseover="this.style.background='rgba(255,255,255,.3)'"
            onmouseout="this.style.background='rgba(255,255,255,.15)'">
        <i class="fa-solid fa-chevron-right"></i>
    </button>
    <button onclick="closeModal()" style="position:absolute;top:16px;right:16px;background:rgba(255,255,255,.15);border:none;border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:white;font-size:.9rem;z-index:10;"
            onmouseover="this.style.background='rgba(255,255,255,.3)'"
            onmouseout="this.style.background='rgba(255,255,255,.15)'">
        <i class="fa-solid fa-xmark"></i>
    </button>
</div>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">
    <div class="page-header">
        <div class="container">
            <div class="page-breadcrumb"><a href="browse.php">Browse</a> › <?= sanitize($product['name']) ?></div>
        </div>
    </div>

    <div class="container">
        <div class="row g-4">
            <!-- Product Image -->
            <div class="col-lg-5">
                <div style="background:white;border-radius:var(--radius-lg);overflow:hidden;box-shadow:var(--shadow-sm);border:1px solid var(--border);">
                   <?php
$slides = [];
if ($product['image']) {
    $slides[] = ['type' => 'img', 'src' => BASE_URL . '/assets/images/products/' . sanitize($product['image'])];
}
$extraStmt = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY sort_order ASC LIMIT 3");
$extraStmt->execute([$product['id']]);
foreach ($extraStmt->fetchAll() as $img) {
    $slides[] = ['type' => 'img', 'src' => BASE_URL . '/assets/images/products/' . sanitize($img['image'])];
}
// Only add emoji placeholder if NO images were uploaded at all
if (count($slides) === 0) {
    $slides[] = ['type' => 'emoji', 'val' => $emojis[$product['category']] ?? '🌾'];
}
$totalSlides = count($slides);
?>

<!-- Carousel -->
<div style="position:relative;overflow:hidden;" id="carousel">
    <div id="carouselTrack" style="display:flex;transition:transform .4s cubic-bezier(.4,0,.2,1);will-change:transform;">
        <?php foreach ($slides as $i => $slide): ?>
        <div class="carousel-slide" style="min-width:100%;height:350px;flex-shrink:0;cursor:zoom-in;" onclick="openModal(<?= $i ?>)">
            <?php if ($slide['type'] === 'img'): ?>
                <img src="<?= $slide['src'] ?>" style="width:100%;height:100%;object-fit:cover;" alt="<?= sanitize($product['name']) ?>">
            <?php else: ?>
                <div style="height:100%;background:linear-gradient(135deg,var(--pale-green),var(--light-green));display:flex;align-items:center;justify-content:center;font-size:7rem;">
                    <?= $slide['val'] ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <button onclick="moveCarousel(-1)" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.88);border:none;border-radius:50%;width:38px;height:38px;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 10px rgba(0,0,0,.15);z-index:10;font-size:.9rem;color:var(--text);transition:all .2s;"
            onmouseover="this.style.transform='translateY(-50%) scale(1.08)'"
            onmouseout="this.style.transform='translateY(-50%)'">
        <i class="fa-solid fa-chevron-left"></i>
    </button>
    <button onclick="moveCarousel(1)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.88);border:none;border-radius:50%;width:38px;height:38px;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 10px rgba(0,0,0,.15);z-index:10;font-size:.9rem;color:var(--text);transition:all .2s;"
            onmouseover="this.style.transform='translateY(-50%) scale(1.08)'"
            onmouseout="this.style.transform='translateY(-50%)'">
        <i class="fa-solid fa-chevron-right"></i>
    </button>

    <div style="position:absolute;bottom:10px;right:10px;background:rgba(0,0,0,.45);color:white;font-size:.65rem;font-weight:700;padding:4px 9px;border-radius:20px;pointer-events:none;display:flex;align-items:center;gap:4px;">
        <i class="fa-solid fa-magnifying-glass-plus"></i> Click to zoom
    </div>

    <div style="position:absolute;bottom:10px;left:50%;transform:translateX(-50%);display:flex;gap:6px;z-index:10;" id="dotWrap">
        <?php for ($i = 0; $i < $totalSlides; $i++): ?>
        <button onclick="goToSlide(<?= $i ?>)" id="dot<?= $i ?>"
                style="width:<?= $i===0?'22':'8' ?>px;height:8px;border-radius:20px;border:none;background:<?= $i===0?'var(--primary)':'rgba(255,255,255,.6)' ?>;cursor:pointer;transition:all .3s;padding:0;"></button>
        <?php endfor; ?>
    </div>
</div>

<!-- Thumbnail strip -->
<div style="display:flex;gap:8px;padding:10px 12px;overflow-x:auto;scrollbar-width:none;" id="thumbStrip">
    <?php foreach ($slides as $i => $slide): ?>
    <div onclick="goToSlide(<?= $i ?>)" id="thumb<?= $i ?>"
         style="min-width:58px;height:58px;border-radius:10px;overflow:hidden;cursor:pointer;border:2.5px solid <?= $i===0?'var(--primary)':'var(--border)' ?>;transition:all .2s;flex-shrink:0;opacity:<?= $i===0?'1':'.65' ?>;">
        <?php if ($slide['type'] === 'img'): ?>
            <img src="<?= $slide['src'] ?>" style="width:100%;height:100%;object-fit:cover;">
        <?php else: ?>
            <div style="height:100%;background:linear-gradient(135deg,var(--pale-green),var(--light-green));display:flex;align-items:center;justify-content:center;font-size:1.5rem;">
                <?= $slide['val'] ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
                    <div style="padding:1.2rem;">
                        <div class="d-flex gap-2 flex-wrap">
                            <span class="badge-category"><?= sanitize($product['category']) ?></span>
                            <?php if ($product['is_organic']): ?><span class="badge-organic" style="position:static;">🌿 Organic</span><?php endif; ?>
                            <?php if ($product['certification']): ?><span style="background:#E3F2FD;color:#1565C0;font-size:0.65rem;padding:3px 8px;border-radius:20px;font-weight:700;"><?= sanitize($product['certification']) ?></span><?php endif; ?>
                        </div>
                 <!-- Farmer Info -->
<?php
$farmerProfileStmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
$farmerProfileStmt->execute([$product['farmer_id']]);
$farmerProfileRow = $farmerProfileStmt->fetch();
$farmerProfileImg = $farmerProfileRow['profile_image'] ?? null;
$premCheck2 = $pdo->prepare("SELECT is_premium, premium_until FROM farmers WHERE user_id = ?");
$premCheck2->execute([$product['farmer_id']]);
$premRow2 = $premCheck2->fetch();
$isFarmerPremiumProduct = $premRow2 && $premRow2['is_premium'] && strtotime($premRow2['premium_until']) > time();
?>
<div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);<?= $isFarmerPremiumProduct ? 'background:linear-gradient(135deg,#fffbeb,#fef3c7,#fde68a);border:1.5px solid #f59e0b;border-radius:var(--radius-lg);padding:1rem;box-shadow:0 4px 18px rgba(245,158,11,.18);margin-top:.75rem;' : '' ?>">
<div style="display:flex;align-items:center;gap:10px;">
    <?php if ($farmerProfileImg): ?>
    <img src="<?= BASE_URL ?>/assets/images/profiles/<?= sanitize($farmerProfileImg) ?>"
         style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid var(--primary);flex-shrink:0;">
    <?php else: ?>
    <div style="width:44px;height:44px;background:linear-gradient(135deg,var(--primary),var(--primary-light));border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:1.1rem;flex-shrink:0;">
        <?= strtoupper(substr($product['farmer_name'],0,1)) ?>
    </div>
    <?php endif; ?>
                                <div>
<div style="font-weight:800;color:var(--text);"><?= sanitize($product['farm_name'] ?? $product['farmer_name']) ?></div>
<div style="font-size:0.78rem;color:var(--text-muted);">📍 <?= sanitize($product['farmer_city']) ?></div>
<?php
if ($isFarmerPremiumProduct):
?>
<div style="margin-top:5px;display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
    <span style="background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.6rem;font-weight:800;padding:3px 10px;border-radius:99px;letter-spacing:.04em;box-shadow:0 2px 6px rgba(217,119,6,.35);">⭐ PREMIUM SELLER</span>
    <span style="font-size:.65rem;color:#d97706;font-weight:700;">Verified Farmer</span>
</div>
<?php endif; ?>
                                </div>
                            </div>
                            <?php if ($product['farm_bio']): ?>
                            <p style="font-size:0.82rem;color:var(--text-muted);margin:0.6rem 0 0;line-height:1.5;"><?= sanitize($product['farm_bio']) ?></p>
                            <?php endif; ?>
                            <?php if (isLoggedIn()): ?>
                            <a href="../messages/index.php?to=<?= $product['farmer_id'] ?>" class="btn-outline-green mt-2" style="padding:0.4rem 1rem;font-size:0.82rem;"><i class="fa-solid fa-comments"></i> Message Farmer</a>
                            <?php endif; ?>
                        </div>

                        <?php if ($farmerCoords): ?>
                        <!-- Location Map -->
                        <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);">
                            <div style="font-size:0.8rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:0.6rem;">
                                <i class="fa-solid fa-map text-green me-1"></i> Location Map
                            </div>
                            <?php if ($distanceKm !== null): ?>
                            <div style="display:flex;gap:.5rem;margin-bottom:.6rem;flex-wrap:wrap;">
                                <span style="background:#dbeafe;color:#1d4ed8;border-radius:99px;padding:3px 10px;font-size:.7rem;font-weight:800;">
                                    <i class="fa-solid fa-route me-1"></i><?= number_format($distanceKm,1) ?> km away
                                </span>
                                <span style="background:<?= $deliveryFee > 0 ? '#fff7ed' : '#dcfce7' ?>;color:<?= $deliveryFee > 0 ? '#ea580c' : '#16a34a' ?>;border-radius:99px;padding:3px 10px;font-size:.7rem;font-weight:800;">
                                    <i class="fa-solid fa-truck me-1"></i><?= $deliveryFee > 0 ? '₱'.number_format($deliveryFee,2).' delivery' : 'Free delivery' ?>
                                </span>
                                <span style="background:#f0fdf4;color:#15803d;border-radius:99px;padding:3px 10px;font-size:.7rem;font-weight:800;">
                                    <i class="fa-solid fa-truck-fast me-1"></i><?= deliveryFeeLabel($distanceKm) ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
                            <div id="productMap" style="height:220px;border-radius:12px;border:1.5px solid var(--border);"></div>
                            <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                            <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                const farmerLat = <?= floatval($farmerCoords['latitude']) ?>;
                                const farmerLng = <?= floatval($farmerCoords['longitude']) ?>;
                                <?php if ($buyerCoords): ?>
                                const buyerLat  = <?= floatval($buyerCoords['latitude']) ?>;
                                const buyerLng  = <?= floatval($buyerCoords['longitude']) ?>;
                                const hasbuyer  = true;
                                <?php else: ?>
                                const hasbuyer  = false;
                                <?php endif; ?>
                                const centerLat = hasbuyer ? (farmerLat + <?= $buyerCoords ? floatval($buyerCoords['latitude']) : 'farmerLat' ?>) / 2 : farmerLat;
                                const centerLng = hasbuyer ? (farmerLng + <?= $buyerCoords ? floatval($buyerCoords['longitude']) : 'farmerLng' ?>) / 2 : farmerLng;
                                const map = L.map('productMap').setView([centerLat, centerLng], hasbuyer ? 9 : 13);
                                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);
                                const farmerIcon = L.divIcon({
                                    html: '<div style="background:#16a34a;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.3);font-size:15px;">🌾</div>',
                                    className: '', iconSize:[34,34], iconAnchor:[17,17], popupAnchor:[0,-20]
                                });
                                L.marker([farmerLat, farmerLng], {icon: farmerIcon})
                                    .addTo(map)
                                    .bindPopup('<strong>🌾 <?= addslashes(sanitize($product['farm_name'] ?? $product['farmer_name'])) ?></strong><br><small>📍 <?= addslashes(sanitize($product['farmer_city'])) ?></small>')
                                    .openPopup();
                                <?php if ($buyerCoords): ?>
                                const buyerIcon = L.divIcon({
                                    html: '<div style="background:#3b82f6;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.3);font-size:15px;">🛒</div>',
                                    className: '', iconSize:[34,34], iconAnchor:[17,17], popupAnchor:[0,-20]
                                });
                                L.marker([buyerLat, buyerLng], {icon: buyerIcon})
                                    .addTo(map)
                                    .bindPopup('<strong>🛒 Your Location</strong><br><small>Delivery destination</small>');
                                L.polyline([[farmerLat, farmerLng],[buyerLat, buyerLng]], {color:'#3b82f6', weight:2, dashArray:'7,5', opacity:.65}).addTo(map);
                                map.fitBounds([[farmerLat,farmerLng],[buyerLat,buyerLng]], {padding:[30,30]});
                                <?php endif; ?>
                            });
                            </script>
                            <div style="display:flex;gap:.5rem;margin-top:.5rem;flex-wrap:wrap;">
                                <span style="font-size:.7rem;color:#16a34a;font-weight:700;">🌾 Farmer location</span>
                                <?php if ($buyerCoords): ?>
                                <span style="font-size:.7rem;color:#3b82f6;font-weight:700;">&nbsp;·&nbsp; 🛒 Your location</span>
                                <?php else: ?>
                                <span style="font-size:.7rem;color:#ea580c;font-weight:600;">&nbsp;·&nbsp; <i class="fa-solid fa-location-dot"></i> Pin your location in profile to see distance</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Product Details & Order -->
            <div class="col-lg-7">
                <div class="gl-card mb-3">
                    <div class="gl-card-body">
                        <h1 style="font-family:'Playfair Display',serif;font-size:2rem;color:var(--text);margin-bottom:0.3rem;"><?= sanitize($product['name']) ?></h1>
                        <div style="font-size:2.2rem;font-weight:800;color:var(--primary);font-family:'Playfair Display',serif;">
                            ₱<?= number_format($product['price_per_kg'],2) ?><span style="font-size:1rem;font-weight:500;color:var(--text-muted);font-family:'Nunito',sans-serif;"> / kg</span>
                        </div>
                        <div class="row g-2 mt-3">
                            <?php $meta = [
                                ['fa-location-dot','Location', sanitize($product['location'] ?: $product['farmer_city'])],
                                ['fa-calendar-days','Harvest Date', $product['harvest_date'] ? date('M j, Y',strtotime($product['harvest_date'])) : 'Fresh'],
                                ['fa-weight-scale','Available Stock', number_format($product['stock_kg'],1).' kg'],
                                ['fa-scale-balanced','Min. Order', $product['min_order_kg'].' kg'],
                            ]; foreach ($meta as $m): ?>
                            <div class="col-6">
                                <div style="background:var(--bg);border-radius:10px;padding:0.8rem;">
                                    <div style="font-size:0.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;">
                                        <i class="fa-solid <?= $m[0] ?> text-green me-1"></i><?= $m[1] ?>
                                    </div>
                                    <div style="font-weight:700;font-size:0.9rem;color:var(--text);"><?= $m[2] ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Premium buyer bulk discount banner -->
                        <?php if ($buyerIsPremium): ?>
                        <div style="margin-top:1rem;background:linear-gradient(135deg,#EFF6FF,#DBEAFE);border:1.5px solid #93C5FD;border-radius:12px;padding:.75rem 1rem;">
                            <div style="font-size:.72rem;font-weight:800;color:#1D4ED8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem;">
                                💼 Premium Buyer — Bulk Discounts on Product Price
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                                <span style="background:white;border:1px solid #93C5FD;border-radius:99px;padding:2px 10px;font-size:.68rem;font-weight:700;color:#1D4ED8;">50–99 kg → 5% off subtotal</span>
                                <span style="background:white;border:1px solid #93C5FD;border-radius:99px;padding:2px 10px;font-size:.68rem;font-weight:700;color:#1D4ED8;">100–199 kg → 8% off</span>
                                <span style="background:#1D4ED8;color:white;border-radius:99px;padding:2px 10px;font-size:.68rem;font-weight:700;">200+ kg → 12% off 💼</span>
                            </div>
                        </div>
                        <?php else: ?>
                        <!-- Delivery bulk discount info banner (non-premium buyers) -->
                        <div style="margin-top:1rem;background:linear-gradient(135deg,#ecfdf5,#d1fae5);border:1.5px solid #6ee7b7;border-radius:12px;padding:.75rem 1rem;">
                            <div style="font-size:.72rem;font-weight:800;color:#065f46;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem;">
                                <i class="fa-solid fa-boxes-stacked me-1"></i> Bulk Order Delivery Discounts
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                                <span style="background:white;border:1px solid #6ee7b7;border-radius:99px;padding:2px 10px;font-size:.68rem;font-weight:700;color:#065f46;">50–99 kg → 10% off delivery</span>
                                <span style="background:white;border:1px solid #6ee7b7;border-radius:99px;padding:2px 10px;font-size:.68rem;font-weight:700;color:#065f46;">100–199 kg → 15% off</span>
                                <span style="background:white;border:1px solid #6ee7b7;border-radius:99px;padding:2px 10px;font-size:.68rem;font-weight:700;color:#065f46;">200–499 kg → 20% off</span>
                                <span style="background:#065f46;color:white;border-radius:99px;padding:2px 10px;font-size:.68rem;font-weight:700;">500+ kg → 25% off 🏆</span>
                            </div>
                            <div style="margin-top:.5rem;font-size:.65rem;color:#065f46;opacity:.75;">
                                <i class="fa-solid fa-crown me-1"></i> <a href="../buyer/premium.php" style="color:#1565C0;font-weight:700;text-decoration:none;">Upgrade to Premium</a> to unlock product price discounts too!
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($product['description']): ?>
                        <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);">
                            <div style="font-size:0.8rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:0.4rem;">Description</div>
                            <p style="color:var(--text);font-size:0.9rem;line-height:1.7;margin:0;"><?= nl2br(sanitize($product['description'])) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Order Form -->
               <?php if ($canBuy && isset($_SESSION['user_id']) && $_SESSION['user_id'] != $product['farmer_id']): ?>
                <div class="gl-card">
                    <div class="gl-card-body">
                        <h5 style="font-weight:800;margin-bottom:1.2rem;"><i class="fa-solid fa-cart-plus text-green me-2"></i>Place Order</h5>
                        <?php if ($orderError): ?>
                        <div style="background:#FFF5F5;border:1px solid #FED7D7;color:#C53030;border-radius:10px;padding:0.75rem 1rem;margin-bottom:1rem;font-size:0.88rem;font-weight:600;">
                            <i class="fa-solid fa-triangle-exclamation me-2"></i><?= sanitize($orderError) ?>
                        </div>
                        <?php endif; ?>
                        <form method="POST" action="" enctype="multipart/form-data">
                            <div class="gl-form-group">
                                <label>Quantity (kg) — Min. <?= $product['min_order_kg'] ?>kg</label>
                                <div class="gl-input-wrap">
                                    <i class="fa-solid fa-weight-scale input-icon"></i>
                                    <input type="number" name="quantity_kg" class="gl-input"
                                           placeholder="<?= $product['min_order_kg'] ?>"
                                           step="0.5"
                                           min="<?= $product['min_order_kg'] ?>"
                                           max="<?= $product['stock_kg'] ?>"
                                           value="<?= $_POST['quantity_kg'] ?? $product['min_order_kg'] ?>"
                                           id="qtyInput"
                                           oninput="calcTotal()">
                                </div>
                            </div>

                            <!-- Order Summary -->
                            <div style="background:var(--pale-green);border-radius:12px;padding:1rem;margin-bottom:1rem;">

                                <!-- Original Subtotal -->
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                                    <span style="font-weight:700;color:var(--text-muted);">Subtotal:</span>
                                    <span id="subtotalPrice" style="font-size:1rem;font-weight:800;color:var(--text);">₱<?= number_format($previewSubtotal,2) ?></span>
                                </div>

                                <!-- Premium Bulk Discount row (only for premium buyers, hidden until qty >= 50) -->
                                <?php if ($buyerIsPremium): ?>
                                <div id="premiumBulkRow" style="display:<?= $previewPremiumBulk['discount'] > 0 ? 'flex' : 'none' ?>;justify-content:space-between;align-items:center;margin-bottom:4px;font-size:.72rem;background:#DBEAFE;border-radius:8px;padding:3px 8px;margin-top:2px;">
                                    <span style="color:#1D4ED8;display:flex;align-items:center;gap:4px;">
                                        <i class="fa-solid fa-crown"></i>
                                        <span id="premiumBulkLabel"><?= $previewPremiumBulk['label'] ?></span>
                                    </span>
                                    <span id="premiumBulkAmt" style="font-weight:800;color:#1D4ED8;">-₱<?= number_format($previewPremiumBulk['discount'],2) ?></span>
                                </div>
                                <?php endif; ?>

                                <?php if ($distanceKm !== null): ?>
                                <!-- Delivery fee -->
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2px;font-size:.78rem;">
                                    <span style="color:var(--text-muted);display:flex;align-items:center;gap:4px;">
                                        <i class="fa-solid fa-route" style="color:#3b82f6;"></i>
                                        Delivery (<?= number_format($distanceKm,1) ?> km · <?= deliveryFeeLabel($distanceKm) ?>):
                                    </span>
                                    <span id="deliveryFeeDisplay" style="font-weight:800;color:#3b82f6;">
                                        <?= $previewDelivery > 0 ? '₱'.number_format($previewDelivery,2) : 'FREE' ?>
                                    </span>
                                </div>

                                <!-- Delivery bulk discount row -->
                                <div id="bulkDiscountRow" style="display:<?= $previewBulk['discount'] > 0 ? 'flex' : 'none' ?>;justify-content:space-between;align-items:center;margin-bottom:4px;font-size:.72rem;background:#dcfce7;border-radius:8px;padding:3px 8px;margin-top:2px;">
                                    <span style="color:#15803d;display:flex;align-items:center;gap:4px;">
                                        <i class="fa-solid fa-tag"></i>
                                        <span id="bulkDiscountLabel"><?= $previewBulk['label'] ?></span>
                                    </span>
                                    <span id="bulkDiscountAmt" style="font-weight:800;color:#15803d;">-₱<?= number_format($previewBulk['discount'],2) ?></span>
                                </div>
                                <?php endif; ?>

                                <!-- Buyer service fee -->
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;font-size:.78rem;">
                                    <span style="color:var(--text-muted);display:flex;align-items:center;gap:4px;">
                                        <i class="fa-solid fa-handshake" style="color:#7c3aed;"></i>
                                        Service fee:
                                        <span id="serviceFeeNote" style="font-size:.65rem;color:#94a3b8;font-style:italic;">(coordination &amp; handling)</span>
                                    </span>
                                    <span id="serviceFeeDisplay" style="font-weight:800;color:#7c3aed;">₱<?= number_format($previewServiceFee,2) ?></span>
                                </div>

                                <!-- Farmer commission note -->
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;font-size:.72rem;opacity:.75;">
                                    <span style="color:var(--text-muted);display:flex;align-items:center;gap:4px;">
                                        <i class="fa-solid fa-seedling" style="color:var(--primary);"></i>
                                        Farmer commission (5%):
                                    </span>
                                    <span id="platformFeeDisplay" style="font-weight:700;color:var(--primary);">₱<?= number_format($previewPlatformFee,2) ?></span>
                                </div>

                                <!-- Grand Total -->
                                <div style="border-top:1px dashed var(--primary);margin-top:6px;padding-top:6px;display:flex;justify-content:space-between;align-items:center;">
                                    <span style="font-weight:800;color:var(--text-muted);">Total you pay:</span>
                                    <span id="totalPrice" style="font-size:1.3rem;font-weight:800;color:var(--primary);font-family:'Playfair Display',serif;">₱<?= number_format($previewDiscSubtotal + $previewDelivery + $previewServiceFee,2) ?></span>
                                </div>

                                <?php if ($distanceKm === null): ?>
                                <div style="margin-top:6px;font-size:.72rem;color:#ea580c;display:flex;align-items:center;gap:4px;">
                                    <i class="fa-solid fa-location-dot"></i>
                                    <span>Set your location in your profile to see delivery fee</span>
                                </div>
                                <?php endif; ?>

                                <!-- Fee breakdown legend -->
                                <div style="margin-top:.75rem;padding-top:.6rem;border-top:1px solid rgba(0,0,0,.06);display:flex;flex-direction:column;gap:3px;">
                                    <?php if ($buyerIsPremium): ?>
                                    <div style="font-size:.65rem;color:#1D4ED8;line-height:1.5;font-weight:700;">
                                        <i class="fa-solid fa-crown" style="color:#1D4ED8;"></i>
                                        <strong>Premium discount</strong>: 5% (50–99 kg) · 8% (100–199 kg) · 12% (200+ kg) off subtotal
                                    </div>
                                    <?php endif; ?>
                                    <div style="font-size:.65rem;color:#64748b;line-height:1.5;">
                                        <i class="fa-solid fa-circle-info" style="color:#3b82f6;"></i>
                                        <strong>Delivery fee</strong> is distance-based — exact fare shown above.
                                    </div>
                                    <div style="font-size:.65rem;color:#64748b;line-height:1.5;">
                                        <i class="fa-solid fa-circle-info" style="color:#7c3aed;"></i>
                                        <strong>Service fee</strong>: ₱50 (under ₱500) · ₱100 (₱500–₱1,999) · ₱150 (₱2,000+)
                                    </div>
                                    <div style="font-size:.65rem;color:#64748b;line-height:1.5;">
                                        <i class="fa-solid fa-circle-info" style="color:var(--primary);"></i>
                                        <strong>Farmer commission</strong> (5%) is deducted from farmer's payout — not an extra charge to you.
                                    </div>
                                </div>
                            </div>

                            <div class="gl-form-group">
                                <label>Delivery Address</label>
                                <div class="gl-input-wrap">
                                    <i class="fa-solid fa-location-dot input-icon" style="top:14px;transform:none;"></i>
                                    <?php
                              $buyerAddress = '';
                                    if ($canBuy) {
                                        $addrStmt = $pdo->prepare("SELECT location FROM users WHERE id = ?");
                                        $addrStmt->execute([$_SESSION['user_id']]);
                                        $buyerAddr    = $addrStmt->fetch();
                                        $buyerAddress = $_POST['delivery_address'] ?? ($buyerAddr['location'] ?? '');
                                    }
                                    ?>
                                    <textarea name="delivery_address" class="gl-input" placeholder="Full delivery address..." rows="2"><?= sanitize($buyerAddress) ?></textarea>
                                </div>
                            </div>
                            <div class="gl-form-group">
                                <label>Notes (Optional)</label>
                                <div class="gl-input-wrap">
                                    <i class="fa-solid fa-note-sticky input-icon" style="top:14px;transform:none;"></i>
                                    <textarea name="notes" class="gl-input" placeholder="Special instructions..." rows="2"><?= sanitize($_POST['notes'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <!-- Proof of Payment Upload -->
                            <div class="gl-form-group" id="proofUploadWrap" style="display:none;">
                                <label style="font-size:.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;">
                                    <i class="fa-solid fa-receipt" style="color:var(--primary);"></i> Proof of Payment
                                </label>
                                <div style="border:2px dashed var(--border);border-radius:12px;padding:1.2rem;text-align:center;background:var(--bg);cursor:pointer;transition:all .2s;"
                                     onclick="document.getElementById('proofFile').click()"
                                     id="proofDropZone">
                                    <i class="fa-solid fa-cloud-arrow-up" style="font-size:1.6rem;color:var(--primary);margin-bottom:.4rem;display:block;"></i>
                                    <div style="font-size:.82rem;font-weight:700;color:var(--text);">Click to upload screenshot</div>
                                    <div style="font-size:.7rem;color:var(--text-muted);">JPG, PNG up to 5MB</div>
                                    <div id="proofFileName" style="font-size:.72rem;color:var(--primary);font-weight:700;margin-top:.4rem;display:none;"></div>
                                </div>
                                <input type="file" id="proofFile" name="proof_of_payment" accept="image/*" style="display:none;" onchange="handleProofUpload(this)">
                            </div>

                            <!-- Payment Method -->
                            <div class="gl-form-group">
                                <label style="font-size:.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;display:flex;align-items:center;gap:6px;">
                                    <i class="fa-solid fa-lock" style="color:var(--primary);"></i> Payment Method
                                </label>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">

                                    <label class="pm-card" data-m="gcash" style="position:relative;cursor:pointer;">
                                        <input type="radio" name="payment_method" value="GCash" required style="position:absolute;opacity:0;pointer-events:none;" onchange="selectPayment('gcash')">
                                        <div class="pm-inner" id="pmi_gcash" style="border:2px solid var(--border);border-radius:14px;padding:.85rem .9rem;background:white;transition:all .2s;display:flex;flex-direction:column;gap:6px;height:100%;">
                                            <div class="pm-check" id="pmck_gcash" style="position:absolute;top:8px;right:8px;width:18px;height:18px;border-radius:50%;background:#0070F0;display:flex;align-items:center;justify-content:center;font-size:.6rem;color:white;opacity:0;transition:opacity .2s;"><i class="fa-solid fa-check"></i></div>
                                            <img src="../assets/images/logo/gcash.jpg" style="height:22px;object-fit:contain;object-position:left;" alt="GCash">
                                            <div><div style="font-size:.8rem;font-weight:700;color:var(--text);">GCash</div><div style="font-size:.65rem;color:var(--text-muted);">Instant · e-wallet</div></div>
                                        </div>
                                    </label>

                                    <label class="pm-card" data-m="maya" style="position:relative;cursor:pointer;">
                                        <input type="radio" name="payment_method" value="Maya" required style="position:absolute;opacity:0;pointer-events:none;" onchange="selectPayment('maya')">
                                        <div class="pm-inner" id="pmi_maya" style="border:2px solid var(--border);border-radius:14px;padding:.85rem .9rem;background:white;transition:all .2s;display:flex;flex-direction:column;gap:6px;height:100%;">
                                            <div class="pm-check" id="pmck_maya" style="position:absolute;top:8px;right:8px;width:18px;height:18px;border-radius:50%;background:#00B14F;display:flex;align-items:center;justify-content:center;font-size:.6rem;color:white;opacity:0;transition:opacity .2s;"><i class="fa-solid fa-check"></i></div>
                                            <img src="../assets/images/logo/maya.jpg" style="height:22px;object-fit:contain;object-position:left;" alt="Maya">
                                            <div><div style="font-size:.8rem;font-weight:700;color:var(--text);">Maya</div><div style="font-size:.65rem;color:var(--text-muted);">Instant · e-wallet</div></div>
                                        </div>
                                    </label>

                                    <label class="pm-card" data-m="bank" style="position:relative;cursor:pointer;">
                                        <input type="radio" name="payment_method" value="Bank Transfer" required style="position:absolute;opacity:0;pointer-events:none;" onchange="selectPayment('bank')">
                                        <div class="pm-inner" id="pmi_bank" style="border:2px solid var(--border);border-radius:14px;padding:.85rem .9rem;background:white;transition:all .2s;display:flex;flex-direction:column;gap:6px;height:100%;">
                                            <div class="pm-check" id="pmck_bank" style="position:absolute;top:8px;right:8px;width:18px;height:18px;border-radius:50%;background:#1d4ed8;display:flex;align-items:center;justify-content:center;font-size:.6rem;color:white;opacity:0;transition:opacity .2s;"><i class="fa-solid fa-check"></i></div>
                                            <div style="width:26px;height:22px;display:flex;align-items:center;"><i class="fa-solid fa-building-columns" style="color:#1d4ed8;font-size:1.1rem;"></i></div>
                                            <div><div style="font-size:.8rem;font-weight:700;color:var(--text);">Bank Transfer</div><div style="font-size:.65rem;color:var(--text-muted);">BDO, BPI, UnionBank</div></div>
                                        </div>
                                    </label>

                                    <label class="pm-card" data-m="cod" style="position:relative;cursor:pointer;">
                                        <input type="radio" name="payment_method" value="Cash on Delivery" required style="position:absolute;opacity:0;pointer-events:none;" onchange="selectPayment('cod')">
                                        <div class="pm-inner" id="pmi_cod" style="border:2px solid var(--border);border-radius:14px;padding:.85rem .9rem;background:white;transition:all .2s;display:flex;flex-direction:column;gap:6px;height:100%;">
                                            <div class="pm-check" id="pmck_cod" style="position:absolute;top:8px;right:8px;width:18px;height:18px;border-radius:50%;background:#D97706;display:flex;align-items:center;justify-content:center;font-size:.6rem;color:white;opacity:0;transition:opacity .2s;"><i class="fa-solid fa-check"></i></div>
                                            <div style="width:26px;height:22px;display:flex;align-items:center;"><i class="fa-solid fa-money-bill-wave" style="color:#D97706;font-size:1.1rem;"></i></div>
                                            <div><div style="font-size:.8rem;font-weight:700;color:var(--text);">Cash on Delivery</div><div style="font-size:.65rem;color:var(--text-muted);">Pay upon receipt</div></div>
                                        </div>
                                    </label>
                                </div>

                                <!-- Detail Panels -->
                                <div id="pm_detail_wrap" style="display:none;margin-bottom:10px;">
                                    <div id="pmd_gcash" class="pmd-panel" style="display:none;background:#EEF5FF;border:1.5px solid #C5DEFF;border-radius:14px;padding:1rem;">
                                        <div style="font-size:.7rem;font-weight:800;color:#0070F0;letter-spacing:.06em;text-transform:uppercase;margin-bottom:.65rem;display:flex;align-items:center;gap:5px;"><i class="fa-solid fa-mobile-screen"></i> Send via GCash</div>
                                        <div style="display:flex;justify-content:space-between;align-items:center;padding:.3rem 0;border-bottom:1px solid rgba(0,112,240,.1);font-size:.78rem;"><span style="color:#64748b;">Number</span><strong style="font-family:monospace;font-size:.9rem;">0948-797-0726</strong></div>
                                        <div style="display:flex;justify-content:space-between;align-items:center;padding:.3rem 0;border-bottom:1px solid rgba(0,112,240,.1);font-size:.78rem;"><span style="color:#64748b;">Account Name</span><strong>GreenLink Farmers</strong></div>
                                        <div style="display:flex;justify-content:space-between;align-items:center;padding:.3rem 0;font-size:.78rem;"><span style="color:#64748b;">Amount</span><strong style="color:#0070F0;font-family:monospace;" id="gcash_amt">—</strong></div>
                                        <div style="display:flex;align-items:flex-start;gap:6px;background:#FFFBEE;border-left:3px solid #F59E0B;border-radius:0 8px 8px 0;padding:.45rem .7rem;margin-top:.6rem;font-size:.7rem;color:#92400E;line-height:1.5;"><i class="fa-solid fa-triangle-exclamation" style="margin-top:1px;flex-shrink:0;"></i>Screenshot your receipt and enter the reference number below.</div>
                                        <div style="position:relative;margin-top:.6rem;">
                                            <i class="fa-solid fa-hashtag" style="position:absolute;left:.65rem;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.72rem;pointer-events:none;"></i>
                                            <input type="text" name="payment_reference" placeholder="GCash reference number (13 digits)" style="width:100%;font-family:monospace;font-size:.82rem;padding:.6rem .6rem .6rem 2rem;border:1.5px solid #C5DEFF;border-radius:10px;outline:none;background:white;letter-spacing:.02em;" onfocus="this.style.borderColor='#0070F0'" onblur="this.style.borderColor='#C5DEFF'">
                                        </div>
                                    </div>

                                    <div id="pmd_maya" class="pmd-panel" style="display:none;background:#EBF9F1;border:1.5px solid #B3EAC9;border-radius:14px;padding:1rem;">
                                        <div style="font-size:.7rem;font-weight:800;color:#00B14F;letter-spacing:.06em;text-transform:uppercase;margin-bottom:.65rem;display:flex;align-items:center;gap:5px;"><i class="fa-solid fa-mobile-screen"></i> Send via Maya</div>
                                        <div style="display:flex;justify-content:space-between;align-items:center;padding:.3rem 0;border-bottom:1px solid rgba(0,177,79,.1);font-size:.78rem;"><span style="color:#64748b;">Number</span><strong style="font-family:monospace;font-size:.9rem;">0948-797-0726</strong></div>
                                        <div style="display:flex;justify-content:space-between;align-items:center;padding:.3rem 0;border-bottom:1px solid rgba(0,177,79,.1);font-size:.78rem;"><span style="color:#64748b;">Account Name</span><strong>GreenLink Farmers</strong></div>
                                        <div style="display:flex;justify-content:space-between;align-items:center;padding:.3rem 0;font-size:.78rem;"><span style="color:#64748b;">Amount</span><strong style="color:#00B14F;font-family:monospace;" id="maya_amt">—</strong></div>
                                        <div style="display:flex;align-items:flex-start;gap:6px;background:#FFFBEE;border-left:3px solid #F59E0B;border-radius:0 8px 8px 0;padding:.45rem .7rem;margin-top:.6rem;font-size:.7rem;color:#92400E;line-height:1.5;"><i class="fa-solid fa-triangle-exclamation" style="margin-top:1px;flex-shrink:0;"></i>Screenshot your receipt and enter the reference number below.</div>
                                        <div style="position:relative;margin-top:.6rem;">
                                            <i class="fa-solid fa-hashtag" style="position:absolute;left:.65rem;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.72rem;pointer-events:none;"></i>
                                            <input type="text" name="payment_reference" placeholder="Maya reference number" style="width:100%;font-family:monospace;font-size:.82rem;padding:.6rem .6rem .6rem 2rem;border:1.5px solid #B3EAC9;border-radius:10px;outline:none;background:white;" onfocus="this.style.borderColor='#00B14F'" onblur="this.style.borderColor='#B3EAC9'">
                                        </div>
                                    </div>

                                    <div id="pmd_bank" class="pmd-panel" style="display:none;background:#EEF0FF;border:1.5px solid #C5CCFF;border-radius:14px;padding:1rem;">
                                        <div style="font-size:.7rem;font-weight:800;color:#1d4ed8;letter-spacing:.06em;text-transform:uppercase;margin-bottom:.65rem;display:flex;align-items:center;gap:5px;"><i class="fa-solid fa-building-columns"></i> Bank Transfer</div>
                                        <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:.6rem;">
                                            <div style="display:flex;align-items:center;gap:10px;background:white;border-radius:10px;padding:.5rem .75rem;font-size:.8rem;"><span style="font-size:.6rem;font-weight:800;padding:2px 7px;border-radius:6px;background:#E6F0FF;color:#1A3FBF;min-width:44px;text-align:center;">BDO</span><span style="flex:1;font-family:monospace;font-weight:600;color:var(--text);">1234-5678-9012</span><span style="font-size:.65rem;color:#94a3b8;">GreenLink Inc.</span></div>
                                            <div style="display:flex;align-items:center;gap:10px;background:white;border-radius:10px;padding:.5rem .75rem;font-size:.8rem;"><span style="font-size:.6rem;font-weight:800;padding:2px 7px;border-radius:6px;background:#FFE6E6;color:#BF1A1A;min-width:44px;text-align:center;">BPI</span><span style="flex:1;font-family:monospace;font-weight:600;color:var(--text);">9876-5432-10</span><span style="font-size:.65rem;color:#94a3b8;">GreenLink Inc.</span></div>
                                            <div style="display:flex;align-items:center;gap:10px;background:white;border-radius:10px;padding:.5rem .75rem;font-size:.8rem;"><span style="font-size:.6rem;font-weight:800;padding:2px 7px;border-radius:6px;background:#E6F3FF;color:#0070BF;min-width:44px;text-align:center;">UB</span><span style="flex:1;font-family:monospace;font-weight:600;color:var(--text);">1122-3344-5566</span><span style="font-size:.65rem;color:#94a3b8;">GreenLink Inc.</span></div>
                                        </div>
                                        <div style="display:flex;align-items:flex-start;gap:6px;background:#FFFBEE;border-left:3px solid #F59E0B;border-radius:0 8px 8px 0;padding:.45rem .7rem;font-size:.7rem;color:#92400E;line-height:1.5;"><i class="fa-solid fa-triangle-exclamation" style="margin-top:1px;flex-shrink:0;"></i>Use your <strong>Order ID</strong> as reference. Send proof of payment to admin.</div>
                                        <div style="position:relative;margin-top:.6rem;">
                                            <i class="fa-solid fa-hashtag" style="position:absolute;left:.65rem;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.72rem;pointer-events:none;"></i>
                                            <input type="text" name="payment_reference" placeholder="Bank transaction reference number" style="width:100%;font-family:monospace;font-size:.82rem;padding:.6rem .6rem .6rem 2rem;border:1.5px solid #C5CCFF;border-radius:10px;outline:none;background:white;" onfocus="this.style.borderColor='#1d4ed8'" onblur="this.style.borderColor='#C5CCFF'">
                                        </div>
                                    </div>

                                    <div id="pmd_cod" class="pmd-panel" style="display:none;background:#FFFBEE;border:1.5px solid #FFE599;border-radius:14px;padding:1rem;">
                                        <div style="font-size:.7rem;font-weight:800;color:#D97706;letter-spacing:.06em;text-transform:uppercase;margin-bottom:.65rem;display:flex;align-items:center;gap:5px;"><i class="fa-solid fa-hand-holding-dollar"></i> Cash on Delivery</div>
                                        <div style="font-size:.8rem;color:var(--text);margin-bottom:.5rem;line-height:1.6;">Please prepare the <strong>exact amount</strong> when your order arrives:</div>
                                        <div style="display:inline-flex;align-items:center;gap:6px;background:white;border:2px solid #D97706;border-radius:12px;padding:.55rem 1rem;margin-bottom:.5rem;">
                                            <i class="fa-solid fa-peso-sign" style="color:#D97706;font-size:.85rem;"></i>
                                            <span id="cod_amt" style="font-family:monospace;font-size:1.15rem;font-weight:500;color:#D97706;">—</span>
                                        </div>
                                        <div style="font-size:.72rem;color:#92400E;line-height:1.5;">💡 The farmer will contact you to arrange delivery. Payment is collected when goods are handed over.</div>
                                        <input type="hidden" name="payment_reference" value="COD">
                                    </div>
                                </div>

                                <!-- Security strip -->
                                <div style="display:flex;align-items:center;justify-content:center;gap:1.25rem;padding:.6rem 0;border-top:1px solid var(--border);margin-bottom:.75rem;">
                                    <span style="font-size:.62rem;color:var(--text-muted);font-weight:600;display:flex;align-items:center;gap:4px;"><i class="fa-solid fa-lock" style="color:var(--primary);"></i>Secure</span>
                                    <span style="font-size:.62rem;color:var(--text-muted);font-weight:600;display:flex;align-items:center;gap:4px;"><i class="fa-solid fa-shield-halved" style="color:var(--primary);"></i>Buyer Protected</span>
                                    <span style="font-size:.62rem;color:var(--text-muted);font-weight:600;display:flex;align-items:center;gap:4px;"><i class="fa-solid fa-rotate-left" style="color:var(--primary);"></i>Easy Returns</span>
                                </div>
                            </div>

                           <div style="display:flex;gap:.6rem;margin-top:.5rem;">
    <button type="button"
            onclick="addToCart(<?= $product['id'] ?>, this)"
            style="flex-shrink:0;padding:.9rem 1.1rem;border-radius:12px;border:2px solid var(--primary);background:white;color:var(--primary);font-weight:800;font-size:.88rem;cursor:pointer;display:flex;align-items:center;gap:6px;transition:all .2s;"
            onmouseover="this.style.background='var(--pale-green)'"
           onmouseout="if(!this.disabled)this.style.background='white'">
        <i class="fa-solid fa-basket-shopping"></i> Add to Cart
    </button>
    <button type="submit" name="place_order" class="btn-green" style="flex:1;padding:.9rem;display:flex;align-items:center;justify-content:space-between;">
        <span><i class="fa-solid fa-cart-plus me-2"></i>Place Order</span>
        <span id="btnTotal" style="font-family:monospace;font-size:.85rem;opacity:.75;"></span>
    </button>
</div>
                        </form>
                    </div>
                </div>
                <?php elseif (!isLoggedIn()): ?>
                <div class="gl-card" style="border:2px solid var(--primary);">
                    <div class="gl-card-body text-center">
                        <p style="font-size:0.95rem;font-weight:600;color:var(--text-muted);margin-bottom:1rem;">Login to place an order</p>
                        <a href="../auth/login.php" class="btn-green"><i class="fa-solid fa-right-to-bracket"></i> Login to Order</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
const price          = <?= floatval($product['price_per_kg']) ?>;
const hasDistance    = <?= $distanceKm !== null ? 'true' : 'false' ?>;
const distanceKm     = <?= floatval($distanceKm ?? 0) ?>;
const buyerIsPremium = <?= $buyerIsPremium ? 'true' : 'false' ?>;

function calcDeliveryFee(km, weightKg) {
    let base;
    if (km <= 0)        base = 0;
    else if (km <= 20)  base = 250;
    else if (km <= 50)  base = 380;
    else if (km <= 100) base = 520  + (km - 50)  * 2.50;
    else if (km <= 200) base = 645  + (km - 100) * 3.00;
    else if (km <= 400) base = 945  + (km - 200) * 3.50;
    else if (km <= 600) base = 1645 + (km - 400) * 4.00;
    else                base = 2445 + (km - 600) * 5.00;
    const surcharge = weightKg > 20 ? (weightKg - 20) * 3.0 : 0;
    return Math.round((base + surcharge) * 100) / 100;
}

function calcServiceFee(subtotal) {
    if (subtotal < 500)  return 50;
    if (subtotal < 2000) return 100;
    return 150;
}

function calcBulkDiscount(qty, deliveryFee) {
    if (qty < 50)   return { discount: 0, pct: 0, label: '' };
    let pct;
    if      (qty < 100)  pct = 10;
    else if (qty < 200)  pct = 15;
    else if (qty < 500)  pct = 20;
    else                 pct = 25;
    const discount = Math.round(deliveryFee * (pct / 100) * 100) / 100;
    return { discount, pct, label: `${pct}% bulk discount (${qty}kg+)` };
}

// Premium buyer: discount on subtotal
function calcPremiumBulkDiscount(qty, subtotal) {
    if (!buyerIsPremium || qty < 50) return { discount: 0, pct: 0, label: '' };
    let pct;
    if      (qty < 100)  pct = 5;
    else if (qty < 200)  pct = 8;
    else                 pct = 12;
    const discount = Math.round(subtotal * (pct / 100) * 100) / 100;
    return { discount, pct, label: `${pct}% Premium bulk discount (${qty}kg+)` };
}

function calcTotal() {
    const qty        = parseFloat(document.getElementById('qtyInput').value) || 0;
    const subtotal   = qty * price;

    // Premium buyer discount on subtotal
    const premBulk        = calcPremiumBulkDiscount(qty, subtotal);
    const discSubtotal    = subtotal - premBulk.discount;

    const platformFee  = Math.round(discSubtotal * 0.05 * 100) / 100;
    const serviceFee   = calcServiceFee(discSubtotal);
    const liveDelivery = hasDistance ? calcDeliveryFee(distanceKm, qty) : 0;
    const bulk         = calcBulkDiscount(qty, liveDelivery);
    const finalDeliv   = Math.max(0, liveDelivery - bulk.discount);
    const total        = Math.round((discSubtotal + finalDeliv + serviceFee) * 100) / 100;
    const fmt          = v => '₱' + v.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});

    document.getElementById('subtotalPrice').textContent     = fmt(subtotal);
    document.getElementById('totalPrice').textContent        = fmt(total);
    document.getElementById('serviceFeeDisplay').textContent = fmt(serviceFee);
    if (document.getElementById('platformFeeDisplay'))
        document.getElementById('platformFeeDisplay').textContent = fmt(platformFee);
    if (document.getElementById('deliveryFeeDisplay'))
        document.getElementById('deliveryFeeDisplay').textContent = finalDeliv > 0 ? fmt(finalDeliv) : 'FREE';

    // Premium bulk discount row
    const premRow = document.getElementById('premiumBulkRow');
    if (premRow) {
        if (premBulk.discount > 0) {
            premRow.style.display = 'flex';
            document.getElementById('premiumBulkLabel').textContent = premBulk.label;
            document.getElementById('premiumBulkAmt').textContent   = '-' + fmt(premBulk.discount);
        } else {
            premRow.style.display = 'none';
        }
    }

    // Delivery bulk discount row
    const bulkRow = document.getElementById('bulkDiscountRow');
    if (bulkRow) {
        if (bulk.discount > 0) {
            bulkRow.style.display = 'flex';
            document.getElementById('bulkDiscountLabel').textContent = bulk.label;
            document.getElementById('bulkDiscountAmt').textContent   = '-' + fmt(bulk.discount);
        } else {
            bulkRow.style.display = 'none';
        }
    }

    const totalStr = fmt(total);
    if (document.getElementById('gcash_amt')) document.getElementById('gcash_amt').textContent = totalStr;
    if (document.getElementById('maya_amt'))  document.getElementById('maya_amt').textContent  = totalStr;
    if (document.getElementById('cod_amt'))   document.getElementById('cod_amt').textContent   = total.toFixed(2);
    if (document.getElementById('btnTotal'))  document.getElementById('btnTotal').textContent  = totalStr;
}

function selectPayment(method) {
    const colors = { gcash:'#0070F0', maya:'#00B14F', bank:'#1d4ed8', cod:'#D97706' };
    const bgSel  = { gcash:'#F0F6FF', maya:'#F0FBF4', bank:'#F0F3FF', cod:'#FFFBF0' };

    ['gcash','maya','bank','cod'].forEach(m => {
        const inner = document.getElementById('pmi_' + m);
        const check = document.getElementById('pmck_' + m);
        if (inner) { inner.style.borderColor = 'var(--border)'; inner.style.background = 'white'; inner.style.transform = ''; inner.style.boxShadow = ''; }
        if (check) check.style.opacity = '0';
    });

    const selInner = document.getElementById('pmi_' + method);
    const selCheck = document.getElementById('pmck_' + method);
    if (selInner) {
        selInner.style.borderColor = colors[method];
        selInner.style.background  = bgSel[method];
        selInner.style.transform   = 'translateY(-2px)';
        selInner.style.boxShadow   = '0 4px 14px rgba(0,0,0,.08)';
    }
    if (selCheck) selCheck.style.opacity = '1';

    document.getElementById('pm_detail_wrap').style.display = 'block';
    ['gcash','maya','bank','cod'].forEach(m => {
        const p = document.getElementById('pmd_' + m);
        if (p) p.style.display = m === method ? 'block' : 'none';
    });

    const wrap = document.getElementById('proofUploadWrap');
    if (wrap) wrap.style.display = method !== 'cod' ? 'block' : 'none';

    calcTotal();
}

function handleProofUpload(input) {
    if (!input.files || !input.files[0]) return;
    const file   = input.files[0];
    const zone   = document.getElementById('proofDropZone');
    const nameEl = document.getElementById('proofFileName');
    nameEl.textContent    = '✅ ' + file.name;
    nameEl.style.display  = 'block';
    zone.style.borderColor = 'var(--primary)';
    zone.style.background  = 'var(--pale-green)';
    const reader = new FileReader();
    reader.onload = e => {
        let preview = document.getElementById('proofPreview');
        if (!preview) {
            preview = document.createElement('img');
            preview.id = 'proofPreview';
            preview.style.cssText = 'width:100%;max-height:160px;object-fit:contain;border-radius:8px;margin-top:.6rem;border:1px solid var(--border);';
            zone.appendChild(preview);
        }
        preview.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

calcTotal();

// ── Carousel & Modal ──────────────────────────────────────────
const slides = <?php
    $jsSlides = [];
    foreach ($slides as $s) {
        $jsSlides[] = $s['type'] === 'img'
            ? ['type' => 'img', 'src' => $s['src']]
            : ['type' => 'emoji', 'val' => $s['val']];
    }
    echo json_encode($jsSlides);
?>;
const totalSlides = slides.length;
let currentSlide  = 0;

function goToSlide(n) {
    currentSlide = (n + totalSlides) % totalSlides;
    document.getElementById('carouselTrack').style.transform = `translateX(-${currentSlide * 100}%)`;
    slides.forEach((_, i) => {
        const d = document.getElementById('dot' + i);
        if (d) { d.style.width = i === currentSlide ? '22px' : '8px'; d.style.background = i === currentSlide ? 'var(--primary)' : 'rgba(255,255,255,.6)'; }
        const t = document.getElementById('thumb' + i);
        if (t) { t.style.borderColor = i === currentSlide ? 'var(--primary)' : 'var(--border)'; t.style.opacity = i === currentSlide ? '1' : '.65'; }
    });
}
function moveCarousel(dir) { goToSlide(currentSlide + dir); }

function openModal(index) {
    currentSlide = index;
    renderModal(index);
    document.getElementById('imgModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeModal() {
    document.getElementById('imgModal').style.display = 'none';
    document.body.style.overflow = '';
}
function modalNav(dir, e) {
    e && e.stopPropagation();
    renderModal((currentSlide + dir + totalSlides) % totalSlides);
}
function renderModal(index) {
    currentSlide = index;
    const s = slides[index];
    document.getElementById('modalContent').innerHTML = s.type === 'img'
        ? `<img src="${s.src}" style="max-width:88vw;max-height:82vh;object-fit:contain;display:block;">`
        : `<div style="width:340px;height:340px;background:linear-gradient(135deg,#d1fae5,#a7f3d0);display:flex;align-items:center;justify-content:center;font-size:9rem;">${s.val}</div>`;
    document.getElementById('modalCaption').textContent = `Photo ${index + 1} of ${totalSlides}`;
    goToSlide(index);
}

document.addEventListener('keydown', e => {
    if (document.getElementById('imgModal').style.display !== 'none') {
        if (e.key === 'ArrowLeft')  modalNav(-1, e);
        if (e.key === 'ArrowRight') modalNav(1, e);
        if (e.key === 'Escape')     closeModal();
    } else {
        if (e.key === 'ArrowLeft')  moveCarousel(-1);
        if (e.key === 'ArrowRight') moveCarousel(1);
    }
});

let touchStartX = 0;
document.getElementById('carousel').addEventListener('touchstart', e => touchStartX = e.touches[0].clientX);
document.getElementById('carousel').addEventListener('touchend',   e => {
    const diff = touchStartX - e.changedTouches[0].clientX;
    if (Math.abs(diff) > 40) moveCarousel(diff > 0 ? 1 : -1);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>