<?php
$page_title = 'Buyer Dashboard';
require_once __DIR__ . '/../includes/header.php';
requireRole('buyer');

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];

// Stats
$totalOrders   = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE buyer_id=?"); $totalOrders->execute([$userId]); $totalOrders = $totalOrders->fetchColumn();
$activeOrders  = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE buyer_id=? AND status NOT IN ('completed','cancelled')"); $activeOrders->execute([$userId]); $activeOrders = $activeOrders->fetchColumn();
$totalSpent    = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE buyer_id=? AND status='completed'"); $totalSpent->execute([$userId]); $totalSpent = $totalSpent->fetchColumn();
$unreadMessages= $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id=? AND is_read=0"); $unreadMessages->execute([$userId]); $unreadMessages = $unreadMessages->fetchColumn();

// Recent orders
$recentOrders = $pdo->prepare("SELECT o.*, u.name as farmer_name FROM orders o JOIN users u ON o.farmer_id=u.id WHERE o.buyer_id=? ORDER BY o.created_at DESC LIMIT 5");
$recentOrders->execute([$userId]); $recentOrders = $recentOrders->fetchAll();

// Recommended products (latest available)
$recommended = $pdo->query("SELECT p.*, u.name as farmer_name, u.location as farmer_city FROM products p JOIN users u ON p.farmer_id=u.id WHERE p.is_available=1 ORDER BY p.created_at DESC LIMIT 4")->fetchAll();

$statusColors = ['pending'=>'status-pending','confirmed'=>'status-confirmed','processing'=>'status-processing','shipped'=>'status-shipped','completed'=>'status-completed','cancelled'=>'status-cancelled'];
$emojis = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕','Others'=>'📦'];

// ── Premium status ────────────────────────────────────────────────────────────
$buyerRow  = $pdo->prepare("SELECT is_premium, premium_until FROM users WHERE id=?");
$buyerRow->execute([$userId]);
$buyerRow  = $buyerRow->fetch();
$isPremium = $buyerRow && !empty($buyerRow['is_premium']) && strtotime($buyerRow['premium_until']) > time();
?>

<div style="background:<?= $isPremium ? 'linear-gradient(180deg,#eff6ff 0%,#dbeafe 40%,var(--bg) 100%)' : 'var(--bg)' ?>;min-height:100vh;padding-bottom:3rem;">
 <div class="page-header" style="<?= $isPremium ? 'background:linear-gradient(135deg,#1e3a8a,#1565C0,#1d4ed8);box-shadow:0 4px 20px rgba(29,78,216,.25);' : '' ?>">
        <div class="container">
           <div class="d-flex align-items-center justify-content-between">
    <div>
        <h1 ...>...</h1>
        <div class="page-breadcrumb" ...>Welcome back, <strong><?= sanitize($_SESSION['user_name']) ?></strong> 🍽️</div>
    </div>
    <div class="d-flex gap-2">
       <button onclick="toggleCustomizeMode()" id="customizeBtn"
        style="background:rgba(255,255,255,0.15);color:white;border:1.5px solid rgba(255,255,255,0.6);padding:0.4rem 1rem;border-radius:8px;font-size:.82rem;font-weight:700;cursor:pointer;backdrop-filter:blur(4px);transition:background .15s;">
    <i class="fa-solid fa-pen-to-square"></i> Customize
</button>
<button onclick="saveDashboardLayout()" id="saveBtn"
        style="display:none;background:#16a34a;color:white;border:1.5px solid #16a34a;padding:0.4rem 1rem;border-radius:8px;font-size:.82rem;font-weight:700;cursor:pointer;transition:background .15s;">
    <i class="fa-solid fa-floppy-disk"></i> Save Layout
</button>
<button onclick="cancelCustomize()" id="cancelBtn"
        style="display:none;background:rgba(255,255,255,0.12);color:white;border:1.5px solid rgba(255,255,255,0.5);padding:0.4rem 1rem;border-radius:8px;font-size:.82rem;font-weight:700;cursor:pointer;transition:background .15s;">
    <i class="fa-solid fa-xmark"></i> Cancel
</button>
        <a href="../buyer/browse.php" class="btn-green" ...>Browse Products</a>
    </div>
