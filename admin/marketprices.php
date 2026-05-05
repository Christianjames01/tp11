<?php
$page_title = 'Market Prices';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin');

$pdo = getDBConnection();

// ── Fetch all market prices with 7-day history per product ──────────────────
$allPrices = $pdo->query("
    SELECT product_name, category, market_price, suggested_price, price_date, location, unit
    FROM market_prices
    ORDER BY product_name, price_date ASC
")->fetchAll();

// Group by product — last 7 price points each
$byProduct = [];
foreach ($allPrices as $row) {
    $byProduct[$row['product_name']][] = $row;
}

$commodities = [];
foreach ($byProduct as $name => $rows) {
    $rows    = array_slice($rows, -7);
    $prices  = array_map(fn($r) => (float)$r['market_price'], $rows);
    $dates   = array_map(fn($r) => date('M d', strtotime($r['price_date'])), $rows);
    $first   = $prices[0] ?? 0;
    $last    = end($prices) ?: 0;
    $chg     = $first > 0 ? round((($last - $first) / $first) * 100, 1) : 0;
    $latest  = end($rows);
    $commodities[] = [
        'name'      => $name,
        'cat'       => $latest['category'],
        'curr'      => round($last, 2),
        'suggested' => round((float)$latest['suggested_price'], 2),
        'chg'       => $chg,
        'prices'    => $prices,
        'dates'     => $dates,
        'location'  => $latest['location'],
        'unit'      => $latest['unit'] ?? 'kg',
    ];
}

$catAvg = [];
foreach ($commodities as $c) { $catAvg[$c['cat']][] = $c['curr']; }
$catLabels = array_keys($catAvg);
$catData   = array_map(fn($arr) => round(array_sum($arr) / count($arr), 2), $catAvg);

$totalCommodities = count($commodities);
$totalCategories  = count($catLabels);
$risingCount      = count(array_filter($commodities, fn($c) => $c['chg'] > 0));
$fallingCount     = count(array_filter($commodities, fn($c) => $c['chg'] < 0));
?>
<style>
.ticker-bar{display:flex;overflow:hidden;border:1px solid var(--border);border-radius:var(--radius-lg);margin-bottom:1.25rem;position:relative;}
.ticker-wrap{display:flex;overflow:hidden;flex:1;}
.ticker-inner{display:flex;transition:transform .3s ease;}
.tick{flex:0 0 150px;padding:.75rem 1rem;cursor:pointer;border-right:1px solid var(--border);transition:background .15s;}
.tick:hover,.tick.sel{background:var(--pale-green);}
.tick.sel{border-bottom:3px solid var(--primary);}
.tick-name{font-size:.65rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.tick-price{font-size:1rem;font-weight:800;color:var(--text);margin:2px 0;}
.tick-chg{font-size:.7rem;font-weight:700;}
.ticker-btn{width:32px;flex-shrink:0;background:white;border:none;border-left:1px solid var(--border);cursor:pointer;font-size:.9rem;color:var(--text-muted);transition:background .15s;display:flex;align-items:center;justify-content:center;}
.ticker-btn:first-child{border-left:none;border-right:1px solid var(--border);}
.ticker-btn:hover{background:var(--pale-green);color:var(--primary);}
.up{color:#16a34a;}.dn{color:#dc2626;}.neu{color:var(--text-muted);}
.metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:1.25rem;}
.mcard{background:white;border:1px solid var(--border);border-radius:var(--radius-md);padding:.85rem 1rem;box-shadow:var(--shadow-sm);}
.mcard-label{font-size:.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;}
.mcard-val{font-size:1.4rem;font-weight:800;color:var(--text);}
.mcard-sub{font-size:.7rem;font-weight:700;margin-top:2px;}
.charts-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:1.25rem;}

.add-form{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.1rem 1.25rem;margin-bottom:1.25rem;box-shadow:var(--shadow-sm);}
.add-form h6{font-weight:800;font-size:.9rem;margin-bottom:1rem;}
.form-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
.form-grid input,.form-grid select{font-size:.82rem;padding:7px 10px;border:1px solid var(--border);border-radius:var(--radius-md);outline:none;font-family:'Nunito',sans-serif;width:100%;}
.form-grid input:focus,.form-grid select:focus{border-color:var(--primary);}
.gl-table table{font-size:.82rem;width:100%;}
.badge{font-size:.68rem;padding:2px 8px;border-radius:99px;font-weight:700;}
.badge-buy{background:#dbeafe;color:#1d4ed8;}.badge-dn{background:#fee2e2;color:#dc2626;}.badge-neu{background:#f3f4f6;color:#6b7280;}
@media(max-width:992px){.metrics{grid-template-columns:repeat(2,1fr);}.charts-row{grid-template-columns:1fr;}.form-grid{grid-template-columns:1fr 1fr;}}
@media(max-width:576px){.metrics{grid-template-columns:1fr 1fr;}.form-grid{grid-template-columns:1fr;}}
</style>

<div style="background:var(--bg);min-height:100vh;padding-bottom:3rem;">

    <!-- Page Header -->
    <div class="page-header">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h1><i class="fa-solid fa-chart-line text-green me-2"></i>Farm Market Prices</h1>
                    <div class="page-breadcrumb">Admin — <strong>AI Commodity Market Tracker</strong> 📊</div>
                </div>
                <div>
                    <button onclick="syncPSA()" class="btn-green" style="padding:.45rem 1rem;font-size:.82rem;margin-left:.5rem;" id="syncBtn"><i class="fa-solid fa-rotate"></i> Sync PSA Prices</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container" style="padding-top:1.5rem;">
        <div class="col-12">

            <?php if (!empty($commodities)): ?>

            <!-- Ticker -->
            <div class="ticker-bar">
                <button class="ticker-btn" onclick="tickerScroll(-1)"><i class="fa-solid fa-chevron-left"></i></button>
                <div class="ticker-wrap">
                    <div class="ticker-inner" id="tickerInner">
                        <?php foreach ($commodities as $i => $c): ?>
                        <div class="tick <?= $i===0?'sel':'' ?>" onclick="selectCommodity(<?= $i ?>)">
                            <div class="tick-name"><?= htmlspecialchars($c['name']) ?></div>
                            <div class="tick-price">₱<?= number_format($c['curr'],2) ?></div>
                            <div class="tick-chg <?= $c['chg']>0?'up':($c['chg']<0?'dn':'neu') ?>"><?= $c['chg']>=0?'+':'' ?><?= $c['chg'] ?>%</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button class="ticker-btn" onclick="tickerScroll(1)"><i class="fa-solid fa-chevron-right"></i></button>
            </div>

            <!-- Metrics -->
            <div class="metrics" id="metricsRow"></div>

            <!-- Charts -->
            <div class="charts-row">
                <div class="gl-card"><div class="gl-card-body">
                    <h6 style="font-weight:800;font-size:.88rem;margin-bottom:.75rem;"><i class="fa-solid fa-chart-line text-green me-2"></i>Price Trend — <span id="trendLabel" style="color:var(--primary);"></span></h6>
                    <div style="position:relative;height:200px;"><canvas id="trendChart"></canvas></div>
                </div></div>
                <div class="gl-card"><div class="gl-card-body">
                    <h6 style="font-weight:800;font-size:.88rem;margin-bottom:.75rem;"><i class="fa-solid fa-chart-bar text-green me-2"></i>Avg Price by Category</h6>
                    <div style="position:relative;height:200px;"><canvas id="catChart"></canvas></div>
                </div></div>
            </div>

         

            <!-- Table -->
            <div class="mb-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
    <h5 style="font-weight:800;margin:0;"><i class="fa-solid fa-table text-green me-2"></i>Market Overview</h5>
    <div style="position:relative;width:260px;">
        <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.8rem;pointer-events:none;"></i>
<input id="searchInput" type="text" placeholder="Search in Tagalog, Bisaya, or English..."
    style="font-size:.82rem;padding:7px 12px 7px 30px;border:1px solid var(--border);border-radius:var(--radius-md);outline:none;font-family:'Nunito',sans-serif;width:100%;transition:border-color .15s;"
    onfocus="this.style.borderColor='var(--primary)'"
    onblur="this.style.borderColor='var(--border)'"></div>
</div>
<div id="searchMeta" style="font-size:.75rem;color:var(--text-muted);margin-bottom:.5rem;min-height:1rem;"></div>
                <div class="gl-table">
                    <table style="width:100%;"><thead><tr>
                        <th>Commodity</th><th>Category</th><th>Market ₱/kg</th>
                        <th>Suggested ₱/kg</th><th>Margin</th><th>7d Change</th>
                        <th>Signal</th><th>Location</th>
                    </tr></thead>
                    <tbody id="overviewBody"></tbody></table>
                </div>
            </div>

            <?php else: ?>
            <div class="gl-card text-center" style="padding:3rem;">
                <div style="font-size:3rem;margin-bottom:1rem;">📊</div>
                <h5 style="font-weight:800;">No market price data yet</h5>
                <p style="color:var(--text-muted);font-size:.88rem;">Add your first commodity price below.</p>
            </div>
            <?php endif; ?>

            <!-- Add Form -->
            <div class="add-form">
                <h6><i class="fa-solid fa-plus-circle text-green me-2"></i>Add / Update Commodity Price</h6>
                <form method="POST" action="<?= BASE_URL ?>/admin/marketprices_save.php">
                    <div class="form-grid">
                        <div><label style="font-size:.75rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Product Name</label><input type="text" name="product_name" placeholder="e.g. Pechay" required></div>
                        <div><label style="font-size:.75rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Category</label>
                            <select name="category" required><option value="">Select category</option><option>Vegetables</option><option>Fruits</option><option>Grains</option><option>Coffee</option><option>Livestock</option><option>Seafood</option><option>Others</option></select></div>
                        <div><label style="font-size:.75rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Market Price (₱/kg)</label><input type="number" name="market_price" step="0.01" placeholder="0.00" required></div>
                        <div><label style="font-size:.75rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Suggested Price (₱/kg)</label><input type="number" name="suggested_price" step="0.01" placeholder="0.00" required></div>
                        <div><label style="font-size:.75rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Location</label><input type="text" name="location" placeholder="e.g. Davao City" required></div>
                        <div><label style="font-size:.75rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Price Date</label><input type="date" name="price_date" value="<?= date('Y-m-d') ?>" required></div>
                    </div>
                    <div style="margin-top:1rem;"><button type="submit" class="btn-green" style="font-size:.85rem;padding:.5rem 1.5rem;"><i class="fa-solid fa-floppy-disk"></i> Save Price</button></div>
                </form>
            </div>

        </div><!-- end col-12 -->
    </div><!-- end container -->
</div><!-- end page wrapper -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const ALIASES = {
  "Palay [Paddy] Other Variety, dry (conv. to 14% mc)": { tl: ["palay","bigas"], ceb: ["humay","bugas"] },
  "Corngrain [Maize] White, matured": { tl: ["mais","puting mais"], ceb: ["mais"] },
  "Corngrain [Maize] Yellow, matured": { tl: ["mais","dilaw na mais"], ceb: ["mais"] },
  "White Corn":   { tl: ["puting mais","mais"], ceb: ["mais"] },
  "Sweet Corn":   { tl: ["matamis na mais","mais"], ceb: ["mais"] },
  "Ampalaya [Bitter gourd]":       { tl: ["ampalaya","amplaya"],      ceb: ["ampalaya","paiat"] },
  "Ampalaya [Bitter gourd] leaves":{ tl: ["dahon ng ampalaya"],       ceb: ["dahon ampalaya"] },
  "Eggplant long, purple":         { tl: ["talong"],                   ceb: ["talong"] },
  "Cabbage":                       { tl: ["repolyo"],                  ceb: ["repolyo"] },
  "Pechay":                        { tl: ["pechay","petsay"],          ceb: ["petsay"] },
  "Pechay native":                 { tl: ["pechay","petsay"],          ceb: ["petsay"] },
  "Pechay Baguio [Chinese cabbage]":{ tl: ["pechay baguio","repolyo"], ceb: ["petsay"] },
  "Kangkong [Morning glory]":      { tl: ["kangkong","tangkong"],      ceb: ["kangkong"] },
  "Camote [Sweet potato] tops":    { tl: ["kamote","talbos ng kamote"],ceb: ["camote","kamote"] },
  "Gabi [Dasheen] leaves":         { tl: ["gabi","dahon ng gabi"],     ceb: ["gabi"] },
  "Squash":                        { tl: ["kalabasa"],                  ceb: ["kalabasa"] },
  "Squash tops":                   { tl: ["talbos ng kalabasa","kalabasa"], ceb: ["kalabasa"] },
  "String Beans":                  { tl: ["sitaw","bataw"],             ceb: ["sitaw"] },
  "Stringbeans":                   { tl: ["sitaw","bataw"],             ceb: ["sitaw"] },
  "Okra":                          { tl: ["okra"],                      ceb: ["okra"] },
  "Tomato":                        { tl: ["kamatis"],                   ceb: ["kamatis"] },
  "Cucumber":                      { tl: ["pipino"],                    ceb: ["pipino"] },
  "Chayote":                       { tl: ["sayote"],                    ceb: ["sayote"] },
  "Chayote tops":                  { tl: ["talbos ng sayote","sayote"], ceb: ["sayote"] },
  "Upo [Bottle gourd]":            { tl: ["upo"],                       ceb: ["upo"] },
  "Patola [Dishrag gourd], native":{ tl: ["patola"],                    ceb: ["patola"] },
  "Habitchuelas [Snap beans]":     { tl: ["habichuelas","sitaw"],       ceb: ["sitaw"] },
  "Sigarillas [Winged beans]":     { tl: ["sigarilyas"],                ceb: ["sigarilyas"] },
  "Mustard":                       { tl: ["mustasa"],                   ceb: ["mustasa"] },
  "Saluyot [Jews mallows]":        { tl: ["saluyot"],                   ceb: ["saluyot"] },
  "Malunggay [Horseradish] leaves":{ tl: ["malunggay"],                 ceb: ["malunggay"] },
  "Alugbati":                      { tl: ["alugbati"],                  ceb: ["alugbati"] },
  "Sili [Chili pepper] leaves":    { tl: ["dahon ng sili","sili"],      ceb: ["sili"] },
  "Pepper finger, green":          { tl: ["sili","pangsigang"],         ceb: ["sili"] },
  "Pepper hot, red labuyo [Chili pepper]": { tl: ["labuyo","sili"],    ceb: ["labuyo","sili"] },
  "Pepper Bell, red and green":    { tl: ["kampanilya","bell pepper"],  ceb: ["kampanilya"] },
  "Pepper Black":                  { tl: ["paminta"],                   ceb: ["paminta"] },
  "Cauliflower":                   { tl: ["cauliflower","koliplor"],     ceb: ["koliplor"] },
  "Onion Leeks":                   { tl: ["sibuyas","dahon ng sibuyas"],ceb: ["sibuyas"] },
  "Ginger Hawaiian":               { tl: ["luya"],                      ceb: ["luya"] },
  "Ginger native":                 { tl: ["luya"],                      ceb: ["luya"] },
  "Mongo [Mungbean] green, labo":  { tl: ["monggo","munggo"],           ceb: ["monggo"] },
  "Soybeans":                      { tl: ["soya","utaw"],               ceb: ["utaw"] },
  "Peanut with shell, dry":        { tl: ["mani"],                      ceb: ["mani"] },
  "Patani [Lima beans]":           { tl: ["patani"],                    ceb: ["patani"] },
  "Kadios [Pigeon pea], green":    { tl: ["kadyos"],                    ceb: ["kadyos"] },
  "Yambean, pods":                 { tl: ["singkamas"],                  ceb: ["singkamas"] },
  "Banana (Lakatan)":              { tl: ["saging","lakatan"],          ceb: ["saging","lakatan"] },
  "Banana Lakatan, green":         { tl: ["saging lakatan","saging"],   ceb: ["saging"] },
  "Banana Latundan, green":        { tl: ["saging latundan","saging"],  ceb: ["saging"] },
  "Banana Saba, green":            { tl: ["saging saba","saging"],      ceb: ["saging"] },
  "Banana Cavendish":              { tl: ["saging","cavendish"],        ceb: ["saging"] },
  "Banana, Other Variety":         { tl: ["saging"],                    ceb: ["saging"] },
  "Banana Blossom":                { tl: ["puso ng saging"],            ceb: ["puso saging"] },
  "Mango Carabao, green":          { tl: ["mangga","carabao"],          ceb: ["mangga"] },
  "Mango Indian, green":           { tl: ["mangga indian","mangga"],    ceb: ["mangga"] },
  "Mango Piko, green":             { tl: ["mangga piko","mangga"],      ceb: ["mangga"] },
  "Mango, Other Variety":          { tl: ["mangga"],                    ceb: ["mangga"] },
  "Carabao Mango":                 { tl: ["mangga","carabao"],          ceb: ["mangga"] },
  "Pineapple Hawaiian":            { tl: ["pinya"],                     ceb: ["pinya"] },
  "Pineapple native":              { tl: ["pinya"],                     ceb: ["pinya"] },
  "Papaya Hawaiian":               { tl: ["papaya"],                    ceb: ["papaya","kapaya"] },
  "Papaya native":                 { tl: ["papaya"],                    ceb: ["papaya","kapaya"] },
  "Papaya Solo":                   { tl: ["papaya"],                    ceb: ["papaya","kapaya"] },
  "Papaya, green":                 { tl: ["papaya","hilaw na papaya"],  ceb: ["papaya"] },
  "Durian":                        { tl: ["durian"],                    ceb: ["durian"] },
  "Jackfruit, green":              { tl: ["langka","nangka"],           ceb: ["nangka"] },
  "Jackfruit, ripe":               { tl: ["langka","nangka"],           ceb: ["nangka"] },
  "Watermelon":                    { tl: ["pakwan"],                    ceb: ["pakwan"] },
  "Guava":                         { tl: ["bayabas"],                   ceb: ["bayabas"] },
  "Guayabano [Soursop]":           { tl: ["guyabano"],                  ceb: ["guyabano"] },
  "Avocado":                       { tl: ["abokado","avocado"],         ceb: ["abokado"] },
  "Calamansi":                     { tl: ["kalamansi"],                 ceb: ["kalamansi"] },
  "Lanzones":                      { tl: ["lanzones"],                  ceb: ["lanzones"] },
  "Rambutan":                      { tl: ["rambutan"],                  ceb: ["rambutan"] },
  "Mangosteen":                    { tl: ["mangosteen","manggustan"],   ceb: ["manggustan"] },
  "Pomelo":                        { tl: ["suha"],                      ceb: ["suha"] },
  "Mandarin Ladu":                 { tl: ["dalandan","mandarin"],       ceb: ["dalandan"] },
  "Orange":                        { tl: ["dalandan","orange"],         ceb: ["dalandan"] },
  "Lemon":                         { tl: ["limon","lemon"],             ceb: ["limon"] },
  "Lime":                          { tl: ["dayap"],                     ceb: ["dayap"] },
  "Tamarind":                      { tl: ["sampalok"],                  ceb: ["sampalok"] },
  "Starapple":                     { tl: ["kaimito"],                   ceb: ["kaimito"] },
  "Santol native":                 { tl: ["santol"],                    ceb: ["santol"] },
  "Chico [Sapota]":                { tl: ["chiko","tsiko"],             ceb: ["tsiko"] },
  "Atis [Sugarapple]":             { tl: ["atis"],                      ceb: ["atis"] },
  "Canistel":                      { tl: ["tiesa"],                     ceb: ["tiesa"] },
  "Balimbing":                     { tl: ["balimbing"],                 ceb: ["balimbing"] },
  "Breadfruit":                    { tl: ["rimas"],                     ceb: ["rimas"] },
  "Kamansi [Seeded breadfruit]":   { tl: ["kamansi","rimas"],           ceb: ["kamansi"] },
  "Guapple":                       { tl: ["guapple","bayabas"],         ceb: ["bayabas"] },
  "Aratelis (Manzanita)":          { tl: ["aratelis"],                  ceb: ["aratelis"] },
  "Arabica Coffee":                { tl: ["kape","arabika"],            ceb: ["kape"] },
  // Livestock
  "Beef":                          { tl: ["baka","karne ng baka"],      ceb: ["baka","karne baka"] },
  "Beef, whole":                   { tl: ["baka"],                      ceb: ["baka"] },
  "Beef, bone-in":                 { tl: ["baka","buto-buto"],          ceb: ["baka"] },
  "Beef, boneless":                { tl: ["baka","walang buto"],        ceb: ["baka"] },
  "Pork":                          { tl: ["baboy","karne ng baboy"],    ceb: ["baboy","karne baboy"] },
  "Pork, whole":                   { tl: ["baboy","liempo"],            ceb: ["baboy"] },
  "Pork Liempo":                   { tl: ["liempo","baboy"],            ceb: ["liempo","baboy"] },
  "Pork Kasim [Shoulder]":         { tl: ["kasim","baboy"],             ceb: ["baboy","kasim"] },
  "Chicken":                       { tl: ["manok","karne ng manok"],    ceb: ["manok","karne manok"] },
  "Chicken, whole":                { tl: ["manok"],                     ceb: ["manok"] },
  "Chicken Breast":                { tl: ["dibdib ng manok","manok"],   ceb: ["dughan manok","manok"] },
  "Native Chicken":                { tl: ["native na manok","manok bisaya"], ceb: ["manok bisaya"] },
  "Eggs, chicken":                 { tl: ["itlog ng manok","itlog"],    ceb: ["itlog manok","itlog"] },
  "Eggs, duck":                    { tl: ["itlog ng pato","itlog"],     ceb: ["itlog pato","itlog"] },
  "Goat":                          { tl: ["kambing","karne ng kambing"],ceb: ["kanding","karne kanding"] },
  "Carabao [Buffalo] meat":        { tl: ["kalabaw"],                   ceb: ["kalabaw"] },
  // Seafood
  "Bangus [Milkfish]":             { tl: ["bangus"],                    ceb: ["bangus"] },
  "Bangus":                        { tl: ["bangus"],                    ceb: ["bangus"] },
  "Tilapia":                       { tl: ["tilapya","isda"],            ceb: ["tilapya","isda"] },
  "Galunggong [Scad]":             { tl: ["galunggong","isda"],         ceb: ["galunggong","isda"] },
  "Tambakol [Yellowfin tuna]":     { tl: ["tambakol","tuna","isda"],    ceb: ["tambakol","tuna"] },
  "Yellowfin Tuna":                { tl: ["tuna","tambakol"],           ceb: ["tuna","tambakol"] },
  "Maya-maya [Red snapper]":       { tl: ["maya-maya","isda"],          ceb: ["maya-maya","isda"] },
  "Lapu-lapu [Grouper]":           { tl: ["lapu-lapu","isda"],          ceb: ["lapu-lapu","isda"] },
  "Crab":                          { tl: ["alimango","alimasag"],       ceb: ["alimango","alimasag"] },
  "Mud Crab":                      { tl: ["alimango"],                  ceb: ["alimango"] },
  "Shrimp":                        { tl: ["hipon"],                     ceb: ["hipon"] },
  "Sugpo [Tiger prawn]":           { tl: ["sugpo","hipon"],             ceb: ["sugpo","hipon"] },
  "Squid":                         { tl: ["pusit"],                     ceb: ["pusit"] },
  "Mussel [Tahong]":               { tl: ["tahong"],                    ceb: ["tahong"] },
  "Oyster [Talaba]":               { tl: ["talaba"],                    ceb: ["talaba"] },
  "Tuyo [Dried herring]":          { tl: ["tuyo","daing"],              ceb: ["bulad","tuyo"] },
  "Danggit [Dried rabbitfish]":    { tl: ["danggit","daing"],           ceb: ["danggit","bulad"] },
};

// Pre-build flat alias lookup
const ALIAS_FLAT = {};
for (const [key, val] of Object.entries(ALIASES)) {
  ALIAS_FLAT[key.toLowerCase()] = [...(val.tl||[]), ...(val.ceb||[])];
}

const mkt       = <?= json_encode(array_values($commodities)) ?>;
const catLabels = <?= json_encode($catLabels) ?>;
const catData   = <?= json_encode(array_values($catData)) ?>;

let selected = 0, trendInst = null, catInst = null;

// ── Search ──────────────────────────────────────────────────────────────────
function matchesAlias(productName, query) {
    if (query.length < 2) return false;
    const pLower = productName.toLowerCase();
    for (const [key, aliases] of Object.entries(ALIAS_FLAT)) {
        const keyFirst = key.split(/[\s,\[]/)[0];
        const pFirst   = pLower.split(/[\s,\[]/)[0];
        const related  = pLower === key || pLower.startsWith(keyFirst) ||
                         key.startsWith(pFirst) || pLower.includes(key) || key.includes(pLower);
        if (!related) continue;
        if (aliases.some(a => a.includes(query) || query.includes(a))) return true;
    }
    return false;
}

function renderTable(filter = '') {
    const q = filter.toLowerCase().trim();
    const rows = mkt.filter(d =>
        !q || d.name.toLowerCase().includes(q) || d.cat.toLowerCase().includes(q) ||
        d.location.toLowerCase().includes(q) || matchesAlias(d.name, q)
    );
    const meta = document.getElementById('searchMeta');
    meta.textContent = q ? `${rows.length} result${rows.length!==1?'s':''} for "${filter}"` : `Showing all ${mkt.length} commodities`;
    const highlight = (text, q) => {
        if (!q) return text;
        const idx = text.toLowerCase().indexOf(q);
        if (idx === -1) return text;
        return text.slice(0,idx) + `<mark style="background:#fef08a;border-radius:2px;padding:0 2px;">${text.slice(idx,idx+q.length)}</mark>` + text.slice(idx+q.length);
    };
    document.getElementById('overviewBody').innerHTML = rows.map(d => {
        const margin = d.curr > 0 ? ((d.suggested-d.curr)/d.curr*100).toFixed(1) : 0;
        const sig = d.chg > 2 ? '<span class="badge badge-buy">Buy signal</span>' : d.chg < -2 ? '<span class="badge badge-dn">Caution</span>' : '<span class="badge badge-neu">Hold</span>';
        const aliasHint = q && !d.name.toLowerCase().includes(q) && matchesAlias(d.name, q)
            ? ` <span style="font-size:.68rem;color:var(--primary);font-weight:700;background:var(--pale-green);padding:1px 6px;border-radius:99px;">${q}</span>` : '';
        return `<tr>
            <td><strong>${highlight(d.name,q)}</strong>${aliasHint}</td>
            <td style="color:var(--text-muted);">${highlight(d.cat,q)}</td>
            <td><strong style="color:var(--primary);">₱${d.curr.toFixed(2)}</strong></td>
            <td>₱${d.suggested.toFixed(2)}</td>
            <td class="up">+${margin}%</td>
            <td class="${d.chg>0?'up':d.chg<0?'dn':'neu'}">${d.chg>=0?'+':''}${d.chg}%</td>
            <td>${sig}</td>
            <td style="font-size:.78rem;color:var(--text-muted);">${d.location}</td>
        </tr>`;
    }).join('') || `<tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted);">
        <i class="fa-solid fa-search" style="font-size:1.5rem;margin-bottom:.5rem;display:block;opacity:.3;"></i>
        No commodities found for "<strong>${filter}</strong>"</td></tr>`;
}
window.filterTable = val => renderTable(val);

// ── Charts & Metrics ────────────────────────────────────────────────────────
window.selectCommodity = function(i) {
    selected = i;
    document.querySelectorAll('.tick').forEach((el,idx) => el.classList.toggle('sel', idx===i));
    renderMetrics(); renderTrend();
};

function renderMetrics() {
    const d = mkt[selected]; if (!d) return;
    const hi = Math.max(...d.prices).toFixed(2), lo = Math.min(...d.prices).toFixed(2);
    const margin = d.curr > 0 ? ((d.suggested-d.curr)/d.curr*100).toFixed(1) : 0;
    const cc = d.chg >= 0 ? '#16a34a' : '#dc2626';
    document.getElementById('metricsRow').innerHTML = `
        <div class="mcard"><div class="mcard-label">Current Price</div><div class="mcard-val">₱${d.curr.toFixed(2)}</div><div class="mcard-sub" style="color:${cc}">${d.chg>=0?'▲':'▼'} ${Math.abs(d.chg)}% (7d)</div></div>
        <div class="mcard"><div class="mcard-label">7d High</div><div class="mcard-val">₱${hi}</div><div class="mcard-sub neu">Range peak</div></div>
        <div class="mcard"><div class="mcard-label">7d Low</div><div class="mcard-val">₱${lo}</div><div class="mcard-sub neu">Range floor</div></div>
        <div class="mcard"><div class="mcard-label">Suggested Margin</div><div class="mcard-val">+${margin}%</div><div class="mcard-sub up">₱${d.suggested.toFixed(2)}/kg</div></div>`;
}

function renderTrend() {
    const d = mkt[selected]; if (!d) return;
    document.getElementById('trendLabel').textContent = d.name;
    const up = d.chg>=0, col = up?'#16a34a':'#dc2626', fill = up?'rgba(22,163,74,0.08)':'rgba(220,38,38,0.08)';
    if (trendInst) trendInst.destroy();
    trendInst = new Chart(document.getElementById('trendChart'), {
        type:'line', data:{labels:d.dates.length?d.dates:['No data'],datasets:[{data:d.prices,borderColor:col,backgroundColor:fill,borderWidth:2.5,pointBackgroundColor:col,pointRadius:4,tension:0.4,fill:true}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>'₱'+c.parsed.y.toFixed(2)+'/kg'}}},scales:{x:{grid:{display:false},ticks:{font:{size:10}}},y:{grid:{color:'rgba(0,0,0,0.05)'},ticks:{font:{size:10},callback:v=>'₱'+v.toFixed(0)}}}}
    });
}

function renderCatChart() {
    const colors = ['#3E7C3F','#639922','#185FA5','#BA7517','#993556','#0F6E56','#5F5E5A'];
    if (catInst) catInst.destroy();
    catInst = new Chart(document.getElementById('catChart'), {
        type:'bar', data:{labels:catLabels,datasets:[{data:catData,backgroundColor:colors.slice(0,catLabels.length),borderRadius:5,borderSkipped:false}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>'Avg ₱'+c.parsed.y.toFixed(2)}}},scales:{x:{grid:{display:false},ticks:{font:{size:10}}},y:{grid:{color:'rgba(0,0,0,0.05)'},ticks:{font:{size:10},callback:v=>'₱'+v}}}}
    });
}

