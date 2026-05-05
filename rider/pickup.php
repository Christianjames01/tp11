<?php
$page_title = 'Ready for Pickup';
$hide_navbar = true;
require_once __DIR__ . '/../includes/header.php';
requireRole('rider');

$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];

// Fetch rider profile
$rider = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$rider->execute([$userId]);
$rider = $rider->fetch();

// Handle pickup confirmation (mark as shipped) — only if rider_id matches
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pickup_order'])) {
    $orderId = intval($_POST['order_id']);
    $stmt = $pdo->prepare("
        UPDATE orders
        SET status = 'shipped'
        WHERE id = ?
          AND rider_id = ?
          AND status = 'confirmed'
    ");
    $stmt->execute([$orderId, $userId]);

   if ($stmt->rowCount() > 0) {
    // ✅ Auto-message the buyer
    $orderInfo = $pdo->prepare("SELECT o.buyer_id, u.name as rider_name FROM orders o JOIN users u ON u.id = ? WHERE o.id = ?");
    $orderInfo->execute([$userId, $orderId]);
    $info = $orderInfo->fetch();

    if ($info) {
        $riderName = $info['rider_name'];
       $orderDetails = $pdo->prepare("SELECT total_amount, payment_method FROM orders WHERE id = ?");
$orderDetails->execute([$orderId]);
$orderData = $orderDetails->fetch();

$totalAmount  = number_format($orderData['total_amount'], 2);
$paymentMethod = $orderData['payment_method'] ?? 'Cash on Delivery';
$isCOD = $paymentMethod === 'Cash on Delivery';

$autoMsg = "Hi! I'm {$riderName}, your GreenLink delivery rider. 🛵\n\n"
         . "I've just picked up your Order #{$orderId} and it's now on its way to you!\n\n"
         . ($isCOD
             ? "💰 Please prepare ₱{$totalAmount} (Cash on Delivery) when I arrive."
             : "✅ Payment: {$paymentMethod} — no cash needed upon delivery.")
         . "\n\nMake sure someone is available to receive the package. "
         . "Feel free to message me if you have any questions. See you soon! 🌿";
        $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)")
            ->execute([$userId, $info['buyer_id'], $autoMsg]);
    }

    setFlash('success', "Order #$orderId picked up! It's now in transit. 🛵");
} else {
    setFlash('error', "Could not confirm pickup for Order #$orderId. You may not be assigned to it.");
}

   header("Location: orders.php?status=shipped"); exit();
}

// Fetch ONLY confirmed orders assigned to THIS rider
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
    WHERE o.rider_id = ?
      AND o.status   = 'confirmed'
    ORDER BY o.updated_at DESC
");
$orders->execute([$userId]);
$orders = $orders->fetchAll();

// Stats (for context)
$totalPickupReady = count($orders);

$inTransit = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE rider_id = ? AND status = 'shipped'");
$inTransit->execute([$userId]); $inTransit = $inTransit->fetchColumn();

$delivered = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE rider_id = ? AND status = 'completed'");
$delivered->execute([$userId]); $delivered = $delivered->fetchColumn();

$flash = getFlash();
$emojis = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕','Livestock'=>'🐄','Seafood'=>'🐟','Others'=>'📦'];
?>

<style>
:root {
    --rider-primary: #1B5E20;
    --rider-accent:  #4CAF50;
    --rider-orange:  #F97316;
    --rider-blue:    #3B82F6;
    --rider-bg:      #F0F7F0;
}

.pickup-page { background: var(--rider-bg); min-height: 100vh; padding-bottom: 3rem; }

