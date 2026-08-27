<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['admin', 'manager']);

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

/* ===============================
   FILTER PARAMETERS
================================ */
$search = $_GET['search'] ?? '';
$dateFrom = $_GET['from'] ?? date('Y-m-01'); // Start of current month
$dateTo = $_GET['to'] ?? date('Y-m-d'); // Today
$affiliateFilter = $_GET['affiliate'] ?? 'all';
$offerFilter = $_GET['offer'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';
$sortBy = $_GET['sort'] ?? 'payout';
$sortOrder = $_GET['order'] ?? 'desc';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 25;

// Validate dates
if (!strtotime($dateFrom)) $dateFrom = date('Y-m-01');
if (!strtotime($dateTo)) $dateTo = date('Y-m-d');

/* ===============================
   BUILD WHERE CLAUSE
================================ */
$where = ['u.role_id = 3']; // Affiliates only
$params = [];
$joinClauses = [];

// Date filter
if ($dateFrom && $dateTo) {
    $where[] = 'DATE(cl.created_at) BETWEEN :date_from AND :date_to';
    $params['date_from'] = $dateFrom;
    $params['date_to'] = $dateTo;
}

// Search filter
if ($search) {
    $where[] = '(u.name LIKE :search OR u.email LIKE :search OR u.company LIKE :search)';
    $params['search'] = "%$search%";
}

// Affiliate filter
if ($affiliateFilter !== 'all') {
    $where[] = 'u.user_id = :affiliate_id';
    $params['affiliate_id'] = (int)$affiliateFilter;
}

// Offer filter
if ($offerFilter !== 'all') {
    $where[] = 'cl.offer_id = :offer_id';
    $params['offer_id'] = (int)$offerFilter;
    $joinClauses[] = 'LEFT JOIN offers o ON o.offer_id = cl.offer_id';
}

// Status filter for conversions
if ($statusFilter !== 'all') {
    $where[] = 'c.status = :status';
    $params['status'] = $statusFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$joinSql = $joinClauses ? implode(' ', $joinClauses) : '';

/* ===============================
   GET TOTAL COUNT
================================ */
$countSql = "
    SELECT COUNT(DISTINCT u.user_id)
    FROM users u
    LEFT JOIN clicks cl ON cl.affiliate_id = u.user_id
    LEFT JOIN conversions c ON c.click_id = cl.click_id
    $joinSql
    $whereSql
";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalAffiliates = $countStmt->fetchColumn();
$totalPages = ceil($totalAffiliates / $perPage);
$offset = ($page - 1) * $perPage;

/* ===============================
   GET AFFILIATE PERFORMANCE DATA
================================ */
// Determine sort order
$orderBy = "payout {$sortOrder}";
switch ($sortBy) {
    case 'name':
        $orderBy = "affiliate_name {$sortOrder}";
        break;
    case 'clicks':
        $orderBy = "clicks {$sortOrder}";
        break;
    case 'conversions':
        $orderBy = "conversions {$sortOrder}";
        break;
    case 'approved':
        $orderBy = "approved_conversions {$sortOrder}";
        break;
    case 'cr':
        $orderBy = "conversion_rate {$sortOrder}";
        break;
    case 'epc':
        $orderBy = "epc {$sortOrder}";
        break;
    case 'quality':
        $orderBy = "quality_score {$sortOrder}";
        break;
}

$sql = "
    SELECT
        u.user_id AS affiliate_id,
        u.name AS affiliate_name,
        u.email,
        u.company,
        u.status as affiliate_status,
        u.created_at as affiliate_joined,
        u.balance,
        
        COUNT(DISTINCT cl.click_id) AS clicks,
        COUNT(DISTINCT c.conversion_id) AS conversions,
        SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END) AS payout,
        SUM(CASE WHEN c.status = 'pending' THEN 1 ELSE 0 END) AS pending_conversions,
        SUM(CASE WHEN c.status = 'pending' THEN c.payout ELSE 0 END) AS pending_payout,
        SUM(CASE WHEN c.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_conversions,
        
        -- Performance metrics
        CASE 
            WHEN COUNT(DISTINCT cl.click_id) > 0 
            THEN (SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) / COUNT(DISTINCT cl.click_id)) * 100
            ELSE 0
        END as conversion_rate,
        
        CASE 
            WHEN COUNT(DISTINCT cl.click_id) > 0 
            THEN SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END) / COUNT(DISTINCT cl.click_id)
            ELSE 0
        END as epc,
        
        -- Quality score calculation
        CASE
            WHEN COUNT(DISTINCT cl.click_id) = 0 THEN 0
            WHEN SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) >= 10 
                 AND (SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) / COUNT(DISTINCT cl.click_id)) >= 0.01 
                 THEN 90
            WHEN COUNT(DISTINCT cl.click_id) >= 50 
                 AND SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) = 0 
                 THEN 10
            ELSE 50
        END as quality_score,
        
        -- Recent activity
        MAX(cl.created_at) as last_click,
        MAX(c.created_at) as last_conversion,
        
        -- Offer diversity
        COUNT(DISTINCT cl.offer_id) as unique_offers
        
    FROM users u
    LEFT JOIN clicks cl ON cl.affiliate_id = u.user_id
    LEFT JOIN conversions c ON c.click_id = cl.click_id
    $joinSql
    $whereSql
    GROUP BY u.user_id
    ORDER BY $orderBy
    LIMIT :offset, :per_page
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $perPage, PDO::PARAM_INT);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$affiliates = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   GET SUMMARY STATISTICS
================================ */
$summarySql = "
    SELECT
    COUNT(*) AS total_affiliates,
    SUM(active_affiliate) AS active_affiliates,

    SUM(clicks) AS total_clicks,
    SUM(conversions) AS total_conversions,
    SUM(approved_conversions) AS total_approved,
    SUM(payout) AS total_payout,
    SUM(pending_payout) AS total_pending_payout,

    AVG(conversion_rate) AS avg_conversion_rate,
    AVG(epc) AS avg_epc

