<?php
$page_title = 'Farmer Dashboard';
require_once __DIR__ . '/../includes/header.php';
requireRole('farmer');

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];

// Stats
$totalProducts = $pdo->prepare("SELECT COUNT(*) FROM products WHERE farmer_id = ?");
$totalProducts->execute([$userId]); $totalProducts = $totalProducts->fetchColumn();

$activeOrders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE farmer_id = ? AND status NOT IN ('completed','cancelled')");
$activeOrders->execute([$userId]); $activeOrders = $activeOrders->fetchColumn();

$earnings = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE farmer_id = ? AND status = 'completed'");
$earnings->execute([$userId]); $earnings = $earnings->fetchColumn();

$pendingOrders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE farmer_id = ? AND status = 'pending'");
$pendingOrders->execute([$userId]); $pendingOrders = $pendingOrders->fetchColumn();

// Recent orders
$recentOrders = $pdo->prepare("SELECT o.*, u.name as buyer_name FROM orders o JOIN users u ON o.buyer_id=u.id WHERE o.farmer_id=? ORDER BY o.created_at DESC LIMIT 5");
$recentOrders->execute([$userId]); $recentOrders = $recentOrders->fetchAll();

// My products
$myProducts = $pdo->prepare("SELECT * FROM products WHERE farmer_id = ? ORDER BY created_at DESC LIMIT 6");
$myProducts->execute([$userId]); $myProducts = $myProducts->fetchAll();

// Farmer profile
$farmer = $pdo->prepare("SELECT f.*, u.name, u.email, u.phone, u.location, u.profile_image FROM farmers f JOIN users u ON f.user_id=u.id WHERE f.user_id=?");
$farmer->execute([$userId]); $farmer = $farmer->fetch();

$statusColors = ['pending'=>'status-pending','confirmed'=>'status-confirmed','processing'=>'status-processing','shipped'=>'status-shipped','completed'=>'status-completed','cancelled'=>'status-cancelled'];
?>

<div style="background:<?= (!empty($farmer['is_premium']) && !empty($farmer['premium_until']) && strtotime($farmer['premium_until']) > time()) ? 'linear-gradient(180deg,#fffbeb 0%,#fef9ee 40%,var(--bg) 100%)' : 'var(--bg)' ?>;min-height:100vh;padding-bottom:3rem;">
    <!-- Page Header -->
    <div class="page-header" style="<?= (!empty($farmer['is_premium']) && !empty($farmer['premium_until']) && strtotime($farmer['premium_until']) > time()) ? 'background:linear-gradient(135deg,#78350f,#92400e,#b45309,#d97706);box-shadow:0 4px 20px rgba(217,119,6,.25);' : '' ?>">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h1 style="<?= (!empty($farmer['is_premium']) && !empty($farmer['premium_until']) && strtotime($farmer['premium_until']) > time()) ? 'color:white;' : '' ?>">
                        <?php if (!empty($farmer['is_premium']) && !empty($farmer['premium_until']) && strtotime($farmer['premium_until']) > time()): ?>
                            ⭐ Premium Farmer Dashboard
                        <?php else: ?>
                            <i class="fa-solid fa-tractor text-green me-2"></i>Farmer Dashboard
                        <?php endif; ?>
                    </h1>
                    <div class="page-breadcrumb" style="<?= (!empty($farmer['is_premium']) && !empty($farmer['premium_until']) && strtotime($farmer['premium_until']) > time()) ? 'color:rgba(255,255,255,.8);' : '' ?>">
                        Welcome back, <strong><?= sanitize($_SESSION['user_name']) ?></strong>
                        <?= (!empty($farmer['is_premium']) && !empty($farmer['premium_until']) && strtotime($farmer['premium_until']) > time()) ? '⭐' : '🌾' ?>
                    </div>
                </div>
              <a href="add_product.php" class="btn-green" style="<?= (!empty($farmer['is_premium']) && !empty($farmer['premium_until']) && strtotime($farmer['premium_until']) > time()) ? 'background:white;color:#b45309;border-color:white;' : '' ?>">
                    <i class="fa-solid fa-plus"></i> Add Product
                </a>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="row g-4">
            <!-- Sidebar -->
            <div class="col-lg-3">
