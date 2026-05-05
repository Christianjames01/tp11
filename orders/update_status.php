<?php
require_once __DIR__ . '/../config/database.php';
requireLogin();

$pdo = getDBConnection();
$orderId = intval($_GET['id'] ?? 0);
$newStatus = sanitize($_GET['status'] ?? '');
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];

$validStatuses = ['confirmed','processing','shipped','completed','cancelled'];
if (!$orderId || !in_array($newStatus, $validStatuses)) {
    header('Location: index.php'); exit();
}

// Verify ownership
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id=? AND (buyer_id=? OR farmer_id=?)");
$stmt->execute([$orderId, $userId, $userId]);
$order = $stmt->fetch();

if (!$order) { header('Location: index.php'); exit(); }

// Only farmers can confirm/process/ship/complete; both can cancel
if (in_array($newStatus, ['confirmed','processing','shipped','completed']) && $role !== 'farmer') {
    setFlash('error', 'Only the farmer can update this status.');
    header('Location: detail.php?id=' . $orderId); exit();
}

if ($newStatus === 'cancelled' && !in_array($order['status'], ['pending','confirmed'])) {
    setFlash('error', 'This order cannot be cancelled at its current stage.');
    header('Location: detail.php?id=' . $orderId); exit();
}

$pdo->prepare("UPDATE orders SET status=?, updated_at=NOW() WHERE id=?")->execute([$newStatus, $orderId]);
setFlash('success', "Order #$orderId has been updated to: " . ucfirst($newStatus) . " ✅");
header('Location: detail.php?id=' . $orderId); exit();
