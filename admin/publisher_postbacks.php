<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

/* ===============================
   SAVE / UPDATE AFFILIATE POSTBACK
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_postback'])) {
        $affiliateId = (int)$_POST['affiliate_id'];
        $postbackUrl = trim($_POST['postback_url']);
        $status = $_POST['status'] ?? 'active';
        $postbackType = $_POST['postback_type'] ?? 'global';
        $name = trim($_POST['postback_name'] ?? '');

        if (!$affiliateId || empty($postbackUrl)) {
            $error = 'Affiliate and Postback URL are required';
        } elseif (!filter_var($postbackUrl, FILTER_VALIDATE_URL)) {
            $error = 'Please enter a valid URL';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO affiliate_postbacks 
                    (affiliate_id, postback_url, status, postback_type, name, updated_at)
                VALUES 
                    (:aid, :url, :status, :type, :name, NOW())
                ON DUPLICATE KEY UPDATE
                    postback_url = VALUES(postback_url),
                    status = VALUES(status),
                    postback_type = VALUES(postback_type),
                    name = VALUES(name),
                    updated_at = NOW()
            ");

            $stmt->execute([
                'aid' => $affiliateId,
                'url' => $postbackUrl,
                'status' => $status,
                'type' => $postbackType,
                'name' => $name
            ]);

            $success = 'Postback configuration saved successfully';
        }
    }
    
    // Bulk status update
    if (isset($_POST['bulk_status'])) {
        $selectedAffiliates = $_POST['selected_affiliates'] ?? [];
        $bulkStatus = $_POST['bulk_status'];
        
        if (empty($selectedAffiliates)) {
            $error = 'Please select at least one affiliate';
        } else {
            $placeholders = implode(',', array_fill(0, count($selectedAffiliates), '?'));
            $stmt = $pdo->prepare("
                UPDATE affiliate_postbacks 
                SET status = ?, updated_at = NOW() 
                WHERE affiliate_id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$bulkStatus], $selectedAffiliates));
            
            $success = count($selectedAffiliates) . ' postback(s) status updated to ' . ucfirst($bulkStatus);
        }
    }
}

/* ===============================
   FETCH AFFILIATES + POSTBACKS
================================ */
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? 'all';
$typeFilter = $_GET['type'] ?? 'all';

// Build WHERE clause for affiliates
$where = ['u.role_id = 3'];
$params = [];

if ($search) {
    $where[] = '(u.name LIKE :search OR u.email LIKE :search OR ap.name LIKE :search)';
    $params['search'] = "%$search%";
}

if ($statusFilter !== 'all') {
    $where[] = 'COALESCE(ap.status, "inactive") = :status';
    $params['status'] = $statusFilter;
}