<div class="gl-sidebar" style="<?= (!empty($farmer['is_premium']) && !empty($farmer['premium_until']) && strtotime($farmer['premium_until']) > time()) ? 'border-color:#f59e0b;box-shadow:0 4px 20px rgba(245,158,11,.15);' : '' ?>">
                    <div class="gl-sidebar-header" style="<?= (!empty($farmer['is_premium']) && !empty($farmer['premium_until']) && strtotime($farmer['premium_until']) > time()) ? 'background:linear-gradient(135deg,#78350f,#d97706);' : '' ?>">
    <?php if (!empty($farmer['profile_image'])): ?>
        <img src="<?= BASE_URL ?>/assets/images/profiles/<?= sanitize($farmer['profile_image']) ?>"
             style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:3px solid var(--primary);margin-bottom:0.5rem;">
    <?php else: ?>
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?></div>
    <?php endif; ?>
   <div class="user-name"><?= sanitize($_SESSION['user_name']) ?></div>
<div class="user-role">🌾 Farmer</div>
<?php if (!empty($farmer['is_premium']) && !empty($farmer['premium_until']) && strtotime($farmer['premium_until']) > time()): ?>
<div style="margin-top:8px;display:flex;flex-direction:column;align-items:center;gap:3px;">
    <span style="background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.62rem;font-weight:800;padding:4px 14px;border-radius:99px;letter-spacing:.04em;box-shadow:0 2px 8px rgba(217,119,6,.35);">⭐ PREMIUM</span>
   <span style="font-size:.65rem;color:#1a1a1a;font-weight:700;">Until <?= date('M j, Y', strtotime($farmer['premium_until'])) ?></span>
</div>
<?php else: ?>
<div style="margin-top:8px;">
    <a href="premium.php" style="display:inline-block;background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.62rem;font-weight:800;padding:4px 14px;border-radius:99px;text-decoration:none;letter-spacing:.04em;">
        ⭐ Go Premium
    </a>
</div>
<?php endif; ?>
</div>
                    <nav class="gl-sidebar-nav">
                        <a href="dashboard.php" class="active"><i class="fa-solid fa-grid-2"></i> Dashboard</a>
                        <a href="products.php"><i class="fa-solid fa-seedling"></i> My Products</a>
                        <a href="add_product.php"><i class="fa-solid fa-plus-circle"></i> Add Product</a>
                        <div class="nav-divider"></div>
                        <a href="../orders/index.php"><i class="fa-solid fa-box"></i> My Orders</a>
<?php
                        $unreadMsg = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id=? AND is_read=0");
                        $unreadMsg->execute([$userId]);
                        $unreadCount = $unreadMsg->fetchColumn();
                        ?>
                        <a href="../messages/index.php" style="display:flex;align-items:center;justify-content:space-between;">
                            <span><i class="fa-solid fa-comments"></i> Messages</span>
                            <?php if ($unreadCount > 0): ?>
                            <span style="background:#ef4444;color:white;font-size:.6rem;font-weight:800;padding:1px 7px;border-radius:99px;"><?= $unreadCount ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="../market/prices.php"><i class="fa-solid fa-chart-line"></i> Market Prices</a>
                        <div class="nav-divider"></div>
<a href="profile.php"><i class="fa-solid fa-user-pen"></i> Edit Profile</a>
                        <a href="premium.php" style="<?= (!empty($farmer['is_premium']) && strtotime($farmer['premium_until']) > time()) ? 'color:#d97706;font-weight:800;' : '' ?>">
                            <i class="fa-solid fa-star" style="color:#d97706;"></i>
                            <?= (!empty($farmer['is_premium']) && !empty($farmer['premium_until']) && strtotime($farmer['premium_until']) > time()) ? 'Premium Active' : 'Go Premium' ?>
                        </a>
                        <a href="../auth/logout.php" style="color:#E53E3E;"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                    </nav>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-lg-9">
                <!-- Stat Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-1">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="fa-solid fa-seedling"></i></div>
                            <div><div class="stat-value"><?= $totalProducts ?></div><div class="stat-label">Products</div></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-2">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="fa-solid fa-box"></i></div>
                            <div><div class="stat-value"><?= $activeOrders ?></div><div class="stat-label">Active Orders</div></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-3">
                        <div class="stat-card">
                            <div class="stat-icon orange"><i class="fa-solid fa-clock"></i></div>
                            <div><div class="stat-value"><?= $pendingOrders ?></div><div class="stat-label">Pending</div></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-4">
                        <div class="stat-card">
                            <div class="stat-icon earth"><i class="fa-solid fa-peso-sign"></i></div>
                            <div><div class="stat-value">₱<?= number_format($earnings, 0) ?></div><div class="stat-label">Earnings</div></div>
                        </div>
                    </div>
                </div>

              <!-- Premium Analytics -->
