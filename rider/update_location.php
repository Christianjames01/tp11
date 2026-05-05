<?php
require_once __DIR__ . '/../includes/header.php';
requireLogin();

header('Content-Type: application/json');

// Allow both 'rider' and 'delivery' roles (DB uses both)
$allowedRoles = ['rider', 'delivery'];
if (!in_array($_SESSION['role'], $allowedRoles)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized role: ' . $_SESSION['role']]);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$lat   = isset($input['lat']) ? floatval($input['lat']) : null;
$lng   = isset($input['lng']) ? floatval($input['lng']) : null;

if (!$lat || !$lng) {
    echo json_encode(['success' => false, 'error' => 'Invalid coordinates']);
    exit();
}

// Sanity check: valid lat/lng ranges
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    echo json_encode(['success' => false, 'error' => 'Coordinates out of range']);
    exit();
}

$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];

$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("UPDATE users SET latitude = ?, longitude = ? WHERE id = ?");
$stmt->execute([$lat, $lng, $userId]);

echo json_encode([
    'success' => true,
    'user_id' => $userId,
    'lat'     => $lat,
    'lng'     => $lng
]);
exit();