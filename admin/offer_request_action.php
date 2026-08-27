<?php
define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['admin', 'manager']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $_GET['action'] ?? '';

if (!$id || !in_array($action, ['approve', 'reject'], true)) {
    die('Invalid request');
}

$status = ($action === 'approve') ? 'approved' : 'rejected';

$stmt = $pdo->prepare("
    UPDATE affiliate_offer_approval
    SET status = :status,
        approved_at = NOW(),
        approved_by = :by
    WHERE id = :id
    LIMIT 1
");

$stmt->execute([
    'status' => $status,
    'by'     => auth_user_id(),
    'id'     => $id
]);

header('Location: offer_requests.php');
exit;
