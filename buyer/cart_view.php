<?php
$page_title = 'My Cart';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/delivery.php';

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
$cart = $_SESSION['cart'];

$pdo = getDBConnection();

// ── Enrich cart items with farmer info ────────────────────────
$enriched = [];
foreach ($cart as $pid => $item) {
    $stmt = $pdo->prepare("
        SELECT p.farmer_id, u.name as farmer_name, u.location as farmer_city,
               u.latitude as farmer_lat, u.longitude as farmer_lng, f.farm_name
        FROM products p
        JOIN users u ON p.farmer_id = u.id
        LEFT JOIN farmers f ON f.user_id = p.farmer_id
        WHERE p.id = ?
    ");
    $stmt->execute([$pid]);
    $extra = $stmt->fetch();
    $enriched[$pid] = array_merge($item, $extra ?: []);
}

// ── Get buyer coordinates ─────────────────────────────────────
$buyerLat = null;
$buyerLng = null;
if (isLoggedIn()) {
    $bStmt = $pdo->prepare("SELECT latitude, longitude FROM users WHERE id = ?");
    $bStmt->execute([$_SESSION['user_id']]);
    $bCoords = $bStmt->fetch();
    if ($bCoords && $bCoords['latitude']) {
        $buyerLat = floatval($bCoords['latitude']);
        $buyerLng = floatval($bCoords['longitude']);
    }
}

// ── Group by farmer ───────────────────────────────────────────
$byFarmer = [];
foreach ($enriched as $pid => $item) {
    $fid = $item['farmer_id'] ?? 0;
    if (!isset($byFarmer[$fid])) {
        $byFarmer[$fid] = [
            'info'  => [
                'name' => $item['farm_name'] ?? $item['farmer_name'] ?? 'Unknown',
                'city' => $item['farmer_city'] ?? '',
                'lat'  => isset($item['farmer_lat']) ? floatval($item['farmer_lat']) : null,
                'lng'  => isset($item['farmer_lng']) ? floatval($item['farmer_lng']) : null,
            ],
            'items' => [],
        ];
    }
    $byFarmer[$fid]['items'][$pid] = $item;
}

// ── Per-farmer delivery calculation ──────────────────────────
// Uses combined kg of ALL items from that farmer → 1 delivery fee
foreach ($byFarmer as $fid => &$fg) {
    $totalKg      = array_sum(array_map(fn($i) => $i['qty'], $fg['items']));
    $subtotal     = array_sum(array_map(fn($i) => $i['price_per_kg'] * $i['qty'], $fg['items']));
    $distanceKm   = null;
    $deliveryFee  = 0.0;
    $bulkDiscount = 0.0;
    $bulkLabel    = '';

    if ($buyerLat && $fg['info']['lat']) {
        $distanceKm  = haversineDistance($buyerLat, $buyerLng, $fg['info']['lat'], $fg['info']['lng']);
        $rawDelivery = calcDeliveryFee($distanceKm, $totalKg);
        $bulkInfo    = calcBulkDeliveryDiscount($totalKg, $rawDelivery);
        $deliveryFee  = $rawDelivery - $bulkInfo['discount'];
        $bulkDiscount = $bulkInfo['discount'];
        $bulkLabel    = $bulkInfo['label'];
    }

    // Service fee based on subtotal
    $serviceFee = 0;
    if ($subtotal < 500)       $serviceFee = 50;
    elseif ($subtotal < 2000)  $serviceFee = 100;
    else                       $serviceFee = 150;

    $fg['calc'] = [
        'total_kg'     => $totalKg,
        'subtotal'     => $subtotal,
        'distance_km'  => $distanceKm,
        'delivery_fee' => $deliveryFee,
        'bulk_discount'=> $bulkDiscount,
        'bulk_label'   => $bulkLabel,
        'service_fee'  => $serviceFee,
        'grand_total'  => $subtotal + $deliveryFee + $serviceFee,
    ];
}
unset($fg);

$grandSubtotal = array_sum(array_map(fn($fg) => $fg['calc']['subtotal'], $byFarmer));
$grandDelivery = array_sum(array_map(fn($fg) => $fg['calc']['delivery_fee'], $byFarmer));
$grandService  = array_sum(array_map(fn($fg) => $fg['calc']['service_fee'], $byFarmer));
$grandTotal    = $grandSubtotal + $grandDelivery + $grandService;

$emojis = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕','Livestock'=>'🐄','Seafood'=>'🐟','Others'=>'📦'];
?>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">
    <div class="page-header">
        <div class="container">
            <h1>
                <i class="fa-solid fa-cart-shopping text-green me-2"></i>My Cart
                <span style="font-size:1rem;font-weight:700;color:var(--text-muted);">
                    (<?= count($cart) ?> item<?= count($cart) !== 1 ? 's' : '' ?>
                    from <?= count($byFarmer) ?> farmer<?= count($byFarmer) !== 1 ? 's' : '' ?>)
                </span>
            </h1>
        </div>
    </div>

    <div class="container">
        <?php if (empty($cart)): ?>
        <div class="gl-card">
            <div class="gl-card-body" style="text-align:center;padding:3rem;">
                <div style="font-size:4rem;margin-bottom:1rem;">🛒</div>
                <h5 style="font-weight:800;color:var(--text-muted);">Your cart is empty</h5>
                <a href="browse.php" class="btn-green mt-3">Browse Products</a>
            </div>
        </div>
        <?php else: ?>

        <?php if (count($byFarmer) > 1): ?>
        <!-- Multi-farmer savings notice -->
        <div style="background:linear-gradient(135deg,#ecfdf5,#d1fae5);border:1.5px solid #6ee7b7;border-radius:14px;padding:.85rem 1.2rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:10px;">
            <span style="font-size:1.4rem;">🚚</span>
            <div>
                <div style="font-weight:800;font-size:.88rem;color:#065f46;">Orders grouped by farmer — each farmer ships in 1 delivery</div>
                <div style="font-size:.75rem;color:#047857;margin-top:2px;">All items from the same farm are combined into a single shipment, saving you on delivery costs.</div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- LEFT: Farmer groups -->
            <div class="col-lg-8">

                <?php foreach ($byFarmer as $farmerId => $fg):
                    $c = $fg['calc'];
                ?>
                <div class="gl-card mb-4" style="overflow:hidden;">

                    <!-- Farmer header -->
                    <div style="background:linear-gradient(135deg,#1B5E20,#2E7D32);padding:.9rem 1.25rem;display:flex;align-items:center;gap:10px;">
                       <?php
$fImgStmt = $pdo->prepare("SELECT u.profile_image, f.is_premium, f.premium_until FROM users u LEFT JOIN farmers f ON f.user_id = u.id WHERE u.id = ?");
$fImgStmt->execute([$farmerId]);
$fImgRow = $fImgStmt->fetch();
$fIsP = $fImgRow && $fImgRow['is_premium'] && strtotime($fImgRow['premium_until'] ?? '') > time();
?>
<?php if (!empty($fImgRow['profile_image'])): ?>
<img src="<?= BASE_URL ?>/assets/images/profiles/<?= sanitize($fImgRow['profile_image']) ?>"
     style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.6);flex-shrink:0;">