if ($typeFilter !== 'all') {
    $where[] = 'ap.postback_type = :type';
    $params['type'] = $typeFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$affiliates = $pdo->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.status as affiliate_status,
        u.created_at as affiliate_joined,
        ap.id as postback_id,
        ap.postback_url,
        ap.status as postback_status,
        ap.postback_type,
        ap.name as postback_name,
        ap.created_at as postback_created,
        ap.updated_at as postback_updated,
        
        -- Postback statistics
        COUNT(DISTINCT pl.id) as total_fires,
        SUM(CASE WHEN pl.response_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) as successful_fires,
        SUM(CASE WHEN pl.response_code NOT BETWEEN 200 AND 299 OR pl.response_code IS NULL THEN 1 ELSE 0 END) as failed_fires,
        MAX(pl.fired_at) as last_fired
        
    FROM users u
    LEFT JOIN affiliate_postbacks ap 
        ON ap.affiliate_id = u.user_id
    LEFT JOIN postback_logs_aff pl 
        ON pl.affiliate_id = u.user_id
    $whereSql
    GROUP BY u.user_id, ap.id
    ORDER BY u.name ASC
");

foreach ($params as $key => $value) {
    $affiliates->bindValue($key, $value);
}

$affiliates->execute();
$affiliateData = $affiliates->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH POSTBACK LOGS WITH FILTERS
================================ */
$logSearch = $_GET['log_search'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$logStatus = $_GET['log_status'] ?? 'all';

$logWhere = [];
$logParams = [];

if ($logSearch) {
    $logWhere[] = '(u.name LIKE :log_search OR u.email LIKE :log_search OR pl.offer_id LIKE :log_search)';
    $logParams['log_search'] = "%$logSearch%";
}

if ($dateFrom) {
    $logWhere[] = 'pl.fired_at >= :date_from';
    $logParams['date_from'] = $dateFrom . ' 00:00:00';
}

if ($dateTo) {
    $logWhere[] = 'pl.fired_at <= :date_to';
    $logParams['date_to'] = $dateTo . ' 23:59:59';
}

if ($logStatus !== 'all') {
    if ($logStatus === 'success') {
        $logWhere[] = 'pl.response_code BETWEEN 200 AND 299';
    } elseif ($logStatus === 'failed') {
        $logWhere[] = '(pl.response_code NOT BETWEEN 200 AND 299 OR pl.response_code IS NULL)';
    }
}

$logWhereSql = $logWhere ? 'WHERE ' . implode(' AND ', $logWhere) : '';

$postbackLogs = $pdo->prepare("
    SELECT 
        pl.id,
        pl.affiliate_id,
        u.name AS affiliate_name,
        u.email AS affiliate_email,
        pl.offer_id,
        o.offer_name,
        pl.conversion_id,
        pl.payout,
        pl.response_code,
        pl.response_body,
        pl.error_message,
        pl.fired_at,
        TIMESTAMPDIFF(SECOND, pl.created_at, pl.fired_at) as response_time,
        CASE 
            WHEN pl.response_code BETWEEN 200 AND 299 THEN 'success'
            ELSE 'failed'
        END as fire_status
    FROM postback_logs_aff pl
    LEFT JOIN users u ON u.user_id = pl.affiliate_id
    LEFT JOIN offers o ON o.offer_id = pl.offer_id
    $logWhereSql
    ORDER BY pl.fired_at DESC
    LIMIT 50
");

foreach ($logParams as $key => $value) {
    $postbackLogs->bindValue($key, $value);
}

$postbackLogs->execute();
$logData = $postbackLogs->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   STATISTICS
================================ */
$stats = $pdo->query("
    SELECT 
        COUNT(DISTINCT u.user_id) AS total_affiliates,
        COUNT(DISTINCT ap.affiliate_id) AS affiliates_with_postback,
        SUM(CASE WHEN ap.status = 'active' THEN 1 ELSE 0 END) AS active_postbacks,
        SUM(CASE WHEN ap.status = 'inactive' THEN 1 ELSE 0 END) AS inactive_postbacks,

        -- Log stats (last 24h)
        COUNT(pl.id) AS fires_24h,
        SUM(CASE WHEN pl.response_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS success_24h,
        SUM(CASE WHEN pl.response_code NOT BETWEEN 200 AND 299 OR pl.response_code IS NULL THEN 1 ELSE 0 END) AS failed_24h,

        AVG(TIMESTAMPDIFF(SECOND, pl.created_at, pl.fired_at)) AS avg_response_time

    FROM users u
    LEFT JOIN affiliate_postbacks ap 
        ON ap.affiliate_id = u.user_id
    LEFT JOIN postback_logs_aff pl 
        ON pl.affiliate_id = u.user_id
        AND pl.fired_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    WHERE u.role_id = 3
")->fetch(PDO::FETCH_ASSOC);


/* ===============================
   POSTBACK TEMPLATES
================================ */
$postbackTemplates = [
    'global' => [
        'name' => 'Global Postback',
        'template' => 'https://affiliate-domain.com/postback?cid={click_id}&payout={payout}&status={status}&offer={offer_id}',
        'variables' => ['click_id', 'payout', 'status', 'offer_id', 'affiliate_id', 'transaction_id']
    ],
    'hasoffers' => [
        'name' => 'HasOffers/Tune',
        'template' => 'https://tracking.hasoffers.com/tracking.php?aff_sub={click_id}&aff_sub2={offer_id}&payout={payout}',
        'variables' => ['click_id', 'offer_id', 'payout', 'status']
    ],
    'cake' => [
        'name' => 'CAKE',
        'template' => 'https://partner.domain.go2cloud.org/aff_lsr?transaction_id={transaction_id}&adv_sub={affiliate_id}&amount={payout}',
        'variables' => ['transaction_id', 'affiliate_id', 'payout', 'click_id']
    ],
    'custom' => [
        'name' => 'Custom Template',
        'template' => '',
        'variables' => ['click_id', 'payout', 'status', 'offer_id', 'affiliate_id', 'transaction_id', 'user_id', 'conversion_id']
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Publisher Postbacks | Admin Panel | GVS Icon Media</title>
    
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
            font-weight: 700;
        }
        
        .active-value {
            color: #28a745;
            font-weight: 700;
        }
        
        .inactive-value {
            color: #6c757d;
            font-weight: 700;
        }
        
        .fired-value {
            color: #fd7e14;
            font-weight: 700;
        }
        
        .success-value {
            color: #20c997;
            font-weight: 700;
        }
        
        .failed-value {
            color: #dc3545;
            font-weight: 700;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-active {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .status-inactive {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.2);
        }
        
        .status-disabled {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .type-badge {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .response-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .response-success {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .response-failed {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .response-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
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
        
        .btn-edit {
            background: rgba(32, 201, 151, 0.1);
            color: #20c997;
            border: 1px solid rgba(32, 201, 151, 0.2);
        }
        
        .btn-edit:hover {
            background: rgba(32, 201, 151, 0.2);
            color: #20c997;
        }
        
        .btn-test {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .btn-test:hover {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
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
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .empty-state-icon {
            font-size: 50px;
            color: #e3e6f0;
            margin-bottom: 15px;
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
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
        
        .url-box {
            background: #f8f9fa;
            border: 1px solid #e3e6f0;
            border-radius: 8px;
            padding: 12px;
            font-family: monospace;
            font-size: 13px;
            word-break: break-all;
            margin-bottom: 10px;
        }
        
        .template-tag {
            background: rgba(32, 201, 151, 0.1);
            color: #20c997;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-family: monospace;
            margin: 2px;
            display: inline-block;
        }
        
        .log-success {
            background: rgba(40, 167, 69, 0.05);
        }
        
        .log-failed {
            background: rgba(220, 53, 69, 0.05);
        }
        
        .stats-badge {
            background: #f8f9fa;
            color: #6c757d;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .tab-content {
            margin-top: 20px;
        }
        
        .nav-tabs {
            border-bottom: 2px solid #e3e6f0;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 8px 8px 0 0;
        }
        
        .nav-tabs .nav-link.active {
            color: #4e73df;
            border-bottom: 3px solid #4e73df;
            background: rgba(78, 115, 223, 0.05);
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
                <a href="publisher_postbacks.php" class="nav-link active">Publisher Postbacks</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <a class="nav-link" data-widget="fullscreen" href="#" role="button">
                    <i class="fas fa-expand-arrows-alt"></i>
                </a>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                    <i class="fas fa-user-circle mr-1"></i> <?php echo htmlspecialchars($adminName); ?>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user mr-2"></i> Profile
                    </a>
                    <a href="settings.php" class="dropdown-item">
                        <i class="fas fa-cog mr-2"></i> Settings
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
        <!-- Brand Logo -->
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.5rem;">
                <i class="fas fa-crown mr-2"></i>
                <strong>Admin</strong>
            </span>
        </a>

        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Sidebar Menu -->
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
                        <a href="campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-bullhorn"></i>
                            <p>Manage Campaigns</p>
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
                        <a href="publisher_postbacks.php" class="nav-link active">
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
                        <h1 class="m-0">Publisher Postbacks</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="publishers.php">Publishers</a></li>
                            <li class="breadcrumb-item active">Postback Management</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">
                <!-- Dashboard Header -->
                <div class="dashboard-header">
                    <h2 class="mb-0">Postback Configuration</h2>
                    <div class="action-buttons-group">
                        <a href="?export=csv" class="btn btn-outline-primary">
                            <i class="fas fa-download mr-2"></i> Export Config
                        </a>
                        <a href="postback_test.php" class="btn btn-gradient">
                            <i class="fas fa-vial mr-2"></i> Test Postbacks
                        </a>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                    <h5><i class="icon fas fa-check"></i> Success!</h5>
                    <?php echo $success; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                    <h5><i class="icon fas fa-ban"></i> Error!</h5>
                    <?php echo $error; ?>
                </div>
                <?php endif; ?>

                <!-- Summary Stats -->
                <div class="summary-stats">
                    <div class="metric-card">
                        <div class="metric-value total-value"><?php echo number_format($stats['total_affiliates'] ?? 0); ?></div>
                        <div class="metric-label">Total Publishers</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value active-value"><?php echo number_format($stats['affiliates_with_postback'] ?? 0); ?></div>
                        <div class="metric-label">With Postback</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value success-value"><?php echo number_format($stats['active_postbacks'] ?? 0); ?></div>
                        <div class="metric-label">Active Postbacks</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value fired-value"><?php echo number_format($stats['fires_24h'] ?? 0); ?></div>
                        <div class="metric-label">Fires (24h)</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value success-value"><?php echo number_format($stats['success_24h'] ?? 0); ?></div>
                        <div class="metric-label">Success (24h)</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value failed-value"><?php echo number_format($stats['failed_24h'] ?? 0); ?></div>
                        <div class="metric-label">Failed (24h)</div>
                    </div>
                </div>

                <!-- Available Variables -->
                <div class="card-dashboard">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-code mr-2"></i> Available Postback Variables
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <p class="text-muted">Use these variables in your postback URLs:</p>
                            <div>
                                <span class="template-tag">{click_id}</span>
                                <span class="template-tag">{payout}</span>
                                <span class="template-tag">{status}</span>
                                <span class="template-tag">{offer_id}</span>
                                <span class="template-tag">{affiliate_id}</span>
                                <span class="template-tag">{transaction_id}</span>
                                <span class="template-tag">{user_id}</span>
                                <span class="template-tag">{conversion_id}</span>
                                <span class="template-tag">{country}</span>
                                <span class="template-tag">{device}</span>
                            </div>
                        </div>
                        
                        <!-- Quick Templates -->
                        <div class="mb-3">
                            <label class="mb-2">Quick Templates:</label>
                            <select class="filter-control" id="templateSelector" onchange="applyTemplate(this.value)">
                                <option value="">Select a template...</option>
                                <?php foreach ($postbackTemplates as $key => $template): ?>
                                <option value="<?php echo $key; ?>"><?php echo $template['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Bulk Actions -->
                <form method="post" id="bulkForm">
                    <div class="bulk-actions">
                        <div class="form-check select-all-checkbox">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                            <label class="form-check-label" for="selectAll">Select All</label>
                        </div>
                        
                        <select name="bulk_status" class="filter-control" style="width: auto;">
                            <option value="">Bulk Status Update</option>
                            <option value="active">Activate Selected</option>
                            <option value="inactive">Deactivate Selected</option>
                            <option value="disabled">Disable Selected</option>
                        </select>
                        
                        <button type="submit" class="btn btn-outline-primary btn-sm" onclick="return confirm('Apply bulk status update?')">
                            <i class="fas fa-play mr-1"></i> Apply
                        </button>
                    </div>

                    <!-- Tabs -->
                    <ul class="nav nav-tabs" id="postbackTab" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="config-tab" data-toggle="tab" href="#config" role="tab">
                                <i class="fas fa-cog mr-2"></i> Postback Configuration
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="logs-tab" data-toggle="tab" href="#logs" role="tab">
                                <i class="fas fa-history mr-2"></i> Postback Logs (<?php echo count($logData); ?>)
                            </a>
                        </li>
                    </ul>

                    <div class="tab-content" id="postbackTabContent">
                        <!-- Configuration Tab -->
                        <div class="tab-pane fade show active" id="config" role="tabpanel">
                            <!-- Filters -->
                            <div class="card-dashboard">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas fa-filter mr-2"></i> Filter Publishers
                                    </h3>
                                </div>
                                <div class="card-body">
                                    <form method="get" class="filter-row">
                                        <div class="filter-group">
                                            <label for="search"><i class="fas fa-search mr-1"></i> Search</label>
                                            <input type="text" name="search" id="search" class="filter-control" 
                                                   placeholder="Search by name, email..." 
                                                   value="<?php echo htmlspecialchars($search); ?>">
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label for="status"><i class="fas fa-toggle-on mr-1"></i> Postback Status</label>
                                            <select name="status" id="status" class="filter-control">
                                                <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                                                <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                <option value="disabled" <?php echo $statusFilter === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                                            </select>
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label for="type"><i class="fas fa-tag mr-1"></i> Postback Type</label>
                                            <select name="type" id="type" class="filter-control">
                                                <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                                <option value="global" <?php echo $typeFilter === 'global' ? 'selected' : ''; ?>>Global</option>
                                                <option value="hasoffers" <?php echo $typeFilter === 'hasoffers' ? 'selected' : ''; ?>>HasOffers</option>
                                                <option value="cake" <?php echo $typeFilter === 'cake' ? 'selected' : ''; ?>>CAKE</option>
                                                <option value="custom" <?php echo $typeFilter === 'custom' ? 'selected' : ''; ?>>Custom</option>
                                            </select>
                                        </div>
                                        
                                        <div class="filter-group">
                                            <button type="submit" class="btn-gradient" style="height: 45px; width: 100%;">
                                                <i class="fas fa-search mr-2"></i> Apply Filters
                                            </button>
                                        </div>
                                        
                                        <div class="filter-group">
                                            <a href="publisher_postbacks.php" class="btn btn-outline-primary" style="height: 45px; width: 100%; display: flex; align-items: center; justify-content: center;">
                                                <i class="fas fa-redo mr-2"></i> Reset
                                            </a>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Publishers Configuration Table -->
                            <div class="card-dashboard">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas fa-user-friends mr-2"></i> Publisher Postback Configuration
                                    </h3>
                                    <div class="card-tools">
                                        <span class="badge badge-light">
                                            <?php echo count($affiliateData); ?> publisher<?php echo count($affiliateData) != 1 ? 's' : ''; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($affiliateData)): ?>
                                        <div class="empty-state">
                                            <div class="empty-state-icon">
                                                <i class="fas fa-user-friends"></i>
                                            </div>
                                            <h5>No Publishers Found</h5>
                                            <p class="text-muted">No publishers match your search criteria.</p>
                                            <a href="publisher_postbacks.php" class="btn btn-gradient btn-sm">
                                                <i class="fas fa-redo mr-2"></i> Reset Filters
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-dashboard" id="postbacksTable">
                                                <thead>
                                                    <tr>
                                                        <th style="width: 40px;">
                                                            <input type="checkbox" class="form-check-input" id="checkAllConfig">
                                                        </th>
                                                        <th>Publisher</th>
                                                        <th>Postback Configuration</th>
                                                        <th>Status</th>
                                                        <th>Statistics</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($affiliateData as $affiliate): ?>
                                                    <tr>
                                                        <td>
                                                            <input type="checkbox" 
                                                                   name="selected_affiliates[]" 
                                                                   value="<?php echo $affiliate['user_id']; ?>" 
                                                                   class="form-check-input affiliate-checkbox">
                                                        </td>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="mr-3">
                                                                    <div style="width: 40px; height: 40px; background: #667eea; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600;">
                                                                        <?php echo strtoupper(substr($affiliate['name'], 0, 1)); ?>
                                                                    </div>
                                                                </div>
                                                                <div>
                                                                    <strong><?php echo htmlspecialchars($affiliate['name']); ?></strong>
                                                                    <div class="text-muted small">
                                                                        <?php echo htmlspecialchars($affiliate['email']); ?>
                                                                    </div>
                                                                    <div class="text-muted small">
                                                                        Joined: <?php echo date('M d, Y', strtotime($affiliate['affiliate_joined'])); ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <form method="post" class="postback-form">
                                                                <input type="hidden" name="affiliate_id" value="<?php echo $affiliate['user_id']; ?>">
                                                                
                                                                <div class="mb-2">
                                                                    <label class="small text-muted">Postback Name</label>
                                                                    <input type="text" 
                                                                           name="postback_name" 
                                                                           class="filter-control" 
                                                                           placeholder="My Postback"
                                                                           value="<?php echo htmlspecialchars($affiliate['postback_name'] ?? ''); ?>">
                                                                </div>
                                                                
                                                                <div class="mb-2">
                                                                    <label class="small text-muted">Postback Type</label>
                                                                    <select name="postback_type" class="filter-control">
                                                                        <option value="global" <?php echo ($affiliate['postback_type'] ?? 'global') === 'global' ? 'selected' : ''; ?>>Global</option>
                                                                        <option value="hasoffers" <?php echo ($affiliate['postback_type'] ?? '') === 'hasoffers' ? 'selected' : ''; ?>>HasOffers/Tune</option>
                                                                        <option value="cake" <?php echo ($affiliate['postback_type'] ?? '') === 'cake' ? 'selected' : ''; ?>>CAKE</option>
                                                                        <option value="custom" <?php echo ($affiliate['postback_type'] ?? '') === 'custom' ? 'selected' : ''; ?>>Custom</option>
                                                                    </select>
                                                                </div>
                                                                
                                                                <div class="mb-2">
                                                                    <label class="small text-muted">Postback URL</label>
                                                                    <input type="text" 
                                                                           name="postback_url" 
                                                                           class="filter-control" 
                                                                           placeholder="https://example.com/postback?cid={click_id}&payout={payout}"
                                                                           value="<?php echo htmlspecialchars($affiliate['postback_url'] ?? ''); ?>"
                                                                           required>
                                                                </div>
                                                                
                                                                <div class="d-flex justify-content-between align-items-center mt-3">
                                                                    <div>
                                                                        <label class="small text-muted mr-2">Status:</label>
                                                                        <select name="status" class="filter-control" style="width: auto; padding: 4px 8px;">
                                                                            <option value="active" <?php echo ($affiliate['postback_status'] ?? 'inactive') === 'active' ? 'selected' : ''; ?>>Active</option>
                                                                            <option value="inactive" <?php echo ($affiliate['postback_status'] ?? 'inactive') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                                            <option value="disabled" <?php echo ($affiliate['postback_status'] ?? 'inactive') === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                                                                        </select>
                                                                    </div>
                                                                    <button type="submit" name="save_postback" class="btn btn-sm btn-gradient">
                                                                        <i class="fas fa-save mr-1"></i> Save
                                                                    </button>
                                                                </div>
                                                            </form>
                                                        </td>
                                                        <td>
                                                            <div class="mb-2">
                                                                <span class="status-badge status-<?php echo $affiliate['postback_status'] ?? 'inactive'; ?>">
                                                                    <?php echo ucfirst($affiliate['postback_status'] ?? 'inactive'); ?>
                                                                </span>
                                                            </div>
                                                            <div class="mb-2">
                                                                <span class="type-badge">
                                                                    <?php echo ucfirst($affiliate['postback_type'] ?? 'not configured'); ?>
                                                                </span>
                                                            </div>
                                                            <?php if ($affiliate['postback_updated']): ?>
                                                            <div class="small text-muted">
                                                                Updated: <?php echo date('M d, Y', strtotime($affiliate['postback_updated'])); ?>
                                                            </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($affiliate['postback_url']): ?>
                                                            <div class="mb-1">
                                                                <span class="text-primary">
                                                                    <i class="fas fa-bolt mr-1"></i>
                                                                    <?php echo number_format($affiliate['total_fires'] ?? 0); ?> fires
                                                                </span>
                                                            </div>
                                                            <div class="mb-1">
                                                                <span class="text-success">
                                                                    <i class="fas fa-check-circle mr-1"></i>
                                                                    <?php echo number_format($affiliate['successful_fires'] ?? 0); ?> success
                                                                </span>
                                                            </div>
                                                            <div class="mb-1">
                                                                <span class="text-danger">
                                                                    <i class="fas fa-times-circle mr-1"></i>
                                                                    <?php echo number_format($affiliate['failed_fires'] ?? 0); ?> failed
                                                                </span>
                                                            </div>
                                                            <?php if ($affiliate['last_fired']): ?>
                                                            <div class="small text-muted">
                                                                Last: <?php echo date('M d, H:i', strtotime($affiliate['last_fired'])); ?>
                                                            </div>
                                                            <?php endif; ?>
                                                            <?php else: ?>
                                                            <span class="text-muted">No postback configured</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="action-buttons">
                                                                <a href="?test_postback=<?php echo $affiliate['user_id']; ?>" 
                                                                   class="btn-action btn-test"
                                                                   title="Test Postback"
                                                                   onclick="return confirm('Send test postback?')">
                                                                    <i class="fas fa-vial"></i>
                                                                </a>
                                                                <a href="postback_logs.php?affiliate_id=<?php echo $affiliate['user_id']; ?>" 
                                                                   class="btn-action btn-view"
                                                                   title="View Logs">
                                                                    <i class="fas fa-history"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Logs Tab -->
                        <div class="tab-pane fade" id="logs" role="tabpanel">
                            <!-- Log Filters -->
                            <div class="card-dashboard">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas fa-filter mr-2"></i> Filter Logs
                                    </h3>
                                </div>
                                <div class="card-body">
                                    <form method="get" class="filter-row">
                                        <input type="hidden" name="tab" value="logs">
                                        
                                        <div class="filter-group">
                                            <label for="log_search"><i class="fas fa-search mr-1"></i> Search</label>
                                            <input type="text" name="log_search" id="log_search" class="filter-control" 
                                                   placeholder="Search by publisher, offer..." 
                                                   value="<?php echo htmlspecialchars($logSearch); ?>">
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label for="log_status"><i class="fas fa-check-circle mr-1"></i> Status</label>
                                            <select name="log_status" id="log_status" class="filter-control">
                                                <option value="all" <?php echo $logStatus === 'all' ? 'selected' : ''; ?>>All Status</option>
                                                <option value="success" <?php echo $logStatus === 'success' ? 'selected' : ''; ?>>Successful</option>
                                                <option value="failed" <?php echo $logStatus === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                            </select>
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label for="date_from"><i class="fas fa-calendar-alt mr-1"></i> From Date</label>
                                            <input type="date" name="date_from" id="date_from" class="filter-control" 
                                                   value="<?php echo htmlspecialchars($dateFrom); ?>">
                                        </div>
                                        
                                        <div class="filter-group">
                                            <label for="date_to"><i class="fas fa-calendar-alt mr-1"></i> To Date</label>
                                            <input type="date" name="date_to" id="date_to" class="filter-control" 
                                                   value="<?php echo htmlspecialchars($dateTo); ?>">
                                        </div>
                                        
                                        <div class="filter-group">
                                            <button type="submit" class="btn-gradient" style="height: 45px; width: 100%;">
                                                <i class="fas fa-search mr-2"></i> Apply Filters
                                            </button>
                                        </div>
                                        
                                        <div class="filter-group">
                                            <a href="publisher_postbacks.php?tab=logs" class="btn btn-outline-primary" style="height: 45px; width: 100%; display: flex; align-items: center; justify-content: center;">
                                                <i class="fas fa-redo mr-2"></i> Reset
                                            </a>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Logs Table -->
                            <div class="card-dashboard">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas fa-history mr-2"></i> Recent Postback Logs
                                    </h3>
                                    <div class="card-tools">
                                        <span class="badge badge-light">
                                            Last 50 entries
                                        </span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($logData)): ?>
                                        <div class="empty-state">
                                            <div class="empty-state-icon">
                                                <i class="fas fa-history"></i>
                                            </div>
                                            <h5>No Postback Logs Found</h5>
                                            <p class="text-muted">No logs match your search criteria.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-dashboard" id="logsTable">
                                                <thead>
                                                    <tr>
                                                        <th>Publisher</th>
                                                        <th>Offer</th>
                                                        <th>Payout</th>
                                                        <th>Response</th>
                                                        <th>Response Time</th>
                                                        <th>Fired At</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($logData as $log): ?>
                                                    <tr class="<?php echo $log['fire_status'] === 'success' ? 'log-success' : 'log-failed'; ?>">
                                                        <td>
                                                            <div>
                                                                <strong><?php echo htmlspecialchars($log['affiliate_name'] ?? 'Unknown'); ?></strong>
                                                                <div class="text-muted small">
                                                                    ID: <?php echo $log['affiliate_id']; ?>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div>
                                                                <strong><?php echo htmlspecialchars($log['offer_name'] ?? 'N/A'); ?></strong>
                                                                <div class="text-muted small">
                                                                    ID: <?php echo $log['offer_id']; ?>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="text-warning font-weight-bold">
                                                                $<?php echo number_format($log['payout'] ?? 0, 2); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div>
                                                                <span class="response-badge response-<?php echo $log['fire_status']; ?>">
                                                                    <?php echo $log['response_code'] ?? 'No Response'; ?>
                                                                </span>
                                                                <?php if ($log['error_message']): ?>
                                                                <div class="small text-danger mt-1">
                                                                    <?php echo htmlspecialchars(substr($log['error_message'], 0, 50)) . '...'; ?>
                                                                </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <?php if ($log['response_time']): ?>
                                                            <span class="text-muted">
                                                                <?php echo $log['response_time']; ?>s
                                                            </span>
                                                            <?php else: ?>
                                                            <span class="text-muted">N/A</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo date('M d, Y H:i:s', strtotime($log['fired_at'])); ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($log['fire_status'] === 'success'): ?>
                                                            <span class="text-success">
                                                                <i class="fas fa-check-circle"></i> Success
                                                            </span>
                                                            <?php else: ?>
                                                            <span class="text-danger">
                                                                <i class="fas fa-times-circle"></i> Failed
                                                            </span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
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
    // Initialize DataTables
    $('#postbacksTable, #logsTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [], // Disable initial sort
        responsive: true,
        searching: false, // We use custom search
        info: true,
        paging: true,
        language: {
            emptyTable: "No data found"
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
    $('#selectAll, #checkAllConfig').click(function() {
        $('.affiliate-checkbox').prop('checked', $(this).prop('checked'));
    });
    
    // Update "Select All" checkbox when individual checkboxes change
    $('.affiliate-checkbox').change(function() {
        if ($('.affiliate-checkbox:checked').length === $('.affiliate-checkbox').length) {
            $('#selectAll, #checkAllConfig').prop('checked', true);
        } else {
            $('#selectAll, #checkAllConfig').prop('checked', false);
        }
    });
    
    // Template selector
    window.applyTemplate = function(templateKey) {
        if (!templateKey) return;
        
        const templates = {
            'global': 'https://affiliate-domain.com/postback?cid={click_id}&payout={payout}&status={status}&offer={offer_id}',
            'hasoffers': 'https://tracking.hasoffers.com/tracking.php?aff_sub={click_id}&aff_sub2={offer_id}&payout={payout}',
            'cake': 'https://partner.domain.go2cloud.org/aff_lsr?transaction_id={transaction_id}&adv_sub={affiliate_id}&amount={payout}',
            'custom': ''
        };
        
        if (templates[templateKey]) {
            // Find the currently focused input and set its value
            $('input[name="postback_url"]:focus').val(templates[templateKey]);
        }
    };
    
    // Auto-dismiss alerts after 5 seconds
    $('.alert').delay(5000).fadeOut('slow');
    
    // Tab handling from URL
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab');
    if (tabParam === 'logs') {
        $('#logs-tab').tab('show');
    }
    
    // Form submission feedback
    $('.postback-form').submit(function(e) {
        const form = $(this);
        const button = form.find('button[type="submit"]');
        const originalText = button.html();
        
        button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');
        
        setTimeout(() => {
            button.prop('disabled', false).html(originalText);
        }, 2000);
    });
    
    // URL validation
    $('input[name="postback_url"]').on('blur', function() {
        const url = $(this).val();
        if (url && !isValidUrl(url)) {
            $(this).addClass('is-invalid');
            $(this).after('<div class="invalid-feedback">Please enter a valid URL</div>');
        } else {
            $(this).removeClass('is-invalid');
            $(this).next('.invalid-feedback').remove();
        }
    });
    
    function isValidUrl(string) {
        try {
            new URL(string);
            return true;
        } catch (_) {
            return false;
        }
    }
    
    // Search focus based on active tab
    if ($('#config-tab').hasClass('active')) {
        $('#search').focus();
    } else if ($('#logs-tab').hasClass('active')) {
        $('#log_search').focus();
    }
});
</script>

</body>
</html>