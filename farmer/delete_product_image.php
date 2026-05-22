<?php
require_once __DIR__ . '/../config/database.php';
requireLogin();
requireRole('farmer');

$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];

// Delete main image
if (isset($_GET['main']) && isset($_GET['product_id'])) {
    $pid = intval($_GET['product_id']);
    $stmt = $pdo->prepare("SELECT image FROM products WHERE id=? AND farmer_id=?");
    $stmt->execute([$pid, $userId]);
    $product = $stmt->fetch();
    if ($product && $product['image']) {
        $file = __DIR__ . '/../assets/images/products/' . $product['image'];
        if (file_exists($file)) unlink($file);
        $pdo->prepare("UPDATE products SET image=NULL WHERE id=? AND farmer_id=?")->execute([$pid, $userId]);
    }
    header('Location: edit_product.php?id=' . $pid); exit();
}

// Delete carousel image
$imageId   = intval($_GET['id'] ?? 0);
$productId = intval($_GET['product_id'] ?? 0);

$stmt = $pdo->prepare("SELECT pi.image FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE pi.id=? AND p.farmer_id=?");
$stmt->execute([$imageId, $userId]);
$row = $stmt->fetch();

if ($row) {
    $file = __DIR__ . '/../assets/images/products/' . $row['image'];
    if (file_exists($file)) unlink($file);
    $pdo->prepare("DELETE FROM product_images WHERE id=?")->execute([$imageId]);
}

header('Location: edit_product.php?id=' . $productId); exit();