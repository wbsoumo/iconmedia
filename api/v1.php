<?php
/**
 * REST API v1 Endpoint Router & Execution Engine
 * Supports GET /api/v1/offers, /api/v1/offers/{id}, /api/v1/conversions, /api/v1/reports
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY');

define('APP_INIT', true);
require_once __DIR__ . '/../app/config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Read API Key from Header or Query
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
if (!$apiKey && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
        $apiKey = $matches[1];
    }
}

if (!$apiKey) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'code' => 401,
        'message' => 'API Key required. Pass X-API-KEY header or api_key parameter.'
    ], JSON_PRETTY_PRINT);
    exit;
}

// Validate API Key
$stmt = $pdo->prepare("SELECT user_id, name, company, api_enabled FROM users WHERE api_key = ? LIMIT 1");
$stmt->execute([$apiKey]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !$user['api_enabled']) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'code' => 403,
        'message' => 'Invalid or disabled API credentials.'
    ], JSON_PRETTY_PRINT);
    exit;
}

$advertiserId = (int)$user['user_id'];

// Parse Request URI
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);
$path = preg_replace('/^.*?\/api\/v1\//', '', $path);
$segments = array_values(array_filter(explode('/', $path)));

$resource = $segments[0] ?? 'offers';
$resourceId = isset($segments[1]) && is_numeric($segments[1]) ? (int)$segments[1] : null;

/* -------------------------------------------------
   ROUTE 1: GET /api/v1/offers OR /api/v1/offers/{id}
-------------------------------------------------- */
if ($resource === 'offers') {
    if ($resourceId) {
        $oStmt = $pdo->prepare("
            SELECT 
                offer_id,
                offer_name,
                offer_description,
                status,
                payout,
                revenue,
                currency,
                category,
                geo,
                device_type,
                daily_cap,
                total_cap,
                preview_url,
                created_at
            FROM offers 
            WHERE offer_id = ? AND advertiser_id = ?
        ");
        $oStmt->execute([$resourceId, $advertiserId]);
        $offer = $oStmt->fetch(PDO::FETCH_ASSOC);

        if (!$offer) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'code' => 404, 'message' => 'Offer campaign not found.'], JSON_PRETTY_PRINT);
            exit;
        }

        echo json_encode(['status' => 'success', 'data' => $offer], JSON_PRETTY_PRINT);
        exit;
    }

    // List Offers
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    $where = ["advertiser_id = ?"];
    $params = [$advertiserId];

    if (!empty($_GET['status'])) {
        $where[] = "status = ?";
        $params[] = $_GET['status'];
    }

    $whereSql = "WHERE " . implode(" AND ", $where);

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM offers $whereSql");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();

    $listStmt = $pdo->prepare("
        SELECT 
            offer_id,
            offer_name,
            status,
            payout,
            revenue,
            currency,
            category,
            created_at
        FROM offers 
        $whereSql
        ORDER BY offer_id DESC
        LIMIT $offset, $limit
    ");
    $listStmt->execute($params);
    $offers = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $offers,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

/* -------------------------------------------------
   ROUTE 2: GET /api/v1/conversions
-------------------------------------------------- */
if ($resource === 'conversions') {
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    $where = ["o.advertiser_id = ?"];
    $params = [$advertiserId];

    if (!empty($_GET['offer_id'])) {
        $where[] = "o.offer_id = ?";
        $params[] = (int)$_GET['offer_id'];
    }

    if (!empty($_GET['status'])) {
        $where[] = "cv.status = ?";
        $params[] = $_GET['status'];
    }

    $whereSql = "WHERE " . implode(" AND ", $where);

    $cntStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM conversions cv 
        INNER JOIN offers o ON o.offer_id = cv.offer_id 
        $whereSql
    ");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();

    $convStmt = $pdo->prepare("
        SELECT 
            cv.conversion_id,
            cv.transaction_id,
            cv.revenue,
            cv.payout,
            cv.status,
            cv.created_at,
            o.offer_id,
            o.offer_name,
            u.name AS affiliate_name
        FROM conversions cv
        INNER JOIN offers o ON o.offer_id = cv.offer_id
        LEFT JOIN users u ON u.user_id = cv.affiliate_id
        $whereSql
        ORDER BY cv.created_at DESC
        LIMIT $offset, $limit
    ");
    $convStmt->execute($params);
    $conversions = $convStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $conversions,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// Fallback Invalid Route
http_response_code(404);
echo json_encode(['status' => 'error', 'code' => 404, 'message' => 'Invalid API Endpoint.'], JSON_PRETTY_PRINT);
