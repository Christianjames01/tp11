<?php
// Prevent ANY output before JSON (warnings, notices, etc.)
ini_set('display_errors', 0);
error_reporting(0);

session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$action     = $_POST['action'] ?? $_GET['action'] ?? '';
$product_id = intval($_POST['product_id'] ?? $_GET['product_id'] ?? 0);
$qty        = floatval($_POST['qty'] ?? 0);

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

// ── Product info (for cart modal) ─────────────────────────────
if ($action === 'info' && $product_id) {
    $pdo  = getDBConnection();
    $stmt = $pdo->prepare("SELECT id, name, price_per_kg, min_order_kg, stock_kg, image, category, is_organic FROM products WHERE id = ? AND is_available = 1");
    $stmt->execute([$product_id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $p ? json_encode($p) : json_encode(null);
    exit();
}

// ── Add to cart ───────────────────────────────────────────────
if ($action === 'add' && $product_id) {
    $pdo  = getDBConnection();
    $stmt = $pdo->prepare("SELECT id, name, price_per_kg, min_order_kg, stock_kg, image, category, is_organic FROM products WHERE id = ? AND is_available = 1");
    $stmt->execute([$product_id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($p) {
        $addQty = max($qty ?: $p['min_order_kg'], $p['min_order_kg']);
        $addQty = min($addQty, $p['stock_kg']);
        if (isset($_SESSION['cart'][$product_id])) {
            $_SESSION['cart'][$product_id]['qty'] = min(
                $_SESSION['cart'][$product_id]['qty'] + $addQty,
                $p['stock_kg']
            );
        } else {
            $_SESSION['cart'][$product_id] = [
                'id'           => $p['id'],
                'name'         => $p['name'],
                'price_per_kg' => $p['price_per_kg'],
                'min_order_kg' => $p['min_order_kg'],
                'stock_kg'     => $p['stock_kg'],
                'image'        => $p['image'],
                'category'     => $p['category'],
                'is_organic'   => $p['is_organic'],
                'qty'          => $addQty,
            ];
        }
        echo json_encode(['success' => true, 'count' => count($_SESSION['cart']), 'name' => $p['name']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
    }
    exit();
}

// ── Remove item ───────────────────────────────────────────────
if ($action === 'remove' && $product_id) {
    unset($_SESSION['cart'][$product_id]);
    // For AJAX calls return JSON, for direct visits redirect
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        echo json_encode(['success' => true]);
    } else {
        header('Location: cart_view.php');
    }
    exit();
}

// ── Update quantity ───────────────────────────────────────────
if ($action === 'update' && $product_id) {
    if (isset($_SESSION['cart'][$product_id])) {
        $newQty = floatval($_POST['qty'] ?? 0);
        $min    = $_SESSION['cart'][$product_id]['min_order_kg'];
        $max    = $_SESSION['cart'][$product_id]['stock_kg'];
        if ($newQty < $min) $newQty = $min;
        if ($newQty > $max) $newQty = $max;
        $_SESSION['cart'][$product_id]['qty'] = $newQty;
    }
    header('Location: cart_view.php');
    exit();
}

// ── Clear cart ────────────────────────────────────────────────
if ($action === 'clear') {
    $_SESSION['cart'] = [];
    header('Location: cart_view.php');
    exit();
}

// ── Count ─────────────────────────────────────────────────────
if ($action === 'count') {
    echo json_encode(['count' => count($_SESSION['cart'] ?? [])]);
    exit();
}

echo json_encode(['error' => 'Invalid action']);