<?php if (!empty($farmer['is_premium']) && !empty($farmer['premium_until']) && strtotime($farmer['premium_until']) > time()): ?>
<?php
$analytics = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT o.id) AS total_orders,
        COALESCE(SUM(oi.subtotal),0) AS total_revenue,
        COALESCE(AVG(o.total_amount),0) AS avg_order,
        COUNT(DISTINCT o.buyer_id) AS unique_buyers
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    JOIN products p ON p.id = oi.product_id
   WHERE p.farmer_id = ? AND o.status = 'completed'
    ");
$analytics->execute([$userId]);
$an = $analytics->fetch();

// Monthly revenue last 6 months
$monthlyRevenue = $pdo->prepare("
    SELECT DATE_FORMAT(o.created_at,'%b %Y') AS month_label,
           DATE_FORMAT(o.created_at,'%Y-%m') AS month_key,
           COALESCE(SUM(oi.subtotal),0) AS revenue,
           COUNT(DISTINCT o.id) AS orders
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    JOIN products p ON p.id = oi.product_id
   WHERE p.farmer_id = ? AND o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    AND o.status = 'completed'
    GROUP BY DATE_FORMAT(o.created_at,'%Y-%m'), DATE_FORMAT(o.created_at,'%b %Y')
    ORDER BY month_key ASC
");
$monthlyRevenue->execute([$userId]);
$monthly = $monthlyRevenue->fetchAll();

// Top products by revenue
$topProducts = $pdo->prepare("
    SELECT p.name, p.category,
           SUM(oi.quantity_kg) AS total_kg,
           SUM(oi.subtotal) AS revenue,
           COUNT(DISTINCT o.id) AS order_count
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    JOIN orders o ON o.id = oi.order_id
    WHERE p.farmer_id = ? AND o.status = 'completed'
    GROUP BY p.id, p.name, p.category
    ORDER BY revenue DESC LIMIT 5
");
$topProducts->execute([$userId]);
$topProds = $topProducts->fetchAll();

// Weekly orders last 8 weeks
$weeklyOrders = $pdo->prepare("
    SELECT WEEK(o.created_at,1) AS wk,
           MIN(DATE(o.created_at)) AS week_start,
           COUNT(DISTINCT o.id) AS orders,
           COALESCE(SUM(oi.subtotal),0) AS revenue
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    JOIN products p ON p.id = oi.product_id
    WHERE p.farmer_id = ? AND o.created_at >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
    AND o.status = 'completed'
    GROUP BY WEEK(o.created_at,1)
    ORDER BY wk ASC
");
$weeklyOrders->execute([$userId]);
$weekly = $weeklyOrders->fetchAll();

// Category breakdown
$catBreakdown = $pdo->prepare("
    SELECT p.category,
           COALESCE(SUM(oi.subtotal),0) AS revenue,
           SUM(oi.quantity_kg) AS total_kg
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    JOIN orders o ON o.id = oi.order_id
   WHERE p.farmer_id = ? AND o.status = 'completed'
    GROUP BY p.category ORDER BY revenue DESC
");
$catBreakdown->execute([$userId]);
$catData = $catBreakdown->fetchAll();

// Order status breakdown
$statusBreakdown = $pdo->prepare("
    SELECT status, COUNT(*) AS cnt
    FROM orders WHERE farmer_id = ?
    GROUP BY status
");
$statusBreakdown->execute([$userId]);
$statusData = $statusBreakdown->fetchAll();
?>

<div class="mb-4">
    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div class="d-flex align-items-center gap-2">
            <h5 style="font-weight:800;margin:0;">📊 Sales Analytics</h5>
            <span style="background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.6rem;font-weight:800;padding:2px 8px;border-radius:99px;">⭐ PREMIUM</span>
        </div>
        <span style="font-size:.75rem;color:var(--text-muted);font-weight:600;">All-time data</span>
    </div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-3">
        <?php foreach ([
            ['📦', number_format($an['total_orders']), 'Total Orders', '#E8F5E9', '#16a34a'],
            ['💰', '₱'.number_format($an['total_revenue'],0), 'Total Revenue', '#FFF8E1', '#d97706'],
            ['🛒', '₱'.number_format($an['avg_order'],0), 'Avg Order Value', '#E3F2FD', '#1d4ed8'],
            ['👥', number_format($an['unique_buyers']), 'Unique Buyers', '#FCE4EC', '#be185d'],
        ] as [$icon,$val,$lbl,$bg,$color]): ?>
        <div class="col-6 col-lg-3">
            <div style="background:white;border:1px solid var(--border);border-radius:14px;padding:1rem 1.1rem;display:flex;align-items:center;gap:10px;">
                <div style="background:<?= $bg ?>;width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;"><?= $icon ?></div>
                <div>
                    <div style="font-family:'Playfair Display',serif;font-size:1.15rem;font-weight:700;color:var(--text);line-height:1.1;"><?= $val ?></div>
                    <div style="font-size:.65rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-top:2px;"><?= $lbl ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Charts Row 1: Revenue Line + Weekly Orders Bar -->
    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div style="background:white;border:1px solid var(--border);border-radius:14px;padding:1.1rem 1.25rem;">
                <div style="font-size:.78rem;font-weight:800;color:var(--text);margin-bottom:.75rem;">
                    <i class="fa-solid fa-chart-line text-green me-1"></i> Monthly Revenue (Last 6 Months)
                </div>
                <div style="position:relative;height:200px;">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div style="background:white;border:1px solid var(--border);border-radius:14px;padding:1.1rem 1.25rem;">
                <div style="font-size:.78rem;font-weight:800;color:var(--text);margin-bottom:.75rem;">
                    <i class="fa-solid fa-chart-bar text-green me-1"></i> Weekly Orders (Last 8 Weeks)
                </div>
                <div style="position:relative;height:200px;">
                    <canvas id="weeklyChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 2: Top Products Bar + Category Doughnut -->
    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div style="background:white;border:1px solid var(--border);border-radius:14px;padding:1.1rem 1.25rem;">
                <div style="font-size:.78rem;font-weight:800;color:var(--text);margin-bottom:.75rem;">
                    <i class="fa-solid fa-trophy text-green me-1"></i> Top Products by Revenue
                </div>
                <div style="position:relative;height:200px;">
                    <canvas id="topProductsChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div style="background:white;border:1px solid var(--border);border-radius:14px;padding:1.1rem 1.25rem;">
                <div style="font-size:.78rem;font-weight:800;color:var(--text);margin-bottom:.75rem;">
                    <i class="fa-solid fa-chart-pie text-green me-1"></i> Revenue by Category
                </div>
                <div style="position:relative;height:200px;">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Products Table + Order Status -->
    <div class="row g-3">
        <div class="col-lg-8">
            <div style="background:white;border:1px solid var(--border);border-radius:14px;padding:1.1rem 1.25rem;">
                <div style="font-size:.78rem;font-weight:800;color:var(--text);margin-bottom:.75rem;">
                    <i class="fa-solid fa-ranking-star text-green me-1"></i> Best Performing Products
                </div>
                <?php if (empty($topProds)): ?>
                <div style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:.85rem;">No sales data yet.</div>
                <?php else: ?>
                <?php
                $maxRev = max(array_column($topProds,'revenue')) ?: 1;
                $catColors = ['Vegetables'=>'#16a34a','Fruits'=>'#d97706','Grains'=>'#d97706','Coffee'=>'#78350f','Livestock'=>'#be185d','Seafood'=>'#1d4ed8','Others'=>'#6b7280'];
                foreach ($topProds as $i => $tp):
                    $pct = round(($tp['revenue']/$maxRev)*100);
                    $color = $catColors[$tp['category']] ?? '#16a34a';
                ?>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:.75rem;">
                    <div style="width:22px;height:22px;background:<?= $i===0?'linear-gradient(135deg,#d97706,#b45309)':'var(--bg)' ?>;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:800;color:<?= $i===0?'white':'var(--text-muted)' ?>;flex-shrink:0;">
                        <?= $i===0?'🥇':($i===1?'🥈':($i===2?'🥉':($i+1))) ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                            <span style="font-size:.82rem;font-weight:700;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= sanitize($tp['name']) ?></span>
                            <span style="font-size:.78rem;font-weight:800;color:<?= $color ?>;flex-shrink:0;margin-left:8px;">₱<?= number_format($tp['revenue'],0) ?></span>
                        </div>
                        <div style="background:#f3f4f6;border-radius:99px;height:7px;overflow:hidden;">
                            <div style="width:<?= $pct ?>%;background:<?= $color ?>;height:100%;border-radius:99px;transition:width .5s;"></div>
                        </div>
                        <div style="font-size:.68rem;color:var(--text-muted);margin-top:2px;"><?= number_format($tp['total_kg'],1) ?>kg · <?= $tp['order_count'] ?> order<?= $tp['order_count']!=1?'s':'' ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <div style="background:white;border:1px solid var(--border);border-radius:14px;padding:1.1rem 1.25rem;height:100%;">
                <div style="font-size:.78rem;font-weight:800;color:var(--text);margin-bottom:.75rem;">
                    <i class="fa-solid fa-circle-check text-green me-1"></i> Order Status Breakdown
                </div>
                <?php
                $statusConfig = [
                    'pending'   => ['bg'=>'#FFF8E1','color'=>'#d97706','icon'=>'⏳'],
                    'confirmed' => ['bg'=>'#E8F5E9','color'=>'#16a34a','icon'=>'✅'],
                    'delivered' => ['bg'=>'#E3F2FD','color'=>'#1d4ed8','icon'=>'🚚'],
                    'completed' => ['bg'=>'#dcfce7','color'=>'#15803d','icon'=>'🎉'],
                    'cancelled' => ['bg'=>'#FEE2E2','color'=>'#dc2626','icon'=>'❌'],
                ];
                $totalStatusOrders = array_sum(array_column($statusData,'cnt')) ?: 1;
                foreach ($statusData as $s):
                    $sc = $statusConfig[$s['status']] ?? ['bg'=>'#f3f4f6','color'=>'#6b7280','icon'=>'📦'];
                    $pct = round(($s['cnt']/$totalStatusOrders)*100);
                ?>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:.6rem;">
                    <span style="font-size:.85rem;width:20px;text-align:center;"><?= $sc['icon'] ?></span>
                    <div style="flex:1;">
                        <div style="display:flex;justify-content:space-between;font-size:.75rem;font-weight:700;margin-bottom:2px;">
                            <span style="color:var(--text);text-transform:capitalize;"><?= $s['status'] ?></span>
                            <span style="color:<?= $sc['color'] ?>;"><?= $s['cnt'] ?> (<?= $pct ?>%)</span>
                        </div>
                        <div style="background:#f3f4f6;border-radius:99px;height:6px;overflow:hidden;">
                            <div style="width:<?= $pct ?>%;background:<?= $sc['color'] ?>;height:100%;border-radius:99px;"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($statusData)): ?>
                <div style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:.85rem;">No orders yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
    const monthLabels  = <?= json_encode(array_column($monthly,'month_label')) ?>;
    const monthRevenue = <?= json_encode(array_map(fn($r)=>round($r['revenue'],2), $monthly)) ?>;
    const weekLabels   = <?= json_encode(array_map(fn($r)=>date('M d',strtotime($r['week_start'])), $weekly)) ?>;
    const weekOrders   = <?= json_encode(array_column($weekly,'orders')) ?>;
    const prodNames    = <?= json_encode(array_map(fn($r)=>mb_strimwidth($r['name'],0,20,'…'), $topProds)) ?>;
    const prodRevenue  = <?= json_encode(array_map(fn($r)=>round($r['revenue'],2), $topProds)) ?>;
    const catNames     = <?= json_encode(array_column($catData,'category')) ?>;
    const catRevenue   = <?= json_encode(array_map(fn($r)=>round($r['revenue'],2), $catData)) ?>;

    const green  = ['#1B5E20','#2E7D32','#388E3C','#43A047','#4CAF50','#66BB6A'];
    const gold   = ['#78350f','#92400e','#b45309','#d97706','#f59e0b','#fbbf24'];
    const mixed  = ['#1B5E20','#1d4ed8','#d97706','#be185d','#0e7490','#7c3aed','#dc2626','#15803d'];

    // Revenue Line Chart
    if (document.getElementById('revenueChart')) {
        new Chart(document.getElementById('revenueChart'), {
            type: 'line',
            data: {
                labels: monthLabels.length ? monthLabels : ['No data'],
                datasets: [{
                    data: monthRevenue.length ? monthRevenue : [0],
borderColor: '#d97706',
                    backgroundColor: 'rgba(217,119,6,0.08)',
                    borderWidth: 2.5,
                    pointBackgroundColor: '#d97706',
                    pointRadius: 5,
                    tension: 0.4,
                    fill: true,
                    label: 'Revenue'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => '₱' + c.parsed.y.toLocaleString('en-PH', {minimumFractionDigits:2}) } } },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                    y: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 10 }, callback: v => '₱' + (v>=1000 ? (v/1000).toFixed(1)+'k' : v) } }
                }
            }
        });
    }

    // Weekly Orders Bar Chart
    if (document.getElementById('weeklyChart')) {
        new Chart(document.getElementById('weeklyChart'), {
            type: 'bar',
            data: {
                labels: weekLabels.length ? weekLabels : ['No data'],
                datasets: [{
                    data: weekOrders.length ? weekOrders : [0],
                    backgroundColor: '#1d4ed8',
                    borderRadius: 6,
                    borderSkipped: false,
                    label: 'Orders'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => c.parsed.y + ' orders' } } },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 9 } } },
                    y: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 10 }, stepSize: 1 } }
                }
            }
        });
    }

    // Top Products Horizontal Bar
    if (document.getElementById('topProductsChart')) {
        new Chart(document.getElementById('topProductsChart'), {
            type: 'bar',
            data: {
                labels: prodNames.length ? prodNames : ['No data'],
                datasets: [{
                    data: prodRevenue.length ? prodRevenue : [0],
                  backgroundColor: mixed.slice(0, prodNames.length),
                    borderRadius: 6,
                    borderSkipped: false,
                    label: 'Revenue'
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => '₱' + c.parsed.x.toLocaleString('en-PH', {minimumFractionDigits:2}) } } },
                scales: {
                    x: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 10 }, callback: v => '₱' + (v>=1000?(v/1000).toFixed(1)+'k':v) } },
                    y: { grid: { display: false }, ticks: { font: { size: 10 } } }
                }
            }
        });
    }

    // Category Doughnut
    if (document.getElementById('categoryChart')) {
        new Chart(document.getElementById('categoryChart'), {
            type: 'doughnut',
            data: {
                labels: catNames.length ? catNames : ['No data'],
                datasets: [{
                    data: catRevenue.length ? catRevenue : [1],
backgroundColor: mixed.slice(0, catNames.length),
                    borderWidth: 2,
                    borderColor: 'white'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 9 }, padding: 8, boxWidth: 12 } },
                    tooltip: { callbacks: { label: c => c.label + ': ₱' + c.parsed.toLocaleString('en-PH', {minimumFractionDigits:2}) } }
                },
                cutout: '60%'
            }
        });
    }
})();
</script>
<?php endif; ?>

