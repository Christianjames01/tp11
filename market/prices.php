<?php
$page_title = 'Market Prices';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();

// Get latest prices per product
$prices = $pdo->query("SELECT mp.*, ROUND(((mp.suggested_price - mp.market_price)/mp.market_price)*100,1) as margin FROM market_prices mp WHERE mp.price_date = (SELECT MAX(price_date) FROM market_prices mp2 WHERE mp2.product_name=mp.product_name) ORDER BY mp.category, mp.product_name")->fetchAll();

// Group by category
$grouped = [];
foreach ($prices as $p) {
    $grouped[$p['category']][] = $p;
}

// Build history map for modal charts
$historyMap = [];
$historyRows = $pdo->query("
    SELECT product_name, market_price, price_date
    FROM market_prices
    ORDER BY product_name, price_date ASC
")->fetchAll();
foreach ($historyRows as $hr) {
    $historyMap[$hr['product_name']][] = [
        'price' => floatval($hr['market_price']),
        'date'  => date('M d', strtotime($hr['price_date']))
    ];
}
// Keep last 7 per product
foreach ($historyMap as $k => $v) {
    $historyMap[$k] = array_slice($v, -7);
}

// Build image map with fuzzy matching
$imageMap = [];
$imageRows = $pdo->query("
    SELECT name, image FROM products WHERE image IS NOT NULL AND image != ''
")->fetchAll();

$productImages = [];
foreach ($imageRows as $ir) {
    $productImages[strtolower(trim($ir['name']))] = $ir['image'];
}

foreach ($prices as $p) {
    $marketName = $p['product_name'];
    $key = strtolower(trim($marketName));

    // 1. Exact match
    if (isset($productImages[$key])) {
        $imageMap[$marketName] = $productImages[$key];
        continue;
    }

    // 2. Partial match
    foreach ($productImages as $productKey => $img) {
        if (str_contains($key, $productKey) || str_contains($productKey, $key)) {
            $imageMap[$marketName] = $img;
            break;
        }
    }

    // 3. First-word match
    if (!isset($imageMap[$marketName])) {
        $firstWord = explode(' ', $key)[0];
        foreach ($productImages as $productKey => $img) {
            if (str_starts_with($productKey, $firstWord)) {
                $imageMap[$marketName] = $img;
                break;
            }
        }
    }
}

$catEmojis = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕','Livestock'=>'🐄','Seafood'=>'🐟'];
?>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">
    <!-- Hero -->
<div style="background:linear-gradient(135deg,#1B5E20,#2E7D32);padding:2.5rem 0;position:sticky;top:0;z-index:100;">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-7">
                    <h1 style="font-family:'Playfair Display',serif;color:white;font-size:2rem;margin-bottom:0.5rem;">📊 Mindanao Market Prices</h1>
                    <p style="color:rgba(255,255,255,0.8);margin:0 0 1.2rem;">Today's farm-gate prices and GreenLink suggested prices — updated daily.</p>

                    <!-- 🔍 SEARCH BAR -->
                    <div style="position:relative;">
                        <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#636E72;font-size:0.9rem;"></i>
                        <input type="text" id="priceSearchInput" placeholder="Search products (e.g. pechay, mango, coffee...)"
                               style="width:100%;padding:0.75rem 1rem 0.75rem 2.5rem;border-radius:12px;border:none;font-size:0.9rem;font-family:'Nunito',sans-serif;font-weight:600;outline:none;box-shadow:0 4px 15px rgba(0,0,0,0.1);">
                        <span id="priceSearchClear" onclick="clearPriceSearch()" style="display:none;position:absolute;right:12px;top:50%;transform:translateY(-50%);cursor:pointer;color:#636E72;font-size:0.85rem;">✕</span>
                    </div>
                </div>
                <div class="col-lg-5 mt-3 mt-lg-0">
                    <div style="background:rgba(255,255,255,0.12);border-radius:14px;padding:1rem 1.5rem;display:flex;gap:2rem;">
                        <div class="text-center">
                            <div style="font-size:1.5rem;font-weight:800;color:white;" id="priceCount"><?= count($prices) ?></div>
                            <div style="font-size:0.75rem;color:rgba(255,255,255,0.7);font-weight:600;">Products</div>
                        </div>
                        <div class="text-center" style="border-left:1px solid rgba(255,255,255,0.2);padding-left:2rem;">
                            <div style="font-size:1.5rem;font-weight:800;color:white;"><?= date('M j') ?></div>
                            <div style="font-size:0.75rem;color:rgba(255,255,255,0.7);font-weight:600;">Updated</div>
                        </div>
                        <div class="text-center" style="border-left:1px solid rgba(255,255,255,0.2);padding-left:2rem;">
                            <div style="font-size:1.5rem;font-weight:800;color:white;"><?= count($grouped) ?></div>
                            <div style="font-size:0.75rem;color:rgba(255,255,255,0.7);font-weight:600;">Categories</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container mt-4">
        <!-- Legend -->
        <div style="background:white;border-radius:var(--radius-md);padding:1rem 1.5rem;margin-bottom:2rem;box-shadow:var(--shadow-sm);border:1px solid var(--border);display:flex;flex-wrap:wrap;gap:1.5rem;align-items:center;">
            <span style="font-weight:800;font-size:0.85rem;color:var(--text);">📖 Legend:</span>
            <span style="font-size:0.82rem;font-weight:600;color:var(--text-muted);"><span style="background:#F3F4F6;padding:2px 8px;border-radius:6px;">Market Price</span> — Current farm-gate price in Mindanao markets</span>
            <span style="font-size:0.82rem;font-weight:600;color:var(--text-muted);"><span style="background:var(--pale-green);color:var(--primary);padding:2px 8px;border-radius:6px;">GreenLink Price</span> — Our recommended selling price for fair profit</span>
            <span style="font-size:0.82rem;font-weight:600;color:var(--text-muted);"><span style="background:#E3F2FD;color:#1565C0;padding:2px 8px;border-radius:6px;">+X%</span> — Suggested margin above market</span>
        </div>

        <!-- No results message (hidden by default) -->
        <div id="priceNoResults" style="display:none;text-align:center;padding:3rem;background:white;border-radius:var(--radius-md);border:1px solid var(--border);">
            <div style="font-size:3rem;margin-bottom:1rem;">🔍</div>
            <div style="font-weight:800;color:var(--text);font-size:1.1rem;">No results found</div>
            <div style="color:var(--text-muted);font-size:0.88rem;margin-top:0.5rem;">Try searching for a different product name.</div>
        </div>

        <?php foreach ($grouped as $cat => $catPrices): ?>
        <div class="mb-4 animate-on-scroll price-category-group" data-category="<?= $cat ?>">
            <h4 style="font-family:'Playfair Display',serif;color:var(--text);margin-bottom:1rem;" class="price-cat-title">
                <?= ($catEmojis[$cat] ?? '📦') . ' ' . $cat ?>
            </h4>
            <div class="row g-3 price-cards-row">
                <?php foreach ($catPrices as $p): ?>
                <div class="col-sm-6 col-lg-4 col-xl-3 price-card-col" data-name="<?= strtolower(sanitize($p['product_name'])) ?>">
                    <div class="price-card" onclick="openPriceModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)" style="cursor:pointer;transition:transform .15s,box-shadow .15s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,.12)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="price-card-name"><?= sanitize($p['product_name']) ?></div>
                            <?php if ($p['margin'] > 0): ?>
                            <span class="price-tag"><i class="fa-solid fa-arrow-trend-up" style="font-size:0.6rem;"></i> +<?= $p['margin'] ?>%</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:0.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Market Price</div>
                        <div class="price-card-market">₱<?= number_format($p['market_price'],2) ?><span style="font-size:0.75rem;color:var(--text-muted);font-weight:500;">/<?= $p['unit'] ?></span></div>
                        <div style="height:1px;background:var(--border);margin:0.6rem 0;"></div>
                        <div style="font-size:0.72rem;font-weight:700;color:var(--primary);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">GreenLink Suggested</div>
                        <div class="price-card-suggested">₱<?= number_format($p['suggested_price'],2) ?><span style="font-size:0.75rem;font-weight:500;color:var(--text-muted);font-family:'Nunito',sans-serif;">/<?= $p['unit'] ?></span></div>
                        <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.4rem;">📍 <?= sanitize($p['location']) ?></div>
                        <div style="margin-top:.6rem;font-size:.7rem;color:var(--primary);font-weight:700;display:flex;align-items:center;gap:4px;">
                            <i class="fa-solid fa-circle-info"></i> Tap for details & stats
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Disclaimer -->
        <div style="background:white;border-radius:var(--radius-md);padding:1.2rem 1.5rem;border:1px solid var(--border);margin-top:2rem;">
            <p style="font-size:0.82rem;color:var(--text-muted);margin:0;line-height:1.6;">
<strong>⚠️ Disclaimer:</strong> Market prices are sourced from the Philippine Statistics Authority (PSA) and are updated monthly. The latest available data may be 1–3 months behind due to PSA's publication schedule.            </p>
        </div>
    </div>
</div>

<script>
(function(){
    const input = document.getElementById('priceSearchInput');
    const clearBtn = document.getElementById('priceSearchClear');
    const noResults = document.getElementById('priceNoResults');
    const cols = document.querySelectorAll('.price-card-col');
    const groups = document.querySelectorAll('.price-category-group');

    input.addEventListener('input', function(){
        const q = this.value.trim().toLowerCase();
        clearBtn.style.display = q ? 'block' : 'none';
        filterPrices(q);
    });

    function filterPrices(q) {
        let totalVisible = 0;

        groups.forEach(group => {
            const cards = group.querySelectorAll('.price-card-col');
            let groupVisible = 0;
            cards.forEach(card => {
                const name = card.dataset.name || '';
                const show = !q || name.includes(q);
                card.style.display = show ? '' : 'none';
                if (show) groupVisible++;
            });
            // Hide entire category if nothing matches
            group.style.display = groupVisible > 0 ? '' : 'none';
            totalVisible += groupVisible;
        });

        noResults.style.display = totalVisible === 0 ? 'block' : 'none';
        document.getElementById('priceCount').textContent = totalVisible || <?= count($prices) ?>;
    }

    window.clearPriceSearch = function(){
        input.value = '';
        clearBtn.style.display = 'none';
        filterPrices('');
        input.focus();
    };
})();
</script>

<!-- Price Detail Modal -->
<div id="priceModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:1rem;" onclick="if(event.target===this)closePriceModal()">
    <div style="background:white;border-radius:20px;max-width:540px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 25px 60px rgba(0,0,0,.2);">
        <!-- Header -->
        <div id="modalHeader" style="background:linear-gradient(135deg,#1B5E20,#2E7D32);padding:1.5rem;border-radius:20px 20px 0 0;position:relative;">
            <button onclick="closePriceModal()" style="position:absolute;top:1rem;right:1rem;background:rgba(255,255,255,.2);border:none;color:white;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;">✕</button>
            <div id="modalEmoji" style="margin-bottom:.6rem;width:72px;height:72px;border-radius:14px;overflow:hidden;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;font-size:2.5rem;">📦
            </div>
            <div id="modalName" style="font-size:1.3rem;font-weight:800;color:white;font-family:'Playfair Display',serif;"></div>
            <div id="modalCat" style="font-size:.78rem;color:rgba(255,255,255,.75);font-weight:600;margin-top:2px;"></div>
        </div>

        <div style="padding:1.25rem;">

            <!-- Price stats row -->
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:1.25rem;">
                <div style="background:#f8fafc;border-radius:12px;padding:.85rem;text-align:center;">
                    <div style="font-size:.65rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;">Market Price</div>
                    <div id="modalMarket" style="font-size:1.15rem;font-weight:800;color:var(--text);"></div>
                </div>
                <div style="background:var(--pale-green);border-radius:12px;padding:.85rem;text-align:center;">
                    <div style="font-size:.65rem;font-weight:800;color:var(--primary);text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;">Suggested</div>
                    <div id="modalSuggested" style="font-size:1.15rem;font-weight:800;color:var(--primary);"></div>
                </div>
                <div style="background:#dbeafe;border-radius:12px;padding:.85rem;text-align:center;">
                    <div style="font-size:.65rem;font-weight:800;color:#1d4ed8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;">Margin</div>
                    <div id="modalMargin" style="font-size:1.15rem;font-weight:800;color:#1d4ed8;"></div>
                </div>
            </div>

            <!-- Info rows -->
            <div style="background:#f8fafc;border-radius:12px;padding:1rem;margin-bottom:1.25rem;">
                <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border);font-size:.83rem;">
                    <span style="color:var(--text-muted);font-weight:600;"><i class="fa-solid fa-location-dot text-green me-1"></i>Location</span>
                    <span id="modalLocation" style="font-weight:700;color:var(--text);text-align:right;max-width:60%;"></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border);font-size:.83rem;">
                    <span style="color:var(--text-muted);font-weight:600;"><i class="fa-solid fa-calendar text-green me-1"></i>Price Date</span>
                    <span id="modalDate" style="font-weight:700;color:var(--text);"></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border);font-size:.83rem;">
                    <span style="color:var(--text-muted);font-weight:600;"><i class="fa-solid fa-weight-scale text-green me-1"></i>Unit</span>
                    <span id="modalUnit" style="font-weight:700;color:var(--text);"></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:.35rem 0;font-size:.83rem;">
                    <span style="color:var(--text-muted);font-weight:600;"><i class="fa-solid fa-tag text-green me-1"></i>Category</span>
                    <span id="modalCatRow" style="font-weight:700;color:var(--text);"></span>
                </div>
            </div>

            <!-- 7-day trend chart -->
            <div style="margin-bottom:1.25rem;">
                <div style="font-size:.78rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.6rem;"><i class="fa-solid fa-chart-line text-green me-1"></i>7-Day Price Trend</div>
                <div style="position:relative;height:150px;background:#f8fafc;border-radius:12px;padding:.75rem;">
                    <canvas id="modalTrendChart"></canvas>
                </div>
            </div>

            <!-- Stats: high/low/avg -->
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:1.25rem;">
                <div style="background:#dcfce7;border-radius:10px;padding:.7rem;text-align:center;">
                    <div style="font-size:.62rem;font-weight:800;color:#16a34a;text-transform:uppercase;margin-bottom:2px;">7d High</div>
                    <div id="modalHigh" style="font-size:.95rem;font-weight:800;color:#16a34a;"></div>
                </div>
                <div style="background:#fee2e2;border-radius:10px;padding:.7rem;text-align:center;">
                    <div style="font-size:.62rem;font-weight:800;color:#dc2626;text-transform:uppercase;margin-bottom:2px;">7d Low</div>
                    <div id="modalLow" style="font-size:.95rem;font-weight:800;color:#dc2626;"></div>
                </div>
                <div style="background:#dbeafe;border-radius:10px;padding:.7rem;text-align:center;">
                    <div style="font-size:.62rem;font-weight:800;color:#1d4ed8;text-transform:uppercase;margin-bottom:2px;">7d Avg</div>
                    <div id="modalAvg" style="font-size:.95rem;font-weight:800;color:#1d4ed8;"></div>
                </div>
            </div>

            <!-- Freshness / harvest info -->
            <div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1px solid #bbf7d0;border-radius:12px;padding:1rem;margin-bottom:1rem;">
                <div style="font-size:.78rem;font-weight:800;color:#16a34a;margin-bottom:.5rem;"><i class="fa-solid fa-seedling me-1"></i>Freshness & Harvest Info</div>
                <div style="font-size:.82rem;color:#166534;line-height:1.7;" id="modalFreshness"></div>
            </div>

            <!-- Browse button -->
        <a id="modalBrowseBtn" href="<?= BASE_URL ?>/buyer/browse.php" class="btn-green w-100 justify-content-center" style="padding:.75rem;">
    <i class="fa-solid fa-cart-plus me-1"></i> Find This Product on GreenLink