<?php else: ?>
<div style="width:38px;height:38px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:1rem;flex-shrink:0;">
    <?= strtoupper(substr($fg['info']['name'], 0, 1)) ?>
</div>
<?php endif; ?>
                        <div style="flex:1;">
                        <div style="font-weight:800;font-size:.92rem;color:white;display:flex;align-items:center;gap:6px;">
    🌾 <?= sanitize($fg['info']['name']) ?>
    <?php if ($fIsP): ?>
    <span style="background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.55rem;font-weight:800;padding:2px 7px;border-radius:99px;letter-spacing:.04em;">⭐ PREMIUM</span>
    <?php endif; ?>
</div>
<div style="font-size:.72rem;color:rgba(255,255,255,.75);">📍 <?= sanitize($fg['info']['city']) ?></div>
                        </div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
                            <span style="background:rgba(255,255,255,.15);color:white;font-size:.65rem;font-weight:800;padding:3px 10px;border-radius:99px;border:1px solid rgba(255,255,255,.3);">
                                <i class="fa-solid fa-truck me-1"></i>1 Delivery
                            </span>
                            <span style="background:rgba(255,255,255,.15);color:white;font-size:.65rem;font-weight:800;padding:3px 10px;border-radius:99px;border:1px solid rgba(255,255,255,.3);">
                                <?= count($fg['items']) ?> product<?= count($fg['items']) > 1 ? 's' : '' ?> · <?= number_format($c['total_kg'], 1) ?>kg
                            </span>
                        </div>
                    </div>

                    <!-- Items -->
                    <?php foreach ($fg['items'] as $pid => $item): ?>
                    <div style="display:flex;align-items:center;gap:12px;padding:1rem 1.25rem;border-bottom:1px solid var(--border);">
                        <div style="width:54px;height:54px;border-radius:12px;overflow:hidden;background:var(--pale-green);display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0;">
                            <?php if ($item['image']): ?>
                                <img src="<?= BASE_URL ?>/assets/images/products/<?= htmlspecialchars($item['image']) ?>" style="width:100%;height:100%;object-fit:cover;">
                            <?php else: ?>
                                <?= $emojis[$item['category']] ?? '🌾' ?>
                            <?php endif; ?>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-weight:800;color:var(--text);font-size:.9rem;"><?= sanitize($item['name']) ?></div>
                            <div style="font-size:.73rem;color:var(--text-muted);">₱<?= number_format($item['price_per_kg'],2) ?>/kg · Min <?= $item['min_order_kg'] ?>kg</div>
                        </div>
                        <form method="POST" action="cart.php" style="display:flex;align-items:center;gap:5px;">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="product_id" value="<?= $item['id'] ?>">
                            <input type="number" name="qty"
                                   value="<?= $item['qty'] ?>"
                                   min="<?= $item['min_order_kg'] ?>"
                                   max="<?= $item['stock_kg'] ?>"
                                   step="0.5"
                                   style="width:68px;border:1.5px solid var(--border);border-radius:8px;padding:4px 8px;font-size:.85rem;font-weight:700;text-align:center;"
                                   onchange="this.form.submit()">
                            <span style="font-size:.72rem;color:var(--text-muted);">kg</span>
                        </form>
                        <div style="font-weight:800;color:var(--primary);font-size:.92rem;min-width:72px;text-align:right;">
                            ₱<?= number_format($item['price_per_kg'] * $item['qty'], 2) ?>
                        </div>
                        <a href="cart.php?action=remove&product_id=<?= $item['id'] ?>"
                           onclick="return confirm('Remove this item?')"
                           style="color:#ef4444;font-size:.88rem;flex-shrink:0;text-decoration:none;margin-left:4px;">
                            <i class="fa-solid fa-trash"></i>
                        </a>
                    </div>
                    <?php endforeach; ?>

                    <!-- Combined delivery breakdown for this farmer -->
                    <div style="background:#f8fdf9;padding:1rem 1.25rem;">

                        <!-- Fee rows -->
                        <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:.85rem;">

                            <div style="display:flex;justify-content:space-between;font-size:.82rem;">
                                <span style="color:var(--text-muted);">
                                    Products subtotal (<?= number_format($c['total_kg'],1) ?>kg)
                                </span>
                                <strong>₱<?= number_format($c['subtotal'], 2) ?></strong>
                            </div>

                            <?php if ($c['distance_km'] !== null): ?>
                            <div style="display:flex;justify-content:space-between;font-size:.82rem;">
                                <span style="color:#3b82f6;display:flex;align-items:center;gap:5px;">
                                    <i class="fa-solid fa-truck"></i>
                                    Delivery (<?= number_format($c['distance_km'],1) ?>km · <?= number_format($c['total_kg'],1) ?>kg combined)
                                </span>
                                <strong style="color:#3b82f6;">
                                    <?= $c['delivery_fee'] > 0 ? '₱'.number_format($c['delivery_fee'],2) : 'FREE' ?>
                                </strong>
                            </div>

                            <?php if ($c['bulk_discount'] > 0): ?>
                            <div style="display:flex;justify-content:space-between;font-size:.75rem;background:#dcfce7;border-radius:8px;padding:4px 10px;">
                                <span style="color:#15803d;display:flex;align-items:center;gap:4px;">
                                    <i class="fa-solid fa-tag"></i> <?= sanitize($c['bulk_label']) ?>
                                </span>
                                <strong style="color:#15803d;">-₱<?= number_format($c['bulk_discount'],2) ?></strong>
                            </div>
                            <?php endif; ?>

                            <?php else: ?>
                            <div style="display:flex;justify-content:space-between;font-size:.78rem;">
                                <span style="color:#ea580c;display:flex;align-items:center;gap:5px;">
                                    <i class="fa-solid fa-location-dot"></i>
                                    Delivery fee (set location in profile to calculate)
                                </span>
                                <strong style="color:#ea580c;">TBD</strong>
                            </div>
                            <?php endif; ?>

                            <div style="display:flex;justify-content:space-between;font-size:.82rem;">
                                <span style="color:#7c3aed;display:flex;align-items:center;gap:5px;">
                                    <i class="fa-solid fa-handshake"></i>
                                    Service fee
                                </span>
                                <strong style="color:#7c3aed;">₱<?= number_format($c['service_fee'],2) ?></strong>
                            </div>
                        </div>

                        <!-- Farmer total + order button -->
                        <div style="border-top:1.5px dashed var(--primary);padding-top:.75rem;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                            <div>
                                <div style="font-size:.7rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;">This farmer's total</div>
                                <div style="font-size:1.3rem;font-weight:800;color:var(--primary);font-family:'Playfair Display',serif;">
                                    <?= $c['distance_km'] !== null
                                        ? '₱'.number_format($c['grand_total'],2)
                                        : '₱'.number_format($c['subtotal'] + $c['service_fee'],2).' + delivery' ?>
                                </div>
                                <?php if (count($fg['items']) > 1): ?>
                                <div style="font-size:.68rem;color:#16a34a;font-weight:700;margin-top:2px;">
                                    ✅ <?= count($fg['items']) ?> products · 1 delivery fee saved
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Order all from this farmer -->
                            <a href="cart_checkout.php?farmer_id=<?= $farmerId ?>"
                               class="btn-green"
                               style="padding:.75rem 1.25rem;font-size:.85rem;white-space:nowrap;">
                                <i class="fa-solid fa-cart-plus me-1"></i>
                                Order All from <?= sanitize(explode(' ', $fg['info']['name'])[0]) ?>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Clear cart -->
                <div style="text-align:right;margin-top:-.75rem;">
                    <a href="cart.php?action=clear" onclick="return confirm('Clear entire cart?')"
                       style="font-size:.78rem;font-weight:700;color:#ef4444;text-decoration:none;">
                        <i class="fa-solid fa-trash-can me-1"></i> Clear Cart
                    </a>
                </div>
            </div>

            <!-- RIGHT: Summary -->
            <div class="col-lg-4">
                <div class="gl-card" style="position:sticky;top:80px;">
                    <div class="gl-card-body">
                        <h5 style="font-weight:800;margin-bottom:1rem;">Cart Summary</h5>

                        <?php foreach ($byFarmer as $farmerId => $fg):
                            $c = $fg['calc'];
                        ?>
                        <div style="margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid var(--border);">
                            <div style="font-size:.72rem;font-weight:800;color:var(--primary);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.4rem;">
                                🌾 <?= sanitize($fg['info']['name']) ?>
                            </div>
                            <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:2px;">
                                <span style="color:var(--text-muted);">Products (<?= count($fg['items']) ?>)</span>
                                <span>₱<?= number_format($c['subtotal'],2) ?></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:2px;">
                                <span style="color:#3b82f6;">
                                    <i class="fa-solid fa-truck me-1"></i>Delivery
                                    <?php if (count($fg['items']) > 1): ?>
                                    <span style="font-size:.62rem;background:#dcfce7;color:#15803d;border-radius:99px;padding:1px 6px;font-weight:800;">combined</span>
                                    <?php endif; ?>
                                </span>
                                <span style="color:#3b82f6;">
                                    <?php if ($c['distance_km'] !== null): ?>
                                        <?= $c['delivery_fee'] > 0 ? '₱'.number_format($c['delivery_fee'],2) : 'FREE' ?>
                                    <?php else: ?>
                                        <span style="color:#ea580c;">TBD</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:2px;">
                                <span style="color:#7c3aed;"><i class="fa-solid fa-handshake me-1"></i>Service fee</span>
                                <span style="color:#7c3aed;">₱<?= number_format($c['service_fee'],2) ?></span>
                            </div>
                            <?php if ($c['bulk_discount'] > 0): ?>
                            <div style="display:flex;justify-content:space-between;font-size:.75rem;color:#15803d;font-weight:700;">
                                <span><i class="fa-solid fa-tag me-1"></i>Bulk discount</span>
                                <span>-₱<?= number_format($c['bulk_discount'],2) ?></span>
                            </div>
                            <?php endif; ?>
                            <div style="display:flex;justify-content:space-between;font-size:.85rem;font-weight:800;margin-top:4px;border-top:1px solid var(--border);padding-top:4px;">
                                <span>Farmer total</span>
                                <span style="color:var(--primary);">
                                    <?= $c['distance_km'] !== null
                                        ? '₱'.number_format($c['grand_total'],2)
                                        : '₱'.number_format($c['subtotal']+$c['service_fee'],2).'+' ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <!-- Grand total -->
                        <div style="background:var(--pale-green);border-radius:12px;padding:.9rem 1rem;margin-bottom:1rem;">
                            <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:4px;">
                                <span style="color:var(--text-muted);">All products</span>
                                <span>₱<?= number_format($grandSubtotal,2) ?></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:4px;">
                                <span style="color:#3b82f6;">Total delivery (<?= count($byFarmer) ?> shipment<?= count($byFarmer) > 1 ? 's' : '' ?>)</span>
                                <span style="color:#3b82f6;">
                                    <?= $buyerLat ? '₱'.number_format($grandDelivery,2) : 'TBD' ?>
                                </span>
                            </div>
                            <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:6px;">
                                <span style="color:#7c3aed;">Total service fees</span>
                                <span style="color:#7c3aed;">₱<?= number_format($grandService,2) ?></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;align-items:center;border-top:1.5px dashed var(--primary);padding-top:.6rem;">
                                <span style="font-weight:800;font-size:.88rem;">Grand Total</span>
                                <span style="font-size:1.35rem;font-weight:800;color:var(--primary);font-family:'Playfair Display',serif;">
                                    <?= $buyerLat ? '₱'.number_format($grandTotal,2) : '₱'.number_format($grandSubtotal+$grandService,2).'+' ?>
                                </span>
                            </div>
                            <?php if (!$buyerLat): ?>
                            <div style="font-size:.65rem;color:#ea580c;margin-top:4px;">+ delivery (set location in profile)</div>
                            <?php endif; ?>
                        </div>

                        <!-- Info notes -->
                        <div style="font-size:.7rem;color:#64748b;line-height:1.7;margin-bottom:1rem;">
                            <div><i class="fa-solid fa-circle-info" style="color:#3b82f6;"></i> Items from the same farmer ship in <strong>1 delivery</strong></div>
                            <div><i class="fa-solid fa-circle-info" style="color:#7c3aed;"></i> Service fee: ₱50/₱100/₱150 per farmer order</div>
                            <div><i class="fa-solid fa-circle-info" style="color:#16a34a;"></i> Bulk discounts apply per shipment (50kg+)</div>
                        </div>

                        <a href="browse.php" class="btn-outline-green w-100 justify-content-center" style="font-size:.82rem;padding:.6rem;">
                            <i class="fa-solid fa-plus me-1"></i> Add More Items
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>