FROM (
    SELECT
        u.user_id,

        CASE WHEN u.status = 'active' THEN 1 ELSE 0 END AS active_affiliate,

        COUNT(DISTINCT cl.click_id) AS clicks,
        COUNT(DISTINCT c.conversion_id) AS conversions,
        SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END) AS payout,
        SUM(CASE WHEN c.status = 'pending' THEN c.payout ELSE 0 END) AS pending_payout,

        CASE 
            WHEN COUNT(DISTINCT cl.click_id) > 0 
            THEN (SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END)
                 / COUNT(DISTINCT cl.click_id)) * 100
            ELSE 0
        END AS conversion_rate,

        CASE 
            WHEN COUNT(DISTINCT cl.click_id) > 0 
            THEN SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END)
                 / COUNT(DISTINCT cl.click_id)
            ELSE 0
        END AS epc

    FROM users u
    LEFT JOIN clicks cl ON cl.affiliate_id = u.user_id
    LEFT JOIN conversions c ON c.click_id = cl.click_id
    $whereSql
    GROUP BY u.user_id
) x
";

$summaryStmt = $pdo->prepare($summarySql);
foreach ($params as $key => $value) {
    if ($key !== 'offset' && $key !== 'per_page') {
        $summaryStmt->bindValue($key, $value);
    }
}
$summaryStmt->execute();
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

