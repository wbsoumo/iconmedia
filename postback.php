<?php
/**
 * Secure Advertiser Postback + Affiliate Dispatcher
 * Production Safe Version
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/app/config/database.php';

/* =====================================================
   Helper: Safe Log
===================================================== */
function logAdvertiserPostback(PDO $pdo, $raw, $ip, $clickId, $status)
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO postback_logs
            (raw_request, ip_address, click_id, status, created_at)
            VALUES (:raw, INET6_ATON(:ip), :click_id, :status, NOW())
        ");
        $stmt->execute([
            'raw'      => $raw,
            'ip'       => $ip,
            'click_id' => $clickId,
            'status'   => $status
        ]);
    } catch (Throwable $e) {
        // Do not break main flow if logging fails
    }
}

/* =====================================================
   Helper: Fire Affiliate Postback
===================================================== */
function fireAffiliatePostback(PDO $pdo, array $data)
{
    $ch = curl_init($data['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $stmt = $pdo->prepare("
        INSERT INTO affiliate_postback_logs
        (affiliate_id, offer_id, conversion_id, postback_url, http_code, response, created_at)
        VALUES (?,?,?,?,?,?,NOW())
    ");

    $stmt->execute([
        $data['affiliate_id'],
        $data['offer_id'],
        $data['conversion_id'],
        $data['url'],
        $httpCode,
        substr((string)$response, 0, 1000)
    ]);
}

/* =====================================================
   Capture Request
===================================================== */

$rawRequest = $_SERVER['REQUEST_URI'] ?? '';
$ipAddress  = $_SERVER['REMOTE_ADDR'] ?? '';

$clickId = $_GET['click_id'] ?? '';
$status  = $_GET['status'] ?? 'approved';
$amount  = isset($_GET['amount']) ? (float)$_GET['amount'] : null;
$txid    = $_GET['txid'] ?? null;
$token   = $_GET['token'] ?? '';

if ($clickId === '') {
    http_response_code(400);
    exit('MISSING_CLICK_ID');
}

if (!in_array($status, ['approved','pending','rejected'], true)) {
    $status = 'approved';
}

/* =====================================================
   Start Transaction
===================================================== */
$pdo->beginTransaction();

try {

    /* -------------------------------------------------
       Duplicate Protection
    -------------------------------------------------- */
    $dup = $pdo->prepare("SELECT conversion_id FROM conversions WHERE click_id=? LIMIT 1");
    $dup->execute([$clickId]);

    if ($dup->fetch()) {
        logAdvertiserPostback($pdo, $rawRequest, $ipAddress, $clickId, 'duplicate');
        $pdo->rollBack();
        exit('DUPLICATE');
    }

    /* -------------------------------------------------
       Fetch Click + Offer + Advertiser
    -------------------------------------------------- */
    $stmt = $pdo->prepare("
        SELECT
            c.click_id,
            c.offer_id,
            c.affiliate_id,
            c.sub1, c.sub2, c.sub3, c.sub4, c.sub5,
            c.country,
            c.device,
            o.payout,
            o.revenue,
            o.postback_token,
            o.status AS offer_status,
            o.advertiser_id
        FROM clicks c
        INNER JOIN offers o ON o.offer_id = c.offer_id
        WHERE c.click_id = ?
        LIMIT 1
    ");
    $stmt->execute([$clickId]);
    $click = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$click) {
        logAdvertiserPostback($pdo, $rawRequest, $ipAddress, $clickId, 'invalid_click');
        $pdo->rollBack();
        exit('INVALID_CLICK');
    }

    if ($click['offer_status'] !== 'approved') {
        logAdvertiserPostback($pdo, $rawRequest, $ipAddress, $clickId, 'offer_inactive');
        $pdo->rollBack();
        exit('OFFER_NOT_ACTIVE');
    }

    /* -------------------------------------------------
       Token Validation
    -------------------------------------------------- */
    if (empty($click['postback_token']) ||
        $token === '' ||
        !hash_equals((string)$click['postback_token'], (string)$token)) {

        logAdvertiserPostback($pdo, $rawRequest, $ipAddress, $clickId, 'invalid_token');
        $pdo->rollBack();
        exit('INVALID_TOKEN');
    }

    /* -------------------------------------------------
       Determine Payout
    -------------------------------------------------- */
    $payout = (float)$click['payout'];

    if ($amount !== null && $amount >= 0 && $amount <= ($payout * 2)) {
        $payout = $amount;
    }

    /* -------------------------------------------------
       Insert Conversion
    -------------------------------------------------- */
    $insert = $pdo->prepare("
        INSERT INTO conversions
        (click_id, offer_id, affiliate_id, advertiser_id, payout, revenue, status, transaction_id, source, created_at)
        VALUES (?,?,?,?,?,?,?,?, 'postback', NOW())
    ");

    $insert->execute([
        $clickId,
        $click['offer_id'],
        $click['affiliate_id'],
        $click['advertiser_id'],
        $payout,
        $click['revenue'],
        $status,
        $txid
    ]);

    $conversionId = $pdo->lastInsertId();

    /* -------------------------------------------------
       Credit Affiliate
    -------------------------------------------------- */
    if ($status === 'approved') {
        $pdo->prepare("
            UPDATE users
            SET balance = balance + ?
            WHERE user_id = ?
        ")->execute([$payout, $click['affiliate_id']]);
    }

    /* -------------------------------------------------
       Affiliate Postback Dispatch
    -------------------------------------------------- */
    $pb = $pdo->prepare("
        SELECT * FROM affiliate_offer_postbacks
        WHERE affiliate_id = ? AND offer_id = ? AND status='active'
        LIMIT 1
    ");
    $pb->execute([$click['affiliate_id'], $click['offer_id']]);
    $postback = $pb->fetch(PDO::FETCH_ASSOC);

    if (!$postback) {
        $pb = $pdo->prepare("
            SELECT * FROM affiliate_postbacks
            WHERE affiliate_id = ? AND status='active'
            LIMIT 1
        ");
        $pb->execute([$click['affiliate_id']]);
        $postback = $pb->fetch(PDO::FETCH_ASSOC);
    }

    if ($postback && in_array($postback['fire_status'], [$status, 'all'], true)) {

        $tokens = [
            '{click_id}'      => $clickId,
            '{conversion_id}' => $conversionId,
            '{offer_id}'      => $click['offer_id'],
            '{affiliate_id}'  => $click['affiliate_id'],
            '{payout}'        => $payout,
            '{status}'        => $status,
            '{p1}'            => $click['sub1'] ?? '',
            '{p2}'            => $click['sub2'] ?? '',
            '{p3}'            => $click['sub3'] ?? '',
            '{p4}'            => $click['sub4'] ?? '',
            '{p5}'            => $click['sub5'] ?? '',
            '{ip}'            => $ipAddress,
            '{country}'       => $click['country'] ?? '',
            '{device}'        => $click['device'] ?? ''
        ];

        $finalUrl = str_replace(
            array_keys($tokens),
            array_values($tokens),
            $postback['postback_url']
        );

        fireAffiliatePostback($pdo, [
            'affiliate_id'  => $click['affiliate_id'],
            'offer_id'      => $click['offer_id'],
            'conversion_id' => $conversionId,
            'url'           => $finalUrl
        ]);
    }

    /* -------------------------------------------------
       Final Log
    -------------------------------------------------- */
    logAdvertiserPostback($pdo, $rawRequest, $ipAddress, $clickId, 'accepted');

    $pdo->commit();
    exit('OK');

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo "ERROR: " . $e->getMessage();
    echo "<br>LINE: " . $e->getLine();
    exit;
}