</a>
<?php
$alertBuyer = null;
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'buyer') {
    $alertBuyerStmt = $pdo->prepare("SELECT is_premium, premium_until FROM users WHERE id=?");
    $alertBuyerStmt->execute([$_SESSION['user_id']]);
    $alertBuyer = $alertBuyerStmt->fetch();
}
$isAlertPremium = $alertBuyer && !empty($alertBuyer['is_premium']) && strtotime($alertBuyer['premium_until']) > time();
?>
<?php if ($isAlertPremium): ?>
<div style="margin-top:.6rem;display:flex;gap:.5rem;">
    <input type="number" id="alertTargetPrice" placeholder="Target price (₱)" step="0.01" min="0"
           style="flex:1;border:1.5px solid var(--border);border-radius:10px;padding:.5rem .75rem;font-size:.82rem;font-family:inherit;outline:none;">
    <button onclick="setAlert()" class="btn-outline-green" style="padding:.5rem 1rem;font-size:.82rem;white-space:nowrap;">
        🔔 Set Alert
    </button>
</div>
<div id="alertMsg" style="font-size:.72rem;margin-top:.35rem;display:none;"></div>
<?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const HISTORY = <?= json_encode($historyMap) ?>;
const IMAGE_MAP = <?= json_encode($imageMap) ?>;
const BASE = '<?= BASE_URL ?>';
const CAT_EMOJIS = {'Vegetables':'🥬','Fruits':'🍋','Grains':'🌽','Coffee':'☕','Livestock':'🐄','Seafood':'🐟','Others':'📦'};

