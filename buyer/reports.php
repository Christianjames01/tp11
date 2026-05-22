<?php
// ── Bootstrap only config (no HTML output) ──────────────────────────────────
require_once __DIR__ . '/../config/database.php';

requireLogin();
if ($_SESSION['role'] !== 'buyer') {
    header('Location: ../products/browse.php'); exit();
}

$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];

// ── Premium gate check ────────────────────────────────────────────────────────
$bStmt = $pdo->prepare("SELECT *, is_premium, premium_until FROM users WHERE id=?");
$bStmt->execute([$userId]);
$buyer     = $bStmt->fetch();
$isPremium = !empty($buyer['is_premium']) && strtotime($buyer['premium_until']) > time();

// ── Filters ───────────────────────────────────────────────────────────────────
$rangeMap = [
    '7d'  => ['label' => 'Last 7 days',    'days' => 7],
    '30d' => ['label' => 'Last 30 days',   'days' => 30],
    '90d' => ['label' => 'Last 90 days',   'days' => 90],
    '1y'  => ['label' => 'Last 12 months', 'days' => 365],
    'all' => ['label' => 'All time',       'days' => null],
];
$range    = isset($_GET['range']) && isset($rangeMap[$_GET['range']]) ? $_GET['range'] : '30d';
$rDays    = $rangeMap[$range]['days'];
$dateWhere = $rDays ? "AND o.created_at >= DATE_SUB(NOW(), INTERVAL $rDays DAY)" : '';

// ── CSV export (MUST run before any HTML output) ──────────────────────────────
if ($isPremium && isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportSql = "
        SELECT o.id as order_id, o.created_at, o.status,
               u.name as farmer_name,
               o.total_amount, o.delivery_fee, o.service_fee,
               o.payment_method, o.delivery_address
        FROM orders o
        JOIN users u ON u.id = o.farmer_id
        WHERE o.buyer_id = ? $dateWhere
        ORDER BY o.created_at DESC
    ";
    $eStmt = $pdo->prepare($exportSql);
    $eStmt->execute([$userId]);
    $rows = $eStmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="greenlink_orders_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Order #','Date','Status','Farmer','Total (₱)','Delivery Fee (₱)','Service Fee (₱)','Payment','Address']);
    foreach ($rows as $r) {
        fputcsv($out, [
            '#' . $r['order_id'],
            date('M j, Y g:i a', strtotime($r['created_at'])),
            ucfirst(str_replace('_', ' ', $r['status'])),
            $r['farmer_name'],
            number_format($r['total_amount'], 2),
            number_format($r['delivery_fee'], 2),
            number_format($r['service_fee'], 2),
            $r['payment_method'],
            $r['delivery_address'],
        ]);
    }
    fclose($out);
    exit();
}

// ── NOW it's safe to load the header (outputs HTML) ──────────────────────────
$page_title = 'Purchase Reports';
require_once __DIR__ . '/../includes/header.php';

