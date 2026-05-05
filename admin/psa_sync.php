<?php
ob_start();

ini_set('display_errors', 0);
error_reporting(0);
ob_start();
require_once __DIR__ . '/../includes/header.php';
ob_end_clean();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$pdo = getDBConnection();
$pdo->exec("DELETE FROM market_prices WHERE location = 'Region XI - Davao' AND price_date < '2025-01-01'");


$BASE_NFG = 'https://openstat.psa.gov.ph/PXWeb/api/v1/en/DB/2M/NFG/';
$BASE_RP  = 'https://openstat.psa.gov.ph/PXWeb/api/v1/en/DB/2M/RP/';

$datasets = [
    // ── Farmgate New Series (NFG) ─────────────────────────────────────────
    // Variables: [Geolocation, Commodity, Year, Period]
    ['url' => $BASE_NFG . '0032M4AFN01.px', 'category' => 'Grains',      'label' => 'Cereals',          'type' => 'NFG'],
    ['url' => $BASE_NFG . '0032M4AFN03.px', 'category' => 'Vegetables',  'label' => 'Beans & Legumes',  'type' => 'NFG'],
    ['url' => $BASE_NFG . '0032M4AFN04.px', 'category' => 'Vegetables',  'label' => 'Condiments',       'type' => 'NFG'],
    ['url' => $BASE_NFG . '0032M4AFN05.px', 'category' => 'Vegetables',  'label' => 'Fruit Vegetables', 'type' => 'NFG'],
    ['url' => $BASE_NFG . '0032M4AFN06.px', 'category' => 'Vegetables',  'label' => 'Leafy Vegetables', 'type' => 'NFG'],
    ['url' => $BASE_NFG . '0032M4AFN07.px', 'category' => 'Fruits',      'label' => 'Fruits',           'type' => 'NFG'],
    ['url' => $BASE_NFG . '0032M4AFN08.px', 'category' => 'Others',      'label' => 'Commercial Crops', 'type' => 'NFG'],

    // ── Retail Prices (RP) — for Livestock, Poultry, Fish ────────────────
    // Variables: [Geolocation, Commodity, Year, Period]  (same structure as NFG)
    ['url' => $BASE_RP  . '0042M4ARP09.px', 'category' => 'Livestock',   'label' => 'Livestock Retail', 'type' => 'NFG'],
    ['url' => $BASE_RP  . '0042M4ARP10.px', 'category' => 'Livestock',   'label' => 'Poultry Retail',   'type' => 'NFG'],
    ['url' => $BASE_RP  . '0042M4ARP11.px', 'category' => 'Seafood',     'label' => 'Fish Retail',      'type' => 'NFG'],
];

$results = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

foreach ($datasets as $ds) {

    // ── Step 1: GET metadata ──────────────────────────────────────────────
    $metaRaw = @file_get_contents($ds['url']);
    if ($metaRaw === false) {
        $results['errors'][] = "{$ds['label']}: Could not fetch metadata (network error)";
        continue;
    }
    $metaRaw = ltrim($metaRaw, "\xEF\xBB\xBF");
    $meta    = json_decode($metaRaw, true);

    if (empty($meta['variables'])) {
        $results['errors'][] = "{$ds['label']}: Invalid metadata response";
        continue;
    }

    // Detect variable positions dynamically by code name
    $varIndex = [];
    foreach ($meta['variables'] as $i => $v) {
        $varIndex[strtolower($v['code'])] = $i;
    }

    // We expect: geolocation (0), commodity (1), year (2), period (3)
    $geoVar  = $meta['variables'][$varIndex['geolocation'] ?? 0] ?? null;
    $yearVar = $meta['variables'][$varIndex['year']        ?? 2] ?? null;

    if (!$geoVar || !$yearVar) {
        $results['errors'][] = "{$ds['label']}: Unexpected variable structure";
        continue;
    }

    $commodityIdx_var = $varIndex['commodity'] ?? 1;
    $commodityMap     = $meta['variables'][$commodityIdx_var]['valueTexts'] ?? [];
    $yearMap          = $yearVar['valueTexts'] ?? [];
    $yearValues       = $yearVar['values']     ?? [];

    // ── Step 2: Find Region XI geo index ─────────────────────────────────
    $geoValues = $geoVar['values']     ?? [];
    $geoTexts  = $geoVar['valueTexts'] ?? [];
    $geoIndex  = null;
    foreach ($geoTexts as $idx => $text) {
        if (stripos($text, 'Region XI') !== false || stripos($text, 'Davao Region') !== false) {
            $geoIndex = $geoValues[$idx];
            break;
        }
    }
    if ($geoIndex === null) {
        // Fallback: try index position 82 (common in PSA datasets)
        $geoIndex = $geoValues[82] ?? $geoValues[0] ?? '0';
        $results['errors'][] = "{$ds['label']}: Region XI not found by name, using fallback index '{$geoIndex}'";
    }

    // ── Step 3: Find year indices for 2024 and 2025 ──────────────────────
    // Dynamically generate years from 2025 to current year + 1
$currentYear = (int)date('Y');
$targetYears = array_map('strval', range(2025, $currentYear + 1));
    $yearIndices = [];
    foreach ($yearMap as $idx => $text) {
        if (in_array($text, $targetYears)) {
            $yearIndices[] = $yearValues[$idx];
        }
    }
    if (empty($yearIndices)) {
        // Fallback: last 2 available years
        $yearIndices = array_slice($yearValues, -2);
    }

    // ── Step 4: POST data query ───────────────────────────────────────────
    $query = [
        'query' => [
            ['code' => 'Geolocation', 'selection' => ['filter' => 'item', 'values' => [$geoIndex]]],
            ['code' => 'Year',        'selection' => ['filter' => 'item', 'values' => $yearIndices]],
            ['code' => 'Period',      'selection' => ['filter' => 'item', 'values' => ['0','1','2','3','4','5','6','7','8','9','10','11']]],
        ],
        'response' => ['format' => 'json'],
    ];

    $ch = curl_init($ds['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($query),
    ]);
    $response = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $results['errors'][] = "{$ds['label']}: HTTP $httpCode";
        continue;
    }

    $response = ltrim($response, "\xEF\xBB\xBF");
    $data     = json_decode($response, true);
    $rows     = $data['data'] ?? [];

    if (empty($rows)) {
        $results['errors'][] = "{$ds['label']}: No data rows returned";
        continue;
    }

    // ── Step 5: Upsert into market_prices ────────────────────────────────
    // key order: [Geolocation, Commodity, Year, Period]
    $checkStmt  = $pdo->prepare("SELECT id FROM market_prices WHERE product_name = ? AND price_date = ? AND location = ?");
    $updateStmt = $pdo->prepare("UPDATE market_prices SET market_price=?, suggested_price=?, category=?, unit='kg' WHERE product_name=? AND price_date=? AND location=?");
    $insertStmt = $pdo->prepare("INSERT INTO market_prices (product_name, category, market_price, suggested_price, price_date, location, unit) VALUES (?,?,?,?,?,?,?)");

    foreach ($rows as $row) {
        $keys   = $row['key']    ?? [];
        $values = $row['values'] ?? [];

        // Keys correspond to the query variable order: Geolocation(0), Commodity(1), Year(2), Period(3)
        $commIdx   = (int)($keys[1] ?? -1);
        $yearIdx   = (int)($keys[2] ?? -1);
        $periodIdx = (int)($keys[3] ?? -1);
        $price     = isset($values[0]) && $values[0] !== '.' && $values[0] !== '' && $values[0] !== null
                     ? (float)$values[0] : null;

        if ($commIdx < 0 || $yearIdx < 0 || $periodIdx < 0 || !$price || $price <= 0) {
            $results['skipped']++;
            continue;
        }

        $commodity = $commodityMap[$commIdx] ?? null;
        $year      = $yearMap[$yearIdx]      ?? null;

        if (!$commodity || !$year) {
            $results['skipped']++;
            continue;
        }

        $priceDate = "$year-" . str_pad($periodIdx + 1, 2, '0', STR_PAD_LEFT) . "-01";
        $suggested = round($price * 1.15, 2);

        $checkStmt->execute([$commodity, $priceDate, 'Region XI - Davao']);
        if ($checkStmt->fetch()) {
            $updateStmt->execute([$price, $suggested, $ds['category'], $commodity, $priceDate, 'Region XI - Davao']);
            $results['updated']++;
        } else {
            $insertStmt->execute([$commodity, $ds['category'], $price, $suggested, $priceDate, 'Region XI - Davao', 'kg']);
            $results['inserted']++;
        }
    }
}

