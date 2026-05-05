<?php
$page_title = 'All Orders';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin');

$pdo = getDBConnection();

// ── Admin profile ─────────────────────────────────────────────────────────────
$adminId = $_SESSION['user_id'];
$admin   = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$admin->execute([$adminId]);
$admin   = $admin->fetch();

// ── Quick sidebar stats ───────────────────────────────────────────────────────
$totalFarmers      = $pdo->query("SELECT COUNT(*) FROM users WHERE role='farmer'")->fetchColumn();
$totalBuyers       = $pdo->query("SELECT COUNT(*) FROM users WHERE role='buyer'")->fetchColumn();
$availableProducts = $pdo->query("SELECT COUNT(*) FROM products WHERE is_available=1")->fetchColumn();
$pendingOrders     = $pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();

// ── Order summary stats ───────────────────────────────────────────────────────
$totalOrders     = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalRevenue    = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='completed'")->fetchColumn();
$cancelledOrders = $pdo->query("SELECT COUNT(*) FROM orders WHERE status='cancelled'")->fetchColumn();
$completedOrders = $pdo->query("SELECT COUNT(*) FROM orders WHERE status='completed'")->fetchColumn();

// ── Status breakdown ──────────────────────────────────────────────────────────
$statusCounts = [];
$stBreak = $pdo->query("SELECT status, COUNT(*) as cnt FROM orders GROUP BY status");
foreach ($stBreak->fetchAll() as $row) {
    $statusCounts[$row['status']] = $row['cnt'];
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterStatus = $_GET['status']    ?? '';
$filterSearch = trim($_GET['search'] ?? '');
$filterDate   = $_GET['date']      ?? '';
$page         = max(1, intval($_GET['page'] ?? 1));
$perPage      = 15;
$offset       = ($page - 1) * $perPage;

// ── Build query ───────────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($filterStatus !== '') {
    $where[]  = "o.status = ?";
    $params[] = $filterStatus;
}
if ($filterSearch !== '') {
    $where[]  = "(ub.name LIKE ? OR uf.name LIKE ? OR o.id = ?)";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
    $params[] = intval($filterSearch);
}
if ($filterDate !== '') {
    $where[]  = "DATE(o.created_at) = ?";
    $params[] = $filterDate;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$countSQL  = "SELECT COUNT(*) FROM orders o
              JOIN users ub ON o.buyer_id  = ub.id
              JOIN users uf ON o.farmer_id = uf.id
              $whereSQL";
$countStmt = $pdo->prepare($countSQL);
$countStmt->execute($params);
$totalFiltered = $countStmt->fetchColumn();
$totalPages    = ceil($totalFiltered / $perPage);

// Fetch
$fetchSQL = "SELECT o.*,
       ub.name          as buyer_name,  ub.email as buyer_email,  ub.profile_image as buyer_photo,
       uf.name          as farmer_name, uf.email as farmer_email, uf.profile_image as farmer_photo,
       ur.name          as rider_name,
                    (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
             FROM orders o
             JOIN users ub ON o.buyer_id  = ub.id
             JOIN users uf ON o.farmer_id = uf.id
             LEFT JOIN users ur ON o.rider_id = ur.id
             $whereSQL
             ORDER BY o.created_at DESC
             LIMIT $perPage OFFSET $offset";
$fetchStmt = $pdo->prepare($fetchSQL);
$fetchStmt->execute($params);
$orders = $fetchStmt->fetchAll();

// ── Helpers ───────────────────────────────────────────────────────────────────
$statusColors = [
    'pending'    => 'status-pending',
    'confirmed'  => 'status-confirmed',
    'processing' => 'status-processing',
    'shipped'    => 'status-shipped',
    'completed'  => 'status-completed',
    'cancelled'  => 'status-cancelled',
];
$statusIcons = [
    'pending'    => 'clock',
    'confirmed'  => 'circle-check',
    'processing' => 'gear',
    'shipped'    => 'truck',
    'completed'  => 'badge-check',
    'cancelled'  => 'circle-xmark',
];
$allStatuses = ['pending','confirmed','processing','shipped','completed','cancelled'];

$statusBg = [
    'pending'    => '#fff7ed',
    'confirmed'  => '#dbeafe',
    'processing' => '#ede9fe',
    'shipped'    => '#cffafe',
    'completed'  => '#dcfce7',
    'cancelled'  => '#fee2e2',
];
$statusFg = [
    'pending'    => '#ea580c',
    'confirmed'  => '#1d4ed8',
    'processing' => '#7c3aed',
    'shipped'    => '#0e7490',
    'completed'  => '#16a34a',
    'cancelled'  => '#dc2626',
];

// Build pagination URL helper
function pageUrl(int $p): string {
    $q = $_GET;
    $q['page'] = $p;
    return '?' . http_build_query($q);
}
?>

<style>
/* ── Shared ── */
.admin-section-title{font-weight:800;font-size:.95rem;margin:0;}
.action-btn{display:inline-flex;align-items:center;gap:5px;padding:.28rem .7rem;border-radius:var(--radius);font-size:.75rem;font-weight:700;border:none;cursor:pointer;transition:all .15s;font-family:inherit;text-decoration:none;}
.action-btn-primary{background:var(--pale-green);color:var(--primary);}.action-btn-primary:hover{background:var(--primary);color:white;}
.action-btn-gray{background:#f1f5f9;color:#64748b;}.action-btn-gray:hover{background:#64748b;color:white;}

/* ── Summary stat tiles ── */
.order-stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-bottom:1.25rem;}
.order-stat-tile{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem 1.1rem;box-shadow:var(--shadow-sm);}
.order-stat-val{font-size:1.35rem;font-weight:800;color:var(--primary);}
.order-stat-lbl{font-size:.7rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-top:2px;}
.order-stat-icon{width:36px;height:36px;border-radius:var(--radius);display:flex;align-items:center;justify-content:center;font-size:.95rem;margin-bottom:.5rem;}

/* ── Status filter tabs ── */
.status-tabs{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem;}
.status-tab{display:inline-flex;align-items:center;gap:5px;padding:.3rem .8rem;border-radius:99px;font-size:.75rem;font-weight:800;border:1.5px solid transparent;cursor:pointer;text-decoration:none;transition:all .15s;white-space:nowrap;}
.status-tab-all{background:var(--pale-green);color:var(--primary);border-color:var(--primary);}
.status-tab-all:hover,.status-tab-all.active{background:var(--primary);color:white;}
.status-tab-default{background:#f1f5f9;color:#64748b;border-color:#e2e8f0;}
.status-tab-default:hover{background:#e2e8f0;}
.status-tab.active{border-color:currentColor;}

/* ── Filters bar ── */
.filter-bar{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:.9rem 1.1rem;margin-bottom:1rem;box-shadow:var(--shadow-sm);display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;}
.filter-input{border:1.5px solid var(--border);border-radius:var(--radius);padding:.38rem .75rem;font-size:.82rem;font-family:inherit;color:var(--text);outline:none;transition:border-color .15s;background:var(--bg);}
.filter-input:focus{border-color:var(--primary);}
.filter-input::placeholder{color:var(--text-muted);}

/* ── Table tweaks ── */
.orders-table td{vertical-align:middle;}
.buyer-cell{display:flex;align-items:center;gap:8px;}
.buyer-avatar{width:28px;height:28px;border-radius:50%;background:var(--pale-green);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:800;flex-shrink:0;}

/* ── Status badge (read-only) ── */
.status-badge{display:inline-flex;align-items:center;gap:5px;padding:.25rem .65rem;border-radius:99px;font-size:.73rem;font-weight:800;}

/* ── View-only notice ── */
.view-only-notice{display:inline-flex;align-items:center;gap:.4rem;background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;border-radius:var(--radius);padding:.35rem .75rem;font-size:.75rem;font-weight:700;margin-bottom:1rem;}

/* ── Pagination ── */
.pagination{display:flex;gap:.35rem;flex-wrap:wrap;align-items:center;}
.page-btn{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:var(--radius);font-size:.78rem;font-weight:700;border:1.5px solid var(--border);background:white;color:var(--text);text-decoration:none;transition:all .15s;}
.page-btn:hover{background:var(--pale-green);border-color:var(--primary);color:var(--primary);}
.page-btn.active{background:var(--primary);border-color:var(--primary);color:white;}
.page-btn.disabled{opacity:.4;pointer-events:none;}

@media(max-width:768px){
    .order-stat-grid{grid-template-columns:repeat(2,1fr);}
    .filter-bar{flex-direction:column;align-items:stretch;}
    .filter-input{width:100%;}
}
</style>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">

    <div class="page-header">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h1><i class="fa-solid fa-box text-green me-2"></i>All Orders</h1>
                    <div class="page-breadcrumb">
                        <a href="dashboard.php" style="color:var(--primary);text-decoration:none;">Dashboard</a>
                        &rsaquo; <strong>Manage Orders</strong>
                    </div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="users.php"    class="btn-outline-green" style="padding:.45rem 1rem;font-size:.82rem;"><i class="fa-solid fa-users"></i> Users</a>
                    <a href="products.php" class="btn-green"         style="padding:.45rem 1rem;font-size:.82rem;"><i class="fa-solid fa-seedling"></i> Products</a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="row g-4">

            <!-- ── Sidebar ── -->
            <div class="col-lg-3">
                <div class="gl-sidebar">
                    <div class="gl-sidebar-header">
                 
<?php if (!empty($admin['profile_image'])): ?>
    <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($admin['profile_image']) ?>"
         style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid white;" alt="Profile">
<?php else: ?>
    <div class="user-avatar"><?= strtoupper(substr($admin['name'], 0, 1)) ?></div>
<?php endif; ?>
                        <div class="user-name"><?= htmlspecialchars($admin['name']) ?></div>
                        <div class="user-role">⚙️ Administrator</div>
                    </div>
                    <nav class="gl-sidebar-nav">
                        <a href="dashboard.php"><i class="fa-solid fa-grid-2"></i> Dashboard</a>
                        <a href="users.php"><i class="fa-solid fa-users"></i> Manage Users</a>
                        <a href="products.php"><i class="fa-solid fa-seedling"></i> All Products</a>
                        <a href="orders.php" class="active"><i class="fa-solid fa-box"></i> All Orders</a>
                        <a href="<?= BASE_URL ?>/admin/marketprices.php"><i class="fa-solid fa-chart-line"></i> Market Prices</a>
                        <div class="nav-divider"></div>
                        <a href="../auth/logout.php" style="color:#E53E3E;"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                    </nav>
                    <div style="padding:1rem;border-top:1px solid var(--border);margin-top:.5rem;">
                        <div style="font-size:.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem;">Quick Stats</div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;">
                            <span style="font-size:.78rem;color:var(--text-muted);">Farmers</span>
                            <strong style="font-size:.78rem;color:var(--primary);"><?= $totalFarmers ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;">
                            <span style="font-size:.78rem;color:var(--text-muted);">Buyers</span>
                            <strong style="font-size:.78rem;color:var(--primary);"><?= $totalBuyers ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;">
                            <span style="font-size:.78rem;color:var(--text-muted);">Active Listings</span>
                            <strong style="font-size:.78rem;color:var(--primary);"><?= $availableProducts ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span style="font-size:.78rem;color:var(--text-muted);">Pending Orders</span>
                            <strong style="font-size:.78rem;color:#f97316;"><?= $pendingOrders ?></strong>
                        </div>
                    </div>

                    <!-- Status breakdown in sidebar -->
                    <div style="padding:1rem;border-top:1px solid var(--border);">
                        <div style="font-size:.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem;">By Status</div>
                        <?php
                        $sidebarStatusColors = [
                            'pending'    => '#f97316',
                            'confirmed'  => '#3b82f6',
                            'processing' => '#8b5cf6',
                            'shipped'    => '#0891b2',
                            'completed'  => '#16a34a',
                            'cancelled'  => '#dc2626',
                        ];
                        foreach ($allStatuses as $st):
                            $cnt = $statusCounts[$st] ?? 0;
                            $col = $sidebarStatusColors[$st];
                        ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
                            <span style="font-size:.78rem;color:var(--text-muted);"><?= ucfirst($st) ?></span>
                            <span style="font-size:.72rem;font-weight:800;background:<?= $col ?>22;color:<?= $col ?>;padding:1px 8px;border-radius:99px;"><?= $cnt ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- ── Main Content ── -->
            <div class="col-lg-9">

                <!-- View-only notice -->
                <div class="view-only-notice">
                    <i class="fa-solid fa-eye"></i>
                    View-only mode — use <strong>Order Details</strong> to manage individual orders
                </div>

                <!-- Summary stat tiles -->
                <div class="order-stat-grid">
                    <div class="order-stat-tile">
                        <div class="order-stat-icon" style="background:var(--pale-green);">
                            <i class="fa-solid fa-box" style="color:var(--primary);"></i>
                        </div>
                        <div class="order-stat-val"><?= $totalOrders ?></div>
                        <div class="order-stat-lbl">Total Orders</div>
                    </div>
                    <div class="order-stat-tile">
                        <div class="order-stat-icon" style="background:#dcfce7;">
                            <i class="fa-solid fa-peso-sign" style="color:#16a34a;"></i>
                        </div>
                        <div class="order-stat-val" style="color:#16a34a;">₱<?= number_format($totalRevenue, 0) ?></div>
                        <div class="order-stat-lbl">Completed Revenue</div>
                    </div>
                    <div class="order-stat-tile">
                        <div class="order-stat-icon" style="background:#fff7ed;">
                            <i class="fa-solid fa-clock" style="color:#f97316;"></i>
                        </div>
                        <div class="order-stat-val" style="color:#f97316;"><?= $pendingOrders ?></div>
                        <div class="order-stat-lbl">Pending Orders</div>
                    </div>
                    <div class="order-stat-tile">
                        <div class="order-stat-icon" style="background:#fee2e2;">
                            <i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i>
                        </div>
                        <div class="order-stat-val" style="color:#dc2626;"><?= $cancelledOrders ?></div>
                        <div class="order-stat-lbl">Cancelled</div>
                    </div>
                </div>

                <!-- Status filter tabs -->
                <div class="status-tabs">
                    <?php
                    $tabColors = [
                        'pending'    => ['#fff7ed','#f97316'],
                        'confirmed'  => ['#dbeafe','#3b82f6'],
                        'processing' => ['#ede9fe','#8b5cf6'],
                        'shipped'    => ['#cffafe','#0891b2'],
                        'completed'  => ['#dcfce7','#16a34a'],
                        'cancelled'  => ['#fee2e2','#dc2626'],
                    ];
                    $isAllActive = $filterStatus === '';
                    ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['status'=>'','page'=>1])) ?>"
                       class="status-tab status-tab-all <?= $isAllActive ? 'active' : '' ?>">
                        <i class="fa-solid fa-list"></i> All
                        <span style="background:rgba(255,255,255,.4);border-radius:99px;padding:0 6px;font-size:.68rem;"><?= $totalOrders ?></span>
                    </a>
                    <?php foreach ($allStatuses as $st):
                        $cnt      = $statusCounts[$st] ?? 0;
                        $isActive = $filterStatus === $st;
                        [$bg, $col] = $tabColors[$st];
                    ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['status'=>$st,'page'=>1])) ?>"
                       class="status-tab <?= $isActive ? 'active' : 'status-tab-default' ?>"
                       style="<?= $isActive ? "background:$col;color:white;border-color:$col;" : '' ?>">
                        <i class="fa-solid fa-<?= $statusIcons[$st] ?>"></i>
                        <?= ucfirst($st) ?>
                        <span style="background:<?= $isActive ? 'rgba(255,255,255,.3)' : $bg ?>;color:<?= $isActive ? 'white' : $col ?>;border-radius:99px;padding:0 6px;font-size:.68rem;"><?= $cnt ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- Filter bar -->
                <form method="GET" class="filter-bar">
                    <?php if ($filterStatus): ?>
                        <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
                    <?php endif; ?>
                    <i class="fa-solid fa-magnifying-glass" style="color:var(--text-muted);font-size:.85rem;"></i>
                    <input type="text" name="search" class="filter-input" style="flex:1;min-width:180px;"
                           placeholder="Search by buyer, farmer or order #…"
                           value="<?= htmlspecialchars($filterSearch) ?>">
                    <input type="date" name="date" class="filter-input"
                           value="<?= htmlspecialchars($filterDate) ?>"
                           title="Filter by date">
                    <button type="submit" class="action-btn action-btn-primary" style="padding:.4rem .9rem;">
                        <i class="fa-solid fa-filter"></i> Filter
                    </button>
                    <?php if ($filterSearch || $filterDate || $filterStatus): ?>
                    <a href="orders.php" class="action-btn action-btn-gray" style="padding:.4rem .9rem;">
                        <i class="fa-solid fa-xmark"></i> Clear
                    </a>
                    <?php endif; ?>
                    <span style="font-size:.75rem;color:var(--text-muted);margin-left:auto;white-space:nowrap;">
                        <?= number_format($totalFiltered) ?> order<?= $totalFiltered != 1 ? 's' : '' ?>
                    </span>
                </form>

                <!-- Orders table -->
                <?php if (!empty($orders)): ?>
               <div class="gl-table orders-table" style="overflow-x:auto;">
    <table style="table-layout:fixed;min-width:900px;">
                        <thead>
                            <tr>
                              <th style="width:70px;">Order #</th>
