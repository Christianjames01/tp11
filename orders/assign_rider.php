<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/delivery.php';

requireLogin();

$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];
$role   = $_SESSION['role'];

if ($role !== 'farmer') {
    header('Location: ../orders/index.php'); exit();
}

$orderId  = intval($_POST['order_id']  ?? 0);
$riderId  = intval($_POST['rider_id']  ?? 0);

if (!$orderId || !$riderId) {
    setFlash('error', 'Invalid request.');
    header('Location: index.php'); exit();
}

$check = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND farmer_id = ? AND status = 'processing'");
$check->execute([$orderId, $userId]);

if (!$check->fetch()) {
    setFlash('error', 'Order not found or not in processing status.');
    header('Location: index.php'); exit();
}

$pdo->prepare("UPDATE orders SET rider_id = ?, status = 'confirmed' WHERE id = ?")
    ->execute([$riderId, $orderId]);

setFlash('success', "Rider assigned! Order #$orderId is now ready for pickup. 🛵");
header("Location: detail.php?id=$orderId"); exit();