/* Stat cards */
.rider-stat { background: white; border-radius: 16px; padding: 1.1rem 1.2rem; border: 1px solid #E2E8F0; box-shadow: 0 2px 8px rgba(0,0,0,.05); display: flex; align-items: center; gap: 14px; }
.rider-stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
.rider-stat-val { font-size: 1.4rem; font-weight: 800; color: var(--text); line-height: 1; }
.rider-stat-lbl { font-size: .7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; margin-top: 3px; }

/* Order cards */
.order-card { background: white; border-radius: 18px; border: 2px solid #FED7AA; box-shadow: 0 2px 12px rgba(249,115,22,.1); overflow: hidden; margin-bottom: 1rem; transition: box-shadow .2s, transform .15s; }
.order-card:hover { box-shadow: 0 8px 28px rgba(249,115,22,.18); transform: translateY(-2px); }
.order-card-header { padding: 1rem 1.25rem; border-bottom: 1px solid #FEF0E6; display: flex; justify-content: space-between; align-items: center; background: #FFF7ED; }
.order-card-body { padding: 1.25rem; }

/* Route labels */
.route-label { font-size: .68rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 2px; }
.route-name  { font-size: .85rem; font-weight: 700; color: var(--text); }
.route-addr  { font-size: .75rem; color: var(--text-muted); }
.route-phone { font-size: .72rem; color: var(--rider-primary); font-weight: 700; margin-top: 2px; }

/* Pickup button */
.btn-pickup {
    background: linear-gradient(135deg, #F97316, #EA580C);
    color: white;
    border: none;
    border-radius: 12px;
    padding: .75rem 1.6rem;
    font-weight: 800;
    font-size: .88rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all .2s;
    font-family: inherit;
    box-shadow: 0 4px 14px rgba(249,115,22,.35);
}
.btn-pickup:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(249,115,22,.5); }
.btn-pickup:active { transform: translateY(0); }

.btn-msg { background: var(--pale-green); color: var(--rider-primary); border: none; border-radius: 12px; padding: .7rem 1.1rem; font-weight: 700; font-size: .82rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; transition: all .15s; }
.btn-msg:hover { background: var(--rider-primary); color: white; }

/* Pulse animation */
@keyframes pulse-ring {
    0%   { box-shadow: 0 0 0 0 rgba(249,115,22,.5); }
    70%  { box-shadow: 0 0 0 10px rgba(249,115,22,0); }
    100% { box-shadow: 0 0 0 0 rgba(249,115,22,0); }
}
.pulse { animation: pulse-ring 1.8s infinite; }

/* Countdown badge */
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.4} }
.blink { animation: blink 1.4s ease-in-out infinite; }

/* Flash messages */
.flash-success { background: #DCFCE7; border: 1px solid #BBF7D0; color: #16A34A; border-radius: 12px; padding: .8rem 1.1rem; font-weight: 700; font-size: .85rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 8px; }
.flash-error   { background: #FEE2E2; border: 1px solid #FECACA; color: #DC2626; border-radius: 12px; padding: .8rem 1.1rem; font-weight: 700; font-size: .85rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 8px; }

/* Empty state */
.empty-state { text-align: center; padding: 4rem 2rem; color: var(--text-muted); }
.empty-state .icon { font-size: 4rem; margin-bottom: 1rem; opacity: .6; }

/* Nav tabs */
.nav-back { color: rgba(255,255,255,.8); font-size: .85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: color .15s; }
.nav-back:hover { color: white; }

/* Priority badge */
.priority-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #F97316;
    color: white;
    border-radius: 99px;
    padding: 2px 10px;
    font-size: .65rem;
    font-weight: 800;
    letter-spacing: .04em;
}

/* Checklist step indicator */
.step-bar { display: flex; align-items: center; gap: 0; margin-bottom: 1rem; }
.step-item { display: flex; align-items: center; gap: 6px; }
.step-dot  { width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .65rem; font-weight: 800; flex-shrink: 0; }
.step-dot-active   { background: #F97316; color: white; }
.step-dot-inactive { background: #E2E8F0; color: #94A3B8; }
.step-dot-done     { background: #16A34A; color: white; }
.step-line { flex: 1; height: 2px; background: #E2E8F0; margin: 0 4px; min-width: 24px; }
.step-label { font-size: .65rem; font-weight: 700; color: var(--text-muted); white-space: nowrap; }
</style>

<div class="pickup-page">

    <!-- Header -->
    <div style="background:linear-gradient(135deg,#0D3B13 0%,#1B5E20 45%,#2E7D32 100%);padding:1.5rem 0;position:relative;overflow:hidden;">
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
                            Hey, <span style="color:#86EFAC;"><?= sanitize(explode(' ',$rider['name'])[0]) ?></span> 👋
                        </div>
                        <div style="font-size:.82rem;color:rgba(255,255,255,.65);margin-top:.2rem;">
                            🛵 Delivery Rider · <?= sanitize($rider['location'] ?? 'Mindanao') ?>
                        </div>
                        <?php if ($totalPickupReady > 0): ?>
                        <div style="margin-top:.5rem;">
                            <span style="background:rgba(249,115,22,.25);border:1px solid rgba(249,115,22,.4);border-radius:99px;padding:.3rem .85rem;font-size:.72rem;font-weight:600;color:white;display:inline-flex;align-items:center;gap:5px;animation:blink 1.4s ease-in-out infinite;">
                                🔥 <?= $totalPickupReady ?> pickup<?= $totalPickupReady > 1 ? 's' : '' ?> waiting
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right: Nav + Logout -->
                <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;">
                    <a href="dashboard.php" style="display:flex;align-items:center;gap:6px;padding:.5rem 1rem;color:white;font-size:.8rem;font-weight:700;text-decoration:none;border-radius:10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);">
                        <i class="fa-solid fa-gauge"></i> Dashboard
                    </a>
                    <a href="pickup.php" style="display:flex;align-items:center;gap:6px;padding:.5rem 1rem;color:white;font-size:.8rem;font-weight:700;text-decoration:none;border-radius:10px;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.3);">
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

          

    <div class="container" style="padding-top:1.5rem;">

        <?php if ($flash): ?>
        <div class="<?= $flash['type'] === 'success' ? 'flash-success' : 'flash-error' ?>">
            <i class="fa-solid fa-<?= $flash['type'] === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
            <?= sanitize($flash['message']) ?>
        </div>
        <?php endif; ?>

        <!-- Quick Stats -->
        <div class="row g-3 mb-4">
            <div class="col-4">
                <div class="rider-stat" style="border-color:#FED7AA;">
                    <div class="rider-stat-icon" style="background:#FFF7ED;">
                        <i class="fa-solid fa-box-open" style="color:#F97316;"></i>
                    </div>
                    <div>
                        <div class="rider-stat-val" style="color:#F97316;"><?= $totalPickupReady ?></div>
                        <div class="rider-stat-lbl">Pickup Ready</div>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div class="rider-stat">
                    <div class="rider-stat-icon" style="background:#CFFAFE;">
                        <i class="fa-solid fa-truck-fast" style="color:#0E7490;"></i>
                    </div>
                    <div>
                        <div class="rider-stat-val" style="color:#0E7490;"><?= $inTransit ?></div>
                        <div class="rider-stat-lbl">Out for Delivery</div>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div class="rider-stat">
                    <div class="rider-stat-icon" style="background:#DCFCE7;">
                        <i class="fa-solid fa-circle-check" style="color:#16A34A;"></i>
                    </div>
                    <div>
                        <div class="rider-stat-val" style="color:#16A34A;"><?= $delivered ?></div>
                        <div class="rider-stat-lbl">Delivered</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Cards -->
        <?php if (empty($orders)): ?>
        <div class="order-card" style="border-color:#E2E8F0;">
            <div class="empty-state">
                <div class="icon">✅</div>
                <div style="font-weight:800;font-size:1.05rem;color:var(--text);margin-bottom:.5rem;">
                    No orders waiting for pickup!
                </div>
                <div style="font-size:.85rem;margin-bottom:1.25rem;">
                    All your assigned orders have been picked up or none have been assigned yet.
                </div>
                <a href="orders.php" style="display:inline-flex;align-items:center;gap:8px;background:var(--pale-green);color:var(--rider-primary);border-radius:10px;padding:.6rem 1.25rem;font-weight:700;font-size:.85rem;text-decoration:none;">
                    <i class="fa-solid fa-list"></i> View All Orders
                </a>
            </div>
        </div>
        <?php else: ?>

        <!-- Helper banner -->
        <div style="background:#FFF7ED;border:1px solid #FED7AA;border-radius:14px;padding:.85rem 1.2rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:10px;">
            <span style="font-size:1.3rem;">🛵</span>
            <div>
                <div style="font-weight:800;font-size:.85rem;color:#92400E;">You have <?= $totalPickupReady ?> order<?= $totalPickupReady > 1 ? 's' : '' ?> ready for pickup.</div>
                <div style="font-size:.75rem;color:#B45309;">Head to the farmer's location, collect the package, and press <strong>Confirm Pickup</strong>.</div>
            </div>
        </div>

        <?php foreach ($orders as $o): ?>
        <div class="order-card">

            <!-- Card Header -->
            <div class="order-card-header">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <div style="font-weight:800;color:#C2410C;font-size:.98rem;">Order #<?= $o['id'] ?></div>
                    <span class="priority-badge pulse">🔥 Pickup Now</span>
                    <span style="font-size:.72rem;color:#B45309;font-weight:700;">
                        📅 <?= date('M j, Y · g:ia', strtotime($o['created_at'])) ?>
                    </span>
                </div>
                <div style="text-align:right;">
                    <div style="font-weight:800;color:var(--rider-primary);font-size:1rem;">₱<?= number_format($o['delivery_fee'], 2) ?></div>
                    <div style="font-size:.68rem;color:var(--text-muted);">your delivery fee</div>
                </div>
            </div>

            <!-- Card Body -->
            <div class="order-card-body">

                <!-- Progress Steps -->
                <div class="step-bar">
                    <div class="step-item">
                        <div class="step-dot step-dot-done"><i class="fa-solid fa-check" style="font-size:.55rem;"></i></div>
                        <div class="step-label" style="color:#16A34A;">Order Placed</div>
                    </div>
                    <div class="step-line" style="background:linear-gradient(to right,#16A34A,#F97316);"></div>
                    <div class="step-item">
                        <div class="step-dot step-dot-active">2</div>
                        <div class="step-label" style="color:#F97316;font-weight:800;">Pickup</div>
                    </div>
                    <div class="step-line"></div>
                    <div class="step-item">
                        <div class="step-dot step-dot-inactive">3</div>
                        <div class="step-label">In Transit</div>
                    </div>
                    <div class="step-line"></div>
                    <div class="step-item">
                        <div class="step-dot step-dot-inactive">4</div>
                        <div class="step-label">Delivered</div>
                    </div>
                </div>

                <!-- Product Info -->
             <?php
$pImg = $o['product_image'] ?? null;
$pImgUrl = null;
if ($pImg) {
    $pImg = ltrim($pImg, '/');
    if (!str_contains($pImg, '/')) $pImg = 'assets/images/products/' . $pImg;
    $pImgUrl = BASE_URL . '/' . htmlspecialchars($pImg) . '?v=' . time();
}
?>
<div style="width:50px;height:50px;background:white;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">
    <?php if ($pImgUrl): ?>
        <img src="<?= $pImgUrl ?>" style="width:50px;height:50px;object-fit:cover;border-radius:10px;"
             onerror="this.style.display='none';this.parentElement.innerHTML='<?= $emojis[$o['product_category']] ?? '🌾' ?>';">
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

                    <!-- Pickup From -->
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
                        <div style="width:8px;height:8px;border-radius:50%;background:#F97316;"></div>
                        <div style="width:2px;height:22px;background:repeating-linear-gradient(to bottom,#F97316 0,#F97316 3px,transparent 3px,transparent 6px);"></div>
                        <i class="fa-solid fa-arrow-down" style="color:#F97316;font-size:.75rem;"></i>
                    </div>

                    <!-- Deliver To -->
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
                <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;padding-top:.25rem;border-top:1px dashed #FEE5C8;">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                        <button type="submit" name="pickup_order" class="btn-pickup pulse"
                                onclick="return confirm('Confirm pickup for Order #<?= $o['id'] ?>?\n\nThis will mark the order as In Transit.')">
                            <i class="fa-solid fa-box-open"></i> Confirm Pickup
                        </button>
                    </form>

                    <a href="messages.php?to=<?= $o['farmer_id'] ?>" class="btn-msg">
                        <i class="fa-solid fa-tractor"></i> Message Farmer
                    </a>
                    <a href="messages.php?to=<?= $o['buyer_id'] ?>" class="btn-msg">
                        <i class="fa-solid fa-comments"></i> Message Buyer
                    </a>
                </div>

            </div><!-- /card-body -->
        </div><!-- /order-card -->
        <?php endforeach; ?>
        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>