// ── Ticker ───────────────────────────────────────────────────────────────────
let tickerOffset = 0, tickerTimer = null;
function tickerScroll(dir) {
    const inner = document.getElementById('tickerInner');
    const itemW = 150, visible = Math.floor(inner.parentElement.offsetWidth/itemW), total = inner.children.length;
    const maxOffset = Math.max(0, total-visible);
    tickerOffset = Math.max(0, Math.min(tickerOffset+dir, maxOffset));
    inner.style.transform = `translateX(-${tickerOffset*itemW}px)`;
}
function startAutoTicker() {
    tickerTimer = setInterval(() => {
        const inner = document.getElementById('tickerInner');
        const itemW = 150, visible = Math.floor(inner.parentElement.offsetWidth/itemW), total = inner.children.length;
        const maxOffset = Math.max(0, total-visible);
        if (tickerOffset >= maxOffset) {
            tickerOffset = 0;
        } else {
            tickerOffset++;
        }
        inner.style.transform = `translateX(-${tickerOffset*itemW}px)`;

        // Auto-select the first visible commodity and update metrics/chart
        const firstVisible = Math.min(tickerOffset, mkt.length - 1);
        selected = firstVisible;
        document.querySelectorAll('.tick').forEach((el, idx) => el.classList.toggle('sel', idx === firstVisible));
        renderMetrics();
        renderTrend();
    }, 800);
}
function stopAutoTicker() { clearInterval(tickerTimer); }



