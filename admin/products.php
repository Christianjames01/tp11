<?php
$page_title = 'All Products';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin');

$pdo = getDBConnection();

// ── Admin profile ─────────────────────────────────────────────────────────────
$adminId = $_SESSION['user_id'];
$admin   = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$admin->execute([$adminId]);
$admin   = $admin->fetch();

// ── Image helpers ─────────────────────────────────────────────────────────────
function userImgUrl(array $user): string|null {
    $photo = $user['profile_image'] ?? $user['profile_photo'] ?? null;
    if (empty($photo)) return null;
    $img = ltrim($photo, '/');
    if (!str_contains($img, '/')) {
        $img = 'assets/images/profiles/' . $img;
    }
    return BASE_URL . '/' . htmlspecialchars($img) . '?v=' . time();
}

function productImgUrl(array $product): string|null {
    if (empty($product['image'])) return null;
    $img = ltrim($product['image'], '/');
    // If no directory separator, prepend the products folder
    if (!str_contains($img, '/')) {
        $img = 'assets/images/products/' . $img;
    }
    return BASE_URL . '/' . htmlspecialchars($img) . '?v=' . time();
}

// ── Handle Actions ────────────────────────────────────────────────────────────
$msg     = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action']     ?? '';
    $productId = intval($_POST['product_id'] ?? 0);

    if ($action === 'delete' && $productId) {
        $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$productId]);
        $msg     = 'Product deleted successfully.';
        $msgType = 'success';
    }

    if ($action === 'toggle_availability' && $productId) {
        $cur = $pdo->prepare("SELECT is_available FROM products WHERE id = ?");
        $cur->execute([$productId]);
        $newVal = $cur->fetchColumn() ? 0 : 1;
        $pdo->prepare("UPDATE products SET is_available = ? WHERE id = ?")->execute([$newVal, $productId]);
        $msg     = 'Product visibility updated.';
        $msgType = 'success';
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$catFilter    = $_GET['category']     ?? 'all';
$availFilter  = $_GET['availability'] ?? 'all';
$searchFilter = trim($_GET['search']  ?? '');
$sortBy       = $_GET['sort']         ?? 'newest';
$page         = max(1, intval($_GET['page'] ?? 1));
$perPage      = 10;
$offset       = ($page - 1) * $perPage;

$where  = "WHERE 1=1";
$params = [];

if ($catFilter !== 'all') {
    $where  .= " AND p.category = ?";
    $params[] = $catFilter;
}
if ($availFilter === 'active') {
    $where .= " AND p.is_available = 1";
} elseif ($availFilter === 'hidden') {
    $where .= " AND p.is_available = 0";
}
if ($searchFilter !== '') {
    $where  .= " AND (p.name LIKE ? OR u.name LIKE ? OR p.description LIKE ?)";
    $params[] = "%$searchFilter%";
    $params[] = "%$searchFilter%";
    $params[] = "%$searchFilter%";
}

$orderClause = match($sortBy) {
    'price_asc'  => 'p.price_per_kg ASC',
    'price_desc' => 'p.price_per_kg DESC',
    'stock_asc'  => 'p.stock_kg ASC',
    'stock_desc' => 'p.stock_kg DESC',
    'oldest'     => 'p.created_at ASC',
    default      => 'p.created_at DESC',
};

// Count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p JOIN users u ON p.farmer_id = u.id $where");
$countStmt->execute($params);
$totalCount = $countStmt->fetchColumn();
$totalPages = ceil($totalCount / $perPage);

// Fetch products with sales data
$stmt = $pdo->prepare("
    SELECT p.*, u.name as farmer_name,
           COALESCE((SELECT SUM(oi.quantity_kg) FROM order_items oi JOIN orders o ON oi.order_id=o.id WHERE oi.product_id=p.id AND o.status='completed'),0) as sold_kg,
           COALESCE((SELECT COUNT(DISTINCT o.id)  FROM order_items oi JOIN orders o ON oi.order_id=o.id WHERE oi.product_id=p.id),0) as order_count
    FROM products p
    JOIN users u ON p.farmer_id = u.id
    $where
    ORDER BY $orderClause
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$products = $stmt->fetchAll();

// ── Summary Stats ─────────────────────────────────────────────────────────────
$totalProducts     = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$availableProducts = $pdo->query("SELECT COUNT(*) FROM products WHERE is_available=1")->fetchColumn();
$hiddenProducts    = $pdo->query("SELECT COUNT(*) FROM products WHERE is_available=0")->fetchColumn();
$totalStockKg      = $pdo->query("SELECT COALESCE(SUM(stock_kg),0) FROM products")->fetchColumn();

// Category breakdown for sidebar
$catCounts = $pdo->query("SELECT category, COUNT(*) as cnt FROM products GROUP BY category ORDER BY cnt DESC")->fetchAll();

$catEmoji     = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕','Others'=>'📦'];
$categories   = ['Vegetables','Fruits','Grains','Coffee','Others'];
$statusColors = ['pending'=>'status-pending','confirmed'=>'status-confirmed','processing'=>'status-processing','shipped'=>'status-shipped','completed'=>'status-completed','cancelled'=>'status-cancelled'];
?>

<style>
.admin-section-title{font-weight:800;font-size:.95rem;margin:0;}
.filter-bar{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;}
.filter-bar input[type="text"]{border:1px solid var(--border);border-radius:var(--radius);padding:.45rem .9rem;font-size:.82rem;outline:none;font-family:inherit;color:var(--text);min-width:200px;transition:border-color .2s;}
.filter-bar input[type="text"]:focus{border-color:var(--primary);}
.filter-bar select{border:1px solid var(--border);border-radius:var(--radius);padding:.42rem .75rem;font-size:.82rem;outline:none;font-family:inherit;color:var(--text);background:white;cursor:pointer;}
.filter-bar select:focus{border-color:var(--primary);}
.filter-pill{display:inline-flex;align-items:center;gap:5px;padding:.35rem .85rem;border-radius:99px;font-size:.75rem;font-weight:800;border:1.5px solid var(--border);background:white;color:var(--text-muted);cursor:pointer;transition:all .15s;text-decoration:none;}
.filter-pill:hover,.filter-pill.active{background:var(--primary);color:white;border-color:var(--primary);}
.action-btn{display:inline-flex;align-items:center;gap:5px;padding:.28rem .7rem;border-radius:var(--radius);font-size:.73rem;font-weight:700;border:none;cursor:pointer;transition:all .15s;font-family:inherit;text-decoration:none;}
.action-btn-danger{background:#fee2e2;color:#dc2626;}.action-btn-danger:hover{background:#dc2626;color:white;}
.action-btn-warn{background:#fff7ed;color:#ea580c;}.action-btn-warn:hover{background:#ea580c;color:white;}
.action-btn-primary{background:var(--pale-green);color:var(--primary);}.action-btn-primary:hover{background:var(--primary);color:white;}
.action-btn-gray{background:#f1f5f9;color:#64748b;}.action-btn-gray:hover{background:#64748b;color:white;}
.stk-bar{background:#e5e7eb;border-radius:99px;height:5px;overflow:hidden;margin-top:4px;}
.stk-fill{height:100%;border-radius:99px;}
.stk-good{background:var(--primary);}.stk-low{background:#f97316;}.stk-crit{background:#ef4444;}
.pagination{display:flex;gap:.35rem;justify-content:center;margin-top:1rem;}
.pagination a,.pagination span{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:var(--radius);font-size:.78rem;font-weight:700;text-decoration:none;border:1.5px solid var(--border);color:var(--text-muted);transition:all .15s;}
.pagination a:hover{border-color:var(--primary);color:var(--primary);}
.pagination .pg-active{background:var(--primary);color:white;border-color:var(--primary);}
.alert-flash{padding:.75rem 1.1rem;border-radius:var(--radius);margin-bottom:1rem;font-size:.83rem;font-weight:700;display:flex;align-items:center;gap:.5rem;}
.alert-success{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0;}
.alert-error{background:#fee2e2;color:#dc2626;border:1px solid #fecaca;}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal-box{background:white;border-radius:var(--radius-lg);padding:1.75rem;max-width:420px;width:90%;box-shadow:0 25px 60px rgba(0,0,0,.18);transform:scale(.95);transition:transform .2s;}
.modal-overlay.open .modal-box{transform:scale(1);}
.product-img{width:40px;height:40px;border-radius:var(--radius);object-fit:cover;background:var(--pale-green);display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;}
.cat-sidebar-item{display:flex;justify-content:space-between;align-items:center;margin-bottom:.45rem;}
.cat-sidebar-item span:first-child{font-size:.78rem;color:var(--text-muted);}
.cat-sidebar-item strong{font-size:.78rem;color:var(--primary);}
</style>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">

    <div class="page-header">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h1><i class="fa-solid fa-seedling text-green me-2"></i>All Products</h1>
                    <div class="page-breadcrumb">GreenLink Innovators — <strong>Product Management</strong> 🌱</div>
                </div>
                <div class="d-flex gap-2">
                    <a href="dashboard.php" class="btn-outline-green" style="padding:.45rem 1rem;font-size:.82rem;"><i class="fa-solid fa-grid-2"></i> Dashboard</a>
                    <a href="users.php" class="btn-green"><i class="fa-solid fa-users"></i> Users</a>
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
                        <?php $adminImgUrl = userImgUrl($admin); ?>
                        <?php if ($adminImgUrl): ?>
                            <img src="<?= $adminImgUrl ?>" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid white;" alt="Profile"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <div class="user-avatar" style="display:none;">A</div>
                        <?php else: ?>
                            <div class="user-avatar">A</div>
                        <?php endif; ?>
                        <div class="user-name"><?= htmlspecialchars($admin['name']) ?></div>
                        <div class="user-role">⚙️ Administrator</div>
                    </div>
                    <nav class="gl-sidebar-nav">
                        <a href="dashboard.php"><i class="fa-solid fa-grid-2"></i> Dashboard</a>
                        <a href="users.php"><i class="fa-solid fa-users"></i> Manage Users</a>
                        <a href="products.php" class="active"><i class="fa-solid fa-seedling"></i> All Products</a>
                        <a href="orders.php"><i class="fa-solid fa-box"></i> All Orders</a>
                        <a href="<?= BASE_URL ?>/admin/marketprices.php"><i class="fa-solid fa-chart-line"></i> Market Prices</a>
                        <div class="nav-divider"></div>
                        <a href="../auth/logout.php" style="color:#E53E3E;"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                    </nav>
                    <div style="padding:1rem;border-top:1px solid var(--border);margin-top:.5rem;">
                        <div style="font-size:.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem;">By Category</div>
                        <?php foreach ($catCounts as $cc): ?>
                        <div class="cat-sidebar-item">
                            <span><?= ($catEmoji[$cc['category']] ?? '📦') . ' ' . htmlspecialchars($cc['category']) ?></span>
                            <strong><?= $cc['cnt'] ?></strong>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($catCounts)): ?>
                            <p style="font-size:.78rem;color:var(--text-muted);">No products yet.</p>
                        <?php endif; ?>
                        <div style="border-top:1px solid var(--border);margin-top:.5rem;padding-top:.75rem;">
                            <div style="display:flex;justify-content:space-between;margin-bottom:.4rem;">
                                <span style="font-size:.78rem;color:var(--text-muted);">Total Stock</span>
                                <strong style="font-size:.78rem;color:var(--primary);"><?= number_format($totalStockKg, 1) ?> kg</strong>
                            </div>
                            <div style="display:flex;justify-content:space-between;">
                                <span style="font-size:.78rem;color:var(--text-muted);">Hidden</span>
                                <strong style="font-size:.78rem;color:#f97316;"><?= $hiddenProducts ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main -->
            <div class="col-lg-9">

                <?php if ($msg): ?>
                <div class="alert-flash alert-<?= $msgType ?>">
                    <i class="fa-solid fa-<?= $msgType==='success'?'circle-check':'circle-exclamation' ?>"></i>
                    <?= htmlspecialchars($msg) ?>
                </div>
                <?php endif; ?>

                <!-- Stat Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-1">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="fa-solid fa-seedling"></i></div>
                            <div><div class="stat-value"><?= $totalProducts ?></div><div class="stat-label">Total Products</div></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-2">
                        <div class="stat-card">
                            <div class="stat-icon earth"><i class="fa-solid fa-circle-check"></i></div>
                            <div><div class="stat-value"><?= $availableProducts ?></div><div class="stat-label">Active</div></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-3">
                        <div class="stat-card">
                            <div class="stat-icon orange"><i class="fa-solid fa-eye-slash"></i></div>
                            <div><div class="stat-value"><?= $hiddenProducts ?></div><div class="stat-label">Hidden</div></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3 fade-up fade-up-4">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="fa-solid fa-weight-scale"></i></div>
                            <div><div class="stat-value"><?= number_format($totalStockKg, 0) ?></div><div class="stat-label">Total kg</div></div>
                        </div>
                    </div>
                </div>

                <!-- Filter Bar -->
                <div class="filter-bar">
                    <form method="GET" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;flex:1;">
                        <input type="text" name="search" placeholder="🔍  Search products or farmers…" value="<?= htmlspecialchars($searchFilter) ?>">
                        <input type="hidden" name="category" value="<?= htmlspecialchars($catFilter) ?>">
                        <input type="hidden" name="availability" value="<?= htmlspecialchars($availFilter) ?>">
                        <select name="sort" onchange="this.form.submit()">
                            <option value="newest"     <?= $sortBy==='newest'    ?'selected':'' ?>>Newest First</option>
                            <option value="oldest"     <?= $sortBy==='oldest'    ?'selected':'' ?>>Oldest First</option>
                            <option value="price_asc"  <?= $sortBy==='price_asc' ?'selected':'' ?>>Price ↑</option>
                            <option value="price_desc" <?= $sortBy==='price_desc'?'selected':'' ?>>Price ↓</option>
                            <option value="stock_asc"  <?= $sortBy==='stock_asc' ?'selected':'' ?>>Stock ↑</option>
                            <option value="stock_desc" <?= $sortBy==='stock_desc'?'selected':'' ?>>Stock ↓</option>
                        </select>
                        <button type="submit" class="btn-green" style="padding:.42rem 1rem;font-size:.82rem;">Search</button>
                        <?php if ($searchFilter || $catFilter!=='all' || $availFilter!=='all'): ?>
                            <a href="products.php" class="btn-outline-green" style="padding:.42rem .9rem;font-size:.82rem;">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Category Pills -->
                <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem;">
                    <a href="?category=all&availability=<?= $availFilter ?>&search=<?= urlencode($searchFilter) ?>&sort=<?= $sortBy ?>"
                       class="filter-pill <?= $catFilter==='all'?'active':'' ?>">All Categories</a>
                    <?php foreach ($categories as $cat): ?>
                    <a href="?category=<?= urlencode($cat) ?>&availability=<?= $availFilter ?>&search=<?= urlencode($searchFilter) ?>&sort=<?= $sortBy ?>"
                       class="filter-pill <?= $catFilter===$cat?'active':'' ?>"><?= $catEmoji[$cat]??'📦' ?> <?= $cat ?></a>
                    <?php endforeach; ?>
                    <span style="width:1px;background:var(--border);height:26px;margin:0 4px;"></span>
                    <a href="?availability=all&category=<?= $catFilter ?>&search=<?= urlencode($searchFilter) ?>&sort=<?= $sortBy ?>"
                       class="filter-pill <?= $availFilter==='all'?'active':'' ?>">All</a>
                    <a href="?availability=active&category=<?= $catFilter ?>&search=<?= urlencode($searchFilter) ?>&sort=<?= $sortBy ?>"
                       class="filter-pill <?= $availFilter==='active'?'active':'' ?>">Active</a>
                    <a href="?availability=hidden&category=<?= $catFilter ?>&search=<?= urlencode($searchFilter) ?>&sort=<?= $sortBy ?>"
                       class="filter-pill <?= $availFilter==='hidden'?'active':'' ?>">Hidden</a>
                </div>

                <!-- Products Table -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 style="font-weight:800;margin:0;"><i class="fa-solid fa-list text-green me-2"></i>Products
                        <span style="font-size:.75rem;color:var(--text-muted);font-weight:600;margin-left:.5rem;">(<?= $totalCount ?> total)</span>
                    </h5>
                </div>

                <div class="gl-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Farmer</th>
                                <th>Category</th>
                                <th>Price/kg</th>
                                <th>Stock</th>
                                <th>Level</th>
                                <th>Sold</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($products as $p):
                            $pct = min(100, ($p['stock_kg'] / 500) * 100);
                            $cls = $pct > 50 ? 'stk-good' : ($pct > 20 ? 'stk-low' : 'stk-crit');
                            $lbl = $pct > 50 ? 'Good' : ($pct > 20 ? 'Low' : 'Critical');
                            $lcol= $pct > 50 ? '#16a34a' : ($pct > 20 ? '#f97316' : '#ef4444');
                        ?>
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:9px;">
                                    <?php $pImgUrl = productImgUrl($p); ?>
                                    <?php if ($pImgUrl): ?>
                                        <img src="<?= $pImgUrl ?>" class="product-img" alt=""
                                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                        <div class="product-img" style="display:none;"><?= $catEmoji[$p['category']] ?? '📦' ?></div>
                                    <?php else: ?>
                                        <div class="product-img"><?= $catEmoji[$p['category']] ?? '📦' ?></div>
                                    <?php endif; ?>
                                    <div>
                                        <div style="font-weight:700;font-size:.83rem;"><?= sanitize($p['name']) ?></div>
                                        <div style="font-size:.68rem;color:var(--text-muted);">#<?= $p['id'] ?> · <?= $p['order_count'] ?> orders</div>
                                    </div>
                                </div>
                            </td>
                            <td style="font-size:.8rem;color:var(--text-muted);"><?= sanitize($p['farmer_name']) ?></td>
                            <td style="font-size:.78rem;"><?= $catEmoji[$p['category']]??'📦' ?> <?= $p['category'] ?></td>
                            <td><strong style="color:var(--primary);">₱<?= number_format($p['price_per_kg'],2) ?></strong></td>
                            <td><strong style="font-size:.83rem;"><?= number_format($p['stock_kg'],1) ?> kg</strong></td>
                            <td style="min-width:100px;">
                                <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                                    <span style="font-size:.68rem;color:<?= $lcol ?>;font-weight:800;"><?= $lbl ?></span>
                                    <span style="font-size:.68rem;color:var(--text-muted);"><?= round($pct) ?>%</span>
                                </div>
                                <div class="stk-bar"><div class="stk-fill <?= $cls ?>" style="width:<?= $pct ?>%;"></div></div>
                            </td>
                            <td style="font-size:.8rem;color:var(--text-muted);"><?= number_format($p['sold_kg'],1) ?> kg</td>
                            <td>
                                <span class="status-badge <?= $p['is_available']?'status-completed':'status-cancelled' ?>">
                                    <?= $p['is_available']?'Active':'Hidden' ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:flex;gap:.35rem;flex-wrap:wrap;">
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_availability">
                                        <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="action-btn action-btn-gray" title="<?= $p['is_available']?'Hide':'Show' ?>">
                                            <i class="fa-solid fa-<?= $p['is_available']?'eye-slash':'eye' ?>"></i>
                                        </button>
                                    </form>
                                    <a href="../farmer/edit_product.php?id=<?= $p['id'] ?>" class="action-btn action-btn-primary">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <button onclick="confirmDelete(<?= $p['id'] ?>, '<?= addslashes(sanitize($p['name'])) ?>')" class="action-btn action-btn-danger">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($products)): ?>
                        <tr><td colspan="9" style="text-align:center;padding:2.5rem;color:var(--text-muted);">
                            <i class="fa-solid fa-seedling" style="font-size:1.5rem;margin-bottom:.5rem;display:block;opacity:.3;"></i>
                            No products found<?= $searchFilter ? ' for "'.htmlspecialchars($searchFilter).'"' : '' ?>.
                        </td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page-1 ?>&category=<?= $catFilter ?>&availability=<?= $availFilter ?>&search=<?= urlencode($searchFilter) ?>&sort=<?= $sortBy ?>">
                            <i class="fa-solid fa-chevron-left" style="font-size:.65rem;"></i>
                        </a>
                    <?php endif; ?>
                    <?php for ($p2 = max(1,$page-2); $p2 <= min($totalPages,$page+2); $p2++): ?>
                        <?php if ($p2 === $page): ?>
                            <span class="pg-active"><?= $p2 ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $p2 ?>&category=<?= $catFilter ?>&availability=<?= $availFilter ?>&search=<?= urlencode($searchFilter) ?>&sort=<?= $sortBy ?>"><?= $p2 ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page+1 ?>&category=<?= $catFilter ?>&availability=<?= $availFilter ?>&search=<?= urlencode($searchFilter) ?>&sort=<?= $sortBy ?>">
                            <i class="fa-solid fa-chevron-right" style="font-size:.65rem;"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div><!-- /col-lg-9 -->
        </div><!-- /row -->
    </div><!-- /container -->
</div>

<!-- Delete Confirm Modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <div style="text-align:center;margin-bottom:1.25rem;">
            <div style="width:52px;height:52px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem;">
                <i class="fa-solid fa-trash" style="color:#dc2626;font-size:1.2rem;"></i>
            </div>
            <h5 style="font-weight:800;margin-bottom:.4rem;">Delete Product?</h5>
            <p style="font-size:.83rem;color:var(--text-muted);margin:0;">You're about to permanently delete <strong id="deleteProductName"></strong>. This cannot be undone.</p>
        </div>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="product_id" id="deleteProductId">
            <div style="display:flex;gap:.75rem;">
                <button type="button" onclick="closeModal()" style="flex:1;padding:.6rem;border:1.5px solid var(--border);border-radius:var(--radius);background:white;font-weight:700;font-size:.83rem;cursor:pointer;font-family:inherit;">Cancel</button>
                <button type="submit" style="flex:1;padding:.6rem;border:none;border-radius:var(--radius);background:#dc2626;color:white;font-weight:800;font-size:.83rem;cursor:pointer;font-family:inherit;">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function confirmDelete(id, name) {
    document.getElementById('deleteProductId').value = id;
    document.getElementById('deleteProductName').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}
function closeModal() {
    document.getElementById('deleteModal').classList.remove('open');
}
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>