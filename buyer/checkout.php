<?php
$page_title = 'Checkout';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/delivery.php';

requireLogin();
if ($_SESSION['role'] !== 'buyer') {
    header('Location: browse.php'); exit();
}

if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
    header('Location: cart_view.php'); exit();
}

$pdo      = getDBConnection();
$farmerId = intval(isset($_GET['farmer_id']) ? $_GET['farmer_id'] : 0);
if (!$farmerId) { header('Location: cart_view.php'); exit(); }

// ── Load buyer info + premium status ─────────────────────────────────────────
$bStmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
$bStmt->execute([$_SESSION['user_id']]);
$buyer = $bStmt->fetch();
$buyerId   = intval($buyer['id']);
$isPremium = !empty($buyer['is_premium']) && strtotime($buyer['premium_until']) > time();

// ── Load saved addresses (premium perk) ───────────────────────────────────────
$savedAddresses = [];
if ($isPremium) {
    try {
        $addrStmt = $pdo->prepare("SELECT * FROM buyer_addresses WHERE buyer_id=? ORDER BY is_default DESC, created_at DESC");
        $addrStmt->execute([$buyerId]);
        $savedAddresses = $addrStmt->fetchAll();
    } catch (Exception $e) { $savedAddresses = []; }
}

// ── Load farmer info ──────────────────────────────────────────────────────────
$fStmt = $pdo->prepare("SELECT u.*, f.farm_name, f.bio as farm_bio FROM users u LEFT JOIN farmers f ON f.user_id=u.id WHERE u.id=?");
$fStmt->execute([$farmerId]);
$farmer = $fStmt->fetch();
if (!$farmer) { header('Location: cart_view.php'); exit(); }

// ── Filter cart to this farmer only ──────────────────────────────────────────
$farmerItems = [];
foreach ($_SESSION['cart'] as $pid => $item) {
    $chk = $pdo->prepare("SELECT farmer_id FROM products WHERE id=? AND is_available=1");
    $chk->execute([$pid]);
    $row = $chk->fetch();
    if ($row && intval($row['farmer_id']) === $farmerId) {
        $farmerItems[$pid] = $item;
    }
}
if (empty($farmerItems)) { header('Location: cart_view.php'); exit(); }

// ── Coordinates ───────────────────────────────────────────────────────────────
$buyerLat    = floatval(isset($buyer['latitude'])   ? $buyer['latitude']   : 0);
$buyerLng    = floatval(isset($buyer['longitude'])  ? $buyer['longitude']  : 0);
$farmerLat   = floatval(isset($farmer['latitude'])  ? $farmer['latitude']  : 0);
$farmerLng   = floatval(isset($farmer['longitude']) ? $farmer['longitude'] : 0);
$distanceKm  = null;
$deliveryFee = 0.0;
$bulkInfo    = ['discount' => 0, 'label' => '', 'pct' => 0];

if ($buyerLat && $farmerLat) {
    $distanceKm  = haversineDistance($buyerLat, $buyerLng, $farmerLat, $farmerLng);
}

// ── Initial calculations ──────────────────────────────────────────────────────
$totalKg  = array_sum(array_map(function($i) { return $i['qty']; }, $farmerItems));
$subtotal = array_sum(array_map(function($i) { return $i['price_per_kg'] * $i['qty']; }, $farmerItems));

if ($distanceKm !== null) {
    $rawDelivery = calcDeliveryFee($distanceKm, $totalKg);
    $bulkInfo    = calcBulkDeliveryDiscount($totalKg, $rawDelivery);
    $deliveryFee = $rawDelivery - $bulkInfo['discount'];
}

// ── Premium Buyer: 5% bulk discount on subtotal ───────────────────────────────
$premiumDiscount     = 0.0;
$premiumDiscountPct  = 0;
if ($isPremium && $totalKg >= 20) {
    $premiumDiscountPct = 5;
    $premiumDiscount    = round($subtotal * 0.05, 2);
}
$discountedSubtotal = $subtotal - $premiumDiscount;

$serviceFee = $discountedSubtotal < 500 ? 50 : ($discountedSubtotal < 2000 ? 100 : 150);
$grandTotal = $discountedSubtotal + $deliveryFee + $serviceFee;

$emojis = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕','Livestock'=>'🐄','Seafood'=>'🐟','Others'=>'📦'];

