<?php
$page_title = 'My Products';
require_once __DIR__ . '/../includes/header.php';
requireRole('farmer');

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];
$farmer = $pdo->prepare("SELECT f.*, u.profile_image, f.is_premium, f.premium_until FROM farmers f JOIN users u ON f.user_id=u.id WHERE f.user_id=?");
$farmer->execute([$userId]);
$farmer = $farmer->fetch();

// Handle toggle availability
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $pid = intval($_GET['toggle']);
    $pdo->prepare("UPDATE products SET is_available = NOT is_available WHERE id=? AND farmer_id=?")->execute([$pid, $userId]);
    setFlash('success', 'Product visibility updated.');
    header('Location: products.php'); exit();
}

$products = $pdo->prepare("SELECT * FROM products WHERE farmer_id=? ORDER BY created_at DESC");
$products->execute([$userId]); $products = $products->fetchAll();

$emojis = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕','Others'=>'📦'];
?>

<?php
$isPrem = !empty($farmer['is_premium']) && !empty($farmer['premium_until']) && strtotime($farmer['premium_until']) > time();
?>
<div style="background:<?= $isPrem ? 'linear-gradient(180deg,#fffbeb 0%,#fef9ee 40%,var(--bg) 100%)' : 'var(--bg)' ?>;min-height:100vh;padding-bottom:3rem;">
    <div class="page-header" style="<?= $isPrem ? 'background:linear-gradient(135deg,#78350f,#92400e,#b45309,#d97706);box-shadow:0 4px 20px rgba(217,119,6,.25);' : '' ?>">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h1 style="<?= $isPrem ? 'color:white;' : '' ?>">
                        <?php if ($isPrem): ?>
                            ⭐ My Products
                        <?php else: ?>
                            <i class="fa-solid fa-seedling text-green me-2"></i>My Products
                        <?php endif; ?>
                    </h1>
                    <div class="page-breadcrumb" style="<?= $isPrem ? 'color:rgba(255,255,255,.8);' : '' ?>">
                        <a href="dashboard.php" style="<?= $isPrem ? 'color:rgba(255,255,255,.7);' : '' ?>">Dashboard</a> › Products
                    </div>
                </div>
                <a href="add_product.php" class="btn-green" style="<?= $isPrem ? 'background:white;color:#b45309;border-color:white;' : '' ?>">
                    <i class="fa-solid fa-plus"></i> Add Product
                </a>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="row g-4">
            <div class="col-lg-3">
                <div class="gl-sidebar">
                   <div class="gl-sidebar-header">
    <?php if (!empty($farmer['profile_image'])): ?>
        <img src="<?= BASE_URL ?>/assets/images/profiles/<?= sanitize($farmer['profile_image']) ?>"
             style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:3px solid var(--primary);margin-bottom:0.5rem;">
    <?php else: ?>
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name'],0,1)) ?></div>
    <?php endif; ?>
    <div class="user-name"><?= sanitize($_SESSION['user_name']) ?></div>
<div class="user-role">🌾 Farmer</div>
<?php if (!empty($farmer['is_premium']) && !empty($farmer['premium_until']) && strtotime($farmer['premium_until']) > time()): ?>
<div style="margin-top:8px;display:flex;flex-direction:column;align-items:center;gap:3px;">
    <span style="background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.62rem;font-weight:800;padding:4px 14px;border-radius:99px;letter-spacing:.04em;box-shadow:0 2px 8px rgba(217,119,6,.35);">⭐ PREMIUM</span>
<span style="font-size:.65rem;color:#1a1a1a;font-weight:700;">Until <?= date('M j, Y', strtotime($farmer['premium_until'])) ?></span></div>
<?php else: ?>
<div style="margin-top:8px;">
    <a href="premium.php" style="display:inline-block;background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.62rem;font-weight:800;padding:4px 14px;border-radius:99px;text-decoration:none;letter-spacing:.04em;">
        ⭐ Go Premium
    </a>
</div>
<?php endif; ?>
</div>
                    <nav class="gl-sidebar-nav">
                        <a href="dashboard.php"><i class="fa-solid fa-grid-2"></i> Dashboard</a>
                        <a href="products.php" class="active"><i class="fa-solid fa-seedling"></i> My Products</a>
                        <a href="add_product.php"><i class="fa-solid fa-plus-circle"></i> Add Product</a>
                        <div class="nav-divider"></div>
                        <a href="../orders/index.php"><i class="fa-solid fa-box"></i> My Orders</a>
                        <a href="../messages/index.php"><i class="fa-solid fa-comments"></i> Messages</a>
                        <a href="../market/prices.php"><i class="fa-solid fa-chart-line"></i> Market Prices</a>
                    </nav>
                </div>
            </div>

            <div class="col-lg-9">
                <?php if (empty($products)): ?>
                <div class="gl-card">
                    <div class="gl-card-body empty-state">
                        <div class="empty-icon">🌱</div>
                        <p>You haven't listed any products yet.</p>
                        <a href="add_product.php" class="btn-green mt-2"><i class="fa-solid fa-plus"></i> Add Your First Product</a>
                    </div>
                </div>
                <?php else: ?>
                <div class="gl-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th><th>Category</th><th>Price/kg</th><th>Stock</th><th>Harvest Date</th><th>Status</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $p): ?>
                            <tr>
                                <td>
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <div style="width:44px;height:44px;background:var(--pale-green);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;">
                                            <?= $p['image'] ? '<img src="../assets/images/products/'.sanitize($p['image']).'" style="width:44px;height:44px;object-fit:cover;border-radius:10px;">' : ($emojis[$p['category']] ?? '🌾') ?>
                                        </div>
                                        <div>
                                            <div style="font-weight:700;color:var(--text);font-size:0.9rem;"><?= sanitize($p['name']) ?></div>
                                            <?php if ($p['is_organic']): ?><span style="font-size:0.65rem;background:var(--pale-green);color:var(--primary);padding:1px 6px;border-radius:10px;font-weight:700;">🌿 Organic</span><?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge-category"><?= sanitize($p['category']) ?></span></td>
                                <td><strong style="color:var(--primary);">₱<?= number_format($p['price_per_kg'],2) ?></strong></td>
                                <td><?= number_format($p['stock_kg'],1) ?> kg</td>
                                <td style="color:var(--text-muted);font-size:0.82rem;"><?= $p['harvest_date'] ? date('M j, Y',strtotime($p['harvest_date'])) : '—' ?></td>
                                <td>
                                    <a href="products.php?toggle=<?= $p['id'] ?>" style="display:inline-flex;align-items:center;gap:5px;font-size:0.78rem;font-weight:700;text-decoration:none;padding:4px 10px;border-radius:20px;<?= $p['is_available'] ? 'background:#E8F5E9;color:var(--primary);' : 'background:#FFEBEE;color:#C53030;' ?>">
                                        <?= $p['is_available'] ? '● Active' : '● Hidden' ?>
                                    </a>
                                </td>
                                <td>
                                    <div style="display:flex;gap:6px;">
                                        <a href="edit_product.php?id=<?= $p['id'] ?>" class="btn-outline-green" style="padding:0.3rem 0.7rem;font-size:0.78rem;"><i class="fa-solid fa-pen"></i></a>
                                        <a href="delete_product.php?id=<?= $p['id'] ?>" onclick="return confirm('Delete this product permanently?')" class="btn-earth" style="padding:0.3rem 0.7rem;font-size:0.78rem;text-decoration:none;border-radius:var(--radius-sm);display:inline-flex;"><i class="fa-solid fa-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
