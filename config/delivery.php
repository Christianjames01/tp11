<?php
// ── Delivery Fee Configuration ─────────────────────────────────────────────
// Haversine formula — straight-line distance in km
function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R    = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat/2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// ── Distance-based delivery fee (realistic, no artificial cap) ────────────
// Tiers based on actual Mindanao land freight rates:
//   0–20 km   → ₱80  base  (local/within city)
//  21–50 km   → ₱150 base  (nearby municipality)
//  51–100 km  → ₱280 base  + ₱2.50/km over 50
// 101–200 km  → ₱405 base  + ₱3.00/km over 100
// 201–400 km  → ₱705 base  + ₱3.50/km over 200
// 401–600 km  → ₱1,405 base + ₱4.00/km over 400
//  600+ km    → ₱2,205 base + ₱5.00/km over 600
//
// Example: Zamboanga → Tagum ≈ 530 km
//   = ₱1,405 + (530-400) × ₱4.00 = ₱1,405 + ₱520 = ₱1,925

function calcDeliveryFee(float $distanceKm, float $weightKg = 0): float {
    if ($distanceKm <= 0)   $base = 0.0;
    elseif ($distanceKm <= 20)  $base = 250.0;
    elseif ($distanceKm <= 50)  $base = 380.0;
    elseif ($distanceKm <= 100) $base = round(520  + ($distanceKm - 50)  * 2.50, 2);
    elseif ($distanceKm <= 200) $base = round(645  + ($distanceKm - 100) * 3.00, 2);
    elseif ($distanceKm <= 400) $base = round(945  + ($distanceKm - 200) * 3.50, 2);
    elseif ($distanceKm <= 600) $base = round(1645 + ($distanceKm - 400) * 4.00, 2);
    else                        $base = round(2445 + ($distanceKm - 600) * 5.00, 2);

    $weightSurcharge = $weightKg > 20 ? round(($weightKg - 20) * 3.0, 2) : 0.0;
    return $base + $weightSurcharge;
}

// ── Bulk discount on delivery fee ────────────────────────────────────────────
// Applied when quantity_kg >= 50 (bulk order threshold)
//  50– 99 kg  → 10% off delivery
// 100–199 kg  → 15% off delivery
// 200–499 kg  → 20% off delivery
// 500+ kg     → 25% off delivery
function calcBulkDeliveryDiscount(float $qty, float $deliveryFee): array {
    if ($qty < 50)   return ['discount' => 0.0, 'pct' => 0,  'label' => ''];
    if ($qty < 100)  $pct = 10;
    elseif ($qty < 200) $pct = 15;
    elseif ($qty < 500) $pct = 20;
    else                $pct = 25;

    $discount = round($deliveryFee * ($pct / 100), 2);
    return [
        'discount' => $discount,
        'pct'      => $pct,
        'label'    => "{$pct}% bulk discount ({$qty}kg+)",
    ];
}

// ── Delivery fee label for display ───────────────────────────────────────────
function deliveryFeeLabel(float $distanceKm): string {
    if ($distanceKm <= 20)  return 'Local delivery';
    if ($distanceKm <= 50)  return 'Nearby municipality';
    if ($distanceKm <= 100) return 'Provincial delivery';
    if ($distanceKm <= 200) return 'Inter-province';
    if ($distanceKm <= 400) return 'Regional delivery';
    if ($distanceKm <= 600) return 'Long-distance (Mindanao)';
    return 'Island-wide delivery';
}