const FRESHNESS = {
    'Vegetables': '🌿 Most vegetables are harvested daily to weekly. Best consumed within <strong>3–7 days</strong> of harvest. Store in a cool, dry place.',
    'Fruits':     '🍑 Fruits are typically harvested when ripe or near-ripe. Best consumed within <strong>5–14 days</strong>. Refrigerate for longer shelf life.',
    'Grains':     '🌾 Grains are dried and milled for long shelf life. Can last <strong>6–12 months</strong> when stored properly in airtight containers.',
    'Coffee':     '☕ Coffee cherries are harvested once a year. Roasted beans stay fresh for <strong>2–4 weeks</strong> after roasting.',
    'Livestock':  '🥩 Livestock products are sourced fresh. Consume meat within <strong>1–3 days</strong> refrigerated, or freeze for up to 3 months.',
    'Seafood':    '🐟 Seafood is highly perishable. Best consumed within <strong>1–2 days</strong> of catch. Keep iced at all times.',
    'Others':     '📦 Shelf life varies by product type. Check with the farmer for specific harvest and storage details.',
};
let modalChart = null;
let currentAlertProduct = null;

function openPriceModal(p) {
    currentAlertProduct = p;
    const modal = document.getElementById('priceModal');
    const emoji = CAT_EMOJIS[p.category] || '📦';

    // Header
    // Header
const emojiEl = document.getElementById('modalEmoji');
const imgFile = IMAGE_MAP[p.product_name];
if (imgFile) {
    emojiEl.innerHTML = `<img src="${BASE}/assets/images/products/${imgFile}" 
        style="width:72px;height:72px;object-fit:cover;border-radius:14px;" 
        onerror="this.parentElement.textContent='${emoji}'">`;
} else {
    emojiEl.innerHTML = emoji;
}
    document.getElementById('modalName').textContent     = p.product_name;
    document.getElementById('modalCat').textContent      = p.category + ' · ' + p.location;

    // Price stats
    const fmt = v => '₱' + parseFloat(v).toFixed(2);
    document.getElementById('modalMarket').textContent    = fmt(p.market_price) + '/' + p.unit;
    document.getElementById('modalSuggested').textContent = fmt(p.suggested_price) + '/' + p.unit;
    document.getElementById('modalMargin').textContent    = (p.margin > 0 ? '+' : '') + p.margin + '%';

    // Info rows
    document.getElementById('modalLocation').textContent = p.location;
    const priceDate = new Date(p.price_date);
const now = new Date();
const monthsDiff = (now.getFullYear() - priceDate.getFullYear()) * 12 + (now.getMonth() - priceDate.getMonth());
const dateLabel = priceDate.toLocaleDateString('en-PH', {year:'numeric', month:'long', day:'numeric'});
document.getElementById('modalDate').textContent = monthsDiff > 1
    ? dateLabel + ' (latest available)'
    : dateLabel;
    document.getElementById('modalUnit').textContent     = p.unit || 'kg';
    document.getElementById('modalCatRow').textContent   = emoji + ' ' + p.category;

    // Freshness
    document.getElementById('modalFreshness').innerHTML = FRESHNESS[p.category] || FRESHNESS['Others'];

    // Browse link
document.getElementById('modalBrowseBtn').href = '<?= BASE_URL ?>/buyer/browse.php?q=' + encodeURIComponent(p.product_name) + '&category=' + encodeURIComponent(p.category);
    // Chart
    const history = HISTORY[p.product_name] || [];
    const labels  = history.map(h => h.date);
    const data    = history.map(h => h.price);
    const hi      = data.length ? Math.max(...data) : parseFloat(p.market_price);
    const lo      = data.length ? Math.min(...data) : parseFloat(p.market_price);
    const avg     = data.length ? data.reduce((a,b)=>a+b,0)/data.length : parseFloat(p.market_price);

    document.getElementById('modalHigh').textContent = '₱' + hi.toFixed(2);
    document.getElementById('modalLow').textContent  = '₱' + lo.toFixed(2);
    document.getElementById('modalAvg').textContent  = '₱' + avg.toFixed(2);

    if (modalChart) modalChart.destroy();
    const ctx = document.getElementById('modalTrendChart').getContext('2d');
    modalChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels.length ? labels : ['Today'],
            datasets: [{
                data: data.length ? data : [parseFloat(p.market_price)],
                borderColor: '#16a34a',
                backgroundColor: 'rgba(22,163,74,0.08)',
                borderWidth: 2.5,
                pointBackgroundColor: '#16a34a',
                pointRadius: 4,
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => '₱' + c.parsed.y.toFixed(2) + '/' + (p.unit||'kg') }}},
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 }}},
                y: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 10 }, callback: v => '₱' + v.toFixed(0) }}
            }
        }
    });

    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closePriceModal() {
    document.getElementById('priceModal').style.display = 'none';
    document.body.style.overflow = '';
    if (modalChart) { modalChart.destroy(); modalChart = null; }
}

// Close on Escape key
document.addEventListener('keydown', e => { if (e.key === 'Escape') closePriceModal(); });

async function setAlert() {
    const price = parseFloat(document.getElementById('alertTargetPrice').value);
    const msg   = document.getElementById('alertMsg');
    if (!price || price <= 0) {
        msg.style.display = 'block';
        msg.style.color   = '#dc2626';
        msg.textContent   = 'Please enter a valid target price.';
        return;
    }
    try {
        const res  = await fetch('../market/set_alert.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ product_name: currentAlertProduct.product_name, target_price: price })
        });
        const data = await res.json();
        msg.style.display = 'block';
        msg.style.color   = data.success ? '#16a34a' : '#dc2626';
        msg.textContent   = data.message;
    } catch(e) {
        msg.style.display = 'block'; msg.style.color = '#dc2626';
        msg.textContent   = 'Request failed. Try again.';
    }
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>