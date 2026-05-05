<?php
require_once __DIR__ . '/../includes/header.php';
requireLogin();

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];
$toId = intval($_GET['to'] ?? 0);
$after = intval($_GET['after'] ?? 0);

if (!$toId) { echo '[]'; exit; }

// Mark as read
$pdo->prepare("UPDATE messages SET is_read=1 WHERE sender_id=? AND receiver_id=?")->execute([$toId, $userId]);

$stmt = $pdo->prepare("
    SELECT m.id, m.message, m.created_at, m.sender_id
    FROM messages m
    WHERE ((m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?))
    AND m.id > ?
    AND m.sender_id = ?
    ORDER BY m.created_at ASC
");
$stmt->execute([$userId, $toId, $toId, $userId, $after, $toId]);
$rows = $stmt->fetchAll();

$out = array_map(fn($r) => [
    'id'      => $r['id'],
    'message' => htmlspecialchars($r['message'], ENT_QUOTES, 'UTF-8'),
    'time'    => date('g:ia', strtotime($r['created_at'])),
], $rows);

header('Content-Type: application/json');
echo json_encode($out);
exit;