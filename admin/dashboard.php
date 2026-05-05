<?php
$page_title = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin');

$pdo = getDBConnection();

// ── Fetch admin profile ───────────────────────────────────────────────────────
$adminId = $_SESSION['user_id'];
$admin = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$admin->execute([$adminId]);
$admin = $admin->fetch();

// ── Core Stats ────────────────────────────────────────────────────────────────
$totalUsers        = $pdo->query("SELECT COUNT(*) FROM users WHERE role!='admin'")->fetchColumn();
$totalFarmers      = $pdo->query("SELECT COUNT(*) FROM users WHERE role='farmer'")->fetchColumn();
$totalBuyers       = $pdo->query("SELECT COUNT(*) FROM users WHERE role='buyer'")->fetchColumn();
$totalProducts     = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalOrders       = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalRevenue      = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='completed'")->fetchColumn();
$pendingOrders     = $pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
$availableProducts = $pdo->query("SELECT COUNT(*) FROM products WHERE is_available=1")->fetchColumn();

$thisMonth     = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='completed' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
$lastMonth     = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='completed' AND MONTH(created_at)=MONTH(DATE_SUB(NOW(),INTERVAL 1 MONTH)) AND YEAR(created_at)=YEAR(DATE_SUB(NOW(),INTERVAL 1 MONTH))")->fetchColumn();
$revenueChange = $lastMonth > 0 ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1) : 0;

$ordersByStatus = $pdo->query("SELECT status, COUNT(*) as count FROM orders GROUP BY status")->fetchAll();

$revenueMonths = $pdo->query("
    SELECT DATE_FORMAT(created_at,'%b') as month, COALESCE(SUM(total_amount),0) as revenue
    FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) AND status='completed'
    GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY MIN(created_at) ASC
")->fetchAll();
$revenueLabels = array_column($revenueMonths, 'month');
$revenueData   = array_map('floatval', array_column($revenueMonths, 'revenue'));

