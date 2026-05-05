<?php
$page_title = 'Browse Farmers';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();

// ── Filters ──────────────────────────────────────────────────────────────────
$search     = trim($_GET['search'] ?? '');
$filterLoc  = trim($_GET['location'] ?? '');
$sortBy     = $_GET['sort'] ?? 'premium';
$farmerFilt = isset($_GET['farmer']) ? (int)$_GET['farmer'] : null;

// ── Build farmer query — PREMIUM ONLY ────────────────────────────────────────
$where   = [
    "u.role = 'farmer'",
    "u.is_active = 1",
    "f.is_premium = 1",
    "f.premium_until IS NOT NULL",
    "f.premium_until > NOW()",   // only active subscriptions
];
$params  = [];

if ($search !== '') {
    $where[]  = "(u.name LIKE :s OR f.farm_name LIKE :s2 OR f.bio LIKE :s3)";
    $params[':s'] = $params[':s2'] = $params[':s3'] = "%$search%";
}
if ($filterLoc !== '') {
    $where[]          = "(u.location LIKE :loc OR f.farm_location LIKE :loc2)";
    $params[':loc']   = $params[':loc2'] = "%$filterLoc%";
}
if ($farmerFilt) {
    $where[]           = "u.id = :fid";
    $params[':fid']    = $farmerFilt;
}

$orderBy = match($sortBy) {
    'products' => 'product_count DESC',
    'rating'   => 'f.rating DESC',
    'newest'   => 'u.created_at DESC',
    default    => 'f.premium_until DESC, product_count DESC', // longest remaining = top
};

$whereStr = implode(' AND ', $where);

try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.location, u.profile_image, u.created_at,
               f.farm_name, f.farm_location, f.bio, f.rating,
               f.is_premium, f.premium_until,
               COUNT(DISTINCT p.id) AS product_count
        FROM users u
        LEFT JOIN farmers f ON f.user_id = u.id
        LEFT JOIN products p ON p.farmer_id = u.id AND p.is_available = 1
        WHERE $whereStr
        GROUP BY u.id, u.name, u.location, u.profile_image, u.created_at,
                 f.farm_name, f.farm_location, f.bio, f.rating, f.is_premium, f.premium_until
        ORDER BY $orderBy
    ");
    $stmt->execute($params);
    $farmers = $stmt->fetchAll();
} catch (PDOException $e) {
    $farmers = [];
}