<th style="width:160px;">Buyer</th>
<th style="width:160px;">Farmer</th>
<th style="width:55px;">Items</th>
<th style="width:90px;">Amount</th>
<th style="width:100px;">Status</th>
<th style="width:120px;">Rider</th>
<th style="width:110px;">Date</th>
<th style="width:70px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($orders as $o): ?>
                        <tr>
                            <td>
                                <strong style="color:var(--primary);">#<?= $o['id'] ?></strong>
                            </td>

                            <!-- Buyer -->
                            <td>
                                <div class="buyer-cell">
                                   <?php if (!empty($o['buyer_photo'])): ?>
    <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($o['buyer_photo']) ?>"
         style="width:28px;height:28px;border-radius:50%;object-fit:cover;border:1.5px solid var(--border);" alt="">
<?php else: ?>
    <div class="buyer-avatar"><?= strtoupper(substr($o['buyer_name'], 0, 1)) ?></div>
<?php endif; ?>
                                    <div>
                                        <div style="font-weight:700;font-size:.82rem;"><?= sanitize($o['buyer_name']) ?></div>
                                        <div style="font-size:.68rem;color:var(--text-muted);"><?= sanitize($o['buyer_email']) ?></div>
                                    </div>
                                </div>
                            </td>

                            <!-- Farmer -->
                            <td>
                                <div class="buyer-cell">
                                  <?php if (!empty($o['farmer_photo'])): ?>
    <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($o['farmer_photo']) ?>"
         style="width:28px;height:28px;border-radius:50%;object-fit:cover;border:1.5px solid var(--border);" alt="">