<!-- Recent Orders -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 style="font-weight:800;margin:0;">Recent Orders</h5>
                        <a href="../orders/index.php" class="btn-outline-green" style="padding:0.35rem 1rem;font-size:0.82rem;">View All</a>
                    </div>
                    <div class="gl-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Order #</th><th>Buyer</th><th>Amount</th><th>Status</th><th>Date</th><th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentOrders)): ?>
                                <tr><td colspan="6" class="text-center" style="padding:2rem;color:var(--text-muted);">No orders yet 📦</td></tr>
                                <?php else: foreach ($recentOrders as $o): ?>
                                <tr>
                                    <td><strong>#<?= $o['id'] ?></strong></td>
                                    <td><?= sanitize($o['buyer_name']) ?></td>
                                    <td><strong style="color:var(--primary);">₱<?= number_format($o['total_amount'], 2) ?></strong></td>
                                    <td><span class="status-badge <?= $statusColors[$o['status']] ?? '' ?>"><?= ucfirst($o['status']) ?></span></td>
                                    <td style="color:var(--text-muted);font-size:0.82rem;"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
                                    <td><a href="../orders/detail.php?id=<?= $o['id'] ?>" style="color:var(--primary);font-size:0.82rem;font-weight:700;text-decoration:none;">View →</a></td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- My Products -->
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 style="font-weight:800;margin:0;">My Products</h5>
                        <a href="products.php" class="btn-outline-green" style="padding:0.35rem 1rem;font-size:0.82rem;">Manage All</a>
                    </div>
                    <?php if (empty($myProducts)): ?>
                    <div class="gl-card">
                        <div class="gl-card-body empty-state">
                            <div class="empty-icon">🌱</div>
                            <p>You haven't listed any products yet.</p>
                            <a href="add_product.php" class="btn-green mt-2"><i class="fa-solid fa-plus"></i> Add Your First Product</a>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($myProducts as $p): ?>
                        <div class="col-sm-6 col-lg-4">
                            <div class="product-card">
                                <?php if ($p['is_organic']): ?><span class="badge-organic">🌿 Organic</span><?php endif; ?>
                                <div class="product-card-img">
                                    <?php $emojis = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕'];
                                    echo $p['image'] ? '<img src="../assets/images/products/'.sanitize($p['image']).'" alt="">' : ($emojis[$p['category']] ?? '🌾'); ?>
                                </div>
                                <div class="product-card-body">
                                    <div class="product-card-title"><?= sanitize($p['name']) ?></div>
                                    <div class="d-flex justify-content-between align-items-center mt-2">
                                        <div class="product-card-price">₱<?= number_format($p['price_per_kg'],2) ?><span>/kg</span></div>
                                        <span style="font-size:0.75rem;font-weight:700;color:<?= $p['is_available'] ? 'var(--primary)' : '#E53E3E' ?>;">
                                            <?= $p['is_available'] ? '● Active' : '● Hidden' ?>
                                        </span>
                                    </div>
                                    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;">
                                        Stock:
                                        <span style="color:<?= $p['stock_kg'] <= 0 ? '#EF4444' : 'var(--text-muted)' ?>;font-weight:<?= $p['stock_kg'] <= 0 ? '800' : '400' ?>;">
                                            <?= $p['stock_kg'] <= 0 ? '🚫 Out of Stock' : number_format($p['stock_kg'],1).' kg' ?>
                                        </span>
                                    </div>
                                    <?php if ($p['stock_kg'] <= 0): ?>
                                    <form method="POST" action="restock_product.php" class="d-flex gap-1 mt-2" style="align-items:center;">
                                        <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                        <input type="number" name="restock_kg" min="0.1" step="0.1" placeholder="kg to add"
                                               style="width:100%;padding:0.3rem 0.5rem;border:2px solid #FCA5A5;border-radius:8px;font-size:0.78rem;outline:none;color:var(--text);">
                                        <button type="submit" style="padding:0.3rem 0.6rem;background:#EF4444;color:white;border:none;border-radius:8px;font-size:0.78rem;font-weight:700;cursor:pointer;white-space:nowrap;">
                                            + Add
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <div class="d-flex gap-2 mt-2">
                                        <a href="edit_product.php?id=<?= $p['id'] ?>" class="btn-outline-green" style="flex:1;justify-content:center;padding:0.35rem;font-size:0.78rem;"><i class="fa-solid fa-pen"></i> Edit</a>
                                        <a href="delete_product.php?id=<?= $p['id'] ?>" onclick="return confirm('Delete this product?')" class="btn-earth" style="flex:1;text-align:center;padding:0.35rem;font-size:0.78rem;border-radius:var(--radius-md);text-decoration:none;"><i class="fa-solid fa-trash"></i> Del</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div><!-- end col-lg-9 -->
        </div><!-- end row -->
    </div><!-- end container -->
</div><!-- end min-height wrapper -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