// ── Unique locations for filter dropdown (premium only) ───────────────────────
try {
    $locations = $pdo->query("
        SELECT DISTINCT COALESCE(NULLIF(f.farm_location,''), u.location) AS loc
        FROM users u
        LEFT JOIN farmers f ON f.user_id = u.id
        WHERE u.role='farmer' AND u.is_active=1
          AND f.is_premium=1 AND f.premium_until IS NOT NULL AND f.premium_until > NOW()
        HAVING loc IS NOT NULL AND loc != ''
        ORDER BY loc ASC
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch(PDOException $e) {
    $locations = [];
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$totalFarmers  = count($farmers);
$premiumCount  = count(array_filter($farmers, fn($f) =>
    !empty($f['is_premium']) && !empty($f['premium_until']) && strtotime($f['premium_until']) > time()
));

$gradients = [
    'linear-gradient(135deg,#1B5E20,#4CAF50)',
    'linear-gradient(135deg,#1565C0,#42A5F5)',
    'linear-gradient(135deg,#4A148C,#AB47BC)',
    'linear-gradient(135deg,#E65100,#FFA726)',
    'linear-gradient(135deg,#880E4F,#F06292)',
    'linear-gradient(135deg,#006064,#26C6DA)',
    'linear-gradient(135deg,#33691E,#9CCC65)',
    'linear-gradient(135deg,#BF360C,#FF7043)',
];
?>

<style>
:root {
    --primary: #2E7D32;
    --primary-dark: #1B5E20;
    --primary-light: #4CAF50;
    --accent: #A5D6A7;
    --bg: #F7FAF7;
    --text: #1a2e1a;
    --text-muted: #6B7B6B;
    --border: #E0EBE0;
    --pale-green: #E8F5E9;
    --gold: #d97706;
}

/* ── Layout ── */
.browse-wrap { background: var(--bg); min-height: 100vh; padding-bottom: 4rem; }

/* ── Hero ── */
.browse-hero {
    background: linear-gradient(135deg, #1B5E20 0%, #2E7D32 60%, #388E3C 100%);
    padding: 2.75rem 0 2rem;
    position: relative;
    overflow: hidden;
}
.browse-hero::before {
    content: '';
    position: absolute; inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}

/* ── Filter bar ── */
.filter-bar {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--border);
    padding: 1.1rem 1.3rem;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .85rem;
    box-shadow: 0 4px 24px rgba(0,0,0,0.06);
}
.filter-input {
    border: 1.5px solid var(--border);
    border-radius: 10px;
    padding: .48rem .9rem;
    font-size: .83rem;
    color: var(--text);
    background: var(--bg);
    outline: none;
    transition: border-color .2s;
    min-width: 0;
}
.filter-input:focus { border-color: var(--primary); background: white; }
.filter-btn {
    background: var(--primary);
    color: white;
    border: none;
    border-radius: 10px;
    padding: .48rem 1.1rem;
    font-size: .83rem;
    font-weight: 700;
    cursor: pointer;
    display: flex; align-items: center; gap: .4rem;
    transition: background .2s;
    white-space: nowrap;
}
.filter-btn:hover { background: var(--primary-dark); }
.filter-btn-ghost {
    background: transparent;
    color: var(--text-muted);
    border: 1.5px solid var(--border);
    border-radius: 10px;
    padding: .48rem 1rem;
    font-size: .83rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: border-color .2s, color .2s;
    white-space: nowrap;
}
.filter-btn-ghost:hover { border-color: var(--primary); color: var(--primary); }

.sort-select {
    border: 1.5px solid var(--border);
    border-radius: 10px;
    padding: .48rem .9rem;
    font-size: .83rem;
    color: var(--text);
    background: var(--bg);
    outline: none;
    cursor: pointer;
}

/* ── Farmer Cards ── */
.farmer-profile-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--border);
    overflow: hidden;
    transition: transform .25s, box-shadow .25s;
    height: 100%;
    display: flex;
    flex-direction: column;
    position: relative;
}
.farmer-profile-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 20px 50px rgba(46,125,50,0.13);
    border-color: var(--accent);
}
.fpc-stripe { height: 80px; width: 100%; flex-shrink: 0; position: relative; }
.fpc-avatar-wrap {
    display: flex; justify-content: center;
    margin-top: -40px; margin-bottom: .6rem;
    position: relative; z-index: 2;
}
.fpc-avatar-img {
    width: 80px; height: 80px; border-radius: 50%;
    object-fit: cover;
    border: 4px solid white;
    box-shadow: 0 6px 18px rgba(0,0,0,0.15);
}
.fpc-avatar-initials {
    width: 80px; height: 80px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 1.7rem; color: white;
    border: 4px solid white;
    box-shadow: 0 6px 18px rgba(0,0,0,0.15);
    font-family: 'Playfair Display', serif;
}
.fpc-body { padding: 0 1.25rem 1.4rem; flex: 1; display: flex; flex-direction: column; }
.fpc-name {
    font-family: 'Playfair Display', serif;
    font-size: 1.05rem; font-weight: 700; color: var(--text);
    text-align: center;
    display: flex; align-items: center; justify-content: center; gap: 6px;
}
.fpc-verified {
    background: var(--primary); color: white;
    font-size: .58rem; font-weight: 800;
    width: 17px; height: 17px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.fpc-farm {
    font-size: .76rem; font-weight: 700; color: var(--primary);
    text-align: center; margin-top: 3px;
    display: flex; align-items: center; justify-content: center; gap: 5px;
}
.fpc-location {
    font-size: .71rem; color: var(--text-muted);
    text-align: center; margin-top: 3px;
    display: flex; align-items: center; justify-content: center; gap: 4px;
}
.fpc-bio {
    font-size: .77rem; color: var(--text-muted);
    text-align: center; line-height: 1.58;
    margin: .8rem 0 .7rem; flex: 1;
}
.fpc-stats {
    display: flex; align-items: center; justify-content: center;
    background: var(--bg); border-radius: 12px; padding: .6rem 0;
}
.fpc-stat { flex: 1; text-align: center; }
.fpc-stat-num {
    font-family: 'Playfair Display', serif;
    font-weight: 700; font-size: 1.1rem; color: var(--text);
}
.fpc-stat-lbl {
    font-size: .61rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted);
}
.fpc-stat-divider { width: 1px; height: 34px; background: var(--border); flex-shrink: 0; }

