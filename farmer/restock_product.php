<?php
require_once __DIR__ . '/../config/database.php';
requireRole('farmer');

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = intval($_POST['product_id'] ?? 0);
    $restockKg = floatval($_POST['restock_kg'] ?? 0);

    if ($productId && $restockKg > 0) {
        $stmt = $pdo->prepare("UPDATE products SET stock_kg = stock_kg + ?, is_available = 1 WHERE id = ? AND farmer_id = ?");
        $stmt->execute([$restockKg, $productId, $userId]);
    }
}

header('Location: dashboard.php');
exit;