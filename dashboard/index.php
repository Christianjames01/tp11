<?php
$page_title = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();

// ── Stats ────────────────────────────────────────────────────────────────────
$totalFarmers  = $pdo->query("SELECT COUNT(*) FROM users WHERE role='farmer'")->fetchColumn();
$totalProducts = $pdo->query("SELECT COUNT(*) FROM products WHERE is_available=1")->fetchColumn();
$totalOrders   = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalBuyers   = $pdo->query("SELECT COUNT(*) FROM users WHERE role='buyer'")->fetchColumn();

// ── Top-selling products ─────────────────────────────────────────────────────
try {
    $topProducts = $pdo->query("
        SELECT p.id, p.name, p.price_per_kg, p.category, p.image, p.is_organic,
               p.stock_kg, p.min_order_kg, p.harvest_date,
               u.name AS farmer_name, u.location AS farmer_city,
               f.farm_name, f.rating,
               COUNT(oi.id) AS order_count
        FROM products p
        JOIN users u ON p.farmer_id = u.id
        LEFT JOIN farmers f ON f.user_id = p.farmer_id
        LEFT JOIN order_items oi ON oi.product_id = p.id
        WHERE p.is_available = 1 AND u.is_active = 1
        GROUP BY p.id, p.name, p.price_per_kg, p.category, p.image,
                 p.is_organic, p.stock_kg, p.min_order_kg, p.harvest_date,
                 u.name, u.location, f.farm_name, f.rating, p.created_at
        ORDER BY order_count DESC, p.created_at DESC
        LIMIT 6
    ")->fetchAll();
} catch (PDOException $e) {
    $topProducts = [];
}

if (empty($topProducts)) {
    try {
        $topProducts = $pdo->query("
            SELECT p.id, p.name, p.price_per_kg, p.category, p.image, p.is_organic,
                   p.stock_kg, p.min_order_kg, p.harvest_date,
                   u.name AS farmer_name, u.location AS farmer_city,
                   NULL AS farm_name, 0 AS rating, 0 AS order_count
            FROM products p JOIN users u ON p.farmer_id = u.id
            WHERE p.is_available = 1
            ORDER BY p.created_at DESC LIMIT 6
        ")->fetchAll();
    } catch (PDOException $e) {
        $topProducts = [];
    }
}

// ── Newest products ───────────────────────────────────────────────────────────
try {
    $newProducts = $pdo->query("
        SELECT p.id, p.name, p.price_per_kg, p.category, p.image, p.is_organic,
               p.stock_kg, p.min_order_kg, p.harvest_date,
               u.name AS farmer_name, u.location AS farmer_city,
               NULL AS farm_name, 0 AS rating
        FROM products p JOIN users u ON p.farmer_id = u.id
        WHERE p.is_available = 1 AND u.is_active = 1
        ORDER BY p.created_at DESC LIMIT 3
    ")->fetchAll();
} catch (PDOException $e) {
    $newProducts = [];
}

// ── All farmer profiles ────────────────────────────────────────────────────────
try {
    $allFarmers = $pdo->query("
        SELECT u.id, u.name, u.location, u.profile_image,
               f.farm_name, f.farm_location, f.bio, f.rating,
               f.is_premium, f.premium_until,
               COUNT(p.id) AS product_count
        FROM users u
        LEFT JOIN farmers f ON f.user_id = u.id
        LEFT JOIN products p ON p.farmer_id = u.id AND p.is_available = 1
        WHERE u.role = 'farmer' AND u.is_active = 1
        GROUP BY u.id, u.name, u.location, u.profile_image,
                 f.farm_name, f.farm_location, f.bio, f.rating
        ORDER BY f.is_premium DESC, product_count DESC, u.created_at ASC
    ")->fetchAll();
} catch (PDOException $e) {
    $allFarmers = $pdo->query("
        SELECT u.id, u.name, u.location,
               NULL AS profile_image,
              f.farm_name, f.farm_location, f.bio, f.rating,
               f.is_premium, f.premium_until,
               COUNT(p.id) AS product_count
        FROM users u
        LEFT JOIN farmers f ON f.user_id = u.id
        LEFT JOIN products p ON p.farmer_id = u.id AND p.is_available = 1
        WHERE u.role = 'farmer'
        GROUP BY u.id, u.name, u.location, f.farm_name, f.farm_location, f.bio, f.rating
        ORDER BY product_count DESC
    ")->fetchAll();
}
$topFarmers = array_slice($allFarmers, 0, 4);

// ── Recent orders ─────────────────────────────────────────────────────────────
try {
    $recentOrders = $pdo->query("
        SELECT o.id, o.status, o.total_amount, o.created_at,
               u.name AS buyer_name, p.name AS product_name
        FROM orders o
        JOIN users u ON u.id = o.buyer_id
        JOIN order_items oi ON oi.order_id = o.id
        JOIN products p ON p.id = oi.product_id
        ORDER BY o.created_at DESC LIMIT 5
    ")->fetchAll();
} catch (PDOException $e) {
    $recentOrders = [];
}

$emojis = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕','Livestock'=>'🐄','Seafood'=>'🐟','Others'=>'📦'];
$statusColors = [
    'pending'   => ['bg'=>'#FFF8E1','color'=>'#F59E0B','label'=>'Pending'],
    'confirmed' => ['bg'=>'#E8F5E9','color'=>'#22C55E','label'=>'Confirmed'],
    'delivered' => ['bg'=>'#E3F2FD','color'=>'#3B82F6','label'=>'Delivered'],
    'cancelled' => ['bg'=>'#FEE2E2','color'=>'#EF4444','label'=>'Cancelled'],
];
?>

<style>
.stat-card {
    background: white; border-radius: 16px;
    border: 1px solid var(--border);
    padding: 1.25rem 1.4rem;
    display: flex; align-items: center; gap: 1rem;
    transition: transform 0.2s, box-shadow 0.2s; height: 100%;
}
.stat-card:hover { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(0,0,0,0.1); }
.stat-icon { width:50px;height:50px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0; }
.stat-num  { font-family:'Playfair Display',serif;font-size:1.9rem;font-weight:700;line-height:1;color:var(--text); }
.stat-lbl  { font-size:0.7rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;margin-top:3px; }
.stat-trend{ font-size:0.69rem;color:var(--primary);font-weight:600;margin-top:2px; }

.db-section { padding: 2.25rem 0 0; }
.db-section-head { display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.1rem; }
.db-section-title { font-family:'Playfair Display',serif;font-size:1.45rem;font-weight:700;color:var(--text); }
.db-section-title span { color:var(--primary); }
.db-section-sub   { font-size:0.78rem;color:var(--text-muted);margin-top:2px; }
.db-view-all { font-size:0.78rem;font-weight:700;color:var(--primary);text-decoration:none;display:flex;align-items:center;gap:5px;text-transform:uppercase;letter-spacing:.05em; }
.db-view-all:hover { color:var(--primary-dark); }

.farmer-card {
    background:white;border-radius:14px;border:1px solid var(--border);
    padding:1rem 1.2rem;display:flex;align-items:center;gap:.85rem;
    transition:transform .2s,box-shadow .2s;text-decoration:none;color:inherit;height:100%;
}
.farmer-card:hover { transform:translateY(-3px);box-shadow:0 10px 26px rgba(0,0,0,0.09);color:inherit; }
.farmer-avatar { width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--primary),#66BB6A);display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:800;color:white;font-family:'Playfair Display',serif;flex-shrink:0; }
.farmer-name  { font-weight:800;font-size:.9rem;color:var(--text); }
.farmer-loc   { font-size:.7rem;color:var(--text-muted);display:flex;align-items:center;gap:4px;margin-top:2px; }
.farmer-badge { display:inline-flex;align-items:center;gap:4px;background:var(--pale-green);color:var(--primary);border-radius:6px;padding:2px 8px;font-size:.67rem;font-weight:700;margin-top:5px; }

.new-listing-item { display:flex;align-items:center;gap:11px;padding:.8rem 0;border-bottom:1px solid var(--border);text-decoration:none;color:inherit;transition:color .15s; }
.new-listing-item:last-of-type { border-bottom:none; }
.new-listing-item:hover { color:var(--primary); }
.new-listing-emoji { width:42px;height:42px;background:var(--pale-green);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;overflow:hidden; }
.new-listing-name   { font-weight:700;font-size:.86rem;color:var(--text); }
.new-listing-farmer { font-size:.7rem;color:var(--text-muted);margin-top:1px; }
.new-listing-price  { margin-left:auto;flex-shrink:0;font-weight:800;font-size:.86rem;color:var(--primary); }

.badge-hot { position:absolute;top:10px;right:10px;background:#EF4444;color:white;font-size:.6rem;font-weight:800;padding:3px 8px;border-radius:6px;letter-spacing:.04em;text-transform:uppercase; }

/* ── Farmer profile cards ── */
.farmer-profile-card {
    background: white;
    border-radius: 18px;
    border: 1px solid var(--border);
    overflow: hidden;
    transition: transform 0.25s, box-shadow 0.25s;
    height: 100%;
    display: flex;
    flex-direction: column;
}
.farmer-profile-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 16px 40px rgba(0,0,0,0.11);
}
.fpc-stripe {
    height: 70px;
    width: 100%;
    flex-shrink: 0;
}
.fpc-avatar-wrap {
    display: flex;
    justify-content: center;
    margin-top: -36px;
    margin-bottom: .6rem;
    position: relative;
    z-index: 2;
}
.fpc-avatar-img {
    width: 72px; height: 72px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid white;
    box-shadow: 0 4px 14px rgba(0,0,0,0.15);
}
.fpc-avatar-initials {
    width: 72px; height: 72px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 1.5rem; color: white;
    border: 4px solid white;
    box-shadow: 0 4px 14px rgba(0,0,0,0.15);
    font-family: 'Playfair Display', serif;
}
.fpc-body {
    padding: 0 1.1rem 1.25rem;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.fpc-name {
    font-family: 'Playfair Display', serif;
    font-size: 1rem; font-weight: 700;
    color: var(--text);
    text-align: center;
    display: flex; align-items: center; justify-content: center; gap: 5px;
}
.fpc-verified {
    background: var(--primary); color: white;
    font-size: .58rem; font-weight: 800;
    width: 16px; height: 16px;
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.fpc-farm {
    font-size: .75rem; font-weight: 700; color: var(--primary);
    text-align: center; margin-top: 3px;
    display: flex; align-items: center; justify-content: center; gap: 5px;
}
.fpc-location {
    font-size: .7rem; color: var(--text-muted);
    text-align: center; margin-top: 3px;
    display: flex; align-items: center; justify-content: center; gap: 4px;
}
.fpc-bio {
    font-size: .76rem; color: var(--text-muted);
    text-align: center; line-height: 1.55;
    margin: .75rem 0 .6rem;
    flex: 1;
}
.fpc-stats {
    display: flex; align-items: center; justify-content: center;
    gap: 0; background: var(--bg);
    border-radius: 10px; padding: .55rem 0;
}
.fpc-stat { flex: 1; text-align: center; }
.fpc-stat-num { font-family:'Playfair Display',serif;font-weight:700;font-size:1.05rem;color:var(--text); }
.fpc-stat-lbl { font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted); }
.fpc-stat-divider { width:1px;height:32px;background:var(--border);flex-shrink:0; }

.orders-panel { background:white;border-radius:16px;border:1px solid var(--border);overflow:hidden; }
.orders-table { width:100%;border-collapse:collapse; }
.orders-table th { font-size:.63rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);padding:.65rem 1.1rem;text-align:left;border-bottom:1px solid var(--border);background:var(--bg); }
.orders-table td { padding:.82rem 1.1rem;font-size:.83rem;color:var(--text);border-bottom:1px solid var(--border);vertical-align:middle; }
.orders-table tr:last-child td { border-bottom:none; }
.orders-table tr:hover td { background:var(--bg); }
.status-pill { display:inline-flex;align-items:center;padding:3px 10px;border-radius:99px;font-size:.67rem;font-weight:700;letter-spacing:.04em; }
</style>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">

    <!-- ══ HERO HEADER ══ -->
    <div style="background:linear-gradient(135deg,#1B5E20,#2E7D32);padding:2.5rem 0 1.75rem;">
        <div class="container">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                    <p style="color:rgba(255,255,255,.7);font-size:.78rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;margin-bottom:4px;">
                        🌿 GreenLink Marketplace
                    </p>
                    <h2 style="color:white;font-family:'Playfair Display',serif;font-size:clamp(1.45rem,3vw,2.1rem);margin:0;line-height:1.2;">
                        Good <?= date('H') < 12 ? 'Morning' : (date('H') < 17 ? 'Afternoon' : 'Evening') ?>,
                        <span style="color:#A5D6A7;"><?= sanitize($_SESSION['name'] ?? 'Friend') ?> 👋</span>
                    </h2>
                    <p style="color:rgba(255,255,255,.6);font-size:.83rem;margin-top:4px;">
                        <?= date('l, F j, Y') ?> &nbsp;·&nbsp; Here's what's happening on the marketplace
                    </p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="<?= BASE_URL ?>/buyer/browse.php" class="btn-green" style="font-size:.83rem;padding:.5rem 1.1rem;">
                        <i class="fa-solid fa-store"></i> Browse Products
                    </a>
                    <?php if (isLoggedIn()):
                        $role = $_SESSION['role'] ?? '';
                        if ($role === 'farmer'): ?>
                        <a href="<?= BASE_URL ?>/farmer/dashboard.php" class="btn-outline-green" style="background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.4);color:white;font-size:.83rem;padding:.5rem 1.1rem;">
                            <i class="fa-solid fa-tractor"></i> My Farm
                        </a>
                        <?php elseif ($role === 'buyer'): ?>
                        <a href="<?= BASE_URL ?>/orders/index.php" class="btn-outline-green" style="background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.4);color:white;font-size:.83rem;padding:.5rem 1.1rem;">
                            <i class="fa-solid fa-box"></i> My Orders
                        </a>
                        <?php elseif ($role === 'admin'): ?>
                        <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn-outline-green" style="background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.4);color:white;font-size:.83rem;padding:.5rem 1.1rem;">
                            <i class="fa-solid fa-shield-halved"></i> Admin Panel
                        </a>
                        <?php endif;
                    endif; ?>
                </div>
            </div>

            <!-- Stat strip -->
            <div class="row g-3">
                <?php
                $stats = [
                    ['🌾', $totalFarmers,  'Active Farmers',    '#E8F5E9', '↑ Mindanao\'s best'],
                    ['🥬', $totalProducts, 'Products Listed',   '#FFF8E1', '↑ Fresh & available'],
                    ['📦', $totalOrders,   'Orders Completed',  '#E3F2FD', '↑ Farm to table'],
                    ['🛒', $totalBuyers,   'Registered Buyers', '#FCE4EC', '↑ Restaurants & more'],
                ];
                foreach ($stats as [$icon,$num,$lbl,$bg,$trend]): ?>
                <div class="col-6 col-md-3 animate-on-scroll">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:<?= $bg ?>;"><?= $icon ?></div>
                        <div>
                            <div class="stat-num"><?= number_format($num) ?>+</div>
                            <div class="stat-lbl"><?= $lbl ?></div>
                            <div class="stat-trend"><?= $trend ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="container">

       <?php if (isLoggedIn()): $role = $_SESSION['role'] ?? ''; ?>

        <?php if ($role === 'farmer'):
            // Fetch this farmer's own data
            $myFarm = $pdo->prepare("
                SELECT u.name, u.location, u.profile_image,
                       f.farm_name, f.farm_location, f.is_premium, f.premium_until, f.rating,
                       COUNT(p.id) AS product_count
                FROM users u
                LEFT JOIN farmers f ON f.user_id = u.id
                LEFT JOIN products p ON p.farmer_id = u.id AND p.is_available = 1
                WHERE u.id = ?
                GROUP BY u.name, u.location, u.profile_image, f.farm_name, f.farm_location,
                         f.is_premium, f.premium_until, f.rating
            ");
            $myFarm->execute([$_SESSION['user_id']]);
            $myFarm = $myFarm->fetch();
            $isPremium = !empty($myFarm['is_premium']) && !empty($myFarm['premium_until']) && strtotime($myFarm['premium_until']) > time();
        ?>
        <div style="margin-top:1.75rem;">
            <div style="background:linear-gradient(135deg,#1B5E20,#2E7D32);border-radius:20px;padding:1.5rem 2rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;box-shadow:0 8px 30px rgba(27,94,32,.25);">
                <div style="display:flex;align-items:center;gap:1rem;">
                    <div style="width:54px;height:54px;border-radius:14px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:1.8rem;flex-shrink:0;">🏆</div>
                    <div>
                        <div style="color:rgba(255,255,255,.75);font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">Your Farm is Featured</div>
                        <div style="color:white;font-size:1.05rem;font-weight:800;margin-top:2px;">
                            <?= sanitize($myFarm['farm_name'] ?? ($_SESSION['name'] . "'s Farm")) ?>
                            <?php if ($isPremium): ?>
                            <span style="background:linear-gradient(135deg,#78350f,#d97706);font-size:.62rem;padding:2px 9px;border-radius:99px;margin-left:6px;vertical-align:middle;">⭐ PREMIUM</span>
                            <?php endif; ?>
                        </div>
                        <div style="color:rgba(255,255,255,.65);font-size:.78rem;margin-top:3px;">
                            <i class="fa-solid fa-location-dot" style="margin-right:4px;"></i><?= sanitize($myFarm['farm_location'] ?? $myFarm['location'] ?? 'Mindanao') ?>
                            &nbsp;·&nbsp; <?= $myFarm['product_count'] ?> product<?= $myFarm['product_count'] != 1 ? 's' : '' ?> listed
                            <?php if (!empty($myFarm['rating']) && $myFarm['rating'] > 0): ?>
                            &nbsp;·&nbsp; ⭐ <?= number_format($myFarm['rating'],1) ?> rating
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div style="display:flex;gap:.65rem;flex-wrap:wrap;">
                    <a href="<?= BASE_URL ?>/farmer/dashboard.php" class="btn-green" style="background:rgba(255,255,255,.18);border:1.5px solid rgba(255,255,255,.4);color:white;font-size:.82rem;padding:.45rem 1.1rem;">
                        <i class="fa-solid fa-tractor"></i> My Dashboard
                    </a>
                    <a href="<?= BASE_URL ?>/farmer/products.php" class="btn-green" style="background:white;color:#1B5E20;font-size:.82rem;padding:.45rem 1.1rem;">
                        <i class="fa-solid fa-plus"></i> Add Product
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($role === 'buyer'):
            // Fetch buyer's order history to find preferred categories
            $buyerPrefs = $pdo->prepare("
                SELECT p.category, COUNT(*) AS cnt
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                JOIN products p ON p.id = oi.product_id
                WHERE o.buyer_id = ?
                GROUP BY p.category ORDER BY cnt DESC LIMIT 2
            ");
            $buyerPrefs->execute([$_SESSION['user_id']]);
            $prefCats = $buyerPrefs->fetchAll(PDO::FETCH_COLUMN);

            // Recommended: if they have history, match category; else newest
            if (!empty($prefCats)) {
                $inList = implode(',', array_fill(0, count($prefCats), '?'));
                $recStmt = $pdo->prepare("
                    SELECT p.id, p.name, p.price_per_kg, p.category, p.image, p.is_organic,
                           p.stock_kg, u.name AS farmer_name, u.location AS farmer_city,
                           f.farm_name, f.rating
                    FROM products p
                    JOIN users u ON p.farmer_id = u.id
                    LEFT JOIN farmers f ON f.user_id = p.farmer_id
                    WHERE p.is_available = 1 AND u.is_active = 1 AND p.category IN ($inList)
                    ORDER BY RAND() LIMIT 3
                ");
                $recStmt->execute($prefCats);
            } else {
                $recStmt = $pdo->query("
                    SELECT p.id, p.name, p.price_per_kg, p.category, p.image, p.is_organic,
                           p.stock_kg, u.name AS farmer_name, u.location AS farmer_city,
                           f.farm_name, f.rating
                    FROM products p
                    JOIN users u ON p.farmer_id = u.id
                    LEFT JOIN farmers f ON f.user_id = p.farmer_id
                    WHERE p.is_available = 1 AND u.is_active = 1
                    ORDER BY p.created_at DESC LIMIT 3
                ");
            }
            $recProducts = $recStmt->fetchAll();
        ?>
        <?php if (!empty($recProducts)): ?>
        <div style="margin-top:1.75rem;">
            <div style="display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1rem;">
                <div>
                    <div class="db-section-title">
                        🎯 Recommended <span>For You</span>
                    </div>
                    <div class="db-section-sub">
                        <?= !empty($prefCats)
                            ? 'Based on your past orders in ' . implode(' & ', array_map('sanitize', $prefCats))
                            : 'Fresh picks just added to the marketplace' ?>
                    </div>
                </div>
                <a href="<?= BASE_URL ?>/buyer/browse.php" class="db-view-all">Browse All <i class="fa-solid fa-arrow-right"></i></a>
            </div>
            <div class="row g-3">
                <?php foreach ($recProducts as $p): ?>
                <div class="col-sm-6 col-lg-4 animate-on-scroll">
                    <div class="product-card h-100">
                        <?php if ($p['is_organic']): ?><span class="badge-organic">🌿 Organic</span><?php endif; ?>
                        <div class="product-card-img" style="cursor:pointer;"
                             onclick="window.location='<?= BASE_URL ?>/buyer/product.php?id=<?= $p['id'] ?>'">
                            <?php if ($p['image']): ?>
                                <img src="<?= BASE_URL ?>/assets/images/products/<?= sanitize($p['image']) ?>" alt="<?= sanitize($p['name']) ?>">
                            <?php else: ?>
                                <?= $emojis[$p['category']] ?? '🌾' ?>
                            <?php endif; ?>
                        </div>
                        <div class="product-card-body">
                            <span class="badge-category"><?= sanitize($p['category']) ?></span>
                            <div class="product-card-title mt-1"><?= sanitize($p['name']) ?></div>
                            <div class="product-card-meta">
                                <i class="fa-solid fa-user text-green"></i>
                                <?= sanitize($p['farm_name'] ?? $p['farmer_name']) ?>
                            </div>
                            <div class="product-card-meta">
                                <i class="fa-solid fa-location-dot text-green"></i>
                                <?= sanitize($p['farmer_city'] ?? '') ?>
                            </div>
                            <div class="d-flex align-items-center justify-content-between mt-2">
                                <div class="product-card-price">
                                    ₱<?= number_format($p['price_per_kg'],2) ?><span>/kg</span>
                                </div>
                                <a href="<?= BASE_URL ?>/buyer/product.php?id=<?= $p['id'] ?>" class="btn-green" style="padding:.4rem 1rem;font-size:.8rem;">
                                    <i class="fa-solid fa-cart-plus"></i> Order
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; // buyer ?>

        <?php endif; // isLoggedIn ?>

        <!-- ══ TOP-SELLING PRODUCTS ══ -->
        <div class="db-section">    
            <div class="db-section-head">
                <div>
                    <div class="db-section-title">🔥 Top <span>Selling</span> Products</div>
                    <div class="db-section-sub">Most ordered products on the marketplace</div>
                </div>
                <a href="<?= BASE_URL ?>/buyer/browse.php" class="db-view-all">
                    View All <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <?php if (empty($topProducts)): ?>
            <div class="gl-card">
                <div class="gl-card-body empty-state" style="text-align:center;padding:3rem 2rem;">
                    <div style="font-size:3rem;margin-bottom:1rem;">🌱</div>
                    <h5 style="font-weight:800;color:var(--text);margin-bottom:.5rem;">No products listed yet</h5>
                    <p style="color:var(--text-muted);font-size:.88rem;">Be the first farmer to add produce!</p>
                    <a href="<?= BASE_URL ?>/auth/register.php?role=farmer" class="btn-green mt-3" style="display:inline-flex;">
                        Register as Farmer
                    </a>
                </div>
            </div>
            <?php else: ?>
            <div class="row g-3" id="products-grid">
                <?php foreach ($topProducts as $p): ?>
                <div class="col-sm-6 col-xl-4 animate-on-scroll">
                    <div class="product-card h-100" style="<?= ($p['stock_kg'] ?? 1) <= 0 ? 'opacity:0.65;' : '' ?>">
                        <?php if (($p['stock_kg'] ?? 1) <= 0): ?>
                            <span style="position:absolute;top:10px;left:10px;background:#EF4444;color:white;font-size:.72rem;font-weight:800;padding:4px 10px;border-radius:20px;z-index:10;letter-spacing:.03em;box-shadow:0 2px 8px rgba(239,68,68,.35);">🚫 Out of Stock</span>
                        <?php endif; ?>
                        <?php if ($p['is_organic']): ?><span class="badge-organic">🌿 Organic</span><?php endif; ?>
                        <?php if (!empty($p['order_count']) && $p['order_count'] > 0): ?>
                            <span class="badge-hot">🔥 <?= $p['order_count'] ?> orders</span>
                        <?php endif; ?>
                        <div class="product-card-img" style="cursor:pointer;"
                             onclick="window.location='<?= BASE_URL ?>/buyer/product.php?id=<?= $p['id'] ?>'">
                            <?php if ($p['image']): ?>
                                <img src="<?= BASE_URL ?>/assets/images/products/<?= sanitize($p['image']) ?>" alt="<?= sanitize($p['name']) ?>">
                            <?php else: ?>
                                <?= $emojis[$p['category']] ?? '🌾' ?>
                            <?php endif; ?>
                        </div>
                        <div class="product-card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="badge-category"><?= sanitize($p['category']) ?></span>
                                <?php if (!empty($p['rating']) && $p['rating'] > 0): ?>
                                    <span style="font-size:.72rem;font-weight:700;color:#F59E0B;">⭐ <?= number_format($p['rating'],1) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="product-card-title mt-1"><?= sanitize($p['name']) ?></div>
                            <div class="product-card-meta">
                                <i class="fa-solid fa-user text-green"></i>
                                <?= sanitize($p['farm_name'] ?? $p['farmer_name']) ?>
                            </div>
                            <div class="product-card-meta prod-location">
                                <i class="fa-solid fa-location-dot text-green"></i>
                                <?= sanitize($p['farmer_city'] ?? '') ?>
                            </div>
                            <?php if (!empty($p['harvest_date'])): ?>
                            <div class="product-card-meta">
                                <i class="fa-solid fa-calendar-days text-green"></i>
                                <?= date('M j, Y', strtotime($p['harvest_date'])) ?>
                            </div>
                            <?php endif; ?>
                            <?php if (isset($p['stock_kg'])): ?>
                            <div style="font-size:.75rem;color:var(--text-muted);margin-top:4px;">
                                Min. order: <?= $p['min_order_kg'] ?>kg &nbsp;|&nbsp; Stock: <?= number_format($p['stock_kg'],0) ?>kg
                            </div>
                            <?php endif; ?>
                            <div class="d-flex align-items-center justify-content-between mt-2">
                                <div class="product-card-price" data-price="<?= $p['price_per_kg'] ?>">
                                    ₱<?= number_format($p['price_per_kg'],2) ?><span>/kg</span>
                                </div>
                                <?php if (($p['stock_kg'] ?? 1) > 0): ?>
                                <a href="<?= BASE_URL ?>/buyer/product.php?id=<?= $p['id'] ?>" class="btn-green" style="padding:.4rem 1rem;font-size:.8rem;">
                                    <i class="fa-solid fa-cart-plus"></i> Order
                                </a>
                                <?php else: ?>
                                <span style="padding:.4rem 1rem;font-size:.8rem;font-weight:700;color:#9CA3AF;background:#F3F4F6;border-radius:8px;cursor:not-allowed;">Unavailable</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ══ TOP FARMERS + NEW LISTINGS ══ -->
        <div class="db-section">
            <div class="row g-4">

                <!-- Top Farmers -->
                <div class="col-lg-7">
                <div class="db-section-head">
                        <div>
                            <div class="db-section-title">⭐ Top <span>Farmers</span></div>
                            <div class="db-section-sub">Premium sellers on GreenLink</div>
                        </div>
                        <a href="<?= BASE_URL ?>/dashboard/browse.php" class="db-view-all">
                            Browse All <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                    <div class="row g-3">
                        <?php
                        $premiumFarmers = array_filter($topFarmers, fn($f) =>
                            !empty($f['is_premium']) && !empty($f['premium_until']) && strtotime($f['premium_until']) > time()
                        );
                        if (empty($premiumFarmers)): ?>
                        <div class="col-12" style="color:var(--text-muted);font-size:.88rem;padding:1rem 0;">No premium farmers yet.</div>
                        <?php else: foreach ($premiumFarmers as $f): ?>
                        <div class="col-sm-6 animate-on-scroll">
                          <a href="<?= BASE_URL ?>/buyer/browse.php?farmer=<?= $f['id'] ?>" class="farmer-card">
    <?php if (!empty($f['profile_image'])): ?>
        <img src="<?= BASE_URL ?>/assets/images/profiles/<?= sanitize($f['profile_image']) ?>"
             style="width:48px;height:48px;border-radius:12px;object-fit:cover;border:2px solid var(--primary);flex-shrink:0;">
    <?php else: ?>
        <div class="farmer-avatar"><?= strtoupper(substr($f['name'],0,1)) ?></div>
    <?php endif; ?>
    <div style="min-width:0;">
        <div class="farmer-name">
            <?= sanitize($f['name']) ?>
            <?php if (!empty($f['is_premium']) && !empty($f['premium_until']) && strtotime($f['premium_until']) > time()): ?>
            <span style="background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.52rem;font-weight:800;padding:2px 7px;border-radius:99px;letter-spacing:.04em;vertical-align:middle;margin-left:3px;">⭐</span>
            <?php endif; ?>
        </div>
        <div class="farmer-loc">
            <i class="fa-solid fa-location-dot text-green" style="font-size:.6rem;"></i>
            <?= sanitize($f['location'] ?? 'Mindanao') ?>
        </div>
        <?php if (!empty($f['is_premium']) && !empty($f['premium_until']) && strtotime($f['premium_until']) > time()): ?>
        <div style="font-size:.65rem;font-weight:700;color:#d97706;margin-top:2px;">⭐ Premium Seller</div>
        <?php endif; ?>
        <div class="farmer-badge">
            🥬 <?= $f['product_count'] ?> product<?= $f['product_count'] != 1 ? 's' : '' ?>
        </div>
    </div>
</a>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <!-- New Listings -->
                <div class="col-lg-5 animate-on-scroll">
                    <div class="db-section-head">
                        <div>
                            <div class="db-section-title">🆕 New <span>Listings</span></div>
                            <div class="db-section-sub">Just added to the marketplace</div>
                        </div>
                    </div>
                    <div class="gl-card" style="border-radius:16px;">
                        <div class="gl-card-body" style="padding:.5rem 1.25rem 1rem;">
                            <?php if (empty($newProducts)): ?>
                            <p style="color:var(--text-muted);font-size:.85rem;padding:1rem 0;">No products yet.</p>
                            <?php else: foreach ($newProducts as $lp): ?>
                            <a href="<?= BASE_URL ?>/buyer/product.php?id=<?= $lp['id'] ?>" class="new-listing-item">
                                <div class="new-listing-emoji">
                                    <?php if ($lp['image']): ?>
                                        <img src="<?= BASE_URL ?>/assets/images/products/<?= sanitize($lp['image']) ?>" style="width:42px;height:42px;object-fit:cover;border-radius:10px;">
                                    <?php else: ?>
                                        <?= $emojis[$lp['category']] ?? '🌾' ?>
                                    <?php endif; ?>
                                </div>
                                <div style="min-width:0;">
                                    <div class="new-listing-name"><?= sanitize($lp['name']) ?></div>
                                    <div class="new-listing-farmer">
                                        by <?= sanitize($lp['farm_name'] ?? $lp['farmer_name']) ?>
                                        &nbsp;·&nbsp; <?= sanitize($lp['farmer_city'] ?? '') ?>
                                    </div>
                                </div>
                                <div class="new-listing-price">
                                    ₱<?= number_format($lp['price_per_kg'],2) ?><span style="font-size:.67rem;font-weight:400;color:var(--text-muted);">/kg</span>
                                </div>
                            </a>
                            <?php endforeach; endif; ?>
                            <a href="<?= BASE_URL ?>/buyer/browse.php" class="btn-green justify-content-center" style="width:100%;margin-top:.85rem;font-size:.82rem;">
                                Browse All Products <i class="fa-solid fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

      <!-- ══ HARVEST CALENDAR ══ -->
        <div class="db-section">
            <div class="db-section-head">
                <div>
                    <div class="db-section-title">📅 Harvest <span>Calendar</span></div>
                    <div class="db-section-sub">What's fresh and in season this <?= date('F') ?></div>
                </div>
                <a href="<?= BASE_URL ?>/buyer/browse.php" class="db-view-all">
                    Shop Now <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <?php
            // Harvest calendar data — month => [category => [products]]
            $harvestCalendar = [
                1  => ['Vegetables'=>['Pechay','Kangkong','Sitaw'],'Fruits'=>['Banana','Papaya'],'Grains'=>['Rice','Corn']],
                2  => ['Vegetables'=>['Ampalaya','Eggplant','Okra'],'Fruits'=>['Mango','Banana'],'Grains'=>['Rice']],
                3  => ['Fruits'=>['Mango','Watermelon','Jackfruit'],'Vegetables'=>['Tomato','Pepper'],'Coffee'=>['Robusta']],
                4  => ['Fruits'=>['Mango','Lanzones','Rambutan'],'Seafood'=>['Bangus','Tilapia'],'Coffee'=>['Arabica','Robusta']],
                5  => ['Fruits'=>['Durian','Mangosteen','Lanzones'],'Vegetables'=>['Squash','Ampalaya'],'Coffee'=>['Arabica']],
                6  => ['Fruits'=>['Durian','Marang','Rambutan'],'Vegetables'=>['Kamote','Gabi'],'Grains'=>['Corn']],
                7  => ['Fruits'=>['Durian','Marang','Pomelo'],'Vegetables'=>['Pechay','Kangkong'],'Grains'=>['Rice']],
                8  => ['Fruits'=>['Pomelo','Banana','Papaya'],'Vegetables'=>['Sitaw','Okra','Eggplant'],'Grains'=>['Rice']],
                9  => ['Vegetables'=>['Tomato','Pepper','Squash'],'Fruits'=>['Banana','Papaya'],'Grains'=>['Corn','Rice']],
                10 => ['Vegetables'=>['Pechay','Cabbage','Carrot'],'Fruits'=>['Mandarin','Pomelo'],'Grains'=>['Rice']],
                11 => ['Vegetables'=>['Cabbage','Broccoli','Carrot'],'Fruits'=>['Mandarin','Banana'],'Livestock'=>['Chicken','Pork']],
                12 => ['Vegetables'=>['Pechay','Kangkong','Sitaw'],'Fruits'=>['Banana','Papaya','Mandarin'],'Grains'=>['Rice']],
            ];

            $currentMonth  = (int) date('n');
            $prevMonth     = $currentMonth === 1  ? 12 : $currentMonth - 1;
            $nextMonth     = $currentMonth === 12 ?  1 : $currentMonth + 1;
            $monthNames    = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

            $categoryEmoji = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕','Livestock'=>'🐄','Seafood'=>'🐟','Others'=>'📦'];
            $categoryColor = [
                'Vegetables' => ['bg'=>'#E8F5E9','color'=>'#2E7D32','border'=>'#A5D6A7'],
                'Fruits'     => ['bg'=>'#FFF8E1','color'=>'#F57F17','border'=>'#FFE082'],
                'Grains'     => ['bg'=>'#FFF3E0','color'=>'#E65100','border'=>'#FFCC80'],
                'Coffee'     => ['bg'=>'#EFEBE9','color'=>'#4E342E','border'=>'#BCAAA4'],
                'Livestock'  => ['bg'=>'#FCE4EC','color'=>'#880E4F','border'=>'#F48FB1'],
                'Seafood'    => ['bg'=>'#E3F2FD','color'=>'#0D47A1','border'=>'#90CAF9'],
                'Others'     => ['bg'=>'#F3E5F5','color'=>'#4A148C','border'=>'#CE93D8'],
            ];

            // Try to match harvest items with real DB products
            $currentSeasonCategories = array_keys($harvestCalendar[$currentMonth] ?? []);
            try {
                $seasonProducts = [];
                if (!empty($currentSeasonCategories)) {
                    $inList = implode(',', array_fill(0, count($currentSeasonCategories), '?'));
                    $sp = $pdo->prepare("
                        SELECT p.id, p.name, p.category, p.price_per_kg, p.image
                        FROM products p
                        JOIN users u ON p.farmer_id = u.id
                        WHERE p.is_available = 1 AND u.is_active = 1 AND p.category IN ($inList)
                        ORDER BY RAND() LIMIT 12
                    ");
                    $sp->execute($currentSeasonCategories);
                    $seasonProducts = $sp->fetchAll();
                    // Index by category
                    $seasonProductsByCategory = [];
                    foreach ($seasonProducts as $sp) {
                        $seasonProductsByCategory[$sp['category']][] = $sp;
                    }
                }
            } catch (PDOException $e) {
                $seasonProductsByCategory = [];
            }
            ?>

            <!-- Month Tabs -->
            <div style="display:flex;gap:.5rem;overflow-x:auto;padding-bottom:.5rem;scrollbar-width:none;margin-bottom:1.25rem;" id="calMonthTabs">
                <?php for ($m = 1; $m <= 12; $m++):
                    $isActive = $m === $currentMonth;
                    $hasProduce = !empty($harvestCalendar[$m]);
                ?>
                <button onclick="switchCalMonth(<?= $m ?>)"
                    id="calTab<?= $m ?>"
                    style="flex-shrink:0;padding:.45rem 1rem;border-radius:99px;font-size:.75rem;font-weight:700;cursor:pointer;transition:all .2s;white-space:nowrap;
                        <?= $isActive
                            ? 'background:var(--primary);color:white;border:2px solid var(--primary);'
                            : 'background:white;color:var(--text-muted);border:2px solid var(--border);' ?>">
                    <?= substr($monthNames[$m], 0, 3) ?>
                    <?php if ($m === $currentMonth): ?><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:white;margin-left:4px;vertical-align:middle;"></span><?php endif; ?>
                </button>
                <?php endfor; ?>
            </div>

            <!-- Calendar Panels -->
            <?php for ($m = 1; $m <= 12; $m++):
                $isActive = $m === $currentMonth;
                $monthData = $harvestCalendar[$m] ?? [];
            ?>
            <div id="calPanel<?= $m ?>" style="display:<?= $isActive ? 'block' : 'none' ?>;">
                <?php if (empty($monthData)): ?>
                <div style="text-align:center;padding:2rem;color:var(--text-muted);font-size:.88rem;">No harvest data for this month.</div>
                <?php else: ?>

                <!-- Season label -->
                <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;">
                    <?php
                    $season = '';
                    if (in_array($m, [12,1,2]))      $season = '❄️ Cool Dry Season';
                    elseif (in_array($m, [3,4,5]))   $season = '☀️ Hot Dry Season';
                    elseif (in_array($m, [6,7,8,9])) $season = '🌧️ Rainy Season';
                    else                              $season = '🌤️ Cool Wet Season';
                    ?>
                    <span style="background:var(--pale-green);color:var(--primary);font-size:.72rem;font-weight:700;padding:3px 12px;border-radius:99px;">
                        <?= $season ?>
                    </span>
                    <?php if ($m === $currentMonth): ?>
                    <span style="background:#FFF8E1;color:#F59E0B;font-size:.72rem;font-weight:700;padding:3px 12px;border-radius:99px;">
                        📍 Current Month
                    </span>
                    <?php endif; ?>
                </div>

                <div class="row g-3">
                    <?php foreach ($monthData as $cat => $items):
                        $cc  = $categoryColor[$cat]  ?? $categoryColor['Others'];
                        $emo = $categoryEmoji[$cat]   ?? '📦';
                        $dbItems = $seasonProductsByCategory[$cat] ?? [];
                    ?>
                    <div class="col-sm-6 col-lg-4">
                        <div style="background:<?= $cc['bg'] ?>;border:1.5px solid <?= $cc['border'] ?>;border-radius:14px;padding:1rem 1.1rem;height:100%;">
                            <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.75rem;">
                                <div style="width:36px;height:36px;background:white;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;box-shadow:0 2px 8px rgba(0,0,0,0.07);flex-shrink:0;">
                                    <?= $emo ?>
                                </div>
                                <div>
                                    <div style="font-weight:800;font-size:.88rem;color:<?= $cc['color'] ?>;"><?= $cat ?></div>
                                    <div style="font-size:.67rem;color:<?= $cc['color'] ?>;opacity:.75;">In season <?= $monthNames[$m] ?></div>
                                </div>
                            </div>

                            <!-- Produce tags -->
                            <div style="display:flex;flex-wrap:wrap;gap:.35rem;margin-bottom:.75rem;">
                                <?php foreach ($items as $item): ?>
                                <span style="background:white;color:<?= $cc['color'] ?>;border:1px solid <?= $cc['border'] ?>;border-radius:99px;font-size:.68rem;font-weight:700;padding:2px 10px;">
                                    <?= $item ?>
                                </span>
                                <?php endforeach; ?>
                            </div>

                            <!-- Linked DB products if any -->
                            <?php if (!empty($dbItems)): ?>
                            <div style="border-top:1px solid <?= $cc['border'] ?>;padding-top:.6rem;">
                                <div style="font-size:.65rem;font-weight:700;color:<?= $cc['color'] ?>;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.4rem;">
                                    Available Now
                                </div>
                                <?php foreach (array_slice($dbItems, 0, 2) as $dp): ?>
                                <a href="<?= BASE_URL ?>/buyer/product.php?id=<?= $dp['id'] ?>" style="display:flex;align-items:center;gap:.5rem;text-decoration:none;padding:.3rem 0;border-bottom:1px dashed <?= $cc['border'] ?>;">
                                    <div style="width:28px;height:28px;border-radius:7px;background:white;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0;overflow:hidden;">
                                        <?php if ($dp['image']): ?>
                                            <img src="<?= BASE_URL ?>/assets/images/products/<?= sanitize($dp['image']) ?>" style="width:28px;height:28px;object-fit:cover;border-radius:7px;">
                                        <?php else: ?>
                                            <?= $emo ?>
                                        <?php endif; ?>
                                    </div>
                                    <div style="min-width:0;flex:1;">
                                        <div style="font-size:.73rem;font-weight:700;color:<?= $cc['color'] ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($dp['name']) ?></div>
                                    </div>
                                    <div style="font-size:.73rem;font-weight:800;color:<?= $cc['color'] ?>;flex-shrink:0;">₱<?= number_format($dp['price_per_kg'],0) ?>/kg</div>
                                </a>
                                <?php endforeach; ?>
                                <a href="<?= BASE_URL ?>/buyer/browse.php?category=<?= urlencode($cat) ?>" style="display:block;text-align:center;font-size:.68rem;font-weight:700;color:<?= $cc['color'] ?>;margin-top:.4rem;text-decoration:none;">
                                    See all <?= $cat ?> →
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endfor; ?>

            <script>
            function switchCalMonth(m) {
                for (let i = 1; i <= 12; i++) {
                    const panel = document.getElementById('calPanel' + i);
                    const tab   = document.getElementById('calTab'   + i);
                    if (!panel || !tab) continue;
                    const active = i === m;
                    panel.style.display = active ? 'block' : 'none';
                    tab.style.background    = active ? 'var(--primary)' : 'white';
                    tab.style.color         = active ? 'white'          : 'var(--text-muted)';
                    tab.style.borderColor   = active ? 'var(--primary)' : 'var(--border)';
                }
                // Scroll tab into view
                const t = document.getElementById('calTab' + m);
                if (t) t.scrollIntoView({ behavior:'smooth', block:'nearest', inline:'center' });
            }
            </script>
        </div>

        <!-- ══ RECENT ORDERS ══ -->
        <?php if (!empty($recentOrders)): ?>
        <div class="db-section" style="padding-bottom:2.5rem;">
            <div class="db-section-head">
                <div>
                    <div class="db-section-title">📋 Recent <span>Orders</span></div>
                    <div class="db-section-sub">Latest marketplace activity</div>
                </div>
                <a href="<?= BASE_URL ?>/orders/index.php" class="db-view-all">
                    View All <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
            <div class="orders-panel animate-on-scroll">
                <div style="overflow-x:auto;">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Product</th>
                                <th>Buyer</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentOrders as $ord):
                                $sc = $statusColors[$ord['status']] ?? ['bg'=>'#F3F4F6','color'=>'#6B7280','label'=>ucfirst($ord['status'])];
                            ?>
                            <tr>
                                <td style="font-weight:800;font-size:.82rem;">#<?= str_pad($ord['id'],4,'0',STR_PAD_LEFT) ?></td>
                                <td style="font-weight:700;"><?= sanitize($ord['product_name']) ?></td>
                                <td style="color:var(--text-muted);"><?= sanitize($ord['buyer_name']) ?></td>
                                <td style="font-weight:800;color:var(--primary);">₱<?= number_format($ord['total_amount'],2) ?></td>
                                <td style="color:var(--text-muted);font-size:.78rem;"><?= date('M j, Y', strtotime($ord['created_at'])) ?></td>
                                <td>
                                    <span class="status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;">
                                        <?= $sc['label'] ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /container -->
</div>

<script>
(function(){
    const els = document.querySelectorAll('.animate-on-scroll');
    const io = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); }
        });
    }, { threshold: 0.08 });
    els.forEach(el => io.observe(el));
})();
</script>

<!-- ══ ACTIVITY TOASTS + FARMER SPOTLIGHT ══ -->
<div id="activityToast" style="
    position:fixed;bottom:1.5rem;left:1.5rem;z-index:9998;
    max-width:300px;width:calc(100vw - 3rem);
    background:white;border-radius:16px;border:1px solid var(--border);
    box-shadow:0 12px 40px rgba(0,0,0,.15);
    padding:1rem 1.1rem;display:none;align-items:center;gap:.75rem;
    animation:toastIn .35s cubic-bezier(.22,1,.36,1);
">
    <div id="toastIcon" style="width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;"></div>
    <div style="min-width:0;flex:1;">
        <div id="toastTitle" style="font-size:.78rem;font-weight:800;color:var(--text);line-height:1.3;"></div>
        <div id="toastSub" style="font-size:.7rem;color:var(--text-muted);margin-top:2px;line-height:1.4;"></div>
    </div>
    <button onclick="dismissToast()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:.85rem;padding:2px;flex-shrink:0;line-height:1;">✕</button>
</div>

<!-- ══ PREMIUM TICKER STRIP ══ -->
<div id="premiumTicker" style="
    position:fixed;bottom:0;left:0;right:0;z-index:9997;
    background:linear-gradient(90deg,#1B5E20,#2E7D32,#1565C0,#1976D2,#2E7D32,#1B5E20);
    background-size:300% 100%;
    animation:tickerGrad 8s linear infinite;
    padding:.55rem 1rem;display:flex;align-items:center;justify-content:center;gap:1.5rem;
    flex-wrap:wrap;
">
    <div id="tickerText" style="color:white;font-size:.75rem;font-weight:700;text-align:center;letter-spacing:.03em;"></div>
    <button onclick="document.getElementById('premiumTicker').style.display='none'"
            style="background:rgba(255,255,255,.2);border:none;border-radius:99px;color:white;font-size:.65rem;font-weight:700;padding:2px 10px;cursor:pointer;flex-shrink:0;white-space:nowrap;">
        Dismiss
    </button>
</div>

<style>
@keyframes toastIn   { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:none} }
@keyframes tickerGrad{ 0%{background-position:0% 50%} 100%{background-position:100% 50%} }
/* Push page content up so ticker strip doesn't overlap footer */
body { padding-bottom: 44px; }
</style>

<script>
(function(){

// ── Data from PHP ─────────────────────────────────────────────────────────────
const farmers = <?= json_encode(array_map(fn($f) => [
    'name'          => $f['name'],
    'farm'          => $f['farm_name'] ?? ($f['name'] . "'s Farm"),
    'location'      => $f['farm_location'] ?? $f['location'] ?? 'Mindanao',
    'products'      => $f['product_count'],
    'rating'        => $f['rating'] ?? 0,
    'id'            => $f['id'],
    'is_premium'    => !empty($f['is_premium']) && !empty($f['premium_until']) && strtotime($f['premium_until']) > time(),
], $allFarmers)) ?>;

const recentOrders = <?= json_encode(array_map(fn($o) => [
    'product' => $o['product_name'],
    'buyer'   => $o['buyer_name'],
    'amount'  => $o['total_amount'],
    'status'  => $o['status'],
], $recentOrders ?? [])) ?>;

const totalProducts = <?= $totalProducts ?>;
const totalFarmers  = <?= $totalFarmers ?>;
const totalOrders   = <?= $totalOrders ?>;
const sessionRole   = '<?= $_SESSION["role"] ?? "guest" ?>';
const baseUrl       = '<?= BASE_URL ?>';

// ── Ticker strip messages ─────────────────────────────────────────────────────
const tickerMessages = [
    '🌿 GreenLink connects ' + totalFarmers + ' local Mindanao farmers directly to buyers — no middlemen, better prices',
    '⭐ Premium Farmers get featured placement and reach 3× more buyers — upgrade your listing today',
    '💼 Buyer Premium unlocks bulk discounts, early harvest access, and priority order fulfillment',
    '📦 ' + totalOrders + '+ orders fulfilled on GreenLink — join the growing farm-to-table community',
    '🥬 ' + totalProducts + ' fresh products listed right now — browse and order directly from the farm',
    '🔒 All transactions on GreenLink are secure and verified — shop with confidence',
    '🌾 Mindanao\'s #1 farm marketplace — fresh produce, fair prices, direct from farmers',
    sessionRole === 'farmer'
        ? '🚀 Go Premium and appear at the TOP of buyer searches — limited slots available!'
        : '🛒 Upgrade to Buyer Premium and get notified when your favorite products drop in price',
];

let tickerIdx = 0;
function rotateTicker() {
    const el = document.getElementById('tickerText');
    if (!el) return;
    el.style.opacity = '0';
    setTimeout(() => {
        el.textContent = tickerMessages[tickerIdx % tickerMessages.length];
        el.style.transition = 'opacity .4s';
        el.style.opacity = '1';
        tickerIdx++;
    }, 300);
}
rotateTicker();
setInterval(rotateTicker, 7000);

// ── Toast system ──────────────────────────────────────────────────────────────
let toastTimer = null;
let toastHideTimer = null;

function showToast(icon, iconBg, title, sub, link) {
    const toast = document.getElementById('activityToast');
    if (!toast) return;
    document.getElementById('toastIcon').style.background = iconBg;
    document.getElementById('toastIcon').textContent = icon;
    document.getElementById('toastTitle').innerHTML = title;
    document.getElementById('toastSub').textContent = sub;
    toast.onclick = link ? () => window.location = link : null;
    toast.style.cursor = link ? 'pointer' : 'default';
    toast.style.display = 'flex';
    toast.style.animation = 'none';
    void toast.offsetWidth; // reflow
    toast.style.animation = 'toastIn .35s cubic-bezier(.22,1,.36,1)';
    clearTimeout(toastHideTimer);
    toastHideTimer = setTimeout(dismissToast, 6000);
}

window.dismissToast = function() {
    const toast = document.getElementById('activityToast');
    if (toast) toast.style.display = 'none';
    clearTimeout(toastHideTimer);
};

// ── Toast content pools ───────────────────────────────────────────────────────
function farmerSpotlightToast() {
    if (!farmers.length) return;
    const f = farmers[Math.floor(Math.random() * Math.min(farmers.length, 8))];
    const stars = f.rating > 0 ? ' · ⭐ ' + parseFloat(f.rating).toFixed(1) : '';
    showToast(
        f.is_premium ? '⭐' : '🧑‍🌾',
        f.is_premium ? 'linear-gradient(135deg,#78350f,#d97706)' : 'var(--pale-green)',
        (f.is_premium ? '<span style="color:#d97706;">Premium Farmer</span> · ' : '') + '<strong>' + f.farm + '</strong>',
        '📍 ' + f.location + ' · ' + f.products + ' products' + stars,
        baseUrl + '/buyer/browse.php?farmer=' + f.id
    );
}

function activityToast() {
    const pool = [
        { icon:'📦', bg:'#dbeafe', title:'<strong>' + totalOrders + '+ orders</strong> completed on GreenLink', sub:'Join thousands of buyers sourcing direct from farms' },
        { icon:'🌱', bg:'#dcfce7', title:'<strong>' + totalProducts + ' fresh products</strong> available now', sub:'Browse organic & conventional produce from local farms' },
        { icon:'🤝', bg:'#fef9c3', title:'<strong>' + totalFarmers + ' verified farmers</strong> on GreenLink', sub:'All sellers are screened and location-verified' },
        { icon:'💰', bg:'#ffe4e6', title:'<strong>Better prices</strong> when you buy direct', sub:'Skip the middleman — order straight from the farm' },
        { icon:'🚚', bg:'#f3e8ff', title:'<strong>Farm-to-table</strong> in Mindanao', sub:'Fresh produce delivered from farm to your kitchen or restaurant' },
    ];

    // Splice in a real recent order if available
    if (recentOrders.length) {
        const o = recentOrders[Math.floor(Math.random() * recentOrders.length)];
        pool.unshift({
            icon: '🛒',
            bg: '#dcfce7',
            title: 'Someone just ordered <strong>' + o.product + '</strong>',
            sub: '₱' + parseFloat(o.amount).toFixed(2) + ' · ' + o.status + ' — GreenLink is buzzing!'
        });
    }

    const item = pool[Math.floor(Math.random() * pool.length)];
    showToast(item.icon, item.bg, item.title, item.sub, item.link || null);
}

function premiumUpsellToast() {
    if (sessionRole === 'farmer') {
        showToast(
            '👑', 'linear-gradient(135deg,#78350f,#d97706)',
            '<strong>Go Premium</strong> — reach 3× more buyers',
            'Featured placement · Sales analytics · Priority support',
            baseUrl + '/farmer/premium.php'
        );
    } else if (sessionRole === 'buyer') {
        showToast(
            '💼', 'linear-gradient(135deg,#1565C0,#1976D2)',
            '<strong>Buyer Premium</strong> — unlock exclusive perks',
            'Early harvest access · Bulk discounts · Price alerts',
            baseUrl + '/buyer/premium.php'
        );
    } else {
        showToast(
            '🌿', 'linear-gradient(135deg,#1B5E20,#2E7D32)',
            '<strong>Join GreenLink</strong> — free to get started',
            'Connect with ' + totalFarmers + ' local Mindanao farmers today',
            baseUrl + '/auth/register.php'
        );
    }
}

// ── Toast rotation schedule ───────────────────────────────────────────────────
// Order: activity → farmer → activity → premium → farmer → activity → ...
const toastQueue = [
    activityToast,
    farmerSpotlightToast,
    activityToast,
    premiumUpsellToast,
    farmerSpotlightToast,
    activityToast,
    premiumUpsellToast,
    farmerSpotlightToast,
];
let toastQueueIdx = 0;

function runNextToast() {
    // Only show if page is visible and user isn't hovering the toast
    if (document.hidden) return;
    toastQueue[toastQueueIdx % toastQueue.length]();
    toastQueueIdx++;
}

// First toast after 8s, then every 45s
setTimeout(() => {
    runNextToast();
    setInterval(runNextToast, 45000);
}, 8000);

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>