<?php else: ?>
    <div class="buyer-avatar" style="background:#dbeafe;color:#1d4ed8;">
        <?= strtoupper(substr($o['farmer_name'], 0, 1)) ?>
    </div>
<?php endif; ?>
                                    <div>
                                        <div style="font-weight:700;font-size:.82rem;"><?= sanitize($o['farmer_name']) ?></div>
                                        <div style="font-size:.68rem;color:var(--text-muted);"><?= sanitize($o['farmer_email']) ?></div>
                                    </div>
                                </div>
                            </td>

                            <!-- Items -->
                            <td style="text-align:center;">
                                <span style="font-size:.82rem;font-weight:700;color:var(--text-muted);"><?= $o['item_count'] ?></span>
                            </td>

                            <!-- Amount -->
                            <td>
                                <strong style="color:var(--primary);">₱<?= number_format($o['total_amount'], 2) ?></strong>
                            </td>

                            <!-- Status badge (read-only) -->
                            <td>
                                <?php
                                $cur   = $o['status'];
                                $curBg = $statusBg[$cur] ?? '#f1f5f9';
                                $curFg = $statusFg[$cur] ?? '#64748b';
                                ?>
                                <span class="status-badge"
                                      style="background:<?= $curBg ?>;color:<?= $curFg ?>;">
                                    <i class="fa-solid fa-<?= $statusIcons[$cur] ?? 'circle' ?>" style="font-size:.65rem;"></i>
                                    <?= ucfirst($cur) ?>
                                </span>
                            </td>

                            <!-- Rider (read-only) -->
                            <td>
                                <?php if ($o['rider_name']): ?>
                                <div style="display:flex;align-items:center;gap:5px;">
                                    <span style="font-size:.75rem;background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;border-radius:99px;padding:2px 9px;font-weight:700;">
                                        🛵 <?= sanitize($o['rider_name']) ?>
                                    </span>
                                </div>
                                <?php else: ?>
                                <span style="font-size:.72rem;color:var(--text-muted);font-style:italic;">Unassigned</span>
                                <?php endif; ?>
                            </td>

                            <!-- Date -->
   <td style="white-space:nowrap;min-width:110px;width:110px;">
    <div style="font-size:.8rem;font-weight:700;color:var(--text);">
        <?= date('M j, Y', strtotime($o['created_at'])) ?>
    </div>
    <div style="font-size:.72rem;color:var(--text-muted);margin-top:1px;">
        <?= date('g:i A', strtotime($o['created_at'])) ?>
    </div>
