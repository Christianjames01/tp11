<?php
$page_title = 'Browse Products';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();

$category = sanitize(isset($_GET['category']) ? $_GET['category'] : '');
$location = sanitize(isset($_GET['location']) ? $_GET['location'] : '');
$min_price = floatval(isset($_GET['min_price']) ? $_GET['min_price'] : 0);
$max_price = floatval(isset($_GET['max_price']) ? $_GET['max_price'] : 9999);
$organic = isset($_GET['organic']) ? 1 : null;
$search = sanitize(isset($_GET['q']) ? $_GET['q'] : '');
$sort = sanitize(isset($_GET['sort']) ? $_GET['sort'] : 'newest');
$farmerFilter = intval(isset($_GET['farmer']) ? $_GET['farmer'] : 0);
$where = ["p.is_available = 1 AND u.is_active = 1"];
$params = [];

// ── Early Harvest Access filter ───────────────────────────────────────────────
$_earlyPrem = false;
if (isLoggedIn() && in_array($_SESSION['role'], ['buyer', 'farmer'])) {
    $_earlyStmt = $pdo->prepare("SELECT is_premium, premium_until FROM users WHERE id=?");
    $_earlyStmt->execute([$_SESSION['user_id']]);
    $_earlyRow  = $_earlyStmt->fetch();
    $_earlyPrem = $_earlyRow && !empty($_earlyRow['is_premium']) && strtotime($_earlyRow['premium_until']) > time();
}
if (!$_earlyPrem) {
    $where[] = "(p.is_early_access = 0 OR p.is_early_access IS NULL)";
}

if ($farmerFilter) {
    $where[] = "p.farmer_id = ?";
    $params[] = $farmerFilter;
}