$supplyDemand = $pdo->query("
    SELECT DATE_FORMAT(o.created_at,'%b') as month, COUNT(DISTINCT o.id) as demand, COUNT(DISTINCT p.id) as supply
    FROM orders o LEFT JOIN products p ON MONTH(p.created_at)=MONTH(o.created_at) AND YEAR(p.created_at)=YEAR(o.created_at)
    WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(o.created_at,'%Y-%m') ORDER BY MIN(o.created_at) ASC
")->fetchAll();
$sdLabels = array_column($supplyDemand, 'month');
$sdDemand = array_map('intval', array_column($supplyDemand, 'demand'));
$sdSupply = array_map('intval', array_column($supplyDemand, 'supply'));

$stockData = $pdo->query("
    SELECT p.name, p.stock_kg, p.price_per_kg, p.category, p.is_available, u.name as farmer_name,
           COALESCE((SELECT SUM(oi2.quantity_kg) FROM order_items oi2 JOIN orders o2 ON oi2.order_id=o2.id WHERE oi2.product_id=p.id AND o2.status='completed'),0) as sold_kg
    FROM products p JOIN users u ON p.farmer_id=u.id ORDER BY p.stock_kg ASC LIMIT 8
")->fetchAll();

$topFarmers = $pdo->query("
    SELECT u.name, COUNT(o.id) as orders, COALESCE(SUM(o.total_amount),0) as revenue
    FROM users u LEFT JOIN orders o ON o.farmer_id=u.id AND o.status='completed'
    WHERE u.role='farmer' GROUP BY u.id ORDER BY revenue DESC LIMIT 5
")->fetchAll();
$maxRev = max(array_column($topFarmers, 'revenue') ?: [1]);

$recentOrders = $pdo->query("
    SELECT o.*, ub.name as buyer_name, uf.name as farmer_name
    FROM orders o JOIN users ub ON o.buyer_id=ub.id JOIN users uf ON o.farmer_id=uf.id
    ORDER BY o.created_at DESC LIMIT 6
")->fetchAll();

$recentUsers = $pdo->query("SELECT * FROM users WHERE role!='admin' ORDER BY created_at DESC LIMIT 5")->fetchAll();

$catEmoji     = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕','Others'=>'📦'];
$statusColors = ['pending'=>'status-pending','confirmed'=>'status-confirmed','processing'=>'status-processing','on delivery'=>'status-shipped','completed'=>'status-completed','cancelled'=>'status-cancelled'];
?>

<style>
.stk-bar{background:#e5e7eb;border-radius:99px;height:5px;overflow:hidden;margin-top:4px;}
.stk-fill{height:100%;border-radius:99px;}
.stk-good{background:var(--primary);}.stk-low{background:#f97316;}.stk-crit{background:#ef4444;}
.admin-section-title{font-weight:800;font-size:.95rem;margin:0;}

/* Profile styles */
.profile-hero{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:1.5rem;box-shadow:var(--shadow-sm);}
.profile-cover{height:100px;background:linear-gradient(135deg,var(--primary),#639922);position:relative;}
.profile-avatar-wrap{position:absolute;bottom:-40px;left:1.5rem;}
.profile-avatar-img{width:80px;height:80px;border-radius:50%;border:3px solid white;object-fit:cover;background:var(--pale-green);display:flex;align-items:center;justify-content:center;font-size:1.8rem;font-weight:800;color:var(--primary);}
.profile-info-row{padding:3rem 1.5rem 1.25rem;}
.profile-name{font-size:1.15rem;font-weight:800;color:var(--text);}
.profile-role-badge{display:inline-flex;align-items:center;gap:4px;background:var(--pale-green);color:var(--primary);border-radius:99px;padding:2px 10px;font-size:.72rem;font-weight:800;margin-top:4px;}
.profile-bio{font-size:.82rem;color:var(--text-muted);margin-top:.5rem;}
.profile-stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:0;border-top:1px solid var(--border);}
.profile-stat{padding:.75rem 1rem;text-align:center;border-right:1px solid var(--border);}
.profile-stat:last-child{border-right:none;}
.profile-stat-val{font-size:1.1rem;font-weight:800;color:var(--primary);}
.profile-stat-lbl{font-size:.68rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;}

/* User avatar in tables */
.user-thumb{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1.5px solid var(--border);flex-shrink:0;}
.user-initial{width:30px;height:30px;border-radius:50%;background:var(--pale-green);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;flex-shrink:0;}

@media(max-width:992px){.profile-stats-row{grid-template-columns:repeat(2,1fr);}}
@media(max-width:576px){.profile-stats-row{grid-template-columns:repeat(2,1fr);}}
</style>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">

    <div class="page-header">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h1><i class="fa-solid fa-shield-halved text-green me-2"></i>Admin Dashboard</h1>
                    <div class="page-breadcrumb">GreenLink Innovators — <strong>Market Control Center</strong> ⚙️</div>
                </div>
                <div class="d-flex gap-2">
                    <a href="users.php" class="btn-outline-green" style="padding:.45rem 1rem;font-size:.82rem;"><i class="fa-solid fa-users"></i> Users</a>
                    <a href="products.php" class="btn-green"><i class="fa-solid fa-seedling"></i> Products</a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="row g-4">

            <!-- Sidebar -->
            <div class="col-lg-3">
                <div class="gl-sidebar">
                    <div class="gl-sidebar-header">
                        <?php $adminPhoto = $admin['profile_image'] ?? $admin['profile_photo'] ?? null; ?>
                        <?php if (!empty($adminPhoto)): ?>
                            <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($adminPhoto) ?>" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid white;" alt="Profile">
                        <?php else: ?>
                            <div class="user-avatar">A</div>
                        <?php endif; ?>
                        <div class="user-name"><?= htmlspecialchars($admin['name']) ?></div>
                        <div class="user-role">⚙️ Administrator</div>
                    </div>
                    <nav class="gl-sidebar-nav">
                        <a href="dashboard.php" class="active"><i class="fa-solid fa-grid-2"></i> Dashboard</a>
                        <a href="users.php"><i class="fa-solid fa-users"></i> Manage Users</a>
                        <a href="products.php"><i class="fa-solid fa-seedling"></i> All Products</a>
                        <a href="orders.php"><i class="fa-solid fa-box"></i> All Orders</a>
                        <a href="<?= BASE_URL ?>/admin/marketprices.php"><i class="fa-solid fa-chart-line"></i> Market Prices</a>
                        <a href="<?= BASE_URL ?>/admin/edit-profile.php"><i class="fa-solid fa-user-pen"></i> Edit Profile</a>
                        <div class="nav-divider"></div>
                        <a href="../auth/logout.php" style="color:#E53E3E;"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                    </nav>
                    <div style="padding:1rem;border-top:1px solid var(--border);margin-top:.5rem;">
                        <div style="font-size:.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem;">Quick Stats</div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;"><span style="font-size:.78rem;color:var(--text-muted);">Farmers</span><strong style="font-size:.78rem;color:var(--primary);"><?= $totalFarmers ?></strong></div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;"><span style="font-size:.78rem;color:var(--text-muted);">Buyers</span><strong style="font-size:.78rem;color:var(--primary);"><?= $totalBuyers ?></strong></div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;"><span style="font-size:.78rem;color:var(--text-muted);">Active Listings</span><strong style="font-size:.78rem;color:var(--primary);"><?= $availableProducts ?></strong></div>
                        <div style="display:flex;justify-content:space-between;"><span style="font-size:.78rem;color:var(--text-muted);">Pending Orders</span><strong style="font-size:.78rem;color:#f97316;"><?= $pendingOrders ?></strong></div>
                    </div>
                </div>
            </div>

            <!-- Main -->
            <div class="col-lg-9">

                <!-- Revenue Breakdown Card -->
<?php
$totalCommission  = $pdo->query("SELECT COALESCE(SUM(platform_fee),0) FROM orders WHERE status NOT IN ('pending','cancelled')")->fetchColumn();
$totalServiceFees = $pdo->query("SELECT COALESCE(SUM(service_fee),0) FROM orders WHERE status NOT IN ('pending','cancelled')")->fetchColumn();
$totalDelivery    = $pdo->query("SELECT COALESCE(SUM(delivery_fee),0) FROM orders WHERE status NOT IN ('pending','cancelled')")->fetchColumn();
$avgOrderValue = $pdo->query("SELECT COALESCE(AVG(total_amount),0) FROM orders WHERE status='completed'")->fetchColumn();
$completedOrders  = $pdo->query("SELECT COUNT(*) FROM orders WHERE status='completed'")->fetchColumn();
$cancelledOrders  = $pdo->query("SELECT COUNT(*) FROM orders WHERE status='cancelled'")->fetchColumn();
?>
<div class="gl-card mb-4">
    <div class="gl-card-body">
        <h5 style="font-weight:800;margin-bottom:1.25rem;">
            <i class="fa-solid fa-chart-pie text-green me-2"></i>Platform Earnings Breakdown
        </h5>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.25rem;">
           <div style="background:var(--pale-green);border-radius:14px;padding:1rem;text-align:center;cursor:pointer;" onclick="location.href='orders.php?status=completed'">
                <div style="font-size:.7rem;font-weight:800;color:var(--primary);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                    <i class="fa-solid fa-percent me-1"></i>Farmer Commissions (5%)
                </div>
                <div style="font-size:1.4rem;font-weight:800;color:var(--primary);font-family:'Playfair Display',serif;">
                    ₱<?= number_format($totalCommission,2) ?>
                </div>
                <div style="font-size:.68rem;color:var(--text-muted);margin-top:3px;">from completed orders</div>
            </div>
           <div style="background:#F3EEFF;border-radius:14px;padding:1rem;text-align:center;cursor:pointer;" onclick="location.href='orders.php?status=completed'">

                <div style="font-size:.7rem;font-weight:800;color:#7c3aed;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                    <i class="fa-solid fa-handshake me-1"></i>Buyer Service Fees
                </div>
                <div style="font-size:1.4rem;font-weight:800;color:#7c3aed;font-family:'Playfair Display',serif;">
                    ₱<?= number_format($totalServiceFees,2) ?>
                </div>
                <div style="font-size:.68rem;color:var(--text-muted);margin-top:3px;">₱50–₱150 per order</div>
            </div>
         <div style="background:#EFF6FF;border-radius:14px;padding:1rem;text-align:center;cursor:pointer;" onclick="location.href='orders.php?status=completed'">

                <div style="font-size:.7rem;font-weight:800;color:#3b82f6;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                    <i class="fa-solid fa-truck me-1"></i>Delivery Fees
                </div>
                <div style="font-size:1.4rem;font-weight:800;color:#3b82f6;font-family:'Playfair Display',serif;">
                    ₱<?= number_format($totalDelivery,2) ?>
                </div>
                <div style="font-size:.68rem;color:var(--text-muted);margin-top:3px;">distance-based</div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;">
           <div style="background:var(--bg);border-radius:10px;padding:.75rem;text-align:center;border:1px solid var(--border);cursor:pointer;" onclick="location.href='orders.php?status=completed'">
    <div style="font-size:.65rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Total Platform Income</div>
                <div style="font-size:1rem;font-weight:800;color:var(--primary);">₱<?= number_format($totalCommission + $totalServiceFees,2) ?></div>
            </div>
<div style="background:#FFF7ED;border-radius:10px;padding:.75rem;text-align:center;border:1px solid #FED7AA;cursor:pointer;" onclick="location.href='orders.php?status=completed'">
                <div style="font-size:.65rem;font-weight:800;color:#c2410c;text-transform:uppercase;margin-bottom:3px;">⭐ Premium Income</div>
                <div style="font-size:1rem;font-weight:800;color:#ea580c;">₱<?= number_format($pdo->query("SELECT COALESCE(SUM(amount),0) FROM premium_payments WHERE status='approved'")->fetchColumn(), 2) ?></div>
            </div>
           <div style="background:var(--bg);border-radius:10px;padding:.75rem;text-align:center;border:1px solid var(--border);cursor:pointer;" onclick="location.href='orders.php?status=completed'">
                <div style="font-size:.65rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Completed Orders</div>
                <div style="font-size:1rem;font-weight:800;color:#16a34a;"><?= $completedOrders ?></div>
            </div>
            <div style="background:var(--bg);border-radius:10px;padding:.75rem;text-align:center;border:1px solid var(--border);cursor:pointer;" onclick="location.href='orders.php?status=cancelled'">
                <div style="font-size:.65rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Cancelled Orders</div>
                <div style="font-size:1rem;font-weight:800;color:#ef4444;"><?= $cancelledOrders ?></div>
            </div>
        </div>
    </div>
</div>
                    

                <!-- Stat Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-1">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="fa-solid fa-peso-sign"></i></div>
                            <div><div class="stat-value">₱<?= number_format($totalRevenue,0) ?></div><div class="stat-label">Total Revenue</div>
                            <div style="font-size:.68rem;margin-top:2px;color:<?= $revenueChange>=0?'#16a34a':'#dc2626' ?>;font-weight:700;"><?= $revenueChange>=0?'▲':'▼' ?> <?= abs($revenueChange) ?>% vs last month</div></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-2">
                        <div class="stat-card"><div class="stat-icon blue"><i class="fa-solid fa-box"></i></div><div><div class="stat-value"><?= $totalOrders ?></div><div class="stat-label">Total Orders</div></div></div>
                    </div>
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-3">
                        <div class="stat-card"><div class="stat-icon earth"><i class="fa-solid fa-seedling"></i></div><div><div class="stat-value"><?= $totalProducts ?></div><div class="stat-label">Products</div></div></div>
                    </div>
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-4">
                        <div class="stat-card"><div class="stat-icon orange"><i class="fa-solid fa-users"></i></div><div><div class="stat-value"><?= $totalUsers ?></div><div class="stat-label">Total Users</div></div></div>
                    </div>
                </div>

                <!-- Supply vs Demand + Revenue -->
                <div class="row g-3 mb-4">
                    <div class="col-lg-6">
                        <div class="gl-card"><div class="gl-card-body">
                            <h5 class="admin-section-title mb-3"><i class="fa-solid fa-scale-balanced text-green me-2"></i>Supply vs Demand</h5>
                            <div style="position:relative;height:200px;"><canvas id="supplyChart"></canvas></div>
                        </div></div>
                    </div>
                    <div class="col-lg-6">
                        <div class="gl-card"><div class="gl-card-body">
                            <h5 class="admin-section-title mb-3"><i class="fa-solid fa-peso-sign text-green me-2"></i>Revenue Trend</h5>
                            <div style="position:relative;height:200px;"><canvas id="revenueChart"></canvas></div>
                        </div></div>
                    </div>
                </div>

                <!-- Stock Monitor -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 style="font-weight:800;margin:0;"><i class="fa-solid fa-warehouse text-green me-2"></i>Stock Level Monitor</h5>
                        <a href="products.php" class="btn-outline-green" style="padding:.35rem 1rem;font-size:.82rem;">Manage All</a>
                    </div>
                    <div class="gl-table"><table>
                        <thead><tr><th>Product</th><th>Farmer</th><th>Category</th><th>Price/kg</th><th>Stock (kg)</th><th>Level</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($stockData as $s):
                            $pct = min(100, ($s['stock_kg'] / 500) * 100);
                            $cls = $pct > 50 ? 'stk-good' : ($pct > 20 ? 'stk-low' : 'stk-crit');
                            $lbl = $pct > 50 ? 'Good' : ($pct > 20 ? 'Low' : 'Critical');
                            $lcol= $pct > 50 ? '#16a34a' : ($pct > 20 ? '#f97316' : '#ef4444');
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
                            <td style="color:var(--text-muted);font-size:.8rem;"><?= htmlspecialchars($s['farmer_name']) ?></td>
                            <td style="font-size:.78rem;"><?= $catEmoji[$s['category']]??'📦' ?> <?= $s['category'] ?></td>
                            <td><strong style="color:var(--primary);">₱<?= number_format($s['price_per_kg'],2) ?></strong></td>
                            <td><strong><?= number_format($s['stock_kg'],1) ?></strong></td>
                            <td style="min-width:110px;">
                                <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                                    <span style="font-size:.68rem;color:<?= $lcol ?>;font-weight:800;"><?= $lbl ?></span>
                                    <span style="font-size:.68rem;color:var(--text-muted);"><?= round($pct) ?>%</span>
                                </div>
                                <div class="stk-bar"><div class="stk-fill <?= $cls ?>" style="width:<?= $pct ?>%;"></div></div>
                            </td>
                            <td><span class="status-badge <?= $s['is_available']?'status-completed':'status-cancelled' ?>"><?= $s['is_available']?'Active':'Hidden' ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($stockData)): ?><tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted);">No products yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table></div>
                </div>

                <!-- Top Farmers + Recent Users -->
                <div class="row g-3 mb-4">
                    <div class="col-lg-6">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 style="font-weight:800;margin:0;"><i class="fa-solid fa-trophy text-green me-2"></i>Top Farmers</h5>
                        </div>
                        <div class="gl-card"><div class="gl-card-body">
                            <?php foreach ($topFarmers as $i => $f):
                                $pct2 = $maxRev > 0 ? ($f['revenue'] / $maxRev * 100) : 0; ?>
                            <div style="margin-bottom:.9rem;">
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <div style="width:22px;height:22px;border-radius:50%;background:var(--pale-green);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:800;"><?= $i+1 ?></div>
                                        <span style="font-size:.82rem;font-weight:700;"><?= htmlspecialchars($f['name']) ?></span>
                                        <span style="font-size:.7rem;color:var(--text-muted);"><?= $f['orders'] ?> orders</span>
                                    </div>
                                    <strong style="font-size:.82rem;color:var(--primary);">₱<?= number_format($f['revenue'],0) ?></strong>
                                </div>
                                <div style="background:#e5e7eb;border-radius:99px;height:5px;overflow:hidden;">
                                    <div style="height:100%;border-radius:99px;background:var(--primary);width:<?= $pct2 ?>%;"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($topFarmers)): ?><p style="color:var(--text-muted);font-size:.82rem;text-align:center;">No data.</p><?php endif; ?>
                        </div></div>
                    </div>
                    <div class="col-lg-6">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 style="font-weight:800;margin:0;"><i class="fa-solid fa-user-plus text-green me-2"></i>Recent Users</h5>
                            <a href="users.php" class="btn-outline-green" style="padding:.35rem 1rem;font-size:.82rem;">View All</a>
                        </div>
                        <div class="gl-table"><table>
                            <thead><tr><th>User</th><th>Role</th><th>Joined</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentUsers as $u): ?>
                            <tr>
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <?php if (!empty($u['profile_image'])): ?>
                                            <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($u['profile_image']) ?>"
                                                 class="user-thumb" alt="<?= htmlspecialchars($u['name']) ?>">
                                        <?php else: ?>
                                            <div class="user-initial"><?= strtoupper(substr($u['name'],0,1)) ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <div style="font-weight:700;font-size:.82rem;"><?= sanitize($u['name']) ?></div>
                                            <div style="font-size:.68rem;color:var(--text-muted);"><?= sanitize($u['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="status-badge <?= $u['role']==='farmer'?'status-completed':'status-confirmed' ?>"><?= ucfirst($u['role']) ?></span></td>
                                <td style="font-size:.75rem;color:var(--text-muted);"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                    </div>
                </div>

                <!-- Recent Orders -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 style="font-weight:800;margin:0;">Recent Orders</h5>
                        <a href="orders.php" class="btn-outline-green" style="padding:.35rem 1rem;font-size:.82rem;">View All</a>
                    </div>
                    <div class="gl-table"><table>
                        <thead><tr><th>Order #</th><th>Buyer</th><th>Farmer</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentOrders as $o): ?>
                        <tr>
                            <td><strong style="color:var(--primary);">#<?= $o['id'] ?></strong></td>
                            <td><?= sanitize($o['buyer_name']) ?></td>
                            <td style="color:var(--text-muted);"><?= sanitize($o['farmer_name']) ?></td>
                            <td><strong style="color:var(--primary);">₱<?= number_format($o['total_amount'],2) ?></strong></td>
                            <td><span class="status-badge <?= $statusColors[$o['status']] ?? '' ?>"><?= ucfirst($o['status']) ?></span></td>
                            <td style="color:var(--text-muted);font-size:.82rem;"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentOrders)): ?><tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text-muted);">No orders yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table></div>
                </div>

            </div><!-- /col-lg-9 -->
        </div><!-- /row -->
    </div><!-- /container -->
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Nunito', sans-serif";
Chart.defaults.color = '#94a3b8';
const primaryColor = '#3E7C3F';
const gridColor    = 'rgba(0,0,0,0.06)';
const tooltipStyle = { backgroundColor:'#1e293b', borderColor:'#334155', borderWidth:1, titleColor:'#f1f5f9', bodyColor:'#94a3b8', padding:10 };

new Chart(document.getElementById('supplyChart'), {
    type:'bar',
    data:{ labels:<?= json_encode($sdLabels ?: ['No Data']) ?>, datasets:[
        { label:'Demand (Orders)', data:<?= json_encode($sdDemand ?: [0]) ?>, backgroundColor:'rgba(59,130,246,0.7)', borderRadius:5, borderSkipped:false },
        { label:'Supply (Products)', data:<?= json_encode($sdSupply ?: [0]) ?>, backgroundColor:'rgba(62,124,63,0.7)', borderRadius:5, borderSkipped:false }
    ]},
    options:{ responsive:true, maintainAspectRatio:false, interaction:{mode:'index',intersect:false}, plugins:{ legend:{position:'top',labels:{boxWidth:10,font:{size:11}}}, tooltip:tooltipStyle }, scales:{ x:{grid:{display:false},ticks:{font:{size:11}}}, y:{grid:{color:gridColor},ticks:{stepSize:1,font:{size:11}}} } }
});

new Chart(document.getElementById('revenueChart'), {
    type:'line',
    data:{ labels:<?= json_encode($revenueLabels ?: ['No Data']) ?>, datasets:[{ label:'Revenue', data:<?= json_encode($revenueData ?: [0]) ?>, borderColor:primaryColor, backgroundColor:'rgba(62,124,63,0.08)', borderWidth:2.5, pointBackgroundColor:primaryColor, pointRadius:4, tension:0.4, fill:true }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false}, tooltip:{...tooltipStyle, callbacks:{label:c=>'₱'+c.parsed.y.toLocaleString()}} }, scales:{ x:{grid:{display:false},ticks:{font:{size:11}}}, y:{grid:{color:gridColor},ticks:{font:{size:11},callback:v=>'₱'+v.toLocaleString()}} } }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>