.btn-green {
    background: var(--primary); color: white;
    border: none; border-radius: 10px;
    padding: .52rem 1.1rem;
    font-size: .82rem; font-weight: 700;
    cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: .4rem;
    transition: background .2s, transform .15s;
}
.btn-green:hover { background: var(--primary-dark); transform: translateY(-1px); color: white; }

.premium-badge {
    background: linear-gradient(135deg, #78350f, #d97706);
    color: white; font-size: .6rem; font-weight: 800;
    padding: 2px 10px; border-radius: 99px; letter-spacing: .04em;
}

/* ── Stats strip ── */
.hero-stat {
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 14px; padding: .8rem 1.2rem;
    text-align: center; backdrop-filter: blur(6px);
}
.hero-stat-num { font-family: 'Playfair Display', serif; font-size: 1.7rem; font-weight: 700; color: white; line-height: 1; }
.hero-stat-lbl { font-size: .67rem; font-weight: 700; color: rgba(255,255,255,.65); text-transform: uppercase; letter-spacing: .08em; margin-top: 3px; }

/* ── Empty state ── */
.empty-state {
    text-align: center; padding: 4rem 2rem;
    background: white; border-radius: 20px;
    border: 2px dashed var(--border);
}

/* ── Animate ── */
.animate-on-scroll { opacity: 0; transform: translateY(20px); transition: opacity .45s ease, transform .45s ease; }
.animate-on-scroll.visible { opacity: 1; transform: translateY(0); }

/* ── Active farmer highlight ── */
.fpc-active-glow { box-shadow: 0 0 0 3px var(--primary-light), 0 16px 40px rgba(46,125,50,0.18) !important; }

/* ── Tags ── */
.tag-chip {
    display: inline-flex; align-items: center; gap: 4px;
    background: var(--pale-green); color: var(--primary);
    border-radius: 8px; padding: 2px 9px;
    font-size: .67rem; font-weight: 700;
}

@media (max-width: 576px) {
    .filter-bar { gap: .6rem; }
    .fpc-name { font-size: .95rem; }
}
</style>

<div class="browse-wrap">

    <!-- ══ HERO ══ -->
    <div class="browse-hero">
        <div class="container">
            <!-- Breadcrumb -->
            <nav style="margin-bottom:1.25rem;">
                <a href="<?= BASE_URL ?>/dashboard.php" style="color:rgba(255,255,255,.6);font-size:.78rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:5px;">
                    <i class="fa-solid fa-house" style="font-size:.7rem;"></i> Dashboard
                </a>
                <span style="color:rgba(255,255,255,.3);margin:0 .4rem;">›</span>
                <span style="color:white;font-size:.78rem;font-weight:600;">Browse Farmers</span>
            </nav>

            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                    <h1 style="color:white;font-family:'Playfair Display',serif;font-size:clamp(1.6rem,4vw,2.4rem);margin:0;line-height:1.2;">
                        ⭐ Premium <span style="color:#A5D6A7;">Farmers</span>
                    </h1>
                    <p style="color:rgba(255,255,255,.65);font-size:.85rem;margin-top:.35rem;">
                        Verified premium sellers on GreenLink — trusted, reviewed, and committed growers
                    </p>
                </div>
                <a href="<?= BASE_URL ?>/buyer/browse.php" class="btn-green" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.35);font-size:.82rem;">
                    <i class="fa-solid fa-store"></i> Browse Products
                </a>
            </div>

            <!-- Hero stats -->
            <div class="row g-3" style="max-width:600px;">
                <div class="col-4">
                    <div class="hero-stat">
                        <div class="hero-stat-num"><?= count($farmers) ?>+</div>
                        <div class="hero-stat-lbl">Farmers</div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="hero-stat">
                        <div class="hero-stat-num"><?= $premiumCount ?></div>
                        <div class="hero-stat-lbl">Premium</div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="hero-stat">
                        <div class="hero-stat-num"><?= array_sum(array_column($farmers, 'product_count')) ?>+</div>
                        <div class="hero-stat-lbl">Products</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container" style="padding-top:1.75rem;">

        <!-- ══ FILTER BAR ══ -->
        <form method="GET" action="">
            <div class="filter-bar mb-4 animate-on-scroll visible">
                <!-- Search -->
                <div style="flex:1;min-width:180px;">
                    <div style="position:relative;">
                        <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.78rem;pointer-events:none;"></i>
                        <input type="text" name="search" class="filter-input" placeholder="Search farmer or farm name…"
                               value="<?= htmlspecialchars($search) ?>"
                               style="padding-left:2rem;width:100%;">
                    </div>
                </div>

                <!-- Location -->
                <select name="location" class="sort-select" style="min-width:150px;">
                    <option value="">📍 All Locations</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?= htmlspecialchars($loc) ?>" <?= $filterLoc === $loc ? 'selected' : '' ?>>
                        <?= htmlspecialchars($loc) ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <!-- Sort -->
                <select name="sort" class="sort-select">
                    <option value="premium"  <?= $sortBy==='premium'  ? 'selected':'' ?>>⏳ Longest Active</option>
                    <option value="products" <?= $sortBy==='products' ? 'selected':'' ?>>🥬 Most Products</option>
                    <option value="rating"   <?= $sortBy==='rating'   ? 'selected':'' ?>>📊 Highest Rated</option>
                    <option value="newest"   <?= $sortBy==='newest'   ? 'selected':'' ?>>🆕 Newest</option>
                </select>

                <button type="submit" class="filter-btn">
                    <i class="fa-solid fa-filter"></i> Filter
                </button>

                <?php if ($search || $filterLoc || $farmerFilt): ?>
                <a href="<?= BASE_URL ?>/dashboard/browse.php" class="filter-btn-ghost">
                    <i class="fa-solid fa-xmark"></i> Clear
                </a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Active filters -->
        <?php if ($search || $filterLoc || $farmerFilt): ?>
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.25rem;align-items:center;">
            <span style="font-size:.73rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;">Filters:</span>
            <?php if ($search): ?>
            <span class="tag-chip">🔍 "<?= htmlspecialchars($search) ?>"</span>
            <?php endif; ?>
            <?php if ($filterLoc): ?>
            <span class="tag-chip">📍 <?= htmlspecialchars($filterLoc) ?></span>
            <?php endif; ?>
            <?php if ($farmerFilt): ?>
            <span class="tag-chip">👤 Farmer #<?= $farmerFilt ?></span>
            <?php endif; ?>
            <span style="font-size:.75rem;color:var(--text-muted);">— <?= $totalFarmers ?> result<?= $totalFarmers != 1 ? 's' : '' ?></span>
        </div>
        <?php endif; ?>

        <!-- ══ RESULTS HEADER ══ -->
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.25rem;">
            <div>
                <h2 style="font-family:'Playfair Display',serif;font-size:1.35rem;font-weight:700;color:var(--text);margin:0;">
                    <?php if ($farmerFilt): ?>
                        Premium Farmer Profile
                    <?php elseif ($search || $filterLoc): ?>
                        Search Results
                    <?php else: ?>
                        All <span style="color:var(--primary);">Premium Farmers</span>
                    <?php endif; ?>
                </h2>
                <p style="font-size:.77rem;color:var(--text-muted);margin-top:2px;">
                    <?= $totalFarmers ?> farmer<?= $totalFarmers != 1 ? 's' : '' ?> found
                    <?php if ($premiumCount > 0): ?>
                    &nbsp;·&nbsp; <span style="color:var(--gold);font-weight:700;">⭐ <?= $premiumCount ?> premium</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- ══ FARMER GRID ══ -->
        <?php if (empty($farmers)): ?>
        <div class="empty-state animate-on-scroll visible">
            <div style="font-size:3.5rem;margin-bottom:1rem;">⭐</div>
            <h4 style="font-weight:800;color:var(--text);margin-bottom:.5rem;">No premium farmers found</h4>
            <p style="color:var(--text-muted);font-size:.88rem;max-width:340px;margin:0 auto 1.5rem;">
                <?php if ($search || $filterLoc): ?>
                    No premium farmers match your search. Try adjusting your filters.
                <?php else: ?>
                    No farmers have an active premium subscription yet.
                <?php endif; ?>
            </p>
            <a href="<?= BASE_URL ?>/dashboard/browse.php" class="btn-green">
                <i class="fa-solid fa-rotate-left"></i> Clear Filters
            </a>
        </div>

        <?php else: ?>
        <div class="row g-3 g-md-4">
            <?php foreach ($farmers as $idx => $f):
                $initials  = strtoupper(substr($f['name'], 0, 2));
                $farmLabel = !empty($f['farm_name']) ? $f['farm_name'] : $f['name'] . "'s Farm";
                $location  = !empty($f['farm_location']) ? $f['farm_location'] : ($f['location'] ?? 'Mindanao');
                $bio       = !empty($f['bio'])
                    ? $f['bio']
                    : 'Fresh produce straight from the farm to your kitchen.';
                $grad      = $gradients[$f['id'] % count($gradients)];
                $isPremium = !empty($f['is_premium']) && !empty($f['premium_until']) && strtotime($f['premium_until']) > time();
                $isActive  = $farmerFilt === (int)$f['id'];
                $delay     = ($idx % 4) * 60;
           
            ?>
            <div class="col-sm-6 col-lg-4 col-xl-3 animate-on-scroll" style="transition-delay:<?= $delay ?>ms;">
                <div class="farmer-profile-card <?= $isActive ? 'fpc-active-glow' : '' ?>">

                    <!-- Premium ribbon -->
                    <?php if ($isPremium): ?>
                    <div style="position:absolute;top:12px;left:12px;z-index:10;">
                        <span class="premium-badge">⭐ PREMIUM</span>
                    </div>
                    <?php endif; ?>

                 
                    <!-- Stripe -->
                    <div class="fpc-stripe" style="background:<?= $grad ?>;"></div>

                    <!-- Avatar -->
                    <div class="fpc-avatar-wrap">
                        <?php if (!empty($f['profile_image'])): ?>
                            <img src="<?= BASE_URL ?>/assets/images/profiles/<?= sanitize($f['profile_image']) ?>"
                                 class="fpc-avatar-img" alt="<?= sanitize($f['name']) ?>">
                        <?php else: ?>
                            <div class="fpc-avatar-initials" style="background:<?= $grad ?>;">
                                <?= $initials ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="fpc-body">
                        <!-- Name -->
                        <div class="fpc-name">
                            <?= sanitize($f['name']) ?>
                            <?php if ($isPremium): ?>
                            <span class="fpc-verified" title="Premium Verified">✓</span>
                            <?php endif; ?>
                        </div>

                        <!-- Farm name -->
                        <div class="fpc-farm">
                            <i class="fa-solid fa-tractor" style="font-size:.65rem;"></i>
                            <?= sanitize($farmLabel) ?>
                        </div>

                        <!-- Location -->
                        <div class="fpc-location">
                            <i class="fa-solid fa-location-dot" style="font-size:.62rem;color:var(--primary);"></i>
                            <?= sanitize($location) ?>
                        </div>

                        <!-- Bio -->
                        <p class="fpc-bio"><?= sanitize(mb_strimwidth($bio, 0, 90, '…')) ?></p>

                        <!-- Stats -->
                        <div class="fpc-stats">
                            <div class="fpc-stat">
                                <div class="fpc-stat-num"><?= $f['product_count'] ?></div>
                                <div class="fpc-stat-lbl">Products</div>
                            </div>
                            <div class="fpc-stat-divider"></div>
                            <div class="fpc-stat">
                                <div class="fpc-stat-num">
                                    <?php if (!empty($f['rating']) && $f['rating'] > 0): ?>
                                        <span style="color:#F59E0B;">⭐</span> <?= number_format($f['rating'],1) ?>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted);font-size:.9rem;">—</span>
                                    <?php endif; ?>
                                </div>
                                <div class="fpc-stat-lbl">Rating</div>
                            </div>
                        </div>

                        <!-- CTA -->
                        <a href="<?= BASE_URL ?>/buyer/browse.php?farmer=<?= $f['id'] ?>"
                           class="btn-green"
                           style="width:100%;justify-content:center;font-size:.82rem;padding:.55rem 1rem;margin-top:.9rem;">
                            <i class="fa-solid fa-store"></i> View Products
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
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
    }, { threshold: 0.07 });
    els.forEach(el => io.observe(el));

    // Auto-submit sort on change
    document.querySelectorAll('select[name="sort"], select[name="location"]').forEach(sel => {
        sel.addEventListener('change', () => sel.closest('form').submit());
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>