</td>

                            <!-- View only -->
                            <td>
                                <a href="order_detail.php?id=<?= $o['id'] ?>" class="action-btn action-btn-primary" title="View Details">
                                    <i class="fa-solid fa-eye"></i> View
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:1rem;flex-wrap:wrap;gap:.5rem;">
                    <span style="font-size:.78rem;color:var(--text-muted);">
                        Showing <?= ($offset + 1) ?>–<?= min($offset + $perPage, $totalFiltered) ?>
                        of <?= number_format($totalFiltered) ?> orders
                    </span>
                    <div class="pagination">
                        <a href="<?= pageUrl($page - 1) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                            <i class="fa-solid fa-chevron-left" style="font-size:.65rem;"></i>
                        </a>
                        <?php
                        $start = max(1, $page - 2);
                        $end   = min($totalPages, $page + 2);
                        if ($start > 1): ?>
                            <a href="<?= pageUrl(1) ?>" class="page-btn">1</a>
                            <?php if ($start > 2): ?><span style="padding:0 2px;color:var(--text-muted);font-size:.78rem;">…</span><?php endif; ?>
                        <?php endif; ?>
                        <?php for ($i = $start; $i <= $end; $i++): ?>
                            <a href="<?= pageUrl($i) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        <?php if ($end < $totalPages): ?>
                            <?php if ($end < $totalPages - 1): ?><span style="padding:0 2px;color:var(--text-muted);font-size:.78rem;">…</span><?php endif; ?>
                            <a href="<?= pageUrl($totalPages) ?>" class="page-btn"><?= $totalPages ?></a>
                        <?php endif; ?>
                        <a href="<?= pageUrl($page + 1) ?>" class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <i class="fa-solid fa-chevron-right" style="font-size:.65rem;"></i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="gl-card">
                    <div class="gl-card-body" style="text-align:center;padding:3rem 1.5rem;color:var(--text-muted);">
                        <i class="fa-solid fa-box-open" style="font-size:2rem;opacity:.25;display:block;margin-bottom:.75rem;"></i>
                        <strong style="display:block;margin-bottom:.3rem;font-size:.9rem;">No orders found</strong>
                        <span style="font-size:.82rem;">
                            <?= ($filterSearch || $filterStatus || $filterDate)
                                ? 'Try adjusting your filters.'
                                : 'No orders have been placed yet.' ?>
                        </span>
                        <?php if ($filterSearch || $filterStatus || $filterDate): ?>
                        <div style="margin-top:1rem;">
                            <a href="orders.php" class="action-btn action-btn-primary" style="padding:.45rem 1.1rem;">
                                <i class="fa-solid fa-xmark"></i> Clear Filters
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- /col-lg-9 -->
        </div><!-- /row -->
    </div><!-- /container -->
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>