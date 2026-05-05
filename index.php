<?php
$page_title = 'Home';
require_once __DIR__ . '/includes/header.php';

$pdo = getDBConnection();

// Get featured products
$stmt = $pdo->query("SELECT p.*, u.name as farmer_name, u.location as farmer_location 
                     FROM products p JOIN users u ON p.farmer_id = u.id 
                     WHERE p.is_available = 1 ORDER BY p.created_at DESC LIMIT 6");
$products = $stmt->fetchAll();

// Get stats
$totalFarmers = $pdo->query("SELECT COUNT(*) FROM users WHERE role='farmer'")->fetchColumn();
$totalProducts = $pdo->query("SELECT COUNT(*) FROM products WHERE is_available=1")->fetchColumn();
$totalOrders = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
?>

<!-- HERO -->
<section class="gl-hero">
    <div class="container position-relative">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <div class="d-flex align-items-center gap-2 mb-3 fade-up">
                    <span style="background:rgba(255,255,255,0.15);color:white;padding:4px 14px;border-radius:20px;font-size:0.78rem;font-weight:700;letter-spacing:0.5px;">🌾 MINDANAO'S AGRITECH MARKETPLACE</span>
                </div>
                <h1 class="gl-hero-title fade-up fade-up-1">
                    Farm Fresh. <span>Direct.</span><br>Delivered.
                </h1>
                <p class="gl-hero-subtitle fade-up fade-up-2">
                    GreenLink connects Mindanao farmers directly to restaurants and buyers — cutting out the middlemen and bringing fresh, local produce straight from farm to table.
                </p>
                <div class="d-flex flex-wrap gap-3 fade-up fade-up-3">
                    <a href="<?= BASE_URL ?>/buyer/browse.php" class="btn-green" style="font-size:1rem;padding:0.8rem 2rem;">
                        <i class="fa-solid fa-store"></i> Browse Products
                    </a>
                    <a href="<?= BASE_URL ?>/auth/register.php?role=farmer" class="btn-outline-green" style="background:rgba(255,255,255,0.1);border-color:rgba(255,255,255,0.5);color:white;font-size:1rem;padding:0.8rem 2rem;">
                        <i class="fa-solid fa-tractor"></i> I'm a Farmer
                    </a>
                </div>
            </div>
            <div class="col-lg-6 fade-up fade-up-4">
                <div style="background:rgba(255,255,255,0.1);border-radius:20px;padding:2rem;backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.2);">
                    <div class="row g-3">
                        <div class="col-4 text-center">
                            <div style="font-size:2.5rem;font-weight:800;color:white;font-family:'Playfair Display',serif;"><?= $totalFarmers ?>+</div>
                            <div style="font-size:0.78rem;color:rgba(255,255,255,0.75);font-weight:600;">Farmers</div>
                        </div>
                        <div class="col-4 text-center" style="border-left:1px solid rgba(255,255,255,0.2);border-right:1px solid rgba(255,255,255,0.2);">
                            <div style="font-size:2.5rem;font-weight:800;color:white;font-family:'Playfair Display',serif;"><?= $totalProducts ?>+</div>
                            <div style="font-size:0.78rem;color:rgba(255,255,255,0.75);font-weight:600;">Products</div>
                        </div>
                        <div class="col-4 text-center">
                            <div style="font-size:2.5rem;font-weight:800;color:white;font-family:'Playfair Display',serif;"><?= $totalOrders ?>+</div>
                            <div style="font-size:0.78rem;color:rgba(255,255,255,0.75);font-weight:600;">Orders</div>
                        </div>
                    </div>
                    <hr style="border-color:rgba(255,255,255,0.2);margin:1.5rem 0;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:48px;height:48px;background:rgba(255,255,255,0.15);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;">🚚</div>
                        <div>
                            <div style="color:white;font-weight:700;font-size:0.9rem;">Fast Mindanao Delivery</div>
                            <div style="color:rgba(255,255,255,0.7);font-size:0.78rem;">Davao, CDO, GenSan & more</div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px;margin-top:0.8rem;">
                        <div style="width:48px;height:48px;background:rgba(255,255,255,0.15);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;">🌿</div>
                        <div>
                            <div style="color:white;font-weight:700;font-size:0.9rem;">Verified Organic Options</div>
                            <div style="color:rgba(255,255,255,0.7);font-size:0.78rem;">Certified by PH Organic Authority</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- HOW IT WORKS -->
<section style="padding:5rem 0;background:white;">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="section-title">How <span>GreenLink</span> Works</h2>
            <div class="section-divider mx-auto"></div>
            <p class="section-sub">Simple steps to connect farmers and buyers</p>
        </div>
        <div class="row g-4">
            <?php $steps = [
                ['🌾','Farmers List Products','Verified farmers upload their fresh produce with real-time stock and pricing.','1'],
                ['🛒','Buyers Browse & Order','Restaurant owners and buyers discover fresh local produce and place orders.','2'],
                ['🤝','Connect & Confirm','Farmers confirm orders and coordinate delivery directly with buyers.','3'],
                ['🚚','Fresh Delivery','Fresh produce delivered from farm to kitchen — no middlemen, fair prices.','4'],
            ];
            foreach ($steps as $i => $step): ?>
            <div class="col-lg-3 col-sm-6 text-center animate-on-scroll">
                <div style="background:var(--bg);border-radius:20px;padding:2rem 1.5rem;height:100%;border:1px solid var(--border);position:relative;">
                    <div style="position:absolute;top:-14px;left:50%;transform:translateX(-50%);background:var(--primary);color:white;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:800;"><?= $step[3] ?></div>
                    <div style="font-size:2.8rem;margin-bottom:1rem;"><?= $step[0] ?></div>
                    <h5 style="font-weight:800;color:var(--text);margin-bottom:0.5rem;font-size:1rem;"><?= $step[1] ?></h5>
                    <p style="color:var(--text-muted);font-size:0.85rem;margin:0;line-height:1.6;"><?= $step[2] ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- FEATURED PRODUCTS -->
<section style="padding:5rem 0;background:var(--bg);">
    <div class="container">
        <div class="d-flex flex-wrap align-items-end justify-content-between mb-4">
            <div>
                <h2 class="section-title">Fresh <span>Products</span></h2>
                <div class="section-divider"></div>
                <p class="section-sub">Straight from Mindanao farms to your kitchen</p>
            </div>
            <a href="<?= BASE_URL ?>/buyer/browse.php" class="btn-outline-green">
                View All <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
        <div class="row g-3">
            <?php foreach ($products as $p): ?>
            <div class="col-lg-4 col-sm-6 animate-on-scroll">
                <div class="product-card">
                    <?php if ($p['is_organic']): ?><span class="badge-organic">🌿 Organic</span><?php endif; ?>
                    <div class="product-card-img">
                        <?php if ($p['image']): ?>
                            <img src="<?= BASE_URL ?>/assets/images/products/<?= sanitize($p['image']) ?>" alt="<?= sanitize($p['name']) ?>">
                        <?php else: ?>
                            <?php $emojis = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕'];
                            echo $emojis[$p['category']] ?? '🌾'; ?>
                        <?php endif; ?>
                    </div>
                    <div class="product-card-body">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <span class="badge-category"><?= sanitize($p['category']) ?></span>
                        </div>
                        <div class="product-card-title mt-1"><?= sanitize($p['name']) ?></div>
                        <div class="product-card-meta prod-location">
                            <i class="fa-solid fa-location-dot text-green"></i> <?= sanitize($p['farmer_location'] ?? $p['location']) ?>
                        </div>
                        <div class="product-card-meta">
                            <i class="fa-solid fa-calendar-days text-green"></i> Harvest: <?= $p['harvest_date'] ? date('M j, Y', strtotime($p['harvest_date'])) : 'Fresh' ?>
                        </div>
                        <div class="d-flex align-items-center justify-content-between mt-2">
                            <div class="product-card-price" data-price="<?= $p['price_per_kg'] ?>">
                                ₱<?= number_format($p['price_per_kg'], 2) ?><span>/kg</span>
                            </div>
                            <a href="<?= BASE_URL ?>/buyer/product.php?id=<?= $p['id'] ?>" class="btn-green" style="padding:0.4rem 1rem;font-size:0.8rem;">
                                <i class="fa-solid fa-cart-plus"></i> Order
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- WHY GREENLINK -->
<section style="padding:5rem 0;background:white;">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <h2 class="section-title">Why Choose <span>GreenLink?</span></h2>
                <div class="section-divider"></div>
                <p class="section-sub mb-4">We're building a fairer food system for Mindanao.</p>
                <?php $features = [
                    ['🌾','Direct from Farm','No middlemen. Farmers earn more, buyers pay less. Fair for everyone.'],
                    ['✅','Quality Guaranteed','All farmers are verified. Products checked for quality standards.'],
                    ['📱','Mobile-First','Designed for farmers using smartphones. Simple, fast, accessible.'],
                    ['💰','Best Market Prices','We publish daily market prices to ensure transparency for all.'],
                ]; foreach ($features as $f): ?>
                <div style="display:flex;align-items:flex-start;gap:16px;margin-bottom:1.2rem;">
                    <div style="width:48px;height:48px;background:var(--pale-green);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;"><?= $f[0] ?></div>
                    <div>
                        <div style="font-weight:800;color:var(--text);margin-bottom:2px;"><?= $f[1] ?></div>
                        <div style="color:var(--text-muted);font-size:0.88rem;"><?= $f[2] ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <a href="<?= BASE_URL ?>/auth/register.php" class="btn-green mt-2">
                    <i class="fa-solid fa-seedling"></i> Join GreenLink Free
                </a>
            </div>
            <div class="col-lg-6">
                <div style="background:linear-gradient(135deg,var(--bg),var(--pale-green));border-radius:20px;padding:2.5rem;border:1px solid var(--border);">
                    <h4 style="font-family:'Playfair Display',serif;font-size:1.3rem;color:var(--text);margin-bottom:1.5rem;">🧑‍🌾 For Farmers</h4>
                    <ul style="list-style:none;padding:0;margin:0 0 1.5rem;">
                        <?php $fItems = ['Set your own prices','Manage your product catalog','Track all your orders','Chat directly with buyers','View market price reports'];
                        foreach ($fItems as $it): ?>
                        <li style="display:flex;align-items:center;gap:10px;margin-bottom:0.6rem;font-size:0.9rem;font-weight:600;color:var(--text);">
                            <i class="fa-solid fa-circle-check text-green"></i><?= $it ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <h4 style="font-family:'Playfair Display',serif;font-size:1.3rem;color:var(--text);margin-bottom:1rem;">🍽️ For Buyers</h4>
                    <ul style="list-style:none;padding:0;margin:0;">
                        <?php $bItems = ['Browse fresh local produce','Filter by price & location','Order directly from farms','Track your deliveries','Negotiate bulk pricing'];
                        foreach ($bItems as $it): ?>
                        <li style="display:flex;align-items:center;gap:10px;margin-bottom:0.6rem;font-size:0.9rem;font-weight:600;color:var(--text);">
                            <i class="fa-solid fa-circle-check text-green"></i><?= $it ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA -->
<section style="padding:5rem 0;background:linear-gradient(135deg,#1B5E20,#2E7D32);">
    <div class="container text-center">
        <h2 style="font-family:'Playfair Display',serif;font-size:2.5rem;color:white;margin-bottom:1rem;">Ready to Join Mindanao's<br>Farm Revolution?</h2>
        <p style="color:rgba(255,255,255,0.8);font-size:1rem;margin-bottom:2rem;max-width:500px;margin-left:auto;margin-right:auto;">Whether you're a farmer with fresh produce or a buyer looking for quality ingredients — GreenLink is for you.</p>
        <div class="d-flex justify-content-center gap-3 flex-wrap">
            <a href="<?= BASE_URL ?>/auth/register.php?role=farmer" class="btn-green" style="background:white;color:var(--primary);font-size:1rem;padding:0.9rem 2rem;">
                🌾 Register as Farmer
            </a>
            <a href="<?= BASE_URL ?>/auth/register.php?role=buyer" class="btn-outline-green" style="border-color:rgba(255,255,255,0.5);color:white;font-size:1rem;padding:0.9rem 2rem;">
                🛒 Register as Buyer
            </a>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