</div>
        </div>
    </div>

    <div class="container">
        <div class="row g-4">
            <!-- Sidebar -->
            <div class="col-lg-3">
          <div class="gl-sidebar" style="<?= $isPremium ? 'border-color:#f59e0b;box-shadow:0 4px 20px rgba(245,158,11,.15);' : '' ?>">
                    <div class="gl-sidebar-header" style="<?= $isPremium ? 'background:linear-gradient(135deg,#1e3a8a,#1d4ed8);' : '' ?>">
    <?php
    $profileImg = $pdo->prepare("SELECT profile_image FROM users WHERE id=?");
    $profileImg->execute([$userId]);
    $profileImg = $profileImg->fetchColumn();
    ?>
    <?php if ($profileImg): ?>
        <img src="<?= BASE_URL ?>/assets/images/profiles/<?= sanitize($profileImg) ?>"
             style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:3px solid <?= $isPremium ? 'white' : 'var(--primary)' ?>;margin-bottom:0.5rem;">
    <?php else: ?>
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name'],0,1)) ?></div>
    <?php endif; ?>
    <div class="user-name"><?= sanitize($_SESSION['user_name']) ?></div>
    <div class="user-role">🍽️ Buyer</div>
    <?php if ($isPremium): ?>
    <div style="margin-top:8px;display:flex;flex-direction:column;align-items:center;gap:3px;">
        <span style="background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.62rem;font-weight:800;padding:4px 14px;border-radius:99px;letter-spacing:.04em;box-shadow:0 2px 8px rgba(217,119,6,.35);">⭐ PREMIUM</span>
        <span style="font-size:.65rem;color:rgba(255,255,255,.85);font-weight:700;">Until <?= date('M j, Y', strtotime($buyerRow['premium_until'])) ?></span>
    </div>
    <?php else: ?>
    <div style="margin-top:8px;">
        <a href="premium.php" style="display:inline-block;background:linear-gradient(135deg,#1e3a8a,#1d4ed8);color:white;font-size:.62rem;font-weight:800;padding:4px 14px;border-radius:99px;text-decoration:none;letter-spacing:.04em;">
            ⭐ Go Premium
        </a>
    </div>
    <?php endif; ?>
</div>
                    <nav class="gl-sidebar-nav">
                        <a href="dashboard.php" class="active"><i class="fa-solid fa-grid-2"></i> Dashboard</a>
                        <a href="../buyer/browse.php"><i class="fa-solid fa-store"></i> Browse Products</a>
                        <div class="nav-divider"></div>
                        <a href="../orders/index.php"><i class="fa-solid fa-box"></i> My Orders</a>
                        <a href="../messages/index.php"><i class="fa-solid fa-comments"></i> Messages
                            <?php if ($unreadMessages > 0): ?><span style="background:var(--primary);color:white;border-radius:50%;width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;font-size:0.65rem;font-weight:800;margin-left:auto;"><?= $unreadMessages ?></span><?php endif; ?>
                        </a>
                        <a href="../market/prices.php"><i class="fa-solid fa-chart-line"></i> Market Prices</a>
                        <div class="nav-divider"></div>
                        <a href="profile.php"><i class="fa-solid fa-user-pen"></i> Edit Profile</a>
                        <a href="../auth/logout.php" style="color:#E53E3E;"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                    </nav>
                </div>
            </div>

         <!-- Main Content -->