// ── Step 6: Auto-adjust product prices based on updated market prices ─────
$results['products_adjusted'] = 0;
$results['price_changes']     = [];

// Get latest market price per product name
$latestPrices = $pdo->query("
    SELECT product_name, category, market_price, suggested_price
    FROM market_prices mp
    WHERE price_date = (
        SELECT MAX(price_date) FROM market_prices mp2
        WHERE mp2.product_name = mp.product_name
    )
")->fetchAll();

// Fetch affected products BEFORE updating so we can record old prices
$fetchProductsStmt = $pdo->prepare("
    SELECT id, name, price_per_kg
    FROM products
    WHERE LOWER(TRIM(name)) LIKE LOWER(TRIM(?))
      AND is_available = 1
");
$fetchProductsFirstWord = $pdo->prepare("
    SELECT id, name, price_per_kg
    FROM products
    WHERE LOWER(name) LIKE LOWER(?)
      AND is_available = 1
");

$updateProductStmt = $pdo->prepare("
    UPDATE products
    SET price_per_kg = ?
    WHERE id = ?
");

foreach ($latestPrices as $mp) {
    $nameLike  = '%' . trim($mp['product_name']) . '%';
    $firstWord = explode(' ', trim($mp['product_name']))[0];
    $firstLike = $firstWord . '%';
    $newPrice  = (float)$mp['suggested_price'];

    // Fetch matching products (exact-ish match first)
    $fetchProductsStmt->execute([$nameLike]);
    $matchedProducts = $fetchProductsStmt->fetchAll();

    // Fallback: first word match
    if (empty($matchedProducts)) {
        $fetchProductsFirstWord->execute([$firstLike]);
        $matchedProducts = $fetchProductsFirstWord->fetchAll();
    }

    foreach ($matchedProducts as $product) {
        $oldPrice = (float)$product['price_per_kg'];

        // Only update and record if price actually changed
        if (abs($oldPrice - $newPrice) < 0.001) continue;

        $updateProductStmt->execute([$newPrice, $product['id']]);

        $diff = $newPrice - $oldPrice;
        $pct  = $oldPrice > 0 ? round(($diff / $oldPrice) * 100, 1) : 0;
        $dir  = $diff >= 0 ? 'up' : 'down';

        $results['price_changes'][] = [
            'product_name' => $product['name'],
            'old_price'    => $oldPrice,
            'new_price'    => $newPrice,
            'reason'       => "PSA {$mp['product_name']}: ₱" . number_format($oldPrice, 2)
                              . " → ₱" . number_format($newPrice, 2)
                              . " ({$dir} " . abs($pct) . "%)",
        ];

        $results['products_adjusted']++;
    }
}

echo json_encode($results);