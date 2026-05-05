<?php
require_once __DIR__ . '/../includes/header.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getDBConnection();

    $product_name   = trim($_POST['product_name'] ?? '');
    $category       = trim($_POST['category'] ?? '');
    $market_price   = floatval($_POST['market_price'] ?? 0);
    $suggested_price= floatval($_POST['suggested_price'] ?? 0);
    $location       = trim($_POST['location'] ?? '');
    $price_date     = $_POST['price_date'] ?? date('Y-m-d');
    $unit           = 'kg';

    if ($product_name && $category && $market_price > 0 && $suggested_price > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO market_prices (product_name, category, market_price, suggested_price, location, price_date, unit)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$product_name, $category, $market_price, $suggested_price, $location, $price_date, $unit]);
        setFlash('success', "Price for '{$product_name}' saved successfully.");
    } else {
        setFlash('error', 'Please fill in all required fields with valid values.');
    }
}

header('Location: ' . BASE_URL . '/admin/marketprices.php');
exit;