async function syncPSA() {
    const btn = document.getElementById('syncBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Syncing PSA...';
    try {
        const res  = await fetch('<?= BASE_URL ?>/admin/psa_sync.php');
        const raw  = await res.text();
        console.log('PSA response:', raw);
        const data = JSON.parse(raw);
        if (data.errors?.length) {
            showSyncModal('warning', data);
        } else {
            showSyncModal('success', data);
        }
    } catch(e) {
        showSyncModal('error', null, e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-rotate"></i> Sync PSA Prices';
    }
}

function showSyncModal(type, data, errorMsg = '') {
    const modal = document.getElementById('syncModal');
    const icon  = document.getElementById('syncModalIcon');
    const title = document.getElementById('syncModalTitle');
    const body  = document.getElementById('syncModalBody');
    const reload= document.getElementById('syncModalReload');

    if (type === 'success' || type === 'warning') {
        const isWarn = type === 'warning';
        icon.innerHTML = isWarn
            ? '<i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;font-size:2.5rem;"></i>'
            : '<i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:2.5rem;"></i>';
        title.textContent = isWarn ? 'Sync Completed with Errors' : 'PSA Sync Complete!';

        const changes = data.price_changes ?? [];
        const changesHTML = changes.length > 0 ? `
            <div style="margin-top:1rem;">
                <div style="font-size:.73rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;">
                    <i class="fa-solid fa-tags" style="color:var(--primary);margin-right:4px;"></i>Price Changes Applied to Products
                </div>
                <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden;">
                    <div style="max-height:220px;overflow-y:auto;">
                        <table style="width:100%;border-collapse:collapse;font-size:.78rem;">
                            <thead>
                                <tr style="background:var(--pale-green);position:sticky;top:0;z-index:1;">
                                    <th style="padding:.5rem .75rem;text-align:left;font-weight:800;color:var(--text);border-bottom:1px solid var(--border);">Product</th>
                                    <th style="padding:.5rem .75rem;text-align:right;font-weight:800;color:var(--text);border-bottom:1px solid var(--border);">Old ₱</th>
                                    <th style="padding:.5rem .75rem;text-align:right;font-weight:800;color:var(--text);border-bottom:1px solid var(--border);">New ₱</th>
                                    <th style="padding:.5rem .75rem;text-align:center;font-weight:800;color:var(--text);border-bottom:1px solid var(--border);">Change</th>
                                    <th style="padding:.5rem .75rem;text-align:left;font-weight:800;color:var(--text);border-bottom:1px solid var(--border);">Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${changes.map((c, i) => {
                                    const diff  = c.new_price - c.old_price;
                                    const pct   = c.old_price > 0 ? Math.abs((diff / c.old_price) * 100).toFixed(1) : 0;
                                    const up    = diff >= 0;
                                    const color = up ? '#16a34a' : '#dc2626';
                                    const bg    = i % 2 === 0 ? 'white' : '#fafafa';
                                    return `<tr style="background:${bg};">
                                        <td style="padding:.5rem .75rem;font-weight:700;color:var(--text);border-bottom:1px solid var(--border);">${c.product_name}</td>
                                        <td style="padding:.5rem .75rem;text-align:right;color:var(--text-muted);border-bottom:1px solid var(--border);">₱${parseFloat(c.old_price).toFixed(2)}</td>
                                        <td style="padding:.5rem .75rem;text-align:right;font-weight:800;color:${color};border-bottom:1px solid var(--border);">₱${parseFloat(c.new_price).toFixed(2)}</td>
                                        <td style="padding:.5rem .75rem;text-align:center;border-bottom:1px solid var(--border);">
                                            <span style="background:${up?'#dcfce7':'#fee2e2'};color:${color};font-size:.68rem;font-weight:800;padding:2px 8px;border-radius:99px;white-space:nowrap;">
                                                ${up?'▲':'▼'} ${pct}%
                                            </span>
                                        </td>
                                        <td style="padding:.5rem .75rem;font-size:.73rem;color:var(--text-muted);border-bottom:1px solid var(--border);">${c.reason ?? '—'}</td>
                                    </tr>`;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>` : `
            <div style="margin-top:1rem;background:#f3f4f6;border-radius:10px;padding:.85rem;text-align:center;font-size:.82rem;color:var(--text-muted);">
                <i class="fa-solid fa-circle-check" style="color:#9ca3af;margin-right:5px;"></i>No product prices were adjusted this sync.
            </div>`;

        body.innerHTML = `
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;">
                <div style="background:#dcfce7;border-radius:10px;padding:.65rem;text-align:center;">
                    <div style="font-size:1.3rem;font-weight:800;color:#16a34a;">${data.inserted}</div>
                    <div style="font-size:.62rem;font-weight:800;color:#16a34a;text-transform:uppercase;">Inserted</div>
                </div>
                <div style="background:#dbeafe;border-radius:10px;padding:.65rem;text-align:center;">
                    <div style="font-size:1.3rem;font-weight:800;color:#1d4ed8;">${data.updated}</div>
                    <div style="font-size:.62rem;font-weight:800;color:#1d4ed8;text-transform:uppercase;">Updated</div>
                </div>
                <div style="background:#f3f4f6;border-radius:10px;padding:.65rem;text-align:center;">
                    <div style="font-size:1.3rem;font-weight:800;color:#6b7280;">${data.skipped}</div>
                    <div style="font-size:.62rem;font-weight:800;color:#6b7280;text-transform:uppercase;">Skipped</div>
                </div>
                <div style="background:#fef9c3;border-radius:10px;padding:.65rem;text-align:center;">
                    <div style="font-size:1.3rem;font-weight:800;color:#ca8a04;">${data.products_adjusted ?? 0}</div>
                    <div style="font-size:.62rem;font-weight:800;color:#ca8a04;text-transform:uppercase;">Adjusted</div>
                </div>
            </div>
            ${isWarn ? `
            <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:.75rem;margin-top:.75rem;max-height:100px;overflow-y:auto;">
                <div style="font-size:.7rem;font-weight:800;color:#ea580c;margin-bottom:.35rem;">Errors:</div>
                ${data.errors.map(e => `<div style="font-size:.73rem;color:#9a3412;padding:2px 0;border-bottom:1px solid #fed7aa;">${e}</div>`).join('')}
            </div>` : ''}
            ${changesHTML}`;

      reload.style.display = 'block';

        // Refresh category averages from updated data
        if (type === 'success' || type === 'warning') {
            fetch(location.href)
                .then(r => r.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const scripts = doc.querySelectorAll('script');
                    for (const s of scripts) {
                        const m = s.textContent.match(/const catLabels\s*=\s*(\[.*?\]);[\s\S]*?const catData\s*=\s*(\[.*?\]);/);
                        if (m) {
                            try {
                                const newLabels = JSON.parse(m[1]);
                                const newData   = JSON.parse(m[2]);
                                if (catInst) catInst.destroy();
                                catInst = new Chart(document.getElementById('catChart'), {
                                    type:'bar',
                                    data:{labels:newLabels,datasets:[{data:newData,backgroundColor:['#3E7C3F','#639922','#185FA5','#BA7517','#993556','#0F6E56','#5F5E5A'].slice(0,newLabels.length),borderRadius:5,borderSkipped:false}]},
                                    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>'Avg ₱'+c.parsed.y.toFixed(2)}}},scales:{x:{grid:{display:false},ticks:{font:{size:10}}},y:{grid:{color:'rgba(0,0,0,0.05)'},ticks:{font:{size:10},callback:v=>'₱'+v}}}}
                                });
                            } catch(e) { console.warn('Chart refresh failed', e); }
                            break;
                        }
                    }
                })
                .catch(() => {}); // silent fail — user can reload manually
        }
    } else {
        icon.innerHTML  = '<i class="fa-solid fa-circle-xmark" style="color:#dc2626;font-size:2.5rem;"></i>';
        title.textContent = 'Sync Failed';
        body.innerHTML  = `<div style="background:#fee2e2;border-radius:10px;padding:.75rem;font-size:.85rem;color:#991b1b;">${errorMsg}</div>`;
        reload.style.display = 'none';
    }

    modal.style.display = 'flex';
}

