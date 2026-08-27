<?php
define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['admin', 'manager']);

$offerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action  = $_GET['action'] ?? '';

if (!$offerId || !in_array($action, ['approve', 'pause', 'expire'], true)) {
    die('Invalid request');
}

$statusMap = [
    'approve' => 'approved',
    'pause'   => 'paused',
    'expire'  => 'expired'
];

$newStatus = $statusMap[$action];

$stmt = $pdo->prepare("
    UPDATE offers
    SET status = :status,
        updated_at = NOW()
    WHERE offer_id = :id
    LIMIT 1
");
$stmt->execute([
    'status' => $newStatus,
    'id'     => $offerId
]);

header('Location: offers.php');
exit;
