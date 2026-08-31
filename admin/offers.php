<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

// Check for success/error messages from other actions
if (isset($_GET['approved'])) {
    $success = 'Offer approved successfully';
} elseif (isset($_GET['rejected'])) {
    $success = 'Offer rejected';
} elseif (isset($_GET['paused'])) {
    $success = 'Offer paused';
} elseif (isset($_GET['activated'])) {
    $success = 'Offer activated';
} elseif (isset($_GET['deleted'])) {
    $success = 'Offer deleted successfully';
} elseif (isset($_GET['error'])) {
    $error = $_GET['error'];
}

/* ===============================
   FETCH ALL OFFERS WITH FILTERS
================================ */
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? 'all';
$advertiserFilter = $_GET['advertiser'] ?? 'all';
$categoryFilter = $_GET['category'] ?? 'all';
$visibilityFilter = $_GET['visibility'] ?? 'all';
$sortBy = $_GET['sort'] ?? 'recent';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;

// Build WHERE clause
$where = ['1=1'];
$params = [];

if ($search) {
    $where[] = '(o.offer_name LIKE :search OR o.offer_description LIKE :search OR u.name LIKE :search OR u.email LIKE :search)';
    $params['search'] = "%$search%";
}

if ($statusFilter !== 'all') {
    $where[] = 'o.status = :status';
    $params['status'] = $statusFilter;
}

if ($advertiserFilter !== 'all') {
    $where[] = 'o.advertiser_id = :advertiser';
    $params['advertiser'] = (int)$advertiserFilter;
}

if ($categoryFilter !== 'all') {
    $where[] = 'o.category = :category';
    $params['category'] = $categoryFilter;
}

if ($visibilityFilter !== 'all') {
    $where[] = 'o.visibility = :visibility';
    $params['visibility'] = $visibilityFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Determine sort order
$orderBy = 'o.created_at DESC';
switch ($sortBy) {
    case 'recent':
        $orderBy = 'o.created_at DESC';
        break;
    case 'oldest':
        $orderBy = 'o.created_at ASC';
        break;
    case 'name_asc':
        $orderBy = 'o.offer_name ASC';
        break;
    case 'name_desc':
        $orderBy = 'o.offer_name DESC';
        break;
    case 'payout_high':
        $orderBy = 'o.payout DESC';
        break;
    case 'payout_low':
        $orderBy = 'o.payout ASC';
        break;
    case 'revenue_high':
        $orderBy = 'o.revenue DESC';
        break;
    case 'clicks_high':
        $orderBy = 'total_clicks DESC';
        break;
    case 'conversions_high':
        $orderBy = 'total_conversions DESC';
        break;
}

// Get total count
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM offers o
    LEFT JOIN users u ON u.user_id = o.advertiser_id
    $whereSql
");
$countStmt->execute($params);
$totalOffers = $countStmt->fetchColumn();
$totalPages = ceil($totalOffers / $perPage);
$offset = ($page - 1) * $perPage;

// Fetch offers with stats and advertiser info
$sql = "
    SELECT 
        o.offer_id,
        o.offer_name,
        o.offer_description,
        o.payout,
        o.payout_type,
        o.revenue,
        o.status,
        o.visibility,
        o.category,
        o.geo,
        o.country,
        o.device_type,
        o.daily_cap,
        o.total_cap,
        o.start_date,
        o.end_date,
        o.created_at,
        o.updated_at,
        o.postback_token,
        o.advertiser_id,
        o.conversion_tracking,
        o.terms_required,
        o.internal_note,
        
        -- Advertiser info
        u.name AS advertiser_name,
        u.email AS advertiser_email,
        u.company AS advertiser_company,
        
        -- Stats
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        SUM(CASE WHEN cv.status = 'pending' THEN 1 ELSE 0 END) AS pending_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END) AS earned_revenue,
        SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END) AS paid_payout,
        
        -- Performance metrics
        CASE 
            WHEN COUNT(DISTINCT c.click_id) > 0 
            THEN (COUNT(DISTINCT cv.conversion_id) / COUNT(DISTINCT c.click_id)) * 100
            ELSE 0
        END AS conversion_rate,
        
        -- Profit
        (SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END) - 
         SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END)) AS profit
        
    FROM offers o
    INNER JOIN users u ON u.user_id = o.advertiser_id
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    $whereSql
    GROUP BY o.offer_id
    ORDER BY $orderBy
    LIMIT :offset, :per_page
