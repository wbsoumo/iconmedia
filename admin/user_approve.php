<?php
define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $_GET['action'] ?? '';

if (!$userId || !in_array($action, ['approve', 'reject'], true)) {
    die('Invalid request');
}

$newStatus = ($action === 'approve') ? 'active' : 'blocked';

$stmt = $pdo->prepare("
    UPDATE users 
    SET status = :status
    WHERE user_id = :id
    LIMIT 1
");

$stmt->execute([
    'status' => $newStatus,
    'id'     => $userId
]);

header('Location: users.php');
exit;
