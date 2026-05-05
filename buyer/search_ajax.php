<?php
// buyer/search_ajax.php — Real-time product search endpoint
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo '[]'; exit; }

$emojis = ['Vegetables'=>'🥬','Fruits'=>'🍋','Grains'=>'🌽','Coffee'=>'☕','Livestock'=>'🐄','Seafood'=>'🐟','Others'=>'📦'];

$pdo = getDBConnection();
$stmt = $pdo->prepare("
    SELECT p.id, p.name, p.category, p.price_per_kg, p.image,
           COALESCE(p.location, u.location) as location
    FROM products p
    JOIN users u ON p.farmer_id = u.id
    WHERE p.is_available = 1
      AND (p.name LIKE ? OR p.description LIKE ? OR p.category LIKE ?)
    ORDER BY p.name ASC
    LIMIT 8
");
$like = "%$q%";
$stmt->execute([$like, $like, $like]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
    $r['emoji'] = $emojis[$r['category']] ?? '🌾';
}

echo json_encode(array_values($rows));