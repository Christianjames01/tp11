<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Must be logged in as buyer
if (!isLoggedIn() || ($_SESSION['role'] ?? '') !== 'buyer') {
    echo json_encode(['success' => false, 'message' => 'You must be logged in as a buyer.']);
    exit;
}

$pdo = getDBConnection();

// Must be premium
$stmt = $pdo->prepare("SELECT is_premium, premium_until FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (empty($user['is_premium']) || strtotime($user['premium_until']) <= time()) {
    echo json_encode(['success' => false, 'message' => 'Price alerts are a Premium feature.']);
    exit;
}

// Parse JSON body
$body        = json_decode(file_get_contents('php://input'), true);
$productName = trim($body['product_name'] ?? '');
$targetPrice = floatval($body['target_price'] ?? 0);

if (!$productName || $targetPrice <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product or target price.']);
    exit;
}

try {
    $upsert = $pdo->prepare("
        INSERT INTO price_alerts (buyer_id, product_name, target_price, is_triggered, created_at, updated_at)
        VALUES (?, ?, ?, 0, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            target_price = VALUES(target_price),
            is_triggered = 0,
            updated_at   = NOW()
    ");
    $upsert->execute([$_SESSION['user_id'], $productName, $targetPrice]);

    echo json_encode([
        'success' => true,
        'message' => '🔔 Alert set! We\'ll notify you when ' . $productName . ' drops to ₱' . number_format($targetPrice, 2) . '.'
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
exit;