if ($search) { $where[] = "(p.name LIKE ? OR p.description LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($category) { $where[] = "p.category = ?"; $params[] = $category; }
if ($location) { $where[] = "u.location LIKE ?"; $params[] = "%$location%"; }
if ($min_price > 0) { $where[] = "p.price_per_kg >= ?"; $params[] = $min_price; }
if ($max_price < 9999) { $where[] = "p.price_per_kg <= ?"; $params[] = $max_price; }
if ($organic !== null) { $where[] = "p.is_organic = ?"; $params[] = $organic; }

$farmerInfo = null;
if ($farmerFilter) {
    $fq = $pdo->prepare("SELECT u.name, f.farm_name FROM users u LEFT JOIN farmers f ON f.user_id=u.id WHERE u.id=?");
    $fq->execute([$farmerFilter]);
    $farmerInfo = $fq->fetch();
}

$orderBy = match($sort) {
    'price_asc' => 'p.price_per_kg ASC',
    'price_desc' => 'p.price_per_kg DESC',
    'name' => 'p.name ASC',
    default => 'f.is_premium DESC, p.created_at DESC'
};

$whereStr = implode(' AND ', $where);
$sql = "SELECT p.*, u.name as farmer_name, u.location as farmer_city, 
               f.farm_name, f.rating, f.is_premium, f.premium_until
        FROM products p 
        JOIN users u ON p.farmer_id = u.id 
        LEFT JOIN farmers f ON f.user_id = p.farmer_id
        WHERE $whereStr ORDER BY $orderBy";$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $pdo->query("SELECT DISTINCT category FROM products WHERE is_available=1 ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$emojis = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Crops'=>'🌾','Coffee'=>'☕','Livestock'=>'🐄','Seafood'=>'🐟','Others'=>'📦'];
?>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">
    <!-- Search Hero -->
    <div style="background:linear-gradient(135deg,#1B5E20,#2E7D32);padding:2.5rem 0;">
        <div class="container">
            <h2 style="color:white;font-family:'Playfair Display',serif;font-size:1.8rem;margin-bottom:0.5rem;">🌾 Browse Fresh Products</h2>
            <p style="color:rgba(255,255,255,0.8);font-size:0.9rem;margin-bottom:1.2rem;">Direct from Mindanao farms to your kitchen</p>
            <form method="GET" class="d-flex gap-2" autocomplete="off">
                <div class="search-bar-wrap flex-grow-1" style="position:relative;">
                    <i class="fa-solid fa-magnifying-glass search-icon" style="color:#636E72;"></i>
                    <input type="text" name="q" id="liveSearchInput" class="search-input"
                           placeholder="Search products (e.g. pechay, mango, coffee...)"
                           value="<?= htmlspecialchars($search) ?>"
                           autocomplete="off">
                    <div id="liveSearchDropdown" style="display:none;position:absolute;top:calc(100% + 6px);left:0;right:0;background:white;border-radius:14px;box-shadow:0 8px 30px rgba(0,0,0,0.15);border:1px solid var(--border);z-index:9999;max-height:380px;overflow-y:auto;">
                        <div id="liveSearchResults"></div>
                    </div>
                </div>
                <button type="submit" class="btn-green" style="padding:0.75rem 1.5rem;">Search</button>
            </form>
        </div>
    </div>

    <div class="container mt-4">
        <div class="row g-4">
            <!-- Filter Panel -->
            <div class="col-lg-3">
                <form method="GET" id="filterForm">
                    <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
                    <div class="filter-panel">
                        <div class="filter-panel-title"><i class="fa-solid fa-sliders text-green"></i> Filters</div>
                        <div class="filter-group">
                            <label>Category</label>
                            <div style="display:flex;flex-direction:column;gap:4px;">
                                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.88rem;font-weight:600;padding:4px 0;">
                                    <input type="radio" name="category" value="" <?= !$category ? 'checked' : '' ?> onchange="this.form.submit()" style="accent-color:var(--primary);"> All Products
                                </label>
                                <?php foreach ($categories as $cat): ?>
                                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.88rem;font-weight:600;padding:4px 0;">
                                    <input type="radio" name="category" value="<?= $cat ?>" <?= $category === $cat ? 'checked' : '' ?> onchange="this.form.submit()" style="accent-color:var(--primary);">
                                    <?= ($emojis[$cat] ?? '📦') . ' ' . $cat ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="gl-divider"></div>
                        <div class="filter-group">
                            <label>Price Range (₱/kg)</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <input type="number" name="min_price" class="gl-input no-icon" placeholder="Min" value="<?= $min_price > 0 ? $min_price : '' ?>" style="font-size:0.85rem;">
                                </div>
                                <div class="col-6">
                                    <input type="number" name="max_price" class="gl-input no-icon" placeholder="Max" value="<?= $max_price < 9999 ? $max_price : '' ?>" style="font-size:0.85rem;">
                                </div>
                            </div>
                        </div>
                        <div class="gl-divider"></div>
                        <div class="filter-group">
                            <label>Special</label>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.88rem;font-weight:600;background:var(--pale-green);padding:8px 10px;border-radius:10px;">
                                <input type="checkbox" name="organic" <?= isset($_GET['organic']) ? 'checked' : '' ?> style="accent-color:var(--primary);width:16px;height:16px;">
                                🌿 Organic Only
                            </label>
                        </div>
                        <div class="gl-divider"></div>
                        <div class="filter-group">
                            <label>Location</label>
                            <div class="gl-input-wrap">
                                <i class="fa-solid fa-location-dot input-icon"></i>
                                <input type="text" name="location" class="gl-input" placeholder="e.g. Davao, Bukidnon" value="<?= htmlspecialchars($location) ?>" style="font-size:0.85rem;">
                            </div>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn-green justify-content-center" style="padding:0.65rem;">
                                <i class="fa-solid fa-filter"></i> Apply Filters
                            </button>
                            <a href="browse.php" class="btn-outline-green justify-content-center" style="padding:0.65rem;font-size:0.85rem;">Clear All</a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Product Grid -->
           <div class="col-lg-9">
    <?php if ($farmerInfo): ?>
    <div style="background:linear-gradient(135deg,#1B5E20,#2E7D32);color:white;padding:.75rem 1.25rem;border-radius:12px;margin-bottom:1rem;display:flex;align-items:center;justify-content:space-between;">
        <span><i class="fa-solid fa-tractor"></i> Products from <strong><?= sanitize(isset($farmerInfo['farm_name']) ? $farmerInfo['farm_name'] : $farmerInfo['name']) ?></strong></span>
        <a href="browse.php" style="color:rgba(255,255,255,.75);font-size:.82rem;text-decoration:none;">✕ Clear filter</a>
    </div>
    <?php endif; ?>
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
                    <div style="font-weight:700;color:var(--text-muted);font-size:0.88rem;">
                        Showing <strong style="color:var(--primary);"><?= count($products) ?></strong> products
                        <?= $search ? "for \"$search\"" : '' ?>
                        <?= $category ? "in $category" : '' ?>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span style="font-size:0.82rem;font-weight:600;color:var(--text-muted);">Sort:</span>
                        <select onchange="window.location.href=this.value" style="border:2px solid var(--border);border-radius:8px;padding:4px 8px;font-size:0.82rem;font-weight:600;outline:none;">
                            <?php 
                            $curParams = $_GET;
                            foreach (['newest'=>'Newest','price_asc'=>'Price: Low to High','price_desc'=>'Price: High to Low','name'=>'Name A-Z'] as $val => $label):
                                $curParams['sort'] = $val;
                                $url = 'browse.php?' . http_build_query($curParams);
                            ?>
                            <option value="<?= $url ?>" <?= $sort === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php if (empty($products)): ?>
                <div class="gl-card">
                    <div class="gl-card-body empty-state" style="text-align:center;padding:3rem 2rem;">
                        <div class="empty-icon" style="font-size:3rem;margin-bottom:1rem;">🚫</div>
                        <?php if ($search): ?>
                            <h5 style="font-weight:800;color:var(--text);margin-bottom:0.5rem;">"<?= htmlspecialchars($search) ?>" is currently unavailable</h5>
                            <p style="color:var(--text-muted);font-size:0.88rem;margin-bottom:1.25rem;">No farmers are currently selling this product on GreenLink.<br>It may be out of season or out of stock.</p>
                            <div style="display:inline-block;background:#FEF3C7;border:1px solid #FCD34D;border-radius:12px;padding:0.75rem 1.25rem;font-size:0.82rem;color:#92400E;font-weight:600;margin-bottom:1.5rem;">
                                💡 Tip: Check back soon — farmers restock regularly!
                            </div><br>
                        <?php else: ?>
                            <p style="color:var(--text-muted);font-size:0.88rem;margin-bottom:1.25rem;">No products found matching your filters.</p>
                        <?php endif; ?>
                        <a href="browse.php" class="btn-green mt-2" style="display:inline-flex;">Browse All Products</a>
                    </div>
                </div>
                <?php else: ?>
                <div class="row g-3" id="products-grid">
                    <?php foreach ($products as $p): ?>
                    <div class="col-sm-6 col-xl-4 product-card-wrap animate-on-scroll" data-category="<?= sanitize($p['category']) ?>">
                        <div class="product-card h-100" style="<?= $p['stock_kg'] <= 0 ? 'opacity:0.65;' : '' ?>">
                            <?php if ($p['stock_kg'] <= 0): ?>
                                <span style="position:absolute;top:10px;left:10px;background:#EF4444;color:white;font-size:0.72rem;font-weight:800;padding:4px 10px;border-radius:20px;z-index:10;letter-spacing:0.03em;box-shadow:0 2px 8px rgba(239,68,68,0.35);">🚫 Out of Stock</span>
                            <?php endif; ?>
                            <?php if ($p['is_organic']): ?><span class="badge-organic">🌿 Organic</span><?php endif; ?>
                                <?php if (!empty($p['is_premium']) && !empty($p['premium_until']) && strtotime($p['premium_until']) > time()): ?>
<span style="position:absolute;top:10px;left:10px;background:linear-gradient(135deg,#78350f,#d97706);color:white;font-size:.6rem;font-weight:800;padding:3px 9px;border-radius:99px;z-index:10;letter-spacing:.04em;box-shadow:0 2px 8px rgba(217,119,6,.4);">
    ⭐ PREMIUM
</span>
<?php endif; ?>
                            <div class="product-card-img" style="cursor:<?= $p['stock_kg'] <= 0 ? 'default' : 'pointer' ?>;"
                                <?= $p['stock_kg'] <= 0
                                    ? "onclick=\"showOutOfStockModal('" . sanitize($p['name']) . "', '" . sanitize($p['farm_name'] ?? $p['farmer_name']) . "')\""
                                    : "onclick=\"window.location='product.php?id={$p['id']}'\"" ?>>
                                <?php if ($p['image']): ?>
                                    <img src="../assets/images/products/<?= sanitize($p['image']) ?>" alt="<?= sanitize($p['name']) ?>">
                                <?php else: ?>
                                    <?= $emojis[$p['category']] ?? '🌾' ?>
                                <?php endif; ?>
                            </div>
                            <div class="product-card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <span class="badge-category"><?= sanitize($p['category']) ?></span>
                                    <?php if ($p['rating'] > 0): ?>
                                    <span style="font-size:0.72rem;font-weight:700;color:#F59E0B;">⭐ <?= number_format($p['rating'],1) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="product-card-title mt-1"><?= sanitize($p['name']) ?></div>
                                <div class="product-card-meta">
                                    <i class="fa-solid fa-user text-green"></i> <?= sanitize($p['farm_name'] ?? $p['farmer_name']) ?>
                                </div>
                                <div class="product-card-meta prod-location">
                                    <i class="fa-solid fa-location-dot text-green"></i> <?= sanitize($p['farmer_city'] ?? $p['location']) ?>
                                </div>
                                <?php if ($p['harvest_date']): ?>
                                <div class="product-card-meta">
                                    <i class="fa-solid fa-calendar-days text-green"></i> <?= date('M j, Y', strtotime($p['harvest_date'])) ?>
                                </div>
                                <?php endif; ?>
                                <div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;">Min. order: <?= $p['min_order_kg'] ?>kg | Stock: <?= number_format($p['stock_kg'],0) ?>kg</div>
                                <div class="d-flex align-items-center justify-content-between mt-2">
                                    <div class="product-card-price" data-price="<?= $p['price_per_kg'] ?>">
                                        ₱<?= number_format($p['price_per_kg'],2) ?><span>/kg</span>
                                    </div>
                                 <?php if ($p['stock_kg'] > 0): ?>
                                    <?php if (isLoggedIn() && $_SESSION['role'] === 'farmer' && $_SESSION['user_id'] == $p['farmer_id']): ?>
                                    <span style="padding:0.4rem 1rem;font-size:0.8rem;font-weight:700;color:#16a34a;background:#dcfce7;border-radius:8px;">
                                        🌾 Your Product
                                    </span>
                                    <?php else: ?>
                                    <div style="display:flex;gap:5px;align-items:center;">
                                        <button onclick="addToCart(<?= $p['id'] ?>, this)"
                                                style="width:32px;height:32px;border-radius:8px;border:2px solid var(--primary);background:white;color:var(--primary);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.8rem;flex-shrink:0;transition:all .2s;"
                                                title="Add to Cart"
                                                onmouseover="this.style.background='var(--pale-green)'"
                                                onmouseout="this.style.background='white'">
                                            <i class="fa-solid fa-basket-shopping"></i>
                                        </button>
                                        <a href="product.php?id=<?= $p['id'] ?>" class="btn-green" style="padding:0.4rem 1rem;font-size:0.8rem;">
                                            <i class="fa-solid fa-cart-plus"></i> Order
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php else: ?>
                                        <span style="padding:0.4rem 1rem;font-size:0.8rem;font-weight:700;color:#9CA3AF;background:#F3F4F6;border-radius:8px;cursor:not-allowed;">
                                            Unavailable
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes popIn {
    from { transform: scale(0.85); opacity: 0; }
    to   { transform: scale(1);    opacity: 1; }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── Live Search ───────────────────────────────────────────
    const input    = document.getElementById('liveSearchInput');
    const dropdown = document.getElementById('liveSearchDropdown');
    const results  = document.getElementById('liveSearchResults');
    if (input) {
        let debounce;
        input.addEventListener('input', function(){
            clearTimeout(debounce);
            const q = this.value.trim();
            if (q.length < 2) { dropdown.style.display='none'; return; }
            debounce = setTimeout(() => fetchResults(q), 220);
        });
        input.addEventListener('keydown', function(e){
            if (e.key === 'Escape') dropdown.style.display = 'none';
        });
        document.addEventListener('click', function(e){
            if (!e.target.closest('#liveSearchInput') && !e.target.closest('#liveSearchDropdown'))
                dropdown.style.display = 'none';
        });
        async function fetchResults(q){
            results.innerHTML = '<div style="padding:1rem;text-align:center;color:var(--text-muted);font-size:0.85rem;"><i class="fa-solid fa-spinner fa-spin"></i> Searching...</div>';
            dropdown.style.display = 'block';
            try {
                const res  = await fetch(`<?= BASE_URL ?>/buyer/search_ajax.php?q=${encodeURIComponent(q)}`);
                const data = await res.json();
                if (!data.length) {
                    results.innerHTML = '<div style="padding:1.2rem;text-align:center;color:var(--text-muted);font-size:0.85rem;">🌱 No products found for "<strong>'+q+'</strong>"</div>';
                    return;
                }
                results.innerHTML = data.map(p => `
                    <a href="<?= BASE_URL ?>/buyer/product.php?id=${p.id}" style="display:flex;align-items:center;gap:12px;padding:0.75rem 1rem;border-bottom:1px solid var(--border);text-decoration:none;transition:background 0.15s;" onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='white'">
                        <div style="width:46px;height:46px;background:var(--pale-green);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0;overflow:hidden;">
                            ${p.image ? `<img src="<?= BASE_URL ?>/assets/images/products/${p.image}" style="width:46px;height:46px;object-fit:cover;border-radius:10px;">` : (p.emoji || '🌾')}
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-weight:800;color:var(--text);font-size:0.9rem;">${p.name}</div>
                            <div style="font-size:0.75rem;color:var(--text-muted);">${p.category} · 📍 ${p.location}</div>
                        </div>
                        <div style="font-weight:800;color:var(--primary);font-size:0.9rem;flex-shrink:0;">₱${parseFloat(p.price_per_kg).toFixed(2)}<span style="font-weight:400;color:var(--text-muted);font-size:0.72rem;">/kg</span></div>
                    </a>
                `).join('') + `<a href="<?= BASE_URL ?>/buyer/browse.php?q=${encodeURIComponent(q)}" style="display:block;text-align:center;padding:0.75rem;font-size:0.82rem;font-weight:700;color:var(--primary);text-decoration:none;background:var(--pale-green);border-radius:0 0 14px 14px;">View all results for "${q}" →</a>`;
            } catch(e) {
                results.innerHTML = '<div style="padding:1rem;text-align:center;color:var(--text-muted);font-size:0.85rem;">Error loading results.</div>';
            }
        }
    }

    // ── Out of Stock Modal ────────────────────────────────────
    const outOfStockModal = document.getElementById('outOfStockModal');
    if (outOfStockModal) {
        window.showOutOfStockModal = function(name, farmer) {
            document.getElementById('modalProductName').textContent = name;
            document.getElementById('modalFarmerName').textContent = farmer;
            outOfStockModal.style.display = 'flex';
        };
        window.closeOutOfStockModal = function() {
            outOfStockModal.style.display = 'none';
        };
        outOfStockModal.addEventListener('click', function(e) {
            if (e.target === this) closeOutOfStockModal();
        });
    }

    <?php if (isLoggedIn() && $_SESSION['role'] === 'buyer'): ?>

    // ── Cart Badge ────────────────────────────────────────────
    function updateCartBadge(count) {
        const badge = document.getElementById('cartBadge');
        if (badge) {
            badge.textContent = count;
            badge.style.display = count > 0 ? 'flex' : 'none';
        }
    }

    // ── Cart Toast ────────────────────────────────────────────
    function showCartToast(name) {
        let toast = document.getElementById('cartToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'cartToast';
            toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#1B5E20;color:white;padding:.75rem 1.25rem;border-radius:14px;font-size:.85rem;font-weight:700;box-shadow:0 6px 24px rgba(0,0,0,.18);z-index:9999;display:flex;align-items:center;gap:8px;transition:opacity .3s;';
            document.body.appendChild(toast);
        }
        toast.innerHTML = `<i class="fa-solid fa-basket-shopping"></i> "${name}" added to cart!`;
        toast.style.opacity = '1';
        clearTimeout(toast._t);
        toast._t = setTimeout(() => toast.style.opacity = '0', 2500);
    }

    // ── Add to Cart ───────────────────────────────────────────
    window.addToCart = function(productId, btn) {
        fetch(`<?= BASE_URL ?>/buyer/cart.php?action=info&product_id=${productId}`)
            .then(r => r.json())
            .then(p => {
                if (!p || !p.id) return;
                showCartModal(p, btn);
            })
            .catch(() => {});
    };

    // ── Show Modal ────────────────────────────────────────────
    function showCartModal(p, triggerBtn) {
        const existing = document.getElementById('cartAddModal');
        if (existing) existing.remove();

        const emojis = {Vegetables:'🥬',Fruits:'🍋',Grains:'🌽',Coffee:'☕',Livestock:'🐄',Seafood:'🐟',Others:'📦'};
        const emoji  = emojis[p.category] || '🌾';
        const imgHtml = p.image
            ? `<img src="<?= BASE_URL ?>/assets/images/products/${p.image}" style="width:100%;height:100%;object-fit:cover;border-radius:12px;">`
            : `<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:3rem;background:var(--pale-green);border-radius:12px;">${emoji}</div>`;

        const modal = document.createElement('div');
        modal.id = 'cartAddModal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:1rem;backdrop-filter:blur(4px);';
        modal.innerHTML = `
            <div style="background:white;border-radius:20px;width:100%;max-width:420px;box-shadow:0 24px 60px rgba(0,0,0,.25);overflow:hidden;animation:cartModalIn .25s cubic-bezier(.4,0,.2,1);">
                <div style="background:linear-gradient(135deg,#1B5E20,#2E7D32);padding:1.1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <i class="fa-solid fa-basket-shopping" style="color:white;font-size:1rem;"></i>
                        <span style="color:white;font-weight:800;font-size:.95rem;">Add to Cart</span>
                    </div>
                    <button onclick="closeCartModal()" style="background:rgba(255,255,255,.15);border:none;border-radius:50%;width:30px;height:30px;color:white;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.8rem;">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <div style="padding:1.25rem;">
                    <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:1.1rem;">
                        <div style="width:70px;height:70px;flex-shrink:0;border-radius:12px;overflow:hidden;border:1px solid var(--border);">${imgHtml}</div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-weight:800;font-size:1rem;color:var(--text);line-height:1.2;margin-bottom:3px;">${p.name}</div>
                            <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:4px;">${emoji} ${p.category} ${p.is_organic ? '· 🌿 Organic' : ''}</div>
                            <div style="font-size:1.1rem;font-weight:800;color:var(--primary);">₱${parseFloat(p.price_per_kg).toFixed(2)}<span style="font-size:.75rem;font-weight:500;color:var(--text-muted);">/kg</span></div>
                        </div>
                    </div>
                    <div style="background:#f8faf8;border-radius:14px;padding:1rem;margin-bottom:1rem;border:1.5px solid var(--border);">
                        <div style="font-size:.72rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.6rem;">
                            <i class="fa-solid fa-weight-scale" style="color:var(--primary);"></i> Quantity
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:.6rem;">
                            <button onclick="cartModalStep(-1)" style="width:38px;height:38px;border-radius:10px;border:2px solid var(--border);background:white;color:var(--text);font-size:1.1rem;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;">−</button>
                            <div style="flex:1;position:relative;">
                                <input type="number" id="cartModalQty"
                                       value="${p.min_order_kg}" min="${p.min_order_kg}" max="${p.stock_kg}" step="0.5"
                                       oninput="cartModalCalc()"
                                       style="width:100%;text-align:center;font-size:1.2rem;font-weight:800;border:2px solid var(--primary);border-radius:10px;padding:.5rem;outline:none;color:var(--primary);">
                                <span style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:.72rem;color:var(--text-muted);font-weight:700;">kg</span>
                            </div>
                            <button onclick="cartModalStep(1)" style="width:38px;height:38px;border-radius:10px;border:2px solid var(--border);background:white;color:var(--text);font-size:1.1rem;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;">+</button>
                        </div>
                        <div style="display:flex;justify-content:space-between;font-size:.68rem;color:var(--text-muted);font-weight:600;">
                            <span>Min: ${p.min_order_kg} kg</span>
                            <span>Stock: ${parseFloat(p.stock_kg).toLocaleString()} kg</span>
                        </div>
                    </div>
                    <div style="background:var(--pale-green);border-radius:12px;padding:.75rem 1rem;margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center;">
                        <span style="font-size:.82rem;font-weight:700;color:var(--text-muted);">Subtotal</span>
                        <span id="cartModalSubtotal" style="font-size:1.15rem;font-weight:800;color:var(--primary);">₱${(p.min_order_kg * parseFloat(p.price_per_kg)).toFixed(2)}</span>
                    </div>
                    <div style="font-size:.7rem;color:#64748b;display:flex;align-items:flex-start;gap:5px;margin-bottom:1rem;line-height:1.5;">
                        <i class="fa-solid fa-circle-info" style="color:#3b82f6;margin-top:1px;flex-shrink:0;"></i>
                        Delivery fee and service fee will be calculated at checkout based on your location.
                    </div>
                    <div style="display:flex;gap:.6rem;">
                        <button onclick="closeCartModal()" style="flex:1;padding:.75rem;border-radius:12px;border:2px solid var(--border);background:white;color:var(--text-muted);font-weight:700;font-size:.85rem;cursor:pointer;">Cancel</button>
                        <button onclick="confirmAddToCart(${p.id}, ${p.min_order_kg}, ${p.stock_kg})"
                                id="cartModalConfirmBtn"
                                style="flex:2;padding:.75rem;border-radius:12px;border:none;background:linear-gradient(135deg,var(--primary),#2E7D32);color:white;font-weight:800;font-size:.88rem;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;">
                            <i class="fa-solid fa-basket-shopping"></i> Add to Cart
                        </button>
                    </div>
                </div>
            </div>`;

        modal._price      = parseFloat(p.price_per_kg);
        modal._minQty     = parseFloat(p.min_order_kg);
        modal._maxQty     = parseFloat(p.stock_kg);
        modal._triggerBtn = triggerBtn;

        document.body.appendChild(modal);
        document.body.style.overflow = 'hidden';
        modal.addEventListener('click', e => { if (e.target === modal) closeCartModal(); });
        setTimeout(() => document.getElementById('cartModalQty')?.focus(), 100);
    }

    window.closeCartModal = function() {
        const modal = document.getElementById('cartAddModal');
        if (modal) {
            modal.style.opacity = '0';
            modal.style.transition = 'opacity .2s';
            setTimeout(() => { modal.remove(); document.body.style.overflow = ''; }, 200);
        }
    };

    window.cartModalStep = function(dir) {
        const input = document.getElementById('cartModalQty');
        const modal = document.getElementById('cartAddModal');
        if (!input || !modal) return;
        let val = parseFloat(input.value) + (dir * 0.5);
        val = Math.max(modal._minQty, Math.min(modal._maxQty, val));
        input.value = val;
        cartModalCalc();
    };

    window.cartModalCalc = function() {
        const input = document.getElementById('cartModalQty');
        const modal = document.getElementById('cartAddModal');
        const sub   = document.getElementById('cartModalSubtotal');
        if (!input || !modal || !sub) return;
        const qty = parseFloat(input.value) || modal._minQty;
        sub.textContent = '₱' + (qty * modal._price).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    };

    window.confirmAddToCart = async function(productId, minQty, maxQty) {
        const input = document.getElementById('cartModalQty');
        const btn   = document.getElementById('cartModalConfirmBtn');
        const modal = document.getElementById('cartAddModal');
        if (!input || !btn) return;

        let qty = parseFloat(input.value);
        if (isNaN(qty) || qty < minQty) { qty = minQty; input.value = minQty; }
        if (qty > maxQty) { qty = maxQty; input.value = maxQty; }

        const origHtml = btn.innerHTML;
        btn.innerHTML  = '<i class="fa-solid fa-spinner fa-spin"></i> Adding...';
        btn.disabled   = true;

        try {
            const res  = await fetch(`<?= BASE_URL ?>/buyer/cart.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=add&product_id=${productId}&qty=${qty}`
            });
            const data = await res.json();
            if (data.success) {
                btn.innerHTML = '<i class="fa-solid fa-check"></i> Added!';
                btn.style.background = 'linear-gradient(135deg,#16a34a,#15803d)';
                updateCartBadge(data.count);
                if (modal._triggerBtn) {
                    const tb = modal._triggerBtn;
                    const origTb = tb.innerHTML;
                    tb.innerHTML     = '<i class="fa-solid fa-check"></i>';
                    tb.style.background  = 'var(--primary)';
                    tb.style.color       = 'white';
                    tb.style.borderColor = 'var(--primary)';
                    setTimeout(() => {
                        tb.innerHTML         = origTb;
                        tb.style.background  = 'white';
                        tb.style.color       = 'var(--primary)';
                        tb.style.borderColor = 'var(--primary)';
                        tb.disabled          = false;
                    }, 2000);
                }
                showCartToast(data.name);
                setTimeout(() => closeCartModal(), 900);
            } else {
                btn.innerHTML = origHtml;
                btn.disabled  = false;
            }
        } catch(e) {
            btn.innerHTML = origHtml;
            btn.disabled  = false;
        }
    };

    // Init badge
    fetch(`<?= BASE_URL ?>/buyer/cart.php?action=count`)
        .then(r => r.json())
        .then(d => updateCartBadge(d.count))
        .catch(() => {});

    <?php else: ?>
    // Not logged in as buyer — redirect to login on cart click
    window.addToCart = function() {
        window.location.href = '<?= BASE_URL ?>/auth/login.php';
    };
    <?php endif; ?>

});
</script>

<style>
@keyframes cartModalIn {
    from { opacity:0; transform:scale(.92) translateY(16px); }
    to   { opacity:1; transform:scale(1)   translateY(0); }
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>