// ── Summary stats ─────────────────────────────────────────────────────────────
$sumStmt = $pdo->prepare("
    SELECT
        COUNT(*)                                     as total_orders,
        COALESCE(SUM(total_amount), 0)               as total_spent,
        COALESCE(SUM(CASE WHEN status='completed' THEN total_amount END), 0) as completed_spent,
        COALESCE(AVG(CASE WHEN status='completed' THEN total_amount END), 0) as avg_order,
        COALESCE(SUM(delivery_fee), 0)               as total_delivery,
        COALESCE(SUM(service_fee), 0)                as total_fees,
        COUNT(CASE WHEN status='completed' THEN 1 END)  as completed_count,
        COUNT(CASE WHEN status='cancelled' THEN 1 END)  as cancelled_count,
        COUNT(CASE WHEN status='pending'   THEN 1 END)  as pending_count
    FROM orders o
    WHERE buyer_id = ? $dateWhere
");
$sumStmt->execute([$userId]);
$stats = $sumStmt->fetch();

// ── Spending by farmer ────────────────────────────────────────────────────────
$byFarmerStmt = $pdo->prepare("
    SELECT u.name as farmer_name, COUNT(*) as order_count,
           SUM(o.total_amount) as total_spent
    FROM orders o
    JOIN users u ON u.id = o.farmer_id
    WHERE o.buyer_id = ? AND o.status = 'completed' $dateWhere
    GROUP BY o.farmer_id
    ORDER BY total_spent DESC
    LIMIT 8
");
$byFarmerStmt->execute([$userId]);
$byFarmer = $byFarmerStmt->fetchAll();

// ── Spending by category ──────────────────────────────────────────────────────
$byCatStmt = $pdo->prepare("
    SELECT p.category,
           COUNT(DISTINCT o.id) as order_count,
           SUM(oi.subtotal) as cat_spent
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    JOIN products p     ON p.id        = oi.product_id
    WHERE o.buyer_id = ? AND o.status = 'completed' $dateWhere
    GROUP BY p.category
    ORDER BY cat_spent DESC
");
$byCatStmt->execute([$userId]);
$byCat = $byCatStmt->fetchAll();

// ── Monthly spending trend (last 12 months regardless of filter) ──────────────
$monthlyStmt = $pdo->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month_key,
           DATE_FORMAT(created_at, '%b %Y') as month_label,
           COUNT(*) as order_count,
           COALESCE(SUM(total_amount), 0) as monthly_spent
    FROM orders
    WHERE buyer_id = ? AND status IN ('completed','pending','confirmed','processing','on_delivery')
      AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month_key
    ORDER BY month_key ASC
");
$monthlyStmt->execute([$userId]);
$monthly = $monthlyStmt->fetchAll();

// ── Top products purchased ────────────────────────────────────────────────────
$topProdsStmt = $pdo->prepare("
    SELECT p.name, p.category, p.image,
           SUM(oi.quantity_kg) as total_kg,
           SUM(oi.subtotal)    as total_spent,
           COUNT(DISTINCT o.id) as times_ordered
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    JOIN products p     ON p.id        = oi.product_id
    WHERE o.buyer_id = ? AND o.status = 'completed' $dateWhere
    GROUP BY p.id
    ORDER BY total_spent DESC
    LIMIT 5
");
$topProdsStmt->execute([$userId]);
$topProds = $topProdsStmt->fetchAll();

// ── Recent orders list ────────────────────────────────────────────────────────
$recentStmt = $pdo->prepare("
    SELECT o.*, u.name as farmer_name
    FROM orders o
    JOIN users u ON u.id = o.farmer_id
    WHERE o.buyer_id = ? $dateWhere
    ORDER BY o.created_at DESC
    LIMIT 15
");
$recentStmt->execute([$userId]);
$recent = $recentStmt->fetchAll();

// ── Payment method breakdown ──────────────────────────────────────────────────
$payDateWhere = $rDays ? "AND created_at >= DATE_SUB(NOW(), INTERVAL $rDays DAY)" : '';
$payStmt = $pdo->prepare("
    SELECT payment_method, COUNT(*) as cnt, SUM(total_amount) as total
    FROM orders
    WHERE buyer_id = ? $payDateWhere
    GROUP BY payment_method
    ORDER BY cnt DESC
");
$payStmt->execute([$userId]);
$payBreakdown = $payStmt->fetchAll();

$catEmojis = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕','Livestock'=>'🐄','Seafood'=>'🐟','Others'=>'📦'];

$statusColors = [
    'pending'     => '#f59e0b',
    'confirmed'   => '#3b82f6',
    'processing'  => '#8b5cf6',
    'on_delivery' => '#0ea5e9',
    'completed'   => '#16a34a',
    'cancelled'   => '#dc2626',
];
?>

<style>
.report-stat{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.1rem 1.25rem;box-shadow:var(--shadow-sm);}
.report-stat-val{font-size:1.5rem;font-weight:800;color:var(--primary);font-family:'Playfair Display',serif;}
.report-stat-lbl{font-size:.7rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-top:3px;}
.report-stat-sub{font-size:.72rem;color:var(--text-muted);margin-top:2px;}

.range-tab{display:inline-block;padding:.35rem .85rem;border-radius:99px;font-size:.78rem;font-weight:700;text-decoration:none;border:1.5px solid var(--border);color:var(--text-muted);transition:all .2s;white-space:nowrap;}
.range-tab.active{background:var(--primary);border-color:var(--primary);color:white;}
.range-tab:not(.active):hover{border-color:var(--primary);color:var(--primary);}

.farmer-bar{display:flex;align-items:center;gap:10px;margin-bottom:.6rem;}
.farmer-bar-fill{height:8px;border-radius:99px;background:var(--primary);transition:width .6s;}
.farmer-bar-track{flex:1;background:#f1f5f9;border-radius:99px;height:8px;overflow:hidden;}

.cat-pill{display:flex;align-items:center;justify-content:space-between;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:.55rem .85rem;margin-bottom:.4rem;font-size:.83rem;}

.prod-row{display:flex;align-items:center;gap:10px;padding:.7rem 0;border-bottom:1px solid var(--border);}
.prod-row:last-child{border-bottom:none;}

@media(max-width:576px){
    .report-grid-4{grid-template-columns:repeat(2,1fr)!important;}
    .report-grid-3{grid-template-columns:1fr!important;}
}
</style>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">

    <!-- Header -->
    <div class="page-header" style="background:linear-gradient(135deg,#1e3a8a,#1d4ed8);">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h1 style="color:white;">
                        <i class="fa-solid fa-chart-bar me-2" style="color:#93c5fd;"></i>Purchase Reports
                    </h1>
                    <div class="page-breadcrumb" style="color:rgba(255,255,255,.7);">
                        <a href="dashboard.php" style="color:rgba(255,255,255,.7);">Dashboard</a> › Reports
                    </div>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <a href="?range=<?= $range ?>&export=csv"
                       style="background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.4);color:white;border-radius:99px;padding:.4rem 1rem;font-size:.8rem;font-weight:800;text-decoration:none;display:flex;align-items:center;gap:6px;">
                        <i class="fa-solid fa-download"></i> Export CSV
                    </a>
                    <span style="background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.68rem;font-weight:800;padding:5px 12px;border-radius:99px;display:flex;align-items:center;gap:4px;">
                        ⭐ PREMIUM
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="container">

        <!-- Range Tabs -->
        <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1.5rem;padding-top:.25rem;">
            <?php foreach ($rangeMap as $key => $r): ?>
            <a href="?range=<?= $key ?>" class="range-tab <?= $range === $key ? 'active' : '' ?>">
                <?= $r['label'] ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- KPI Grid -->
        <div class="report-grid-4" style="display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-bottom:1.5rem;">
            <div class="report-stat">
                <div class="report-stat-val">₱<?= number_format($stats['completed_spent'], 0) ?></div>
                <div class="report-stat-lbl">Total Spent</div>
                <div class="report-stat-sub"><?= $stats['completed_count'] ?> completed orders</div>
            </div>
            <div class="report-stat">
                <div class="report-stat-val"><?= $stats['total_orders'] ?></div>
                <div class="report-stat-lbl">Orders Placed</div>
                <div class="report-stat-sub"><?= $stats['pending_count'] ?> pending</div>
            </div>
            <div class="report-stat">
                <div class="report-stat-val">₱<?= number_format($stats['avg_order'], 0) ?></div>
                <div class="report-stat-lbl">Avg Order Value</div>
                <div class="report-stat-sub">completed only</div>
            </div>
            <div class="report-stat">
                <div class="report-stat-val" style="color:#dc2626;">₱<?= number_format($stats['total_delivery'] + $stats['total_fees'], 0) ?></div>
                <div class="report-stat-lbl">Fees Paid</div>
                <div class="report-stat-sub">delivery + service</div>
            </div>
        </div>

        <!-- Main grid: chart + breakdowns -->
        <div class="report-grid-3" style="display:grid;grid-template-columns:2fr 1fr;gap:1rem;margin-bottom:1rem;">

            <!-- Spending Trend Chart -->
            <div style="background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;box-shadow:var(--shadow-sm);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                    <h6 style="font-weight:800;margin:0;">📈 Monthly Spending Trend</h6>
                    <span style="font-size:.72rem;color:var(--text-muted);">Last 12 months</span>
                </div>
                <?php if (!empty($monthly)): ?>
                <div style="position:relative;height:220px;">
                    <canvas id="spendingChart"></canvas>
                </div>
                <?php else: ?>
                <div style="text-align:center;padding:3rem;color:var(--text-muted);">
                    <div style="font-size:2rem;margin-bottom:.5rem;">📊</div>
                    <div style="font-weight:700;font-size:.85rem;">No order data yet</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Category Breakdown -->
            <div style="background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;box-shadow:var(--shadow-sm);">
                <h6 style="font-weight:800;margin-bottom:1rem;">🏷️ By Category</h6>
                <?php if (!empty($byCat)):
                    $catTotal = array_sum(array_column($byCat, 'cat_spent'));
                    foreach ($byCat as $c):
                        $pct = $catTotal > 0 ? round(($c['cat_spent'] / $catTotal) * 100) : 0;
                ?>
                <div class="cat-pill">
                    <span style="font-weight:700;color:var(--text);">
                        <?= $catEmojis[$c['category']] ?? '📦' ?> <?= sanitize($c['category']) ?>
                    </span>
                    <span style="font-weight:800;color:var(--primary);font-size:.82rem;">
                        ₱<?= number_format($c['cat_spent'], 0) ?>
                        <span style="color:var(--text-muted);font-weight:600;font-size:.7rem;">(<?= $pct ?>%)</span>
                    </span>
                </div>
                <?php endforeach; else: ?>
                <div style="text-align:center;padding:2rem;color:var(--text-muted);font-size:.82rem;">No completed orders yet</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Second row: top farmers + top products -->
        <div class="report-grid-3" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">

            <!-- Top Farmers -->
            <div style="background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;box-shadow:var(--shadow-sm);">
                <h6 style="font-weight:800;margin-bottom:1rem;">🌾 Top Farmers You Buy From</h6>
                <?php if (!empty($byFarmer)):
                    $maxSpent = max(array_column($byFarmer, 'total_spent'));
                    foreach ($byFarmer as $f):
                        $pct = $maxSpent > 0 ? ($f['total_spent'] / $maxSpent) * 100 : 0;
                ?>
                <div class="farmer-bar">
                    <div style="min-width:110px;font-size:.78rem;font-weight:700;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= sanitize($f['farmer_name']) ?>
                    </div>
                    <div class="farmer-bar-track">
                        <div class="farmer-bar-fill" style="width:<?= $pct ?>%;"></div>
                    </div>
                    <div style="min-width:70px;text-align:right;font-size:.78rem;font-weight:800;color:var(--primary);">
                        ₱<?= number_format($f['total_spent'], 0) ?>
                    </div>
                </div>
                <?php endforeach; else: ?>
                <div style="text-align:center;padding:2rem;color:var(--text-muted);font-size:.82rem;">No completed orders yet</div>
                <?php endif; ?>
            </div>

            <!-- Top Products -->
            <div style="background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;box-shadow:var(--shadow-sm);">
                <h6 style="font-weight:800;margin-bottom:.75rem;">🏆 Top Products Purchased</h6>
                <?php if (!empty($topProds)): foreach ($topProds as $tp): ?>
                <div class="prod-row">
                    <div style="width:38px;height:38px;border-radius:8px;overflow:hidden;background:var(--pale-green);display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">
                        <?php if ($tp['image']): ?>
                        <img src="<?= BASE_URL ?>/assets/images/products/<?= htmlspecialchars($tp['image']) ?>"
                             style="width:100%;height:100%;object-fit:cover;">
                        <?php else: ?>
                        <?= $catEmojis[$tp['category']] ?? '🌾' ?>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:800;font-size:.83rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($tp['name']) ?></div>
                        <div style="font-size:.7rem;color:var(--text-muted);"><?= number_format($tp['total_kg'], 1) ?>kg · <?= $tp['times_ordered'] ?> orders</div>
                    </div>
                    <div style="font-weight:800;color:var(--primary);font-size:.83rem;text-align:right;">
                        ₱<?= number_format($tp['total_spent'], 0) ?>
                    </div>
                </div>
                <?php endforeach; else: ?>
                <div style="text-align:center;padding:2rem;color:var(--text-muted);font-size:.82rem;">No product data yet</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Method Breakdown -->
        <?php if (!empty($payBreakdown)): ?>
        <div style="background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;box-shadow:var(--shadow-sm);margin-bottom:1rem;">
            <h6 style="font-weight:800;margin-bottom:1rem;">💳 Payment Methods Used</h6>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                <?php
                $payIcons = ['GCash'=>'fa-mobile-screen','Maya'=>'fa-mobile-screen','Bank Transfer'=>'fa-building-columns','Cash on Delivery'=>'fa-money-bill-wave'];
                $payColors = ['GCash'=>'#0070F0','Maya'=>'#00B14F','Bank Transfer'=>'#1d4ed8','Cash on Delivery'=>'#D97706'];
                foreach ($payBreakdown as $pm):
                    $col = $payColors[$pm['payment_method']] ?? '#64748b';
                ?>
                <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:.7rem 1rem;min-width:140px;">
                    <div style="font-size:.7rem;font-weight:800;color:<?= $col ?>;margin-bottom:3px;">
                        <i class="fa-solid <?= $payIcons[$pm['payment_method']] ?? 'fa-credit-card' ?> me-1"></i>
                        <?= sanitize($pm['payment_method']) ?>
                    </div>
                    <div style="font-size:1rem;font-weight:800;color:var(--text);"><?= $pm['cnt'] ?> orders</div>
                    <div style="font-size:.72rem;color:var(--text-muted);">₱<?= number_format($pm['total'], 0) ?> total</div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Orders Table -->
        <div style="background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;box-shadow:var(--shadow-sm);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <h6 style="font-weight:800;margin:0;">🕐 Recent Orders</h6>
                <a href="../orders/index.php" style="font-size:.78rem;font-weight:700;color:var(--primary);text-decoration:none;">View All →</a>
            </div>
            <?php if (!empty($recent)): ?>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border);">
                            <th style="padding:.5rem .75rem;text-align:left;font-weight:800;color:var(--text-muted);font-size:.7rem;text-transform:uppercase;">Order #</th>
                            <th style="padding:.5rem .75rem;text-align:left;font-weight:800;color:var(--text-muted);font-size:.7rem;text-transform:uppercase;">Farmer</th>
                            <th style="padding:.5rem .75rem;text-align:left;font-weight:800;color:var(--text-muted);font-size:.7rem;text-transform:uppercase;">Amount</th>
                            <th style="padding:.5rem .75rem;text-align:left;font-weight:800;color:var(--text-muted);font-size:.7rem;text-transform:uppercase;">Status</th>
                            <th style="padding:.5rem .75rem;text-align:left;font-weight:800;color:var(--text-muted);font-size:.7rem;text-transform:uppercase;">Date</th>
                            <th style="padding:.5rem .75rem;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent as $o):
                        $statusCol = $statusColors[$o['status']] ?? '#64748b';
                    ?>
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:.6rem .75rem;font-weight:800;color:var(--primary);">#<?= $o['id'] ?></td>
                        <td style="padding:.6rem .75rem;font-weight:700;color:var(--text);"><?= sanitize($o['farmer_name']) ?></td>
                        <td style="padding:.6rem .75rem;font-weight:800;color:var(--primary);">₱<?= number_format($o['total_amount'], 2) ?></td>
                        <td style="padding:.6rem .75rem;">
                            <span style="background:<?= $statusCol ?>22;color:<?= $statusCol ?>;border-radius:99px;padding:2px 10px;font-size:.72rem;font-weight:800;">
                                <?= ucfirst(str_replace('_', ' ', $o['status'])) ?>
                            </span>
                        </td>
                        <td style="padding:.6rem .75rem;color:var(--text-muted);white-space:nowrap;">
                            <?= date('M j, Y', strtotime($o['created_at'])) ?>
                        </td>
                        <td style="padding:.6rem .75rem;">
                            <a href="../orders/detail.php?id=<?= $o['id'] ?>"
                               style="color:var(--primary);font-weight:700;font-size:.78rem;text-decoration:none;">View →</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div style="text-align:center;padding:2.5rem;color:var(--text-muted);">
                <div style="font-size:2.5rem;margin-bottom:.5rem;">📦</div>
                <div style="font-weight:700;">No orders in this period</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Savings summary (premium perk highlight) -->
        <?php
      $premSavingsStmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount * 0.05), 0) as est_savings
            FROM orders
            WHERE buyer_id = ? AND status = 'completed'
              AND is_priority = 1
              $payDateWhere
        ");
        $premSavingsStmt->execute([$userId]);
        $estSavings = floatval($premSavingsStmt->fetchColumn());
        ?>
        <?php if ($estSavings > 0): ?>
        <div style="background:linear-gradient(135deg,#1e3a8a,#1d4ed8);border-radius:var(--radius-lg);padding:1.25rem 1.5rem;margin-top:1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
            <div style="font-size:2rem;">💰</div>
            <div style="flex:1;">
                <div style="color:white;font-weight:800;font-size:.95rem;">Estimated Premium Savings</div>
                <div style="color:rgba(255,255,255,.75);font-size:.78rem;">5% bulk discount applied to your completed orders this period</div>
            </div>
            <div style="font-size:1.6rem;font-weight:800;color:#93c5fd;font-family:'Playfair Display',serif;">
                ~₱<?= number_format($estSavings, 0) ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
<?php if (!empty($monthly)): ?>
(function(){
    const labels = <?= json_encode(array_column($monthly, 'month_label')) ?>;
    const data   = <?= json_encode(array_map(fn($m) => floatval($m['monthly_spent']), $monthly)) ?>;

    const ctx = document.getElementById('spendingChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Total Spent',
                data,
                backgroundColor: 'rgba(27,94,32,0.18)',
                borderColor: '#1B5E20',
                borderWidth: 2,
                borderRadius: 6,
                hoverBackgroundColor: 'rgba(27,94,32,0.32)',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: c => '₱' + c.parsed.y.toLocaleString('en-PH', {minimumFractionDigits:2})
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                y: {
                    grid: { color: 'rgba(0,0,0,0.05)' },
                    ticks: {
                        font: { size: 11 },
                        callback: v => '₱' + (v >= 1000 ? (v/1000).toFixed(1)+'k' : v)
                    }
                }
            }
        }
    });
})();
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>