function closeSyncModal(doReload) {
    document.getElementById('syncModal').style.display = 'none';
    if (doReload) location.reload();
}


// ── Init ──────────────────────────────────────────────────────────────────────
if (mkt.length > 0) { renderMetrics(); renderTrend(); }
if (catLabels.length > 0) renderCatChart();
renderTable();

(function () {
    let _t = null;
    function wireSearch() {       
         const input = document.getElementById('searchInput');
        if (!input) return;
        input.addEventListener('input', function () {
            clearTimeout(_t);
            _t = setTimeout(() => renderTable(this.value), 120);
        });
        input.addEventListener('keydown', function (e) {
            if (['Backspace','Delete','Enter','Escape'].includes(e.key)) {
                clearTimeout(_t);
                if (e.key === 'Escape') { this.value = ''; }
                renderTable(this.value);
            }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wireSearch);
    else wireSearch();
})();

window.addEventListener('load', () => {
    const bar = document.querySelector('.ticker-wrap');
    if (bar) { bar.addEventListener('mouseenter', stopAutoTicker); bar.addEventListener('mouseleave', startAutoTicker); }
    startAutoTicker();
});
</script>

<!-- PSA Sync Result Modal -->
<div id="syncModal" onclick="if(event.target===this)closeSyncModal(false)"
     style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,.55);z-index:9999;cursor:default;">
    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;padding:1rem;">
        <div onclick="event.stopPropagation()" style="background:white;border-radius:20px;max-width:640px;width:100%;box-shadow:0 25px 60px rgba(0,0,0,.2);overflow:hidden;max-height:90vh;display:flex;flex-direction:column;">
            <div style="padding:1.25rem 1.5rem;text-align:center;border-bottom:1px solid var(--border);flex-shrink:0;">
                <div id="syncModalIcon" style="margin-bottom:.65rem;"></div>
                <div id="syncModalTitle" style="font-size:1.1rem;font-weight:800;color:var(--text);"></div>
            </div>
            <div style="padding:1.25rem;overflow-y:auto;flex:1;">
                <div id="syncModalBody"></div>
            </div>
            <div style="padding:0 1.25rem 1.25rem;display:flex;gap:8px;flex-shrink:0;">
                <button id="syncModalReload" onclick="closeSyncModal(true)"
                        style="flex:1;padding:.65rem;background:var(--primary);color:white;border:none;border-radius:10px;font-weight:700;font-size:.88rem;cursor:pointer;display:none;">
                    <i class="fa-solid fa-rotate-right me-1"></i> Reload Page
                </button>
                <button onclick="closeSyncModal(false)"
                        style="flex:1;padding:.65rem;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:10px;font-weight:700;font-size:.88rem;cursor:pointer;">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>