";

$stmt = $pdo->prepare($sql);

// Bind parameters
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $perPage, PDO::PARAM_INT);

$stmt->execute();
$offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH ADVERTISERS FOR FILTER
================================ */
$advertisers = $pdo->query("
    SELECT user_id, name, email, company 
    FROM users 
    WHERE role_id = 4
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH CATEGORIES FOR FILTER
================================ */
$categories = $pdo->query("
    SELECT DISTINCT category 
    FROM offers 
    WHERE category IS NOT NULL AND category != ''
    ORDER BY category
")->fetchAll(PDO::FETCH_COLUMN);

/* ===============================
   SUMMARY STATISTICS
================================ */
$summaryStmt = $pdo->query("
    SELECT 
        COUNT(*) as total_offers,
        SUM(status = 'pending') as pending_offers,
        SUM(status = 'approved') as approved_offers,
        SUM(status = 'active') as active_offers,
        SUM(status = 'paused') as paused_offers,
        SUM(status = 'rejected') as rejected_offers,
        
        AVG(payout) as avg_payout,
        AVG(revenue) as avg_revenue,
        SUM(revenue) as total_potential_revenue,
        
        (SELECT COUNT(*) FROM clicks) as total_clicks,
        (SELECT COUNT(*) FROM conversions) as total_conversions,
        (SELECT SUM(revenue) FROM conversions WHERE status = 'approved') as total_earned_revenue,
        (SELECT SUM(payout) FROM conversions WHERE status = 'approved') as total_paid_payout
    FROM offers
");
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

/* ===============================
   BULK ACTIONS
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selectedOffers = $_POST['selected_offers'] ?? [];
    
    if (empty($selectedOffers)) {
        $error = 'Please select at least one offer';
    } else {
        $placeholders = implode(',', array_fill(0, count($selectedOffers), '?'));
        
        switch ($action) {
            case 'approve':
                $sql = "UPDATE offers SET status = 'approved', updated_at = NOW() WHERE offer_id IN ($placeholders)";
                $message = 'selected offers have been approved';
                break;
            case 'activate':
                $sql = "UPDATE offers SET status = 'active', updated_at = NOW() WHERE offer_id IN ($placeholders)";
                $message = 'selected offers have been activated';
                break;
            case 'pause':
                $sql = "UPDATE offers SET status = 'paused', updated_at = NOW() WHERE offer_id IN ($placeholders)";
                $message = 'selected offers have been paused';
                break;
            case 'reject':
                $sql = "UPDATE offers SET status = 'rejected', updated_at = NOW() WHERE offer_id IN ($placeholders)";
                $message = 'selected offers have been rejected';
                break;
            case 'delete':
                $sql = "DELETE FROM offers WHERE offer_id IN ($placeholders)";
                $message = 'selected offers have been deleted';
                break;
            default:
                $error = 'Invalid action selected';
                break;
        }
        
        if (!$error) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($selectedOffers);
            $success = count($selectedOffers) . ' ' . $message;
            
            // Refresh page
            header("Location: offers.php?success=" . urlencode($success));
            exit;
        }
    }
}

/* ===============================
   SINGLE OFFER ACTIONS
================================ */
if (isset($_GET['action']) && isset($_GET['id'])) {
    $offerId = (int)$_GET['id'];
    $action = $_GET['action'];
    
    $validActions = ['approve', 'reject', 'activate', 'pause', 'delete'];
    
    if (in_array($action, $validActions)) {
        if ($action === 'delete') {
            $sql = "DELETE FROM offers WHERE offer_id = ?";
        } else {
            $status = ($action === 'approve') ? 'approved' : 
                     (($action === 'activate') ? 'active' : 
                     (($action === 'pause') ? 'paused' : 
                     (($action === 'reject') ? 'rejected' : $action)));
            $sql = "UPDATE offers SET status = ?, updated_at = NOW() WHERE offer_id = ?";
        }
        
        $stmt = $pdo->prepare($sql);
        
        if ($action === 'delete') {
            $stmt->execute([$offerId]);
            header("Location: offers.php?deleted=1");
        } else {
            $stmt->execute([$status, $offerId]);
            header("Location: offers.php?" . $action . "=1");
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Campaigns | Admin Panel | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --info-gradient: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            --warning-gradient: linear-gradient(135deg, #f7971e 0%, #ffd200 100%);
            --danger-gradient: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
            --dark-gradient: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
            --purple-gradient: linear-gradient(135deg, #9f7aea 0%, #667eea 100%);
        }
        
        .card-dashboard {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            overflow: hidden;
        }
        
        .card-dashboard .card-header {
            border-radius: 15px 15px 0 0;
            background: white;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            padding: 20px;
        }
        
        .card-dashboard .card-body {
            padding: 25px;
        }
        
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e3e6f0;
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .filter-group {
            flex: 1;
            min-width: 180px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 8px;
            color: #6c757d;
            font-size: 14px;
            font-weight: 600;
        }
        
        .filter-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e3e6f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #f8f9fc;
        }
        
        .filter-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: white;
        }
        
        .btn-gradient {
            background: var(--primary-gradient);
            border: none;
            color: white;
            font-weight: 600;
            padding: 10px 25px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }
        
        .btn-outline-primary {
            border: 2px solid #667eea;
            color: #667eea;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary-gradient);
            border-color: transparent;
            color: white;
        }
        
        .table-dashboard {
            border: none;
            width: 100%;
        }
        
        .table-dashboard thead th {
            border: none;
            background: #f8f9fc;
            color: #4e73df;
            font-weight: 600;
            padding: 15px;
            border-bottom: 2px solid #e3e6f0;
            text-align: left;
        }
        
        .table-dashboard tbody td {
            padding: 15px;
            border-bottom: 1px solid #f8f9fa;
            vertical-align: middle;
        }
        
        .table-dashboard tbody tr:hover {
            background: #f8f9fc;
        }
        
        .metric-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #e3e6f0;
            text-align: center;
            transition: all 0.3s ease;
            flex: 1;
            min-width: 150px;
        }
        
        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .metric-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .metric-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .summary-stats {
            display: flex;
            gap: 15px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        
        .total-value {
            color: #4e73df;
        }
        
        .pending-value {
            color: #ffc107;
        }
        
        .active-value {
            color: #28a745;
        }
        
        .paused-value {
            color: #6c757d;
        }
        
        .rejected-value {
            color: #dc3545;
        }
        
        .revenue-value {
            color: #20c997;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .status-approved {
            background: rgba(0, 123, 255, 0.15);
            color: #007bff;
            border: 1px solid rgba(0, 123, 255, 0.2);
        }
        
        .status-active {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .status-paused {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.2);
        }
        
        .status-rejected {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .visibility-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .visibility-public {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }
        
        .visibility-private {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn-action {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        
        .btn-approve {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .btn-approve:hover {
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
        }
        
        .btn-reject {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .btn-reject:hover {
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }
        
        .btn-activate {
            background: rgba(0, 123, 255, 0.1);
            color: #007bff;
            border: 1px solid rgba(0, 123, 255, 0.2);
        }
        
        .btn-activate:hover {
            background: rgba(0, 123, 255, 0.2);
            color: #007bff;
        }
        
        .btn-pause {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .btn-pause:hover {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .btn-edit {
            background: rgba(32, 201, 151, 0.1);
            color: #20c997;
            border: 1px solid rgba(32, 201, 151, 0.2);
        }
        
        .btn-edit:hover {
            background: rgba(32, 201, 151, 0.2);
            color: #20c997;
        }
        
        .btn-view {
            background: rgba(78, 115, 223, 0.1);
            color: #4e73df;
            border: 1px solid rgba(78, 115, 223, 0.2);
        }
        
        .btn-view:hover {
            background: rgba(78, 115, 223, 0.2);
            color: #4e73df;
        }
        
        .btn-delete {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .btn-delete:hover {
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }
        
        .bulk-actions {
            background: #f8f9fc;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .select-all-checkbox {
            margin-right: 10px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state-icon {
            font-size: 60px;
            color: #e3e6f0;
            margin-bottom: 15px;
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .action-buttons-group {
            display: flex;
            gap: 10px;
        }
        
        .pagination-container {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 5px;
        }
        
        .page-link {
            padding: 8px 15px;
            border: 1px solid #e3e6f0;
            border-radius: 6px;
            color: #4e73df;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .page-link:hover {
            background: #f8f9fc;
            border-color: #4e73df;
        }
        
        .page-link.active {
            background: #4e73df;
            color: white;
            border-color: #4e73df;
        }
        
        .admin-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            font-weight: 600;
        }
        
        .offer-avatar {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            font-weight: 600;
        }
        
        .alert {
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            border: none;
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            border-left: 4px solid #28a745;
            color: #155724;
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        
        .welcome-banner {
            background: var(--dark-gradient);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.1)" d="M0,96L48,112C96,128,192,160,288,186.7C384,213,480,235,576,213.3C672,192,768,128,864,128C960,128,1056,192,1152,213.3C1248,235,1344,213,1392,202.7L1440,192L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>');
            background-size: cover;
            opacity: 0.1;
        }
        
        .refresh-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            border-radius: 20px;
            padding: 8px 20px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .refresh-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        .postback-token-preview {
            background: #f8f9fc;
            border: 1px solid #e3e6f0;
            border-radius: 8px;
            padding: 4px 8px;
            font-family: monospace;
            font-size: 11px;
            color: #4e73df;
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: pointer;
        }
        
        .profit-positive {
            color: #28a745;
            font-weight: 600;
        }
        
        .profit-negative {
            color: #dc3545;
            font-weight: 600;
        }
        
        .sort-indicator {
            display: inline-block;
            width: 0;
            height: 0;
            margin-left: 5px;
            vertical-align: middle;
            border-right: 4px solid transparent;
            border-left: 4px solid transparent;
        }
        
        .sort-asc {
            border-bottom: 4px solid #4e73df;
            border-top: none;
        }
        
        .sort-desc {
            border-top: 4px solid #4e73df;
            border-bottom: none;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button">
                    <i class="fas fa-bars"></i>
                </a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="offers.php" class="nav-link active">Campaigns</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="create_offer.php" class="nav-link">Create Campaign</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <span class="badge badge-warning navbar-badge"><?php echo $summary['pending_offers'] ?? 0; ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header">
                        <?php echo $summary['pending_offers'] ?? 0; ?> Pending Actions
                    </span>
                    <div class="dropdown-divider"></div>
                    <a href="offers.php?status=pending" class="dropdown-item">
                        <i class="fas fa-clock mr-2 text-warning"></i>
                        <?php echo $summary['pending_offers'] ?? 0; ?> Pending Approvals
                    </a>
                </div>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" data-widget="fullscreen" href="#" role="button">
                    <i class="fas fa-expand-arrows-alt"></i>
                </a>
            </li>
            
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                    <div class="admin-avatar mr-2">
                        <?php echo strtoupper(substr($adminName, 0, 1)); ?>
                    </div>
                    <span><?php echo htmlspecialchars($adminName); ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user mr-2"></i> Admin Profile
                    </a>
                    <a href="settings.php" class="dropdown-item">
                        <i class="fas fa-cog mr-2"></i> System Settings
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="../logout.php" class="dropdown-item">
                        <i class="fas fa-sign-out-alt mr-2"></i> Logout
                    </a>
                </div>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="#" id="darkModeToggle">
                    <i class="fas fa-moon"></i>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.5rem;">
                <i class="fas fa-crown mr-2"></i>
                <strong>Admin</strong>
            </span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>

                    <li class="nav-header">CAMPAIGNS</li>
                    <li class="nav-item">
                        <a href="offers.php" class="nav-link active">
                            <i class="nav-icon fas fa-bullhorn"></i>
                            <p>Manage Campaigns</p>
                            <?php if (($summary['pending_offers'] ?? 0) > 0): ?>
                            <span class="badge badge-warning right"><?php echo $summary['pending_offers']; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="create_campaign.php" class="nav-link">
                            <i class="nav-icon fas fa-plus"></i>
                            <p>Create Campaign</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="campaign_access.php" class="nav-link">
                            <i class="nav-icon fas fa-key"></i>
                            <p>Campaign Access</p>
                        </a>
                    </li>

                    <li class="nav-header">REPORTS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Campaign Report</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link">
                            <i class="nav-icon fas fa-users"></i>
                            <p>Affiliate Report</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_advertisers.php" class="nav-link">
                            <i class="nav-icon fas fa-building"></i>
                            <p>Advertiser Report</p>
                        </a>
                    </li>

                    <li class="nav-header">PUBLISHERS</li>
                    <li class="nav-item">
                        <a href="publishers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-friends"></i>
                            <p>Manage Publishers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="publisher_postbacks.php" class="nav-link">
                            <i class="nav-icon fas fa-link"></i>
                            <p>Publisher Postbacks</p>
                        </a>
                    </li>

                    <li class="nav-header">ADVERTISERS</li>
                    <li class="nav-item">
                        <a href="advertisers.php" class="nav-link">
                            <i class="nav-icon fas fa-briefcase"></i>
                            <p>Manage Advertisers</p>
                        </a>
                    </li>

                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="account_managers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-tie"></i>
                            <p>Account Managers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user-circle"></i>
                            <p>My Profile</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="settings.php" class="nav-link">
                            <i class="nav-icon fas fa-cog"></i>
                            <p>Settings</p>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    </aside>

    <!-- Content Wrapper -->
    <div class="content-wrapper">
        <!-- Content Header -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0">Campaign Management</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Campaigns</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">
                <!-- Welcome Banner -->
                <div class="welcome-banner">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h2>Campaign Management</h2>
                            <p class="mb-0">Review, approve, and manage all advertiser campaigns across the network.</p>
                        </div>
                        <div class="col-md-4 text-right">
                            <a href="create_offer.php" class="btn btn-light">
                                <i class="fas fa-plus-circle mr-2"></i> New Campaign
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>

                <!-- Summary Stats -->
                <div class="summary-stats">
                    <div class="metric-card">
                        <div class="metric-value total-value"><?php echo number_format($summary['total_offers'] ?? 0); ?></div>
                        <div class="metric-label">Total Campaigns</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value pending-value"><?php echo number_format($summary['pending_offers'] ?? 0); ?></div>
                        <div class="metric-label">Pending Review</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value active-value"><?php echo number_format($summary['active_offers'] ?? 0); ?></div>
                        <div class="metric-label">Active</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value paused-value"><?php echo number_format($summary['paused_offers'] ?? 0); ?></div>
                        <div class="metric-label">Paused</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value revenue-value">$<?php echo number_format($summary['total_earned_revenue'] ?? 0, 2); ?></div>
                        <div class="metric-label">Total Revenue</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-card">
                    <div class="filter-row">
                        <form method="get" class="w-100">
                            <div class="filter-row">
                                <div class="filter-group">
                                    <label for="search"><i class="fas fa-search mr-1"></i> Search</label>
                                    <input type="text" name="search" id="search" class="filter-control" 
                                           placeholder="Campaign name, advertiser..." 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label for="status"><i class="fas fa-toggle-on mr-1"></i> Status</label>
                                    <select name="status" id="status" class="filter-control">
                                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="paused" <?php echo $statusFilter === 'paused' ? 'selected' : ''; ?>>Paused</option>
                                        <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label for="advertiser"><i class="fas fa-building mr-1"></i> Advertiser</label>
                                    <select name="advertiser" id="advertiser" class="filter-control">
                                        <option value="all" <?php echo $advertiserFilter === 'all' ? 'selected' : ''; ?>>All Advertisers</option>
                                        <?php foreach ($advertisers as $adv): ?>
                                        <option value="<?php echo $adv['user_id']; ?>" <?php echo $advertiserFilter == $adv['user_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($adv['name'] . ($adv['company'] ? ' - ' . $adv['company'] : '')); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label for="category"><i class="fas fa-tag mr-1"></i> Category</label>
                                    <select name="category" id="category" class="filter-control">
                                        <option value="all" <?php echo $categoryFilter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                        <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $categoryFilter === $cat ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label for="sort"><i class="fas fa-sort mr-1"></i> Sort By</label>
                                    <select name="sort" id="sort" class="filter-control">
                                        <option value="recent" <?php echo $sortBy === 'recent' ? 'selected' : ''; ?>>Most Recent</option>
                                        <option value="oldest" <?php echo $sortBy === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                        <option value="name_asc" <?php echo $sortBy === 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                                        <option value="name_desc" <?php echo $sortBy === 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
                                        <option value="payout_high" <?php echo $sortBy === 'payout_high' ? 'selected' : ''; ?>>Payout (High to Low)</option>
                                        <option value="clicks_high" <?php echo $sortBy === 'clicks_high' ? 'selected' : ''; ?>>Clicks (High to Low)</option>
                                        <option value="conversions_high" <?php echo $sortBy === 'conversions_high' ? 'selected' : ''; ?>>Conversions (High to Low)</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <button type="submit" class="btn-gradient" style="height: 45px; width: 100%;">
                                        <i class="fas fa-search mr-2"></i> Apply Filters
                                    </button>
                                </div>
                                
                                <div class="filter-group">
                                    <a href="offers.php" class="btn btn-outline-primary" style="height: 45px; width: 100%; display: flex; align-items: center; justify-content: center;">
                                        <i class="fas fa-redo mr-2"></i> Reset
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Bulk Actions -->
                <form method="post" id="bulkForm">
                    <div class="bulk-actions">
                        <div class="form-check select-all-checkbox">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                            <label class="form-check-label" for="selectAll">Select All</label>
                        </div>
                        
                        <select name="bulk_action" class="filter-control" style="width: auto;" required>
                            <option value="">Bulk Actions</option>
                            <option value="approve">Approve Selected</option>
                            <option value="activate">Activate Selected</option>
                            <option value="pause">Pause Selected</option>
                            <option value="reject">Reject Selected</option>
                            <option value="delete">Delete Selected</option>
                        </select>
                        
                        <button type="submit" class="btn btn-outline-primary btn-sm" onclick="return confirmBulkAction()">
                            <i class="fas fa-play mr-1"></i> Apply
                        </button>
                        
                        <span class="text-muted ml-2">
                            <?php echo $totalOffers; ?> campaign<?php echo $totalOffers != 1 ? 's' : ''; ?> found
                        </span>
                    </div>

                    <!-- Campaigns Table -->
                    <div class="card-dashboard">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-bullhorn mr-2"></i> All Campaigns
                            </h3>
                            <div class="card-tools">
                                <span class="badge badge-light">
                                    Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($offers)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-bullhorn"></i>
                                    </div>
                                    <h5>No Campaigns Found</h5>
                                    <p class="text-muted">No campaigns match your search criteria.</p>
                                    <a href="offers.php" class="btn btn-gradient btn-sm">
                                        <i class="fas fa-redo mr-2"></i> Reset Filters
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-dashboard" id="offersTable">
                                        <thead>
                                            <tr>
                                                <th style="width: 40px;">
                                                    <input type="checkbox" class="form-check-input" id="checkAll">
                                                </th>
                                                <th>Campaign</th>
                                                <th>Advertiser</th>
                                                <th>Pricing</th>
                                                <th>Status</th>
                                                <th>Visibility</th>
                                                <th>Targeting</th>
                                                <th>Performance</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($offers as $offer): 
                                                $statusClass = 'status-' . $offer['status'];
                                                $visibilityClass = 'visibility-' . ($offer['visibility'] ?? 'public');
                                                $profit = $offer['profit'] ?? 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" 
                                                           name="selected_offers[]" 
                                                           value="<?php echo $offer['offer_id']; ?>" 
                                                           class="form-check-input offer-checkbox">
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="offer-avatar mr-3">
                                                            <?php echo strtoupper(substr($offer['offer_name'], 0, 1)); ?>
                                                        </div>
                                                        <div>
                                                            <strong><?php echo htmlspecialchars($offer['offer_name']); ?></strong>
                                                            <div class="text-muted small">
                                                                ID: #<?php echo $offer['offer_id']; ?>
                                                                <?php if ($offer['category']): ?>
                                                                <span class="ml-2"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($offer['category']); ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php if ($offer['offer_description']): ?>
                                                            <div class="text-muted small truncate-text" style="max-width: 200px;">
                                                                <?php echo htmlspecialchars(substr($offer['offer_description'], 0, 50)) . '...'; ?>
                                                            </div>
                                                            <?php endif; ?>
                                                            <div class="text-muted small">
                                                                <i class="far fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($offer['created_at'])); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($offer['advertiser_name']); ?></strong>
                                                        <?php if ($offer['advertiser_company']): ?>
                                                        <div class="text-muted small">
                                                            <?php echo htmlspecialchars($offer['advertiser_company']); ?>
                                                        </div>
                                                        <?php endif; ?>
                                                        <div class="text-muted small">
                                                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($offer['advertiser_email']); ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold text-success">
                                                        $<?php echo number_format($offer['payout'], 2); ?>
                                                    </div>
                                                    <div class="small text-muted">
                                                        <?php echo strtoupper($offer['payout_type'] ?? 'CPA'); ?>
                                                    </div>
                                                    <div class="small text-primary">
                                                        Revenue: $<?php echo number_format($offer['revenue'], 2); ?>
                                                    </div>
                                                    <div class="small <?php echo $profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                                        Profit: $<?php echo number_format($profit, 2); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?php echo $statusClass; ?>">
                                                        <?php echo ucfirst($offer['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="visibility-badge <?php echo $visibilityClass; ?>">
                                                        <i class="fas fa-<?php echo $offer['visibility'] === 'public' ? 'globe' : 'lock'; ?> mr-1"></i>
                                                        <?php echo ucfirst($offer['visibility'] ?? 'public'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        <?php if ($offer['geo'] && $offer['geo'] !== 'ALL'): ?>
                                                        <div><i class="fas fa-globe mr-1"></i> Geo: <?php echo $offer['geo']; ?></div>
                                                        <?php endif; ?>
                                                        <?php if ($offer['device_type'] && $offer['device_type'] !== 'all'): ?>
                                                        <div><i class="fas fa-mobile-alt mr-1"></i> <?php echo ucfirst($offer['device_type']); ?></div>
                                                        <?php endif; ?>
                                                        <?php if ($offer['daily_cap'] > 0): ?>
                                                        <div><i class="fas fa-chart-line mr-1"></i> Daily: <?php echo $offer['daily_cap']; ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <div class="mb-1">
                                                            <span class="text-primary">
                                                                <i class="fas fa-mouse-pointer mr-1"></i>
                                                                <?php echo number_format($offer['total_clicks']); ?>
                                                            </span>
                                                        </div>
                                                        <div class="mb-1">
                                                            <span class="text-success">
                                                                <i class="fas fa-exchange-alt mr-1"></i>
                                                                <?php echo number_format($offer['approved_conversions']); ?>/<?php echo number_format($offer['total_conversions']); ?>
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <span class="text-warning">
                                                                <i class="fas fa-percentage mr-1"></i>
                                                                CR: <?php echo number_format($offer['conversion_rate'], 2); ?>%
                                                            </span>
                                                        </div>
                                                        <div class="small text-muted mt-1">
                                                            <span class="postback-token-preview" title="<?php echo htmlspecialchars($offer['postback_token'] ?? 'No token'); ?>" onclick="copyToken('<?php echo $offer['postback_token']; ?>')">
                                                                <i class="fas fa-key mr-1"></i>
                                                                Token
                                                            </span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <?php if ($offer['status'] === 'pending'): ?>
                                                        <a href="?action=approve&id=<?php echo $offer['offer_id']; ?>" 
                                                           class="btn-action btn-approve"
                                                           title="Approve"
                                                           onclick="return confirm('Approve this campaign?')">
                                                            <i class="fas fa-check"></i>
                                                        </a>
                                                        <a href="?action=reject&id=<?php echo $offer['offer_id']; ?>" 
                                                           class="btn-action btn-reject"
                                                           title="Reject"
                                                           onclick="return confirm('Reject this campaign?')">
                                                            <i class="fas fa-times"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($offer['status'] === 'approved'): ?>
                                                        <a href="?action=activate&id=<?php echo $offer['offer_id']; ?>" 
                                                           class="btn-action btn-activate"
                                                           title="Activate"
                                                           onclick="return confirm('Activate this campaign?')">
                                                            <i class="fas fa-play"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($offer['status'] === 'active'): ?>
                                                        <a href="?action=pause&id=<?php echo $offer['offer_id']; ?>" 
                                                           class="btn-action btn-pause"
                                                           title="Pause"
                                                           onclick="return confirm('Pause this campaign?')">
                                                            <i class="fas fa-pause"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($offer['status'] === 'paused'): ?>
                                                        <a href="?action=activate&id=<?php echo $offer['offer_id']; ?>" 
                                                           class="btn-action btn-activate"
                                                           title="Activate"
                                                           onclick="return confirm('Activate this campaign?')">
                                                            <i class="fas fa-play"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <a href="offer_edit.php?id=<?php echo $offer['offer_id']; ?>" 
                                                           class="btn-action btn-edit"
                                                           title="Edit Campaign">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        
                                                        <a href="offer_details.php?id=<?php echo $offer['offer_id']; ?>" 
                                                           class="btn-action btn-view"
                                                           title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        
                                                        <a href="?action=delete&id=<?php echo $offer['offer_id']; ?>" 
                                                           class="btn-action btn-delete"
                                                           title="Delete"
                                                           onclick="return confirm('Are you sure you want to delete this campaign? This action cannot be undone.')">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Pagination -->
                                <?php if ($totalPages > 1): ?>
                                <div class="pagination-container">
                                    <?php if ($page > 1): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="page-link">
                                            <i class="fas fa-angle-double-left"></i>
                                        </a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
                                            <i class="fas fa-angle-left"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                           class="page-link <?php echo $i == $page ? 'active' : '' ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>

                                    <?php if ($page < $totalPages): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
                                            <i class="fas fa-angle-right"></i>
                                        </a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>" class="page-link">
                                            <i class="fas fa-angle-double-right"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline">
            <strong>Admin Panel v3.0</strong>
        </div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- REQUIRED SCRIPTS -->
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<!-- Bootstrap 4 -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- AdminLTE App -->
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#offersTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [], // Disable initial sort
        responsive: true,
        searching: false, // We use custom search
        info: false,
        paging: false, // We use custom pagination
        language: {
            emptyTable: "No campaigns found"
        }
    });
    
    // Dark mode toggle
    $('#darkModeToggle').click(function(e) {
        e.preventDefault();
        $('body').toggleClass('dark-mode');
        $(this).find('i').toggleClass('fa-moon fa-sun');
        localStorage.setItem('darkMode', $('body').hasClass('dark-mode'));
    });
    
    if (localStorage.getItem('darkMode') === 'true') {
        $('body').addClass('dark-mode');
        $('#darkModeToggle i').removeClass('fa-moon').addClass('fa-sun');
    }
    
    // Select all functionality
    $('#selectAll, #checkAll').click(function() {
        $('.offer-checkbox').prop('checked', $(this).prop('checked'));
    });
    
    // Update "Select All" checkbox when individual checkboxes change
    $('.offer-checkbox').change(function() {
        if ($('.offer-checkbox:checked').length === $('.offer-checkbox').length) {
            $('#selectAll, #checkAll').prop('checked', true);
        } else {
            $('#selectAll, #checkAll').prop('checked', false);
        }
    });
    
    // Confirm bulk action
    window.confirmBulkAction = function() {
        const action = document.querySelector('select[name="bulk_action"]').value;
        const selectedCount = document.querySelectorAll('.offer-checkbox:checked').length;
        
        if (!action) {
            Swal.fire({
                icon: 'warning',
                title: 'Action Required',
                text: 'Please select a bulk action.'
            });
            return false;
        }
        
        if (selectedCount === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Selection Required',
                text: 'Please select at least one campaign.'
            });
            return false;
        }
        
        let message = '';
        switch(action) {
            case 'approve':
                message = `Approve ${selectedCount} campaign(s)?`;
                break;
            case 'activate':
                message = `Activate ${selectedCount} campaign(s)?`;
                break;
            case 'pause':
                message = `Pause ${selectedCount} campaign(s)?`;
                break;
            case 'reject':
                message = `Reject ${selectedCount} campaign(s)?`;
                break;
            case 'delete':
                message = `Delete ${selectedCount} campaign(s)? This action cannot be undone.`;
                break;
        }
        
        return confirm(message);
    };
    
    // Auto-dismiss alerts after 5 seconds
    $('.alert').delay(5000).fadeOut('slow');
    
    // Search focus
    $('#search').focus();
    
    // Initialize SweetAlert2 Toast
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });
});

// Copy token function
function copyToken(token) {
    navigator.clipboard.writeText(token).then(() => {
        Swal.fire({
            title: 'Copied!',
            text: 'Postback token copied to clipboard',
            icon: 'success',
            timer: 2000,
            showConfirmButton: false
        });
    });
}
</script>

</body>
</html>