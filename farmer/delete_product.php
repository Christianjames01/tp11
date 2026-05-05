<?php
require_once __DIR__ . '/../config/database.php';
requireRole('farmer');

$pdo = getDBConnection();
$pid = intval($_GET['id'] ?? 0);
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM products WHERE id=? AND farmer_id=?");
$stmt->execute([$pid, $userId]);
$product = $stmt->fetch();

if ($product) {
    // Delete image if exists
    if ($product['image']) {
        $imgPath = __DIR__ . '/../assets/images/products/' . $product['image'];
        if (file_exists($imgPath)) unlink($imgPath);
    }
    $pdo->prepare("DELETE FROM products WHERE id=? AND farmer_id=?")->execute([$pid, $userId]);
    setFlash('success', 'Product deleted successfully.');
} else {
    setFlash('error', 'Product not found.');
}
header('Location: products.php'); exit();