/* ===============================
   GET AFFILIATES FOR FILTER
================================ */
$allAffiliates = $pdo->query("
    SELECT user_id, name, email 
    FROM users 
    WHERE role_id = 3 
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   GET OFFERS FOR FILTER
================================ */
$allOffers = $pdo->query("
    SELECT DISTINCT o.offer_id, o.offer_name
    FROM offers o
    INNER JOIN clicks cl ON cl.offer_id = o.offer_id
    WHERE o.status IN ('active', 'approved')
    ORDER BY o.offer_name
")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   EXPORT FUNCTIONALITY
================================ */
if (isset($_GET['export'])) {
    $exportStmt = $pdo->prepare("
        SELECT
            u.name AS affiliate_name,
            u.email,
            u.company,
            u.status as affiliate_status,
            u.created_at as join_date,
            u.balance,
            
            COUNT(DISTINCT cl.click_id) AS clicks,
            COUNT(DISTINCT c.conversion_id) AS conversions,
            SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
            SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END) AS payout,
            SUM(CASE WHEN c.status = 'pending' THEN 1 ELSE 0 END) AS pending_conversions,
            SUM(CASE WHEN c.status = 'pending' THEN c.payout ELSE 0 END) AS pending_payout,
            
            CASE 
                WHEN COUNT(DISTINCT cl.click_id) > 0 
                THEN (SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) / COUNT(DISTINCT cl.click_id)) * 100
                ELSE 0
            END as conversion_rate,
            
            CASE 
                WHEN COUNT(DISTINCT cl.click_id) > 0 
                THEN SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END) / COUNT(DISTINCT cl.click_id)
                ELSE 0
            END as epc
            
        FROM users u
        LEFT JOIN clicks cl ON cl.affiliate_id = u.user_id
        LEFT JOIN conversions c ON c.click_id = cl.click_id
        $whereSql
        GROUP BY u.user_id
        ORDER BY payout DESC
    ");
    
    foreach ($params as $key => $value) {
        if ($key !== 'offset' && $key !== 'per_page') {
            $exportStmt->bindValue($key, $value);
        }
    }
    
    $exportStmt->execute();
    $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="affiliate-performance-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'Affiliate Name', 'Email', 'Company', 'Status', 'Join Date', 'Balance',
        'Clicks', 'Total Conversions', 'Approved Conversions', 'Payout',
        'Pending Conversions', 'Pending Payout', 'Conversion Rate %', 'EPC',
        'Date Range: ' . $dateFrom . ' to ' . $dateTo
    ]);
    
    foreach ($exportData as $row) {
        fputcsv($output, [
            $row['affiliate_name'],
            $row['email'],
            $row['company'],
            $row['affiliate_status'],
            $row['join_date'],
            $row['balance'],
            $row['clicks'],
            $row['conversions'],
            $row['approved_conversions'],
            $row['payout'],
            $row['pending_conversions'],
            $row['pending_payout'],
            number_format($row['conversion_rate'], 2),
            number_format($row['epc'], 4)
        ]);
    }
    
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Publisher Performance Report | Admin Panel | GVS Icon Media</title>
    
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
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .table-dashboard thead th:hover {
            background: #f1f3f9;
        }
        
        .table-dashboard thead th i {
            font-size: 12px;
            margin-left: 5px;
            opacity: 0.5;
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
        
        .clicks-value {
            color: #6610f2;
            font-weight: 700;
        }
        
        .conversions-value {
            color: #20c997;
            font-weight: 700;
        }
        
        .payout-value {
            color: #28a745;
            font-weight: 700;
        }
        
        .cr-value {
            color: #fd7e14;
            font-weight: 700;
        }
        
        .epc-value {
            color: #6f42c1;
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
        
        .status-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .status-blocked {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .quality-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .quality-excellent {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .quality-good {
            background: rgba(32, 201, 151, 0.15);
            color: #20c997;
            border: 1px solid rgba(32, 201, 151, 0.2);
        }
        
        .quality-watch {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .quality-poor {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .performance-bar {
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 5px;
        }
        
        .performance-fill {
            height: 100%;
            border-radius: 3px;
        }
        
        .performance-excellent {
            background: linear-gradient(90deg, #28a745, #20c997);
        }
        
        .performance-good {
            background: linear-gradient(90deg, #20c997, #17a2b8);
        }
        
        .performance-watch {
            background: linear-gradient(90deg, #ffc107, #fd7e14);
        }
        
        .performance-poor {
            background: linear-gradient(90deg, #dc3545, #fd7e14);
        }
        
        .trend-up {
            color: #28a745;
        }
        
        .trend-down {
            color: #dc3545;
        }
        
        .trend-neutral {
            color: #6c757d;
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
        
        .btn-view {
            background: rgba(78, 115, 223, 0.1);
            color: #4e73df;
            border: 1px solid rgba(78, 115, 223, 0.2);
        }
        
        .btn-view:hover {
            background: rgba(78, 115, 223, 0.2);
            color: #4e73df;
        }
        
        .btn-chart {
            background: rgba(32, 201, 151, 0.1);
            color: #20c997;
            border: 1px solid rgba(32, 201, 151, 0.2);
        }
        
        .btn-chart:hover {
            background: rgba(32, 201, 151, 0.2);
            color: #20c997;
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
        
        .date-range-badge {
            background: #f8f9fa;
            color: #6c757d;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .stat-item {
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .stat-label {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
        }
        
        .stat-value {
            font-size: 14px;
            font-weight: 600;
            margin-top: 3px;
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
                <a href="reports_affiliates.php" class="nav-link active">Publisher Report</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <?php if (($summary['total_pending_payout'] ?? 0) > 0): ?>
                    <span class="badge badge-warning navbar-badge">
                        $<?php echo number_format($summary['total_pending_payout'], 0); ?>
                    </span>
                    <?php endif; ?>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header">
                        Pending Payout: $<?php echo number_format($summary['total_pending_payout'] ?? 0, 2); ?>
                    </span>
                    <div class="dropdown-divider"></div>
                    <a href="reports_affiliates.php?status=pending" class="dropdown-item">
                        <i class="fas fa-clock mr-2 text-warning"></i>
                        Review Pending Conversions
                    </a>
                </div>
            </li>
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
                        <a href="reports_affiliates.php" class="nav-link active">
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
                        <h1 class="m-0">Publisher Performance Report</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="reports.php">Reports</a></li>
                            <li class="breadcrumb-item active">Publisher Performance</li>
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
                    <h2 class="mb-0">Publisher Performance Analytics</h2>
                    <div class="action-buttons-group">
                        <span class="date-range-badge">
                            <i class="fas fa-calendar-alt"></i>
                            <?php echo date('M d, Y', strtotime($dateFrom)); ?> - <?php echo date('M d, Y', strtotime($dateTo)); ?>
                        </span>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-outline-primary">
                            <i class="fas fa-download mr-2"></i> Export CSV
                        </a>
                        <button type="button" class="btn btn-gradient" onclick="printReport()">
                            <i class="fas fa-print mr-2"></i> Print Report
                        </button>
                    </div>
                </div>

                <!-- Summary Stats -->
                <div class="summary-stats">
                    <div class="metric-card">
                        <div class="metric-value total-value"><?php echo number_format($summary['total_affiliates'] ?? 0); ?></div>
                        <div class="metric-label">Total Publishers</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value clicks-value"><?php echo number_format($summary['total_clicks'] ?? 0); ?></div>
                        <div class="metric-label">Total Clicks</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value conversions-value"><?php echo number_format($summary['total_conversions'] ?? 0); ?></div>
                        <div class="metric-label">Total Conversions</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value payout-value">$<?php echo number_format($summary['total_payout'] ?? 0, 2); ?></div>
                        <div class="metric-label">Total Payout</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value cr-value"><?php echo number_format($summary['avg_conversion_rate'] ?? 0, 2); ?>%</div>
                        <div class="metric-label">Avg Conversion Rate</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value epc-value">$<?php echo number_format($summary['avg_epc'] ?? 0, 4); ?></div>
                        <div class="metric-label">Average EPC</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card-dashboard">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-filter mr-2"></i> Filter Report Data
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="get" class="filter-row">
                            <div class="filter-group">
                                <label for="search"><i class="fas fa-search mr-1"></i> Search Publishers</label>
                                <input type="text" name="search" id="search" class="filter-control" 
                                       placeholder="Search by name, email, company..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label for="from"><i class="fas fa-calendar-start mr-1"></i> From Date</label>
                                <input type="date" name="from" id="from" class="filter-control" 
                                       value="<?php echo htmlspecialchars($dateFrom); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label for="to"><i class="fas fa-calendar-end mr-1"></i> To Date</label>
                                <input type="date" name="to" id="to" class="filter-control" 
                                       value="<?php echo htmlspecialchars($dateTo); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label for="affiliate"><i class="fas fa-user-friends mr-1"></i> Publisher</label>
                                <select name="affiliate" id="affiliate" class="filter-control">
                                    <option value="all" <?php echo $affiliateFilter === 'all' ? 'selected' : ''; ?>>All Publishers</option>
                                    <?php foreach ($allAffiliates as $aff): ?>
                                    <option value="<?php echo $aff['user_id']; ?>" <?php echo $affiliateFilter == $aff['user_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($aff['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="offer"><i class="fas fa-bullhorn mr-1"></i> Campaign</label>
                                <select name="offer" id="offer" class="filter-control">
                                    <option value="all" <?php echo $offerFilter === 'all' ? 'selected' : ''; ?>>All Campaigns</option>
                                    <?php foreach ($allOffers as $offer): ?>
                                    <option value="<?php echo $offer['offer_id']; ?>" <?php echo $offerFilter == $offer['offer_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($offer['offer_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <button type="submit" class="btn-gradient" style="height: 45px; width: 100%;">
                                    <i class="fas fa-chart-line mr-2"></i> Generate Report
                                </button>
                            </div>
                            
                            <div class="filter-group">
                                <a href="reports_affiliates.php" class="btn btn-outline-primary" style="height: 45px; width: 100%; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-redo mr-2"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Performance Table -->
                <div class="card-dashboard">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-chart-bar mr-2"></i> Publisher Performance Summary
                        </h3>
                        <div class="card-tools">
                            <span class="badge badge-light">
                                Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($affiliates)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fas fa-chart-bar"></i>
                                </div>
                                <h5>No Performance Data Found</h5>
                                <p class="text-muted">No publisher data matches your search criteria.</p>
                                <a href="reports_affiliates.php" class="btn btn-gradient btn-sm">
                                    <i class="fas fa-redo mr-2"></i> Reset Filters
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-dashboard" id="performanceTable">
                                    <thead>
                                        <tr>
                                            <th>
                                                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'name', 'order' => $sortBy === 'name' && $sortOrder === 'asc' ? 'desc' : 'asc'])); ?>">
                                                    Publisher
                                                    <?php if ($sortBy === 'name'): ?>
                                                    <span class="sort-indicator sort-<?php echo $sortOrder; ?>"></span>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'clicks', 'order' => $sortBy === 'clicks' && $sortOrder === 'asc' ? 'desc' : 'asc'])); ?>">
                                                    Clicks
                                                    <?php if ($sortBy === 'clicks'): ?>
                                                    <span class="sort-indicator sort-<?php echo $sortOrder; ?>"></span>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>Conversions</th>
                                            <th>
                                                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'payout', 'order' => $sortBy === 'payout' && $sortOrder === 'asc' ? 'desc' : 'asc'])); ?>">
                                                    Payout
                                                    <?php if ($sortBy === 'payout'): ?>
                                                    <span class="sort-indicator sort-<?php echo $sortOrder; ?>"></span>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'cr', 'order' => $sortBy === 'cr' && $sortOrder === 'asc' ? 'desc' : 'asc'])); ?>">
                                                    CR %
                                                    <?php if ($sortBy === 'cr'): ?>
                                                    <span class="sort-indicator sort-<?php echo $sortOrder; ?>"></span>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'epc', 'order' => $sortBy === 'epc' && $sortOrder === 'asc' ? 'desc' : 'asc'])); ?>">
                                                    EPC
                                                    <?php if ($sortBy === 'epc'): ?>
                                                    <span class="sort-indicator sort-<?php echo $sortOrder; ?>"></span>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'quality', 'order' => $sortBy === 'quality' && $sortOrder === 'asc' ? 'desc' : 'asc'])); ?>">
                                                    Quality
                                                    <?php if ($sortBy === 'quality'): ?>
                                                    <span class="sort-indicator sort-<?php echo $sortOrder; ?>"></span>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>Activity</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($affiliates as $row): 
                                            $clicks = (int)$row['clicks'];
                                            $conversions = (int)$row['conversions'];
                                            $approved = (int)$row['approved_conversions'];
                                            $payout = (float)$row['payout'];
                                            $pendingPayout = (float)$row['pending_payout'];
                                            $cr = (float)$row['conversion_rate'];
                                            $epc = (float)$row['epc'];
                                            $qualityScore = (int)$row['quality_score'];
                                            
                                            // Determine quality label and color
                                            if ($qualityScore >= 80) {
                                                $qualityLabel = 'Excellent';
                                                $qualityClass = 'quality-excellent';
                                                $performanceClass = 'performance-excellent';
                                            } elseif ($qualityScore >= 60) {
                                                $qualityLabel = 'Good';
                                                $qualityClass = 'quality-good';
                                                $performanceClass = 'performance-good';
                                            } elseif ($qualityScore >= 40) {
                                                $qualityLabel = 'Watch';
                                                $qualityClass = 'quality-watch';
                                                $performanceClass = 'performance-watch';
                                            } else {
                                                $qualityLabel = 'Poor';
                                                $qualityClass = 'quality-poor';
                                                $performanceClass = 'performance-poor';
                                            }
                                            
                                            // Status badge
                                            $statusClass = 'status-' . ($row['affiliate_status'] ?? 'pending');
                                            $statusLabel = ucfirst($row['affiliate_status'] ?? 'pending');
                                            
                                            // Last activity
                                            $lastClick = $row['last_click'] ? date('M d', strtotime($row['last_click'])) : 'Never';
                                            $lastConversion = $row['last_conversion'] ? date('M d', strtotime($row['last_conversion'])) : 'Never';
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="mr-3">
                                                        <div style="width: 40px; height: 40px; background: #667eea; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600;">
                                                            <?php echo strtoupper(substr($row['affiliate_name'], 0, 1)); ?>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($row['affiliate_name']); ?></strong>
                                                        <div class="text-muted small">
                                                            <?php echo htmlspecialchars($row['email']); ?>
                                                            <?php if ($row['company']): ?>
                                                                &nbsp;•&nbsp; <?php echo htmlspecialchars($row['company']); ?>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="d-flex align-items-center gap-2 mt-1">
                                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                                <?php echo $statusLabel; ?>
                                                            </span>
                                                            <span class="text-muted small">
                                                                Balance: $<?php echo number_format($row['balance'] ?? 0, 2); ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="font-weight-bold"><?php echo number_format($clicks); ?></div>
                                                <div class="performance-bar">
                                                    <?php if ($clicks > 0): ?>
                                                    <div class="performance-fill <?php echo $performanceClass; ?>" 
                                                         style="width: <?php echo min(100, ($clicks / max(1, $summary['total_clicks'] ?? 1)) * 100); ?>%">
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="stats-grid">
                                                    <div class="stat-item">
                                                        <div class="stat-label">Total</div>
                                                        <div class="stat-value"><?php echo number_format($conversions); ?></div>
                                                    </div>
                                                    <div class="stat-item">
                                                        <div class="stat-label">Approved</div>
                                                        <div class="stat-value text-success"><?php echo number_format($approved); ?></div>
                                                    </div>
                                                    <div class="stat-item">
                                                        <div class="stat-label">Pending</div>
                                                        <div class="stat-value text-warning"><?php echo number_format($row['pending_conversions']); ?></div>
                                                    </div>
                                                    <div class="stat-item">
                                                        <div class="stat-label">Rejected</div>
                                                        <div class="stat-value text-danger"><?php echo number_format($row['rejected_conversions']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="font-weight-bold text-success">
                                                    $<?php echo number_format($payout, 2); ?>
                                                </div>
                                                <?php if ($pendingPayout > 0): ?>
                                                <div class="small text-warning">
                                                    Pending: $<?php echo number_format($pendingPayout, 2); ?>
                                                </div>
                                                <?php endif; ?>
                                                <div class="performance-bar">
                                                    <?php if ($payout > 0): ?>
                                                    <div class="performance-fill <?php echo $performanceClass; ?>" 
                                                         style="width: <?php echo min(100, ($payout / max(1, $summary['total_payout'] ?? 1)) * 100); ?>%">
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="font-weight-bold <?php echo $cr >= 1 ? 'text-success' : ($cr >= 0.5 ? 'text-warning' : 'text-danger'); ?>">
                                                    <?php echo number_format($cr, 2); ?>%
                                                </div>
                                                <div class="small text-muted">
                                                    <?php echo $clicks > 0 ? number_format(($approved / $clicks) * 100, 2) : '0'; ?>% approved
                                                </div>
                                            </td>
                                            <td>
                                                <div class="font-weight-bold <?php echo $epc >= 0.5 ? 'text-success' : ($epc >= 0.1 ? 'text-warning' : 'text-danger'); ?>">
                                                    $<?php echo number_format($epc, 4); ?>
                                                </div>
                                                <div class="small text-muted">
                                                    Per click
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <span class="quality-badge <?php echo $qualityClass; ?>">
                                                        <?php echo $qualityLabel; ?>
                                                    </span>
                                                    <div class="ml-2 small text-muted">
                                                        <?php echo $qualityScore; ?> pts
                                                    </div>
                                                </div>
                                                <div class="performance-bar mt-2">
                                                    <div class="performance-fill <?php echo $performanceClass; ?>" 
                                                         style="width: <?php echo $qualityScore; ?>%">
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <div class="mb-1">
                                                        <i class="fas fa-mouse-pointer mr-1 text-muted"></i>
                                                        Last click: <?php echo $lastClick; ?>
                                                    </div>
                                                    <div class="mb-1">
                                                        <i class="fas fa-exchange-alt mr-1 text-muted"></i>
                                                        Last conv: <?php echo $lastConversion; ?>
                                                    </div>
                                                    <div>
                                                        <i class="fas fa-bullhorn mr-1 text-muted"></i>
                                                        <?php echo $row['unique_offers']; ?> campaigns
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="affiliate_details.php?id=<?php echo $row['affiliate_id']; ?>" 
                                                       class="btn-action btn-view"
                                                       title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="reports_affiliate_detail.php?id=<?php echo $row['affiliate_id']; ?>&from=<?php echo $dateFrom; ?>&to=<?php echo $dateTo; ?>" 
                                                       class="btn-action btn-chart"
                                                       title="View Detailed Report">
                                                        <i class="fas fa-chart-line"></i>
                                                    </a>
                                                    <a href="publisher_performance.php?id=<?php echo $row['affiliate_id']; ?>" 
                                                       class="btn-action"
                                                       title="Performance Analytics"
                                                       style="background: rgba(102, 16, 242, 0.1); color: #6610f2; border: 1px solid rgba(102, 16, 242, 0.2);">
                                                        <i class="fas fa-chart-pie"></i>
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
    // Initialize DataTable with custom sorting
    $('#performanceTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [], // Disable initial sort (we use server-side sorting)
        responsive: true,
        searching: false,
        info: false,
        paging: false, // We use custom pagination
        language: {
            emptyTable: "No performance data found"
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
    
    // Auto-dismiss alerts after 5 seconds
    $('.alert').delay(5000).fadeOut('slow');
    
    // Date range validation
    $('#from, #to').change(function() {
        const fromDate = new Date($('#from').val());
        const toDate = new Date($('#to').val());
        
        if (fromDate > toDate) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Date Range',
                text: 'From date cannot be after To date'
            });
            $('#from').val($('#to').val());
        }
    });
    
    // Quick date filters
    $('#quickFilter').change(function() {
        const value = $(this).val();
        const today = new Date();
        
        switch(value) {
            case 'today':
                $('#from').val(today.toISOString().split('T')[0]);
                $('#to').val(today.toISOString().split('T')[0]);
                break;
            case 'yesterday':
                const yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);
                $('#from').val(yesterday.toISOString().split('T')[0]);
                $('#to').val(yesterday.toISOString().split('T')[0]);
                break;
            case 'week':
                const weekStart = new Date(today);
                weekStart.setDate(weekStart.getDate() - 7);
                $('#from').val(weekStart.toISOString().split('T')[0]);
                $('#to').val(today.toISOString().split('T')[0]);
                break;
            case 'month':
                const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
                $('#from').val(monthStart.toISOString().split('T')[0]);
                $('#to').val(today.toISOString().split('T')[0]);
                break;
            case 'quarter':
                const quarterStart = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3, 1);
                $('#from').val(quarterStart.toISOString().split('T')[0]);
                $('#to').val(today.toISOString().split('T')[0]);
                break;
            case 'year':
                $('#from').val(today.getFullYear() + '-01-01');
                $('#to').val(today.toISOString().split('T')[0]);
                break;
        }
        
        if (value !== '') {
            $('form').submit();
        }
    });
    
    // Print report function
    window.printReport = function() {
        const originalTitle = document.title;
        document.title = 'Publisher Performance Report - ' + 
                         $('#from').val() + ' to ' + $('#to').val() + ' - GVS Icon Media';
        
        // Hide elements that shouldn't print
        $('.main-header, .main-sidebar, .content-header, .dashboard-header .action-buttons-group, .footer').hide();
        
        // Show print-only elements
        $('.print-only').show();
        
        // Print
        window.print();
        
        // Restore
        document.title = originalTitle;
        $('.main-header, .main-sidebar, .content-header, .dashboard-header .action-buttons-group, .footer').show();
        $('.print-only').hide();
    };
    
    // Search focus
    $('#search').focus();
    
    // Performance score tooltips
    $('.quality-badge').hover(function() {
        const score = $(this).next('.text-muted').text().replace(' pts', '');
        $(this).attr('title', 'Performance Score: ' + score + '/100');
    });
    
    // Auto-refresh data every 5 minutes if no filters are set
    const hasFilters = window.location.search.includes('search=') || 
                      window.location.search.includes('affiliate=') || 
                      window.location.search.includes('offer=');
    
    if (!hasFilters) {
        setTimeout(function() {
            // Refresh page to get updated data
            window.location.reload();
        }, 300000); // 5 minutes
    }
});
</script>

</body>
</html>