<div class="col-lg-9">

    <!-- Customize Mode Banner -->
    <div id="customizeBanner" class="d-none mb-3" style="background:linear-gradient(135deg,#1e3a8a,#1d4ed8);color:white;border-radius:14px;padding:0.85rem 1.2rem;display:flex;align-items:center;gap:10px;font-size:.85rem;font-weight:700;">
        <i class="fa-solid fa-arrows-up-down-left-right" style="font-size:1.1rem;"></i>
        <span>Drag widgets to reorder • Toggle the eye icon to show/hide sections</span>
    </div>

   <!-- Stats -->
    <div id="widget-stats" data-widget="stats" class="mb-4">
    <div class="row g-3">
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-1">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="fa-solid fa-box"></i></div>
                            <div><div class="stat-value"><?= $totalOrders ?></div><div class="stat-label">Total Orders</div></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-2">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="fa-solid fa-truck"></i></div>
                            <div><div class="stat-value"><?= $activeOrders ?></div><div class="stat-label">Active</div></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-3">
                        <div class="stat-card">
                            <div class="stat-icon earth"><i class="fa-solid fa-peso-sign"></i></div>
                            <div><div class="stat-value">₱<?= number_format($totalSpent,0) ?></div><div class="stat-label">Total Spent</div></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-4">
                        <div class="stat-card">
                            <div class="stat-icon orange"><i class="fa-solid fa-comments"></i></div>
                            <div><div class="stat-value"><?= $unreadMessages ?></div><div class="stat-label">Unread Msgs</div></div>
                        </div>
                    </div>
    </div><!-- end row -->
    </div><!-- end #widget-stats -->

    <!-- ── Premium Analytics ─────────────────────────────────────── -->
    <div id="widget-analytics" data-widget="analytics">
                <?php if ($isPremium):
                // KPI
                $anStmt = $pdo->prepare("
                    SELECT
                        COUNT(DISTINCT o.id)                  AS total_orders,
                        COALESCE(SUM(o.total_amount),0)       AS total_spent,
                        COALESCE(AVG(o.total_amount),0)       AS avg_order,
                        COUNT(DISTINCT o.farmer_id)           AS unique_farmers
                    FROM orders o
                    WHERE o.buyer_id = ? AND o.status != 'cancelled'
                ");
                $anStmt->execute([$userId]); $an = $anStmt->fetch();

                // Monthly spending last 6 months
                $monthlyStmt = $pdo->prepare("
                    SELECT DATE_FORMAT(created_at,'%b %Y') AS month_label,
                           DATE_FORMAT(created_at,'%Y-%m') AS month_key,
                           COALESCE(SUM(total_amount),0)   AS spent,
                           COUNT(*)                         AS orders
                    FROM orders
                    WHERE buyer_id = ? AND status != 'cancelled'
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                    GROUP BY month_key, month_label
                    ORDER BY month_key ASC
                ");
                $monthlyStmt->execute([$userId]); $monthly = $monthlyStmt->fetchAll();

                // Top farmers
                $topFarmersStmt = $pdo->prepare("
                    SELECT u.name AS farmer_name,
                           COUNT(DISTINCT o.id) AS order_count,
                           SUM(o.total_amount)  AS total_spent
                    FROM orders o
                    JOIN users u ON u.id = o.farmer_id
                    WHERE o.buyer_id = ? AND o.status = 'completed'
                    GROUP BY o.farmer_id ORDER BY total_spent DESC LIMIT 5
                ");
                $topFarmersStmt->execute([$userId]); $topFarmers = $topFarmersStmt->fetchAll();

                // Top categories
                $topCatStmt = $pdo->prepare("
                    SELECT p.category,
                           SUM(oi.subtotal)    AS cat_spent,
                           SUM(oi.quantity_kg) AS total_kg
                    FROM orders o
                    JOIN order_items oi ON oi.order_id = o.id
                    JOIN products p     ON p.id = oi.product_id
                    WHERE o.buyer_id = ? AND o.status != 'cancelled'
                    GROUP BY p.category ORDER BY cat_spent DESC
                ");
                $topCatStmt->execute([$userId]); $catData = $topCatStmt->fetchAll();

                // Order status breakdown
                $statusStmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM orders WHERE buyer_id=? GROUP BY status");
                $statusStmt->execute([$userId]); $statusData = $statusStmt->fetchAll();
                ?>

                <div class="mb-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <h5 style="font-weight:800;margin:0;">📊 Spending Analytics</h5>
                            <span style="background:linear-gradient(135deg,#1565C0,#1976D2);color:white;font-size:.6rem;font-weight:800;padding:2px 8px;border-radius:99px;">⭐ PREMIUM</span>
                        </div>
                        <a href="reports.php" style="font-size:.78rem;font-weight:700;color:var(--primary);text-decoration:none;">Full Report →</a>
                    </div>

                    <!-- KPI Cards -->
                    <div class="row g-3 mb-3">
                        <?php foreach ([
                            ['📦', number_format($an['total_orders']), 'Total Orders', '#E8F5E9', '#16a34a'],
                            ['💰', '₱'.number_format($an['total_spent'],0), 'Total Spent', '#FFF8E1', '#d97706'],
                            ['🛒', '₱'.number_format($an['avg_order'],0), 'Avg Order Value', '#E3F2FD', '#1d4ed8'],
                            ['🌾', number_format($an['unique_farmers']), 'Farmers Ordered From', '#FCE4EC', '#be185d'],
                        ] as [$icon,$val,$lbl,$bg,$color]): ?>
                        <div class="col-6 col-lg-3">
                            <div style="background:white;border:1px solid var(--border);border-radius:14px;padding:1rem 1.1rem;display:flex;align-items:center;gap:10px;">
                                <div style="background:<?= $bg ?>;width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;"><?= $icon ?></div>
                                <div>
                                    <div style="font-family:'Playfair Display',serif;font-size:1.1rem;font-weight:700;color:var(--text);line-height:1.1;"><?= $val ?></div>
                                    <div style="font-size:.63rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-top:2px;"><?= $lbl ?></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Charts Row 1 -->
                    <div class="row g-3 mb-3">
                        <div class="col-lg-7">
                            <div style="background:white;border:1px solid var(--border);border-radius:14px;padding:1.1rem 1.25rem;">
                                <div style="font-size:.78rem;font-weight:800;color:var(--text);margin-bottom:.75rem;">
                                    <i class="fa-solid fa-chart-line text-green me-1"></i> Monthly Spending (Last 6 Months)
                                </div>
                                <div style="position:relative;height:200px;">
                                    <canvas id="buyerSpendingChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div style="background:white;border:1px solid var(--border);border-radius:14px;padding:1.1rem 1.25rem;">
                                <div style="font-size:.78rem;font-weight:800;color:var(--text);margin-bottom:.75rem;">
                                    <i class="fa-solid fa-chart-pie text-green me-1"></i> Spending by Category
                                </div>
                                <div style="position:relative;height:200px;">
                                    <canvas id="buyerCatChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row 2 -->
                    <div class="row g-3 mb-3">
                        <div class="col-lg-8">
                            <div style="background:white;border:1px solid var(--border);border-radius:14px;padding:1.1rem 1.25rem;">
                                <div style="font-size:.78rem;font-weight:800;color:var(--text);margin-bottom:.75rem;">
                                    <i class="fa-solid fa-trophy text-green me-1"></i> Top Farmers You Buy From
                                </div>
                                <?php if (empty($topFarmers)): ?>
                                <div style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:.85rem;">No completed orders yet.</div>
                                <?php else:
                                    $maxFSpent = max(array_column($topFarmers,'total_spent')) ?: 1;
                                    foreach ($topFarmers as $i => $tf):
                                        $pct = round(($tf['total_spent']/$maxFSpent)*100);
                                ?>
                                <div style="display:flex;align-items:center;gap:10px;margin-bottom:.75rem;">
                                    <div style="width:22px;height:22px;background:<?= $i===0?'linear-gradient(135deg,#1565C0,#1976D2)':'var(--bg)' ?>;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:800;color:<?= $i===0?'white':'var(--text-muted)' ?>;flex-shrink:0;">
                                        <?= $i===0?'🥇':($i===1?'🥈':($i===2?'🥉':($i+1))) ?>
                                    </div>
                                    <div style="flex:1;min-width:0;">
                                        <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                                            <span style="font-size:.82rem;font-weight:700;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= sanitize($tf['farmer_name']) ?></span>
                                            <span style="font-size:.78rem;font-weight:800;color:#1565C0;flex-shrink:0;margin-left:8px;">₱<?= number_format($tf['total_spent'],0) ?></span>
                                        </div>
                                        <div style="background:#f3f4f6;border-radius:99px;height:7px;overflow:hidden;">
                                            <div style="width:<?= $pct ?>%;background:#1565C0;height:100%;border-radius:99px;transition:width .5s;"></div>
                                        </div>
                                        <div style="font-size:.68rem;color:var(--text-muted);margin-top:2px;"><?= $tf['order_count'] ?> order<?= $tf['order_count']!=1?'s':'' ?></div>
                                    </div>
                                </div>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div style="background:white;border:1px solid var(--border);border-radius:14px;padding:1.1rem 1.25rem;height:100%;">
                                <div style="font-size:.78rem;font-weight:800;color:var(--text);margin-bottom:.75rem;">
                                    <i class="fa-solid fa-circle-check text-green me-1"></i> Order Status Breakdown
                                </div>
                                <?php
                                $statusConfig = [
                                    'pending'     => ['color'=>'#d97706','icon'=>'⏳'],
                                    'confirmed'   => ['color'=>'#16a34a','icon'=>'✅'],
                                    'processing'  => ['color'=>'#8b5cf6','icon'=>'📦'],
                                    'on_delivery' => ['color'=>'#0ea5e9','icon'=>'🚚'],
                                    'completed'   => ['color'=>'#15803d','icon'=>'🎉'],
                                    'cancelled'   => ['color'=>'#dc2626','icon'=>'❌'],
                                ];
                                $totalSO = array_sum(array_column($statusData,'cnt')) ?: 1;
                                foreach ($statusData as $s):
                                    $sc = $statusConfig[$s['status']] ?? ['color'=>'#6b7280','icon'=>'📦'];
                                    $pct = round(($s['cnt']/$totalSO)*100);
                                ?>
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:.6rem;">
                                    <span style="font-size:.85rem;width:20px;text-align:center;"><?= $sc['icon'] ?></span>
                                    <div style="flex:1;">
                                        <div style="display:flex;justify-content:space-between;font-size:.75rem;font-weight:700;margin-bottom:2px;">
                                            <span style="color:var(--text);text-transform:capitalize;"><?= ucfirst(str_replace('_',' ',$s['status'])) ?></span>
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
                (function(){
                    const monthLabels  = <?= json_encode(array_column($monthly,'month_label')) ?>;
                    const monthSpent   = <?= json_encode(array_map(fn($r)=>round($r['spent'],2), $monthly)) ?>;
                    const catNames     = <?= json_encode(array_column($catData,'category')) ?>;
                    const catSpent     = <?= json_encode(array_map(fn($r)=>round($r['cat_spent'],2), $catData)) ?>;
                    const mixed = ['#1B5E20','#1d4ed8','#d97706','#be185d','#0e7490','#7c3aed','#dc2626','#15803d'];

                    if (document.getElementById('buyerSpendingChart')) {
                        new Chart(document.getElementById('buyerSpendingChart'), {
                            type: 'line',
                            data: {
                                labels: monthLabels.length ? monthLabels : ['No data'],
                                datasets: [{ data: monthSpent.length ? monthSpent : [0], borderColor:'#1565C0', backgroundColor:'rgba(21,101,192,0.08)', borderWidth:2.5, pointBackgroundColor:'#1565C0', pointRadius:5, tension:0.4, fill:true, label:'Spending' }]
                            },
                            options: { responsive:true, maintainAspectRatio:false,
                                plugins:{ legend:{display:false}, tooltip:{callbacks:{label:c=>'₱'+c.parsed.y.toLocaleString('en-PH',{minimumFractionDigits:2})}}},
                                scales:{ x:{grid:{display:false},ticks:{font:{size:10}}}, y:{grid:{color:'rgba(0,0,0,0.04)'},ticks:{font:{size:10},callback:v=>'₱'+(v>=1000?(v/1000).toFixed(1)+'k':v)}}}
                            }
                        });
                    }
                    if (document.getElementById('buyerCatChart')) {
                        new Chart(document.getElementById('buyerCatChart'), {
                            type: 'doughnut',
                            data: { labels: catNames.length?catNames:['No data'], datasets:[{ data:catSpent.length?catSpent:[1], backgroundColor:mixed.slice(0,catNames.length), borderWidth:2, borderColor:'white' }] },
                            options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{position:'bottom',labels:{font:{size:9},padding:8,boxWidth:12}}, tooltip:{callbacks:{label:c=>c.label+': ₱'+c.parsed.toLocaleString('en-PH',{minimumFractionDigits:2})}}}, cutout:'60%' }
                        });
                    }
                })();
             </script>
                <?php endif; ?>
    </div><!-- end #widget-analytics -->

<div id="widget-orders" data-widget="orders">
                <!-- Recent Orders -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 style="font-weight:800;margin:0;">Recent Orders</h5>
                        <a href="../orders/index.php" class="btn-outline-green" style="padding:0.35rem 1rem;font-size:0.82rem;">View All</a>
                    </div>
                    <div class="gl-table">
                        <table>
                            <thead><tr><th>Order #</th><th>Farmer</th><th>Amount</th><th>Status</th><th>Date</th><th></th></tr></thead>
                            <tbody>
                                <?php if (empty($recentOrders)): ?>
                                <tr><td colspan="6" class="text-center" style="padding:2rem;color:var(--text-muted);">No orders yet. <a href="../buyer/browse.php" style="color:var(--primary);">Start shopping →</a></td></tr>
                                <?php else: foreach ($recentOrders as $o): ?>
                                <tr>
                                    <td><strong>#<?= $o['id'] ?></strong></td>
                                    <td><?= sanitize($o['farmer_name']) ?></td>
                                    <td><strong style="color:var(--primary);">₱<?= number_format($o['total_amount'],2) ?></strong></td>
                                    <td><span class="status-badge <?= $statusColors[$o['status']] ?? '' ?>"><?= ucfirst($o['status']) ?></span></td>
                                    <td style="color:var(--text-muted);font-size:0.82rem;"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
                                    <td><a href="../orders/detail.php?id=<?= $o['id'] ?>" style="color:var(--primary);font-size:0.82rem;font-weight:700;text-decoration:none;">View →</a></td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
</div>
    </div><!-- end #widget-orders -->

   <div id="widget-products" data-widget="products">
                <!-- Recommended Products -->
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 style="font-weight:800;margin:0;">🌾 Fresh Picks for You</h5>
                        <a href="../buyer/browse.php" class="btn-outline-green" style="padding:0.35rem 1rem;font-size:0.82rem;">See All</a>
                    </div>
                    <div class="row g-3">
                        <?php foreach ($recommended as $p): ?>
                        <div class="col-sm-6">
                            <div class="product-card" style="display:flex;flex-direction:row;height:auto;">
                                <div class="product-card-img" style="width:90px;height:90px;flex-shrink:0;font-size:2rem;">
                                    <?= $p['image'] ? '<img src="../assets/images/products/'.sanitize($p['image']).'" style="width:90px;height:90px;object-fit:cover;">' : ($emojis[$p['category']] ?? '🌾') ?>
                                </div>
                                <div class="product-card-body" style="padding:0.8rem;flex:1;">
                                    <div class="product-card-title" style="font-size:0.88rem;"><?= sanitize($p['name']) ?></div>
                                    <div class="product-card-meta" style="font-size:0.72rem;"><i class="fa-solid fa-location-dot text-green"></i> <?= sanitize($p['farmer_city']) ?></div>
                                    <div class="d-flex align-items-center justify-content-between mt-1">
                                        <div style="font-size:1rem;font-weight:800;color:var(--primary);">₱<?= number_format($p['price_per_kg'],2) ?><span style="font-size:0.72rem;font-weight:500;color:var(--text-muted);">/kg</span></div>
                                        <a href="../buyer/product.php?id=<?= $p['id'] ?>" class="btn-green" style="padding:0.3rem 0.7rem;font-size:0.75rem;"><i class="fa-solid fa-cart-plus"></i></a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
        </div>
    </div><!-- end #widget-products -->

            </div><!-- end col-lg-9 -->
        </div>
    </div>
</div>


<!-- ── Dashboard Customization Script ──────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<style>
/* ── Widget wrappers ────────────────────────────────────────────────────── */
.widget-wrapper {
    border-radius: 14px;
}
.widget-wrapper.is-dragging * {
    pointer-events: none;
}
.widget-wrapper.hidden-widget {
    display: none !important;
}

/* ── Sortable dragging states ───────────────────────────────────────────── */
.widget-wrapper.sortable-ghost {
    opacity: .3;
    transform: scale(.97);
    border: 2px dashed #1d4ed8;
    border-radius: 14px;
}
.widget-wrapper.sortable-chosen {
    box-shadow: 0 8px 32px rgba(29,78,216,.18);
    border-radius: 14px;
    cursor: grabbing;
}
.widget-wrapper.sortable-drag {
    opacity: 1 !important;
    background: white;
    border-radius: 14px;
    box-shadow: 0 12px 40px rgba(29,78,216,.22);
}

/* ── Per-widget toolbar ─────────────────────────────────────────────────── */
.customize-toolbar {
    display: none;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    padding: 7px 12px;
    background: linear-gradient(135deg,#eff6ff,#dbeafe);
    border: 1.5px dashed #1d4ed8;
    border-radius: 10px;
    user-select: none;
}
.customize-toolbar.active { display: flex; }

.drag-handle {
    color: #1d4ed8;
    font-size: 1.05rem;
    cursor: grab;
    padding: 2px 4px;
    border-radius: 6px;
    transition: background .15s;
}
.drag-handle:hover  { background: #bfdbfe; }
.drag-handle:active { cursor: grabbing; }

.widget-label {
    font-size: .76rem;
    font-weight: 800;
    color: #1e3a8a;
    flex: 1;
    letter-spacing: .02em;
}

.toggle-vis-btn {
    background: none;
    border: none;
    cursor: pointer;
    font-size: .85rem;
    padding: 4px 8px;
    border-radius: 7px;
    font-weight: 700;
    color: #1d4ed8;
    transition: background .15s, color .15s;
    display: flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
}
.toggle-vis-btn:hover     { background: #bfdbfe; }
.toggle-vis-btn.is-hidden { color: #9ca3af; }

/* ── Hidden-widget notice bar ───────────────────────────────────────────── */
.widget-hidden-notice {
    display: none;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    background: #f9fafb;
    border: 1.5px dashed #d1d5db;
    border-radius: 12px;
    margin-bottom: 12px;
    font-size: .8rem;
    font-weight: 700;
    color: #9ca3af;
}
.widget-hidden-notice.active { display: flex; }
</style>

<script>
(function () {
    const STORAGE_KEY = 'buyerDashboardLayout_v2';
    const widgetIds   = ['stats', 'analytics', 'orders', 'products'];
    const widgetMeta  = {
        stats:     { label: '📊 Stats Overview'     },
        analytics: { label: '📈 Spending Analytics' },
        orders:    { label: '📦 Recent Orders'       },
        products:  { label: '🌾 Fresh Picks'         },
    };

    let sortableInst    = null;
    let isCustomizing   = false;
    let preEditSnapshot = null;

    /* ── 1. Inject toolbar + hidden-notice per widget ──────────────────── */
    widgetIds.forEach(id => {
        const el = document.getElementById('widget-' + id);
        if (!el) return;
        el.classList.add('widget-wrapper');

        /* Toolbar (drag handle + label + hide/show button) */
        const tb = document.createElement('div');
        tb.className = 'customize-toolbar';
        tb.id = 'toolbar-' + id;
        tb.innerHTML = `
            <span class="drag-handle" title="Drag to reorder">
                <i class="fa-solid fa-grip-dots-vertical"></i>
            </span>
            <span class="widget-label">${widgetMeta[id].label}</span>
            <button class="toggle-vis-btn" id="visBtn-${id}"
                    onclick="toggleWidgetVisibility('${id}')">
                <i class="fa-solid fa-eye" id="visIcon-${id}"></i>
                <span id="visTxt-${id}">Hide</span>
            </button>`;
        el.insertBefore(tb, el.firstChild);

        /* Notice placeholder shown when widget is hidden during edit mode */
        const notice = document.createElement('div');
        notice.className = 'widget-hidden-notice';
        notice.id = 'notice-' + id;
        notice.innerHTML = `
            <i class="fa-solid fa-eye-slash"></i>
            <span>${widgetMeta[id].label} — hidden</span>
            <button class="toggle-vis-btn" style="margin-left:auto;"
                    onclick="toggleWidgetVisibility('${id}')">
                <i class="fa-solid fa-eye"></i> Show
            </button>`;
        el.parentElement.insertBefore(notice, el);
    });

    /* ── 2. Layout helpers ─────────────────────────────────────────────── */
    function getContainer() {
        const el = document.getElementById('widget-stats');
        return el ? el.parentElement : null;
    }

    function snapshot() {
        const container = getContainer();
        const nodes = Array.from(container.children);
        const order = widgetIds
            .filter(id => document.getElementById('widget-' + id))
            .sort((a, b) =>
                nodes.indexOf(document.getElementById('widget-' + a)) -
                nodes.indexOf(document.getElementById('widget-' + b))
            );
        const hidden = widgetIds.filter(id => {
            const el = document.getElementById('widget-' + id);
            return el && el.classList.contains('hidden-widget');
        });
        return { order, hidden };
    }

    function applyLayout(state) {
        if (!state) return;
        const container = getContainer();

        /* Restore order */
        (state.order || []).forEach(id => {
            const el = document.getElementById('widget-' + id);
            if (el) container.appendChild(el);
        });

        /* Restore visibility */
        widgetIds.forEach(id => {
            const el   = document.getElementById('widget-' + id);
            const icon = document.getElementById('visIcon-' + id);
            const txt  = document.getElementById('visTxt-'  + id);
            const btn  = document.getElementById('visBtn-'  + id);
            if (!el) return;
            const hide = (state.hidden || []).includes(id);
            el.classList.toggle('hidden-widget', hide);
   if (icon) icon.className  = hide ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
            if (txt)  txt.textContent = hide ? 'Hide'                  : 'Show';
            if (btn)  btn.classList.toggle('is-hidden', hide);
        });
    }

    function loadSaved() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (raw) applyLayout(JSON.parse(raw));
        } catch(e) {}
    }

    /* ── 3. Enter customize mode ───────────────────────────────────────── */
    window.toggleCustomizeMode = function () {
        isCustomizing   = true;
        preEditSnapshot = snapshot();

 document.getElementById('customizeBtn').style.display = 'none';
        document.getElementById('saveBtn').style.display      = '';
        document.getElementById('cancelBtn').style.display    = '';

        const banner = document.getElementById('customizeBanner');
        banner.classList.remove('d-none');
        banner.style.display = 'flex';

        /* Show toolbars */
        document.querySelectorAll('.customize-toolbar').forEach(t => t.classList.add('active'));

        /* For already-hidden widgets: show dimmed + show notice */
        widgetIds.forEach(id => {
            const el     = document.getElementById('widget-' + id);
            const notice = document.getElementById('notice-' + id);
            if (!el) return;
            const isHidden = el.classList.contains('hidden-widget');
            if (isHidden) {
                el.style.display       = '';
                el.style.opacity       = '.28';
                el.style.pointerEvents = 'none';
            }
            if (notice) notice.classList.toggle('active', isHidden);
        });

 /* Enable drag-and-drop */
        const container = getContainer();
        if (container && !sortableInst) {
            sortableInst = Sortable.create(container, {
                handle:        '.drag-handle',
                animation:     200,
                ghostClass:    'sortable-ghost',
                chosenClass:   'sortable-chosen',
                dragClass:     'sortable-drag',
                filter:        '.widget-hidden-notice',
                forceFallback: true,   /* use pure-JS drag — avoids HTML5 DnD quirks */
                fallbackTolerance: 3,
                scroll:        false,  /* we handle scroll manually */
                onStart:       startAutoScroll,
                onEnd:         stopAutoScroll,
            });
        }
    };

  /* ── Auto-scroll helpers ───────────────────────────────────────────── */
    let _raf      = null;
    let _dragging = false;
    let _clientY  = 0;
    let _scrollEl = null;   /* resolved once per drag start */

    function _onMove(e) {
        _clientY = e.touches ? e.touches[0].clientY : e.clientY;
    }

    /* Walk up and find the element that is actually scrolling the page */
    function _resolveScroller() {
        /* Try documentElement first (Chrome), then body (Safari/older) */
        if (document.documentElement.scrollTop > 0 ||
            document.documentElement.scrollHeight > document.documentElement.clientHeight) {
            return document.documentElement;
        }
        if (document.body.scrollTop > 0 ||
            document.body.scrollHeight > document.body.clientHeight) {
            return document.body;
        }
        return document.documentElement; /* safe fallback */
    }

    function _scrollLoop() {
        if (!_dragging) return;

        const zone     = 160;   /* px from top/bottom edge */
        const maxSpeed = 24;
        const wh       = window.innerHeight;
        let   speed    = 0;

        if (_clientY < zone) {
            speed = -maxSpeed * (1 - _clientY / zone);          /* scroll UP */
        } else if (_clientY > wh - zone) {
            speed = maxSpeed * (1 - (wh - _clientY) / zone);   /* scroll DOWN */
        }

        if (speed !== 0 && _scrollEl) {
            _scrollEl.scrollTop += speed;
        }

        _raf = requestAnimationFrame(_scrollLoop);
    }

function startAutoScroll() {
        _dragging = true;
        document.querySelectorAll('.widget-wrapper').forEach(w => w.classList.add('is-dragging'));
        _clientY  = window.innerHeight / 2;
        _scrollEl = _resolveScroller();
        document.addEventListener('mousemove', _onMove);
        document.addEventListener('touchmove', _onMove, { passive: true });
        cancelAnimationFrame(_raf);
        _raf = requestAnimationFrame(_scrollLoop);
    }

function stopAutoScroll() {
        _dragging = false;
        document.querySelectorAll('.widget-wrapper').forEach(w => w.classList.remove('is-dragging'));
        _scrollEl = null;
        document.removeEventListener('mousemove', _onMove);
        document.removeEventListener('touchmove', _onMove);
        if (_raf) { cancelAnimationFrame(_raf); _raf = null; }
    }

    /* ── 4. Toggle a single widget's visibility ────────────────────────── */
    window.toggleWidgetVisibility = function (id) {
        const el     = document.getElementById('widget-' + id);
        const icon   = document.getElementById('visIcon-' + id);
        const txt    = document.getElementById('visTxt-'  + id);
        const btn    = document.getElementById('visBtn-'  + id);
        const notice = document.getElementById('notice-'  + id);
        if (!el) return;

        const nowHiding = !el.classList.contains('hidden-widget');
        el.classList.toggle('hidden-widget', nowHiding);

        /* While in edit mode keep widget visible but dimmed */
        if (isCustomizing) {
            el.style.display       = '';
            el.style.opacity       = nowHiding ? '.28' : '1';
            el.style.pointerEvents = nowHiding ? 'none' : '';
        }

        if (icon)   icon.className  = nowHiding ? 'fa-solid fa-eye'       : 'fa-solid fa-eye-slash';
        if (txt)    txt.textContent = nowHiding ? 'Show'                  : 'Hide';
        if (btn)    btn.classList.toggle('is-hidden', nowHiding);
        if (notice) notice.classList.toggle('active', isCustomizing && nowHiding);
    };

    /* ── 5. Save ───────────────────────────────────────────────────────── */
    window.saveDashboardLayout = function () {
        /* Clear in-edit style overrides and enforce final display state */
        widgetIds.forEach(id => {
            const el = document.getElementById('widget-' + id);
            if (!el) return;
            el.style.opacity       = '';
            el.style.pointerEvents = '';
            el.style.display       = el.classList.contains('hidden-widget') ? 'none' : '';
        });

        localStorage.setItem(STORAGE_KEY, JSON.stringify(snapshot()));
        exitCustomize();

        /* Success toast */
        const toast = document.createElement('div');
        toast.textContent = '✅ Dashboard layout saved!';
        toast.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;background:#16a34a;color:#fff;padding:.75rem 1.5rem;border-radius:12px;font-weight:700;font-size:.85rem;box-shadow:0 4px 18px rgba(0,0,0,.15);';
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2600);
    };

    /* ── 6. Cancel — restore pre-edit state ────────────────────────────── */
    window.cancelCustomize = function () {
        /* Clear all inline overrides first */
        widgetIds.forEach(id => {
            const el = document.getElementById('widget-' + id);
            if (el) {
                el.style.opacity       = '';
                el.style.pointerEvents = '';
                el.style.display       = '';
                el.classList.remove('hidden-widget');
            }
        });
        if (preEditSnapshot) applyLayout(preEditSnapshot);
        exitCustomize();
    };

    /* ── 7. Exit customize mode ────────────────────────────────────────── */
    function exitCustomize() {
        isCustomizing = false;
document.getElementById('customizeBtn').style.display = '';
        document.getElementById('saveBtn').style.display      = 'none';
        document.getElementById('cancelBtn').style.display    = 'none';

        const banner = document.getElementById('customizeBanner');
        banner.classList.add('d-none');
        banner.style.display = '';

        document.querySelectorAll('.customize-toolbar').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.widget-hidden-notice').forEach(n => n.classList.remove('active'));

        if (sortableInst) { sortableInst.destroy(); sortableInst = null; }
    }

   /* ── 8. Boot ───────────────────────────────────────────────────────── */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadSaved);
    } else {
        loadSaved();
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