// ── Handle submission ─────────────────────────────────────────────────────────
$orderError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $address          = trim(isset($_POST['delivery_address']) ? $_POST['delivery_address'] : '');
    $notes            = trim(isset($_POST['notes'])            ? $_POST['notes']            : '');
    $paymentMethod    = trim(isset($_POST['payment_method'])   ? $_POST['payment_method']   : '');
    $paymentReference = trim(isset($_POST['payment_reference'])? $_POST['payment_reference']: '');

    // Save new address if premium buyer checked the box
    if ($isPremium && !empty($_POST['save_address']) && $address) {
        $addrLabel = trim($_POST['address_label'] ?? 'Saved Address');
        try {
            $pdo->prepare("INSERT INTO buyer_addresses (buyer_id, label, address, is_default) VALUES (?,?,?,0)")
                ->execute([$buyerId, $addrLabel ?: 'Saved Address', $address]);
        } catch (Exception $e) {}
    }

    if (!$address)        $orderError = 'Please enter a delivery address.';
    elseif (!$paymentMethod) $orderError = 'Please select a payment method.';
    else {
        $finalItems = [];
        foreach ($farmerItems as $pid => $item) {
            $newQty = floatval(isset($_POST['qty_' . $pid]) ? $_POST['qty_' . $pid] : $item['qty']);
            $newQty = max($item['min_order_kg'], min($item['stock_kg'], $newQty));
            $finalItems[$pid] = array_merge($item, ['qty' => $newQty]);
        }
        $finalKg       = array_sum(array_map(function($i) { return $i['qty']; }, $finalItems));
        $finalSubtotal = array_sum(array_map(function($i) { return $i['price_per_kg'] * $i['qty']; }, $finalItems));

        // Recalculate premium discount
        $finalPremDiscount = 0.0;
        if ($isPremium && $finalKg >= 20) {
            $finalPremDiscount = round($finalSubtotal * 0.05, 2);
        }
        $finalDiscountedSub = $finalSubtotal - $finalPremDiscount;

        $finalDelivery = 0.0;
        $finalDistance = null;
        if ($buyerLat && $farmerLat) {
            $finalDistance = haversineDistance($buyerLat, $buyerLng, $farmerLat, $farmerLng);
            $rawDel        = calcDeliveryFee($finalDistance, $finalKg);
            $bInfo         = calcBulkDeliveryDiscount($finalKg, $rawDel);
            $finalDelivery = $rawDel - $bInfo['discount'];
        }
        $finalService      = $finalDiscountedSub < 500 ? 50 : ($finalDiscountedSub < 2000 ? 100 : 150);
        $finalPlatformFee  = round($finalDiscountedSub * 0.05, 2);
        $finalFarmerPayout = round($finalDiscountedSub - $finalPlatformFee, 2);
        $finalGrand        = $finalDiscountedSub + $finalDelivery + $finalService;

        // Proof upload
        $proofFilename = null;
        if (isset($_FILES['proof_of_payment']) && $_FILES['proof_of_payment']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
            $finfo   = finfo_open(FILEINFO_MIME_TYPE);
            $mime    = finfo_file($finfo, $_FILES['proof_of_payment']['tmp_name']);
            finfo_close($finfo);
            if (in_array($mime, $allowed) && $_FILES['proof_of_payment']['size'] <= 5*1024*1024) {
                $ext           = pathinfo($_FILES['proof_of_payment']['name'], PATHINFO_EXTENSION);
                $proofFilename = 'proof_'.time().'_'.uniqid().'.'.strtolower($ext);
                $uploadDir     = __DIR__.'/../assets/images/proofs/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                move_uploaded_file($_FILES['proof_of_payment']['tmp_name'], $uploadDir.$proofFilename);
            }
        }

        $pdo->beginTransaction();
        try {
            // is_priority = 1 for premium buyers (⚡ Priority Order Processing perk)
            $isPriority = $isPremium ? 1 : 0;

            $oStmt = $pdo->prepare("INSERT INTO orders (buyer_id, farmer_id, status, total_amount, platform_fee, farmer_payout, delivery_fee, service_fee, distance_km, delivery_address, notes, payment_method, payment_reference, proof_of_payment, is_priority) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $oStmt->execute([
                $_SESSION['user_id'], $farmerId, 'pending',
                $finalGrand, $finalPlatformFee, $finalFarmerPayout,
                $finalDelivery, $finalService, $finalDistance,
                $address, $notes, $paymentMethod, $paymentReference, $proofFilename,
                $isPriority
            ]);
            $orderId = $pdo->lastInsertId();

            $iStmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity_kg, price_per_kg, subtotal) VALUES (?,?,?,?,?)");
            foreach ($finalItems as $pid => $item) {
                $iSubtotal = $item['price_per_kg'] * $item['qty'];
                $iStmt->execute([$orderId, $pid, $item['qty'], $item['price_per_kg'], $iSubtotal]);
                $pdo->prepare("UPDATE products SET stock_kg = stock_kg - ? WHERE id=?")->execute([$item['qty'], $pid]);
                unset($_SESSION['cart'][$pid]);
            }

            $pdo->commit();
            $premMsg = $isPremium && $finalPremDiscount > 0 ? " 💰 Premium discount of ₱".number_format($finalPremDiscount,2)." applied!" : "";
            $priorityMsg = $isPremium ? " ⚡ Your order is marked priority!" : "";
            $farmName = isset($farmer['farm_name']) ? $farmer['farm_name'] : $farmer['name'];
            setFlash('success', "Order #$orderId placed! {$farmName} will confirm soon. 🌾{$premMsg}{$priorityMsg}");
            header("Location: ../orders/detail.php?id=$orderId");
            exit();

        } catch (\Throwable $e) {
            $pdo->rollBack();
            $orderError = 'Order failed: ' . $e->getMessage();
        }
    }
}
?>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">
    <div class="page-header">
        <div class="container">
            <div class="page-breadcrumb">
                <a href="cart_view.php">Cart</a> › Checkout
            </div>
            <h1><i class="fa-solid fa-cart-plus text-green me-2"></i>Checkout</h1>
        </div>
    </div>

    <div class="container">

        <?php if ($orderError): ?>
        <div style="background:#FFF5F5;border:1px solid #FED7D7;color:#C53030;border-radius:12px;padding:.85rem 1.1rem;margin-bottom:1.25rem;font-size:.88rem;font-weight:600;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?= sanitize($orderError) ?>
        </div>
        <?php endif; ?>

        <?php if ($isPremium): ?>
        <div style="background:linear-gradient(135deg,#1e3a8a,#1d4ed8);border-radius:14px;padding:.85rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <i class="fa-solid fa-crown" style="color:#93c5fd;font-size:1.1rem;"></i>
            <div style="flex:1;">
                <div style="color:white;font-weight:800;font-size:.88rem;">💼 Premium Buyer Benefits Active</div>
                <div style="color:rgba(255,255,255,.75);font-size:.75rem;">
                    ⚡ Priority processing &nbsp;·&nbsp;
                    <?php if ($totalKg >= 20): ?>
                    💰 5% bulk discount applied (₱<?= number_format($premiumDiscount,2) ?> off) &nbsp;·&nbsp;
                    <?php else: ?>
                    💰 5% bulk discount on orders ≥20kg &nbsp;·&nbsp;
                    <?php endif; ?>
                    📍 Saved addresses available
                </div>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
        <div class="row g-4">

            <!-- LEFT: Items + delivery details -->
            <div class="col-lg-7">

                <!-- Farmer header -->
                <div class="gl-card mb-3" style="overflow:hidden;">
                    <div style="background:linear-gradient(135deg,#1B5E20,#2E7D32);padding:1rem 1.25rem;display:flex;align-items:center;gap:12px;">
                        <?php
                        $coFImg = $pdo->prepare("SELECT u.profile_image, f.is_premium, f.premium_until FROM users u LEFT JOIN farmers f ON f.user_id=u.id WHERE u.id=?");
                        $coFImg->execute([$farmerId]);
                        $coFRow = $coFImg->fetch();
                        $coFPrem = $coFRow && !empty($coFRow['is_premium']) && !empty($coFRow['premium_until']) && strtotime($coFRow['premium_until']) > time();
                        ?>
                        <?php if (!empty($coFRow['profile_image'])): ?>
                        <img src="<?= BASE_URL ?>/assets/images/profiles/<?= sanitize($coFRow['profile_image']) ?>"
                             style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.6);flex-shrink:0;">
                        <?php else: ?>
                        <div style="width:44px;height:44px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:1.1rem;flex-shrink:0;">
                            <?= strtoupper(substr(isset($farmer['farm_name']) ? $farmer['farm_name'] : $farmer['name'], 0, 1)) ?>
                        </div>
                        <?php endif; ?>
                        <div>
                            <div style="font-weight:800;color:white;font-size:1rem;display:flex;align-items:center;gap:6px;">
                                🌾 <?= sanitize(isset($farmer['farm_name']) ? $farmer['farm_name'] : $farmer['name']) ?>
                                <?php if ($coFPrem): ?>
                                <span style="background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.55rem;font-weight:800;padding:2px 8px;border-radius:99px;">⭐ PREMIUM</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:.75rem;color:rgba(255,255,255,.75);">📍 <?= sanitize($farmer['location']) ?> · <?= count($farmerItems) ?> product<?= count($farmerItems) > 1 ? 's' : '' ?> · 1 delivery</div>
                        </div>
                        <?php if ($isPremium): ?>
                        <div style="margin-left:auto;">
                            <span style="background:rgba(255,255,255,.2);color:white;border:1px solid rgba(255,255,255,.4);border-radius:99px;padding:4px 12px;font-size:.7rem;font-weight:800;">
                                ⚡ Priority Order
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Items with editable qty -->
                    <div style="padding:0;">
                        <?php foreach ($farmerItems as $pid => $item): ?>
                        <div style="display:flex;align-items:center;gap:12px;padding:1rem 1.25rem;border-bottom:1px solid var(--border);">
                            <div style="width:52px;height:52px;border-radius:10px;overflow:hidden;background:var(--pale-green);display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0;">
                                <?php if ($item['image']): ?>
                                    <img src="<?= BASE_URL ?>/assets/images/products/<?= htmlspecialchars($item['image']) ?>" style="width:100%;height:100%;object-fit:cover;">
                                <?php else: ?>
                                    <?= isset($emojis[$item['category']]) ? $emojis[$item['category']] : '🌾' ?>
                                <?php endif; ?>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:800;font-size:.9rem;color:var(--text);"><?= sanitize($item['name']) ?></div>
                                <div style="font-size:.73rem;color:var(--text-muted);">₱<?= number_format($item['price_per_kg'],2) ?>/kg · stock: <?= number_format($item['stock_kg'],0) ?>kg</div>
                            </div>
                            <div style="display:flex;align-items:center;gap:5px;">
                                <button type="button" onclick="stepQty('qty_<?= $pid ?>','<?= $item['min_order_kg'] ?>','<?= $item['stock_kg'] ?>',-0.5)"
                                        style="width:28px;height:28px;border-radius:6px;border:1.5px solid var(--border);background:white;color:var(--text);font-size:1rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;">−</button>
                                <input type="number" name="qty_<?= $pid ?>" id="qty_<?= $pid ?>"
                                       value="<?= $item['qty'] ?>" min="<?= $item['min_order_kg'] ?>" max="<?= $item['stock_kg'] ?>" step="0.5"
                                       data-price="<?= $item['price_per_kg'] ?>" data-min="<?= $item['min_order_kg'] ?>" data-max="<?= $item['stock_kg'] ?>"
                                       oninput="recalc()"
                                       style="width:62px;border:1.5px solid var(--primary);border-radius:7px;padding:4px 6px;font-size:.85rem;font-weight:800;text-align:center;color:var(--primary);">
                                <button type="button" onclick="stepQty('qty_<?= $pid ?>','<?= $item['min_order_kg'] ?>','<?= $item['stock_kg'] ?>',0.5)"
                                        style="width:28px;height:28px;border-radius:6px;border:1.5px solid var(--border);background:white;color:var(--text);font-size:1rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;">+</button>
                                <span style="font-size:.72rem;color:var(--text-muted);">kg</span>
                            </div>
                            <div style="font-weight:800;color:var(--primary);min-width:72px;text-align:right;font-size:.9rem;" id="sub_<?= $pid ?>">
                                ₱<?= number_format($item['price_per_kg'] * $item['qty'],2) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Delivery address -->
                <div class="gl-card mb-3">
                    <div class="gl-card-body">
                        <h6 style="font-weight:800;margin-bottom:1rem;">
                            <i class="fa-solid fa-location-dot text-green me-2"></i>Delivery Address
                            <?php if ($isPremium): ?>
                            <span style="background:#EFF6FF;color:#1565C0;font-size:.6rem;font-weight:800;padding:2px 8px;border-radius:99px;vertical-align:middle;margin-left:6px;">📍 Premium: Multiple Addresses</span>
                            <?php endif; ?>
                        </h6>

                        <?php if ($isPremium && !empty($savedAddresses)): ?>
                        <div style="margin-bottom:1rem;">
                            <label style="font-size:.75rem;font-weight:800;color:var(--text-muted);display:block;margin-bottom:.4rem;">Saved Addresses</label>
                            <div style="display:flex;flex-direction:column;gap:6px;">
                                <?php foreach ($savedAddresses as $addr): ?>
                                <label style="cursor:pointer;display:flex;align-items:center;gap:10px;border:1.5px solid var(--border);border-radius:10px;padding:.6rem .85rem;transition:border-color .2s;"
                                       onmouseover="this.style.borderColor='#1565C0'" onmouseout="if(!this.querySelector('input').checked)this.style.borderColor='var(--border)'">
                                    <input type="radio" name="saved_addr" value="<?= htmlspecialchars($addr['address']) ?>"
                                           onchange="document.querySelector('[name=delivery_address]').value=this.value"
                                           style="accent-color:#1565C0;">
                                    <div>
                                        <div style="font-weight:800;font-size:.82rem;color:var(--text);"><?= sanitize($addr['label']) ?><?= $addr['is_default'] ? ' <span style="font-size:.6rem;background:#EFF6FF;color:#1565C0;border-radius:99px;padding:1px 7px;">Default</span>' : '' ?></div>
                                        <div style="font-size:.72rem;color:var(--text-muted);"><?= sanitize($addr['address']) ?></div>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                                <label style="cursor:pointer;display:flex;align-items:center;gap:10px;border:1.5px dashed var(--border);border-radius:10px;padding:.6rem .85rem;">
                                    <input type="radio" name="saved_addr" value="" checked onchange="document.querySelector('[name=delivery_address]').value=''" style="accent-color:#1565C0;">
                                    <span style="font-size:.82rem;color:var(--text-muted);font-weight:700;">+ Enter a new address</span>
                                </label>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="gl-input-wrap">
                            <i class="fa-solid fa-location-dot input-icon" style="top:14px;transform:none;"></i>
                            <textarea name="delivery_address" class="gl-input" rows="2"
                                      placeholder="Full delivery address..."><?= sanitize(isset($_POST['delivery_address']) ? $_POST['delivery_address'] : (isset($buyer['location']) ? $buyer['location'] : '')) ?></textarea>
                        </div>

                        <?php if ($isPremium): ?>
                        <div style="margin-top:.75rem;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.78rem;font-weight:700;">
                                <input type="checkbox" name="save_address" value="1" style="accent-color:#1565C0;">
                                Save this address
                            </label>
                            <input type="text" name="address_label" placeholder="Label (e.g. Restaurant, Warehouse)"
                                   style="border:1.5px solid var(--border);border-radius:8px;padding:.35rem .7rem;font-size:.75rem;font-family:inherit;outline:none;flex:1;min-width:160px;">
                        </div>
                        <?php endif; ?>

                        <div class="gl-input-wrap mt-2">
                            <i class="fa-solid fa-note-sticky input-icon" style="top:14px;transform:none;"></i>
                            <textarea name="notes" class="gl-input" rows="2"
                                      placeholder="Special instructions (optional)..."><?= sanitize(isset($_POST['notes']) ? $_POST['notes'] : '') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Payment method -->
                <div class="gl-card">
                    <div class="gl-card-body">
                        <h6 style="font-weight:800;margin-bottom:1rem;"><i class="fa-solid fa-lock text-green me-2"></i>Payment Method</h6>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                            <?php
                            $methods = [
                                'gcash' => ['label'=>'GCash','sub'=>'Instant · e-wallet','icon'=>'fa-mobile-screen','color'=>'#0070F0','bg'=>'#F0F6FF'],
                                'maya'  => ['label'=>'Maya', 'sub'=>'Instant · e-wallet','icon'=>'fa-mobile-screen','color'=>'#00B14F','bg'=>'#F0FBF4'],
                                'bank'  => ['label'=>'Bank Transfer','sub'=>'BDO, BPI, UnionBank','icon'=>'fa-building-columns','color'=>'#1d4ed8','bg'=>'#F0F3FF'],
                                'cod'   => ['label'=>'Cash on Delivery','sub'=>'Pay upon receipt','icon'=>'fa-money-bill-wave','color'=>'#D97706','bg'=>'#FFFBF0'],
                            ];
                            foreach ($methods as $key => $m): ?>
                            <label style="position:relative;cursor:pointer;">
                                <input type="radio" name="payment_method" value="<?= $m['label'] ?>" required
                                       style="position:absolute;opacity:0;pointer-events:none;"
                                       onchange="selectPayment('<?= $key ?>')">
                                <div id="pmi_<?= $key ?>" style="border:2px solid var(--border);border-radius:14px;padding:.85rem .9rem;background:white;transition:all .2s;display:flex;flex-direction:column;gap:5px;position:relative;">
                                    <div id="pmck_<?= $key ?>" style="position:absolute;top:8px;right:8px;width:18px;height:18px;border-radius:50%;background:<?= $m['color'] ?>;display:flex;align-items:center;justify-content:center;font-size:.6rem;color:white;opacity:0;transition:opacity .2s;"><i class="fa-solid fa-check"></i></div>
                                    <i class="fa-solid <?= $m['icon'] ?>" style="font-size:1.1rem;color:<?= $m['color'] ?>;width:22px;"></i>
                                    <div>
                                        <div style="font-size:.82rem;font-weight:800;color:var(--text);"><?= $m['label'] ?></div>
                                        <div style="font-size:.65rem;color:var(--text-muted);"><?= $m['sub'] ?></div>
                                    </div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <!-- Payment detail panels -->
                        <div id="pm_detail_wrap" style="display:none;margin-bottom:10px;">
                            <div id="pmd_gcash" class="pmd-panel" style="display:none;background:#EEF5FF;border:1.5px solid #C5DEFF;border-radius:14px;padding:1rem;">
                                <div style="font-size:.7rem;font-weight:800;color:#0070F0;margin-bottom:.6rem;text-transform:uppercase;"><i class="fa-solid fa-mobile-screen me-1"></i>Send via GCash</div>
                                <div style="font-size:.8rem;margin-bottom:3px;">Number: <strong style="font-family:monospace;">0948-797-0726</strong></div>
                                <div style="font-size:.8rem;margin-bottom:3px;">Name: <strong>GreenLink Farmers</strong></div>
                                <div style="font-size:.8rem;margin-bottom:.6rem;">Amount: <strong style="color:#0070F0;font-family:monospace;" id="gcash_amt">—</strong></div>
                                <input type="text" name="payment_reference" placeholder="GCash reference number (13 digits)" style="width:100%;font-family:monospace;font-size:.82rem;padding:.6rem;border:1.5px solid #C5DEFF;border-radius:10px;outline:none;background:white;">
                            </div>
                            <div id="pmd_maya" class="pmd-panel" style="display:none;background:#EBF9F1;border:1.5px solid #B3EAC9;border-radius:14px;padding:1rem;">
                                <div style="font-size:.7rem;font-weight:800;color:#00B14F;margin-bottom:.6rem;text-transform:uppercase;"><i class="fa-solid fa-mobile-screen me-1"></i>Send via Maya</div>
                                <div style="font-size:.8rem;margin-bottom:3px;">Number: <strong style="font-family:monospace;">0948-797-0726</strong></div>
                                <div style="font-size:.8rem;margin-bottom:3px;">Name: <strong>GreenLink Farmers</strong></div>
                                <div style="font-size:.8rem;margin-bottom:.6rem;">Amount: <strong style="color:#00B14F;font-family:monospace;" id="maya_amt">—</strong></div>
                                <input type="text" name="payment_reference" placeholder="Maya reference number" style="width:100%;font-family:monospace;font-size:.82rem;padding:.6rem;border:1.5px solid #B3EAC9;border-radius:10px;outline:none;background:white;">
                            </div>
                            <div id="pmd_bank" class="pmd-panel" style="display:none;background:#EEF0FF;border:1.5px solid #C5CCFF;border-radius:14px;padding:1rem;">
                                <div style="font-size:.7rem;font-weight:800;color:#1d4ed8;margin-bottom:.6rem;text-transform:uppercase;"><i class="fa-solid fa-building-columns me-1"></i>Bank Transfer</div>
                                <div style="font-size:.78rem;margin-bottom:3px;">BDO: <strong style="font-family:monospace;">1234-5678-9012</strong> — GreenLink Inc.</div>
                                <div style="font-size:.78rem;margin-bottom:.6rem;">UB: <strong style="font-family:monospace;">1122-3344-5566</strong> — GreenLink Inc.</div>
                                <input type="text" name="payment_reference" placeholder="Bank transaction reference" style="width:100%;font-family:monospace;font-size:.82rem;padding:.6rem;border:1.5px solid #C5CCFF;border-radius:10px;outline:none;background:white;">
                            </div>
                            <div id="pmd_cod" class="pmd-panel" style="display:none;background:#FFFBEE;border:1.5px solid #FFE599;border-radius:14px;padding:1rem;">
                                <div style="font-size:.7rem;font-weight:800;color:#D97706;margin-bottom:.6rem;text-transform:uppercase;"><i class="fa-solid fa-hand-holding-dollar me-1"></i>Cash on Delivery</div>
                                <div style="font-size:.82rem;color:var(--text);margin-bottom:.4rem;">Prepare exact amount: <strong style="color:#D97706;font-family:monospace;" id="cod_amt">—</strong></div>
                                <div style="font-size:.72rem;color:#92400E;">💡 Farmer will contact you to arrange delivery.</div>
                                <input type="hidden" name="payment_reference" value="COD">
                            </div>
                        </div>

                        <div id="proofUploadWrap" style="display:none;margin-top:.75rem;">
                            <div style="font-size:.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.4rem;">
                                <i class="fa-solid fa-receipt text-green"></i> Proof of Payment
                            </div>
                            <div style="border:2px dashed var(--border);border-radius:12px;padding:1.1rem;text-align:center;background:var(--bg);cursor:pointer;"
                                 onclick="document.getElementById('proofFile').click()" id="proofDropZone">
                                <i class="fa-solid fa-cloud-arrow-up" style="font-size:1.4rem;color:var(--primary);display:block;margin-bottom:.3rem;"></i>
                                <div style="font-size:.82rem;font-weight:700;color:var(--text);">Click to upload screenshot</div>
                                <div style="font-size:.7rem;color:var(--text-muted);">JPG, PNG up to 5MB</div>
                                <div id="proofFileName" style="font-size:.72rem;color:var(--primary);font-weight:700;margin-top:.3rem;display:none;"></div>
                            </div>
                            <input type="file" id="proofFile" name="proof_of_payment" accept="image/*" style="display:none;" onchange="handleProof(this)">
                        </div>
                    </div>
                </div>

            </div>

            <!-- RIGHT: Order summary -->
            <div class="col-lg-5">
                <div class="gl-card" style="position:sticky;top:80px;">
                    <div class="gl-card-body">
                        <h6 style="font-weight:800;margin-bottom:1rem;">Order Summary</h6>

                        <div id="itemSummary" style="margin-bottom:.75rem;padding-bottom:.75rem;border-bottom:1px solid var(--border);">
                            <?php foreach ($farmerItems as $pid => $item): ?>
                            <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:3px;">
                                <span style="color:var(--text-muted);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-right:8px;"><?= sanitize($item['name']) ?> × <span id="sumqty_<?= $pid ?>"><?= $item['qty'] ?></span>kg</span>
                                <span style="font-weight:700;" id="sumline_<?= $pid ?>">₱<?= number_format($item['price_per_kg']*$item['qty'],2) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div style="display:flex;flex-direction:column;gap:7px;margin-bottom:.75rem;">
                            <div style="display:flex;justify-content:space-between;font-size:.85rem;">
                                <span style="color:var(--text-muted);">Products subtotal</span>
                                <strong id="sumSubtotal">₱<?= number_format($subtotal,2) ?></strong>
                            </div>

                            <!-- Premium discount row -->
                            <div id="premDiscountRow" style="display:<?= $premiumDiscount > 0 ? 'flex' : 'none' ?>;justify-content:space-between;font-size:.82rem;background:#EFF6FF;border-radius:8px;padding:5px 10px;">
                                <span style="color:#1565C0;font-weight:700;"><i class="fa-solid fa-crown me-1"></i>Premium 5% bulk discount</span>
                                <strong style="color:#1565C0;" id="sumPremDiscount">-₱<?= number_format($premiumDiscount,2) ?></strong>
                            </div>
                            <div id="premDiscountHint" style="display:<?= ($isPremium && $premiumDiscount == 0) ? 'block' : 'none' ?>;font-size:.7rem;color:#1565C0;background:#EFF6FF;border-radius:8px;padding:4px 10px;">
                                💡 Add ≥20kg total to unlock your 5% premium discount
                            </div>

                            <?php if ($distanceKm !== null): ?>
                            <div style="display:flex;justify-content:space-between;font-size:.85rem;">
                                <span style="color:#3b82f6;"><i class="fa-solid fa-truck me-1"></i>Delivery (<?= number_format($distanceKm,1) ?>km)</span>
                                <strong style="color:#3b82f6;" id="sumDelivery"><?= $deliveryFee > 0 ? '₱'.number_format($deliveryFee,2) : 'FREE' ?></strong>
                            </div>
                            <?php if ($bulkInfo['discount'] > 0): ?>
                            <div style="display:flex;justify-content:space-between;font-size:.75rem;background:#dcfce7;border-radius:8px;padding:4px 10px;" id="bulkRow">
                                <span style="color:#15803d;"><i class="fa-solid fa-tag me-1"></i><span id="bulkLabel"><?= sanitize($bulkInfo['label']) ?></span></span>
                                <strong style="color:#15803d;" id="bulkAmt">-₱<?= number_format($bulkInfo['discount'],2) ?></strong>
                            </div>
                            <?php else: ?>
                            <div style="display:none;" id="bulkRow">
                                <span style="color:#15803d;"><i class="fa-solid fa-tag me-1"></i><span id="bulkLabel"></span></span>
                                <strong style="color:#15803d;" id="bulkAmt"></strong>
                            </div>
                            <?php endif; ?>
                            <?php else: ?>
                            <div style="display:flex;justify-content:space-between;font-size:.82rem;">
                                <span style="color:#ea580c;"><i class="fa-solid fa-location-dot me-1"></i>Delivery fee</span>
                                <span style="color:#ea580c;">Set location in profile</span>
                            </div>
                            <?php endif; ?>

                            <div style="display:flex;justify-content:space-between;font-size:.85rem;">
                                <span style="color:#7c3aed;"><i class="fa-solid fa-handshake me-1"></i>Service fee</span>
                                <strong style="color:#7c3aed;" id="sumService">₱<?= number_format($serviceFee,2) ?></strong>
                            </div>
                        </div>

                        <div style="background:var(--pale-green);border-radius:12px;padding:.9rem 1rem;margin-bottom:1.1rem;">
                            <div style="display:flex;justify-content:space-between;align-items:center;">
                                <span style="font-weight:800;color:var(--text-muted);">Total you pay</span>
                                <span id="sumTotal" style="font-size:1.5rem;font-weight:800;color:var(--primary);font-family:'Playfair Display',serif;">
                                    ₱<?= number_format($grandTotal,2) ?>
                                </span>
                            </div>
                            <?php if ($isPremium && $premiumDiscount > 0): ?>
                            <div style="font-size:.68rem;color:#1565C0;font-weight:700;margin-top:4px;">
                                💼 You saved ₱<?= number_format($premiumDiscount,2) ?> with Premium!
                            </div>
                            <?php endif; ?>
                        </div>

                        <div style="display:flex;justify-content:center;gap:1.25rem;padding:.5rem 0;border-top:1px solid var(--border);margin-bottom:1rem;">
                            <span style="font-size:.62rem;color:var(--text-muted);font-weight:600;display:flex;align-items:center;gap:4px;"><i class="fa-solid fa-lock" style="color:var(--primary);"></i>Secure</span>
                            <span style="font-size:.62rem;color:var(--text-muted);font-weight:600;display:flex;align-items:center;gap:4px;"><i class="fa-solid fa-shield-halved" style="color:var(--primary);"></i>Protected</span>
                            <?php if ($isPremium): ?>
                            <span style="font-size:.62rem;color:#1565C0;font-weight:800;display:flex;align-items:center;gap:4px;"><i class="fa-solid fa-crown" style="color:#1565C0;"></i>Premium</span>
                            <?php endif; ?>
                        </div>

                        <button type="submit" name="place_order" class="btn-green w-100 justify-content-center" style="padding:1rem;font-size:1rem;">
                            <?php if ($isPremium): ?><i class="fa-solid fa-crown me-1" style="color:#93c5fd;"></i><?php else: ?><i class="fa-solid fa-cart-plus me-2"></i><?php endif; ?>
                            Place Order · <span id="btnTotal">₱<?= number_format($grandTotal,2) ?></span>
                        </button>
                        <a href="cart_view.php" class="btn-outline-green w-100 justify-content-center mt-2" style="font-size:.82rem;padding:.6rem;">← Back to Cart</a>
                    </div>
                </div>
            </div>

        </div>
        </form>
    </div>
