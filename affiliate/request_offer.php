<?php
define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('affiliate');

$affiliateId = auth_user_id();
$offerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$offerId) {
    die('Invalid offer');
}

// Check if offer exists & is approved
$offerCheck = $pdo->prepare("
    SELECT offer_id
    FROM offers
    WHERE offer_id = :oid
      AND status = 'approved'
    LIMIT 1
");
$offerCheck->execute(['oid' => $offerId]);

if (!$offerCheck->fetch()) {
    die('Offer not available');
}

// Check if already applied
$check = $pdo->prepare("
    SELECT status
    FROM affiliate_offer_approval
    WHERE affiliate_id = :aid
      AND offer_id = :oid
    LIMIT 1
");
$check->execute([
    'aid' => $affiliateId,
    'oid' => $offerId
]);

if ($check->fetch()) {
    die('You have already applied for this offer');
}

// Insert request
$insert = $pdo->prepare("
    INSERT INTO affiliate_offer_approval (
        affiliate_id,
        offer_id,
        status
    ) VALUES (
        :aid,
        :oid,
        'pending'
    )
");
$insert->execute([
    'aid' => $affiliateId,
    'oid' => $offerId
]);

header('Location: offers.php');
exit;
