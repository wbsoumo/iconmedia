<?php
/**
 * Click Tracking Endpoint
 * PHP 7.1+
 * NO SESSION
 * NO AUTH
 */

declare(strict_types=1);

define('APP_INIT', true);
require_once __DIR__ . '/app/config/database.php';

/* -------------------------------------------------
   1. Validate required params
-------------------------------------------------- */
$offerId     = isset($_GET['offer']) ? (int)$_GET['offer'] : 0;
$affiliateId = isset($_GET['aff'])   ? (int)$_GET['aff']   : 0;

if ($offerId <= 0 || $affiliateId <= 0) {
    http_response_code(400);
    exit('INVALID_TRACKING_LINK');
}

/* -------------------------------------------------
   2. Capture ALL incoming params (copy)
-------------------------------------------------- */
$incomingParams = $_GET;

/* Remove internal params (we don’t forward these) */
unset($incomingParams['offer'], $incomingParams['aff']);

/* Extract sub params safely for DB */
$sub1 = $incomingParams['sub1'] ?? null;
$sub2 = $incomingParams['sub2'] ?? null;
$sub3 = $incomingParams['sub3'] ?? null;
$sub4 = $incomingParams['sub4'] ?? null;
$sub5 = $incomingParams['sub5'] ?? null;

/* -------------------------------------------------
   3. Fetch & validate offer
-------------------------------------------------- */
$offerStmt = $pdo->prepare("
    SELECT offer_id, offer_url, status, daily_cap
    FROM offers
    WHERE offer_id = :oid
    LIMIT 1
");
$offerStmt->execute(['oid' => $offerId]);
$offer = $offerStmt->fetch(PDO::FETCH_ASSOC);

if (!$offer) {
    http_response_code(404);
    exit('OFFER_NOT_FOUND');
}

if ($offer['status'] !== 'approved') {
    http_response_code(403);
    exit('OFFER_NOT_APPROVED');
}

if (empty($offer['offer_url'])) {
    exit('OFFER_URL_MISSING');
}

/* -------------------------------------------------
   4. Check affiliate approval
-------------------------------------------------- */
$approvalStmt = $pdo->prepare("
    SELECT 1
    FROM affiliate_offer_approval
    WHERE affiliate_id = :aff
      AND offer_id = :oid
      AND status = 'approved'
    LIMIT 1
");
$approvalStmt->execute([
    'aff' => $affiliateId,
    'oid' => $offerId
]);

if (!$approvalStmt->fetchColumn()) {
    http_response_code(403);
    exit('AFFILIATE_NOT_APPROVED');
}

/* -------------------------------------------------
   5. Daily cap check
-------------------------------------------------- */
if (!empty($offer['daily_cap'])) {
    $capStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM conversions
        WHERE offer_id = :oid
          AND DATE(created_at) = CURDATE()
    ");
    $capStmt->execute(['oid' => $offerId]);

    if ((int)$capStmt->fetchColumn() >= (int)$offer['daily_cap']) {
        exit('DAILY_CAP_REACHED');
    }
}

/* -------------------------------------------------
   6. Generate click_id
-------------------------------------------------- */
$clickId = bin2hex(random_bytes(16));

/* -------------------------------------------------
   7. Collect environment data
-------------------------------------------------- */
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
$referer   = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500);

$device = preg_match('/mobile|android|iphone/i', $userAgent) ? 'mobile' : 'desktop';

/* -------------------------------------------------
   8. Insert click
-------------------------------------------------- */
$insert = $pdo->prepare("
    INSERT INTO clicks (
        click_id,
        offer_id,
        affiliate_id,
        sub1, sub2, sub3, sub4, sub5,
        ip_address,
        user_agent,
        referer,
        device,
        created_at
    ) VALUES (
        :click_id,
        :offer_id,
        :affiliate_id,
        :sub1, :sub2, :sub3, :sub4, :sub5,
        INET6_ATON(:ip),
        :ua,
        :ref,
        :device,
        NOW()
    )
");

$insert->execute([
    'click_id'     => $clickId,
    'offer_id'     => $offerId,
    'affiliate_id' => $affiliateId,
    'sub1'         => $sub1,
    'sub2'         => $sub2,
    'sub3'         => $sub3,
    'sub4'         => $sub4,
    'sub5'         => $sub5,
    'ip'           => $ipAddress,
    'ua'           => $userAgent,
    'ref'          => $referer,
    'device'       => $device
]);

/* -------------------------------------------------
   9. Build redirect URL (FORWARD EVERYTHING)
-------------------------------------------------- */
$redirectUrl = $offer['offer_url'];

/* Add click_id to forwarded params */
$incomingParams['click_id'] = $clickId;

/* Build query string */
$queryString = http_build_query($incomingParams);

/* Append correctly */
$separator = (strpos($redirectUrl, '?') === false) ? '?' : '&';
$redirectUrl .= $separator . $queryString;

/* -------------------------------------------------
   10. Redirect
-------------------------------------------------- */
header('Location: ' . $redirectUrl, true, 302);
exit;