</div>

<script>
const distanceKm  = <?= floatval(isset($distanceKm) ? $distanceKm : 0) ?>;
const hasDistance = <?= $distanceKm !== null ? 'true' : 'false' ?>;
const isPremium   = <?= $isPremium ? 'true' : 'false' ?>;

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

function calcBulk(qty, deliveryFee) {
    if (qty < 50) return { discount:0, pct:0, label:'' };
    let pct = qty < 100 ? 10 : qty < 200 ? 15 : qty < 500 ? 20 : 25;
    const discount = Math.round(deliveryFee * (pct/100) * 100) / 100;
    return { discount, pct, label: `${pct}% bulk discount (${qty}kg combined)` };
}

function calcServiceFee(subtotal) {
    return subtotal < 500 ? 50 : subtotal < 2000 ? 100 : 150;
}

function fmt(v) {
    return '₱' + v.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function recalc() {
    let totalKg = 0, subtotal = 0;

    document.querySelectorAll('input[name^="qty_"]').forEach(inp => {
        const pid   = inp.name.replace('qty_', '');
        const qty   = parseFloat(inp.value) || 0;
        const price = parseFloat(inp.dataset.price);
        const line  = price * qty;
        totalKg  += qty;
        subtotal += line;

        const subEl  = document.getElementById('sub_' + pid);
        const sqEl   = document.getElementById('sumqty_' + pid);
        const slEl   = document.getElementById('sumline_' + pid);
        if (subEl) subEl.textContent = fmt(line);
        if (sqEl)  sqEl.textContent  = qty;
        if (slEl)  slEl.textContent  = fmt(line);
    });

    // Premium 5% bulk discount (≥20kg)
    let premDiscount = 0;
    if (isPremium && totalKg >= 20) {
        premDiscount = Math.round(subtotal * 0.05 * 100) / 100;
    }
    const discountedSub = subtotal - premDiscount;

    // Update premium discount row
    const pdr  = document.getElementById('premDiscountRow');
    const pdv  = document.getElementById('sumPremDiscount');
    const pdh  = document.getElementById('premDiscountHint');
    if (isPremium) {
        if (premDiscount > 0) {
            if (pdr) pdr.style.display = 'flex';
            if (pdv) pdv.textContent   = '-' + fmt(premDiscount);
            if (pdh) pdh.style.display = 'none';
        } else {
            if (pdr) pdr.style.display = 'none';
            if (pdh) pdh.style.display = 'block';
        }
    }

    const rawDelivery = hasDistance ? calcDeliveryFee(distanceKm, totalKg) : 0;
    const bulk        = calcBulk(totalKg, rawDelivery);
    const delivery    = Math.max(0, rawDelivery - bulk.discount);
    const service     = calcServiceFee(discountedSub);
    const total       = Math.round((discountedSub + delivery + service) * 100) / 100;
    const totalStr    = fmt(total);

    const sd = document.getElementById('sumSubtotal');
    const sv = document.getElementById('sumDelivery');
    const ss = document.getElementById('sumService');
    const st = document.getElementById('sumTotal');
    const bt = document.getElementById('btnTotal');
    const br = document.getElementById('bulkRow');
    const bl = document.getElementById('bulkLabel');
    const ba = document.getElementById('bulkAmt');

    if (sd) sd.textContent = fmt(subtotal);
    if (sv && hasDistance) sv.textContent = delivery > 0 ? fmt(delivery) : 'FREE';
    if (ss) ss.textContent = fmt(service);
    if (st) st.textContent = totalStr;
    if (bt) bt.textContent = totalStr;

    if (br) {
        if (bulk.discount > 0) {
            br.style.display = 'flex';
            if (bl) bl.textContent = bulk.label;
            if (ba) ba.textContent = '-' + fmt(bulk.discount);
        } else {
            br.style.display = 'none';
        }
    }

    ['gcash_amt','maya_amt','cod_amt'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = id === 'cod_amt' ? total.toFixed(2) : totalStr;
    });
}

function stepQty(id, min, max, step) {
    const inp = document.getElementById(id);
    if (!inp) return;
    let val = parseFloat(inp.value) + step;
    val = Math.max(parseFloat(min), Math.min(parseFloat(max), val));
    inp.value = val;
    recalc();
}

function selectPayment(method) {
    const colors = { gcash:'#0070F0', maya:'#00B14F', bank:'#1d4ed8', cod:'#D97706' };
    const bgs    = { gcash:'#F0F6FF', maya:'#F0FBF4', bank:'#F0F3FF', cod:'#FFFBF0' };

    ['gcash','maya','bank','cod'].forEach(m => {
        const i = document.getElementById('pmi_'+m), c = document.getElementById('pmck_'+m);
        if (i) { i.style.borderColor='var(--border)'; i.style.background='white'; i.style.transform=''; }
        if (c) c.style.opacity = '0';
    });

    const si = document.getElementById('pmi_'+method), sc = document.getElementById('pmck_'+method);
    if (si) { si.style.borderColor=colors[method]; si.style.background=bgs[method]; si.style.transform='translateY(-2px)'; }
    if (sc) sc.style.opacity = '1';

    document.getElementById('pm_detail_wrap').style.display = 'block';
    ['gcash','maya','bank','cod'].forEach(m => {
        const p = document.getElementById('pmd_'+m);
        if (p) p.style.display = m===method ? 'block' : 'none';
    });

    const pw = document.getElementById('proofUploadWrap');
    if (pw) pw.style.display = method !== 'cod' ? 'block' : 'none';

    recalc();
}

function handleProof(input) {
    if (!input.files || !input.files[0]) return;
    const fn = document.getElementById('proofFileName');
    const dz = document.getElementById('proofDropZone');
    if (fn) { fn.textContent = '✅ '+input.files[0].name; fn.style.display='block'; }
    if (dz) { dz.style.borderColor='var(--primary)'; dz.style.background='var(--pale-green)'; }
}

recalc();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>