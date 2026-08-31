<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('advertiser');

$advertiserId = auth_user_id();
$advertiserName = $_SESSION['user_name'] ?? 'Advertiser';

/* ===============================
   FILTERS WITH VALIDATION
================================ */
$offerId  = $_GET['offer_id'] ?? 'all';
$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate   = $_GET['to']   ?? date('Y-m-d');
$export   = isset($_GET['export']);
$sort     = $_GET['sort'] ?? 'revenue';
$order    = $_GET['order'] ?? 'desc';

// Validate dates
if (!strtotime($fromDate)) $fromDate = date('Y-m-01');
if (!strtotime($toDate)) $toDate = date('Y-m-d');
if (strtotime($fromDate) > strtotime($toDate)) {
    $fromDate = $toDate;
}

/* ===============================
   BUILD WHERE CLAUSE WITH POSITIONAL PARAMETERS
================================ */
$where  = ["o.advertiser_id = ?"];
$params = [$advertiserId];

if ($offerId !== 'all') {
    $where[] = "o.offer_id = ?";
    $params[] = (int)$offerId;
}

$where[] = "DATE(c.created_at) BETWEEN ? AND ?";
$params[] = $fromDate;
$params[] = $toDate;

$whereSql = 'WHERE ' . implode(' AND ', $where);

// Define sort order with proper column names
$sortColumns = [
    'name' => 'affiliate_name',
    'clicks' => 'total_clicks',
    'conversions' => 'total_conversions',
    'revenue' => 'approved_revenue',
    'cr' => 'conversion_rate',
    'epc' => 'epc'
];

$sortField = $sortColumns[$sort] ?? 'approved_revenue';
$sortOrder = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

/* ===============================
   AFFILIATE PERFORMANCE QUERY
================================ */
$sql = "
    SELECT
        u.user_id AS affiliate_id,
        u.name AS affiliate_name,
        u.email AS affiliate_email,

        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        COUNT(DISTINCT CASE WHEN cv.status = 'approved' THEN cv.conversion_id END) AS approved_conversions,

        COALESCE(SUM(cv.revenue), 0) AS total_revenue,
        COALESCE(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue END), 0) AS approved_revenue,

        ROUND(
            COUNT(DISTINCT cv.conversion_id) * 100.0 / 
            NULLIF(COUNT(DISTINCT c.click_id), 0), 2
        ) AS conversion_rate,

        ROUND(
            COALESCE(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue END), 0) / 
            NULLIF(COUNT(DISTINCT c.click_id), 0), 4
        ) AS epc

    FROM clicks c
    INNER JOIN offers o ON o.offer_id = c.offer_id
    INNER JOIN users u ON u.user_id = c.affiliate_id
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    $whereSql
    GROUP BY u.user_id, u.name, u.email
";

// Add sorting
switch ($sort) {
    case 'name':
        $sql .= " ORDER BY affiliate_name $sortOrder";
        break;
    case 'clicks':
        $sql .= " ORDER BY total_clicks $sortOrder";
        break;
    case 'conversions':
        $sql .= " ORDER BY total_conversions $sortOrder";
        break;
    case 'revenue':
        $sql .= " ORDER BY approved_revenue $sortOrder";
        break;
    case 'cr':
        $sql .= " ORDER BY conversion_rate $sortOrder";
        break;
    case 'epc':
        $sql .= " ORDER BY epc $sortOrder";
        break;
    default:
        $sql .= " ORDER BY approved_revenue DESC";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$affiliates = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   OFFER DROPDOWN
================================ */
$offersStmt = $pdo->prepare("
    SELECT offer_id, offer_name
    FROM offers
    WHERE advertiser_id = ?
    ORDER BY offer_name
");
$offersStmt->execute([$advertiserId]);
$offers = $offersStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   SUMMARY STATS - FIXED
================================ */
$summary = [
    'total_affiliates' => count($affiliates),
    'total_clicks' => 0,
    'total_conversions' => 0,
    'total_revenue' => 0
];

foreach ($affiliates as $aff) {
    $summary['total_clicks'] += (int)($aff['total_clicks'] ?? 0);
    $summary['total_conversions'] += (int)($aff['total_conversions'] ?? 0);
    $summary['total_revenue'] += (float)($aff['approved_revenue'] ?? 0);
}

/* ===============================
   TOP PERFORMING AFFILIATES
================================ */
$topPerformers = array_slice($affiliates, 0, 5);

/* ===============================
   PERFORMANCE DISTRIBUTION - FIXED
================================ */
$performanceDistribution = [
    ['level' => 'Excellent (10%+)', 'count' => 0, 'revenue' => 0],
    ['level' => 'Good (5-10%)', 'count' => 0, 'revenue' => 0],
    ['level' => 'Average (2-5%)', 'count' => 0, 'revenue' => 0],
    ['level' => 'Low (0-2%)', 'count' => 0, 'revenue' => 0],
    ['level' => 'No Conversions', 'count' => 0, 'revenue' => 0]
];

foreach ($affiliates as $aff) {
    $cr = (float)($aff['conversion_rate'] ?? 0);
    $revenue = (float)($aff['approved_revenue'] ?? 0);
    
    if ($cr >= 10) {
        $performanceDistribution[0]['count']++;
        $performanceDistribution[0]['revenue'] += $revenue;
    } elseif ($cr >= 5) {
        $performanceDistribution[1]['count']++;
        $performanceDistribution[1]['revenue'] += $revenue;
    } elseif ($cr >= 2) {
        $performanceDistribution[2]['count']++;
        $performanceDistribution[2]['revenue'] += $revenue;
    } elseif ($cr > 0) {
        $performanceDistribution[3]['count']++;
        $performanceDistribution[3]['revenue'] += $revenue;
    } else {
        $performanceDistribution[4]['count']++;
        $performanceDistribution[4]['revenue'] += $revenue;
    }
}

/* ===============================
   HANDLE EXPORT
================================ */
if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="affiliate-report-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel
    
    fputcsv($output, [
        'Affiliate Name', 'Email', 'Clicks', 'Conversions', 'Approved', 
        'Approval Rate', 'Conversion Rate', 'Revenue', 'EPC'
    ]);
    
    foreach ($affiliates as $a) {
        $approvalRate = ($a['total_conversions'] ?? 0) > 0
            ? (($a['approved_conversions'] ?? 0) / $a['total_conversions']) * 100
            : 0;
        
        fputcsv($output, [
            $a['affiliate_name'] ?? '',
            $a['affiliate_email'] ?? '',
            number_format($a['total_clicks'] ?? 0),
            number_format($a['total_conversions'] ?? 0),
            number_format($a['approved_conversions'] ?? 0),
            number_format($approvalRate, 2) . '%',
            ($a['conversion_rate'] ?? '0') . '%',
            '$' . number_format($a['approved_revenue'] ?? 0, 2),
            '$' . number_format($a['epc'] ?? 0, 3)
        ]);
    }
    
    fclose($output);
    exit;
}

// Calculate averages
$avgCR = $summary['total_clicks'] > 0 ? 
    ($summary['total_conversions'] / $summary['total_clicks']) * 100 : 0;
$avgEPC = $summary['total_clicks'] > 0 ? 
    $summary['total_revenue'] / $summary['total_clicks'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Affiliate Performance | Advertiser Panel | GVS Icon Media</title>
    
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
        
        .small-box {
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
            color: white;
            overflow: hidden;
            border: none;
        }
        
        .small-box:hover {
            transform: translateY(-5px);
        }
        
        .small-box .icon {
            font-size: 60px;
            opacity: 0.2;
            transition: all 0.3s ease;
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
        }
        
        .small-box:hover .icon {
            opacity: 0.3;
            transform: translateY(-50%) scale(1.1);
        }
        
        .small-box .inner {
            padding: 20px;
            position: relative;
            z-index: 1;
        }
        
        .small-box h3 {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 5px 0;
        }
        
        .small-box p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .bg-gradient-primary {
            background: var(--primary-gradient) !important;
        }
        
        .bg-gradient-success {
            background: var(--success-gradient) !important;
        }
        
        .bg-gradient-info {
            background: var(--info-gradient) !important;
        }
        
        .bg-gradient-warning {
            background: var(--warning-gradient) !important;
        }
        
        .bg-gradient-danger {
            background: var(--danger-gradient) !important;
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
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-active {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        
        .status-inactive {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
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
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
            color: #4e73df;
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
        
        .revenue-value {
            color: #28a745;
            font-weight: 700;
        }
        
        .clicks-value {
            color: #4e73df;
            font-weight: 700;
        }
        
        .conversions-value {
            color: #6610f2;
            font-weight: 700;
        }
        
        .affiliates-value {
            color: #20c997;
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
        
        .date-range-display {
            background: #f8f9fc;
            padding: 10px 15px;
            border-radius: 8px;
            margin: 15px 0;
            font-size: 14px;
            color: #6c757d;
        }
        
        .date-range-display strong {
            color: #4e73df;
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .welcome-banner {
            background: var(--primary-gradient);
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
        
        .quick-stats {
            display: flex;
            justify-content: space-between;
            background: linear-gradient(135deg, #f8f9fc 0%, #eaecf4 100%);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .quick-stat-item {
            text-align: center;
            flex: 1;
        }
        
        .quick-stat-value {
            font-size: 18px;
            font-weight: 700;
            color: #4e73df;
            margin-bottom: 5px;
        }
        
        .quick-stat-label {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
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
        
        .performance-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .performance-excellent {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        
        .performance-good {
            background: rgba(23, 162, 184, 0.15);
            color: #17a2b8;
        }
        
        .performance-average {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }
        
        .performance-low {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
        }
        
        .performance-none {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }
        
        .affiliate-card {
            background: white;
            border: 1px solid #e3e6f0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .affiliate-card:hover {
            border-color: #667eea;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.1);
        }
        
        .sort-link {
            color: #4e73df;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .sort-link:hover {
            color: #2e59d9;
        }
        
        .sort-icon {
            font-size: 10px;
        }
        
        .affiliate-email {
            font-size: 12px;
            color: #6c757d;
            margin-top: 3px;
            word-break: break-all;
        }
        
        .approval-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .approval-high {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        
        .approval-medium {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }
        
        .approval-low {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }
        
        .performance-chart {
            height: 200px;
            display: flex;
            gap: 15px;
            align-items: flex-end;
            margin: 20px 0;
            padding: 0 10px;
        }
        
        .performance-bar {
            flex: 1;
            border-radius: 8px 8px 0 0;
            position: relative;
            transition: height 0.3s ease;
            min-width: 40px;
        }
        
        .performance-bar:hover {
            opacity: 0.9;
            transform: scale(1.02);
        }
        
        .performance-bar::before {
            content: attr(data-count);
            position: absolute;
            top: -25px;
            left: 50%;
            transform: translateX(-50%);
            background: #343a40;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            opacity: 0;
            transition: opacity 0.3s ease;
            white-space: nowrap;
        }
        
        .performance-bar:hover::before {
            opacity: 1;
        }
        
        .performance-label {
            position: absolute;
            bottom: -25px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 11px;
            color: #6c757d;
            font-weight: 600;
        }
        
        .dataTables_wrapper {
            padding: 0;
        }
        
        .dataTables_filter input {
            border: 1px solid #e3e6f0 !important;
            border-radius: 8px !important;
            padding: 8px 15px !important;
        }
        
        .dataTables_length select {
            border: 1px solid #e3e6f0 !important;
            border-radius: 8px !important;
            padding: 8px !important;
        }
        
        .pagination {
            margin-top: 20px;
        }
        
        .badge-excellent {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
        }
        
        .badge-good {
            background: rgba(23, 162, 184, 0.15);
            color: #17a2b8;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
        }
        
        .badge-average {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
        }
        
        .badge-low {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
        }
        
        .badge-none {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
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
                <a href="reports_affiliates.php" class="nav-link active">Affiliate Reports</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-widget="fullscreen" href="#" role="button">
                    <i class="fas fa-expand-arrows-alt"></i>
                </a>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                    <div class="admin-avatar mr-2">
                        <?php echo strtoupper(substr($advertiserName, 0, 1)); ?>
                    </div>
                    <span><?php echo htmlspecialchars($advertiserName); ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user mr-2"></i> Profile
                    </a>
                    <a href="profile.php" class="dropdown-item">
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
                <i class="fas fa-chart-line mr-2"></i>
                <strong>Advertiser</strong>
            </span>
        </a>

        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Sidebar Menu -->
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-tachometer-alt"></i>
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
                        <a href="offers.php" class="nav-link">
                            <i class="nav-icon fas fa-gift"></i>
                            <p>All Offers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="create_offer.php" class="nav-link">
                            <i class="nav-icon fas fa-plus-circle"></i>
                            <p>Create New Offer</p>
                        </a>
                    </li>
                    
                    <li class="nav-header">REPORTS & ANALYTICS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Campaign Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link">
                            <i class="fas fa-exchange-alt nav-icon"></i>
                            <p>Conversion Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link active">
                            <i class="nav-icon fas fa-users"></i>
                            <p>Affiliate Reports</p>
                        </a>
                    </li>
                    
                    <li class="nav-header">TOOLS</li>
                    <li class="nav-item">
                        <a href="ip_whitelist.php" class="nav-link">
                            <i class="nav-icon fas fa-tower-broadcast"></i>
                            <p>IP Whitelist</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="postback.php" class="nav-link">
                            <i class="nav-icon fas fa-code"></i>
                            <p>Postback Manager</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="api.php" class="nav-link">
                            <i class="nav-icon fas fa-plug"></i>
                            <p>API Integration</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="optimization.php" class="nav-link">
                            <i class="nav-icon fas fa-rocket"></i>
                            <p>Optimization Tools</p>
                        </a>
                    </li>
                    
                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user"></i>
                            <p>Profile</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="billing.php" class="nav-link">
                            <i class="nav-icon fas fa-wallet"></i>
                            <p>Billing & Payments</p>
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
                        <h1 class="m-0">Affiliate Performance Report</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="reports.php">Reports</a></li>
                            <li class="breadcrumb-item active">Affiliate Reports</li>
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
                            <h2>Affiliate Performance Analytics</h2>
                            <p class="mb-0">Track and analyze your affiliate partners' performance.</p>
                        </div>
                        <div class="col-md-4 text-right">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 1])); ?>" class="refresh-btn">
                                <i class="fas fa-download mr-1"></i> Export CSV
                            </a>
                        </div>
                    </div>
                    
                    <!-- Quick Stats -->
                    <div class="quick-stats mt-4">
                        <div class="quick-stat-item">
                            <div class="quick-stat-value"><?php echo date('M d, Y', strtotime($fromDate)); ?></div>
                            <div class="quick-stat-label">From Date</div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="quick-stat-value"><?php echo date('M d, Y', strtotime($toDate)); ?></div>
                            <div class="quick-stat-label">To Date</div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="quick-stat-value"><?php echo count($affiliates); ?></div>
                            <div class="quick-stat-label">Active Affiliates</div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="quick-stat-value">$<?php echo number_format($summary['total_revenue'] ?? 0, 2); ?></div>
                            <div class="quick-stat-label">Total Revenue</div>
                        </div>
                    </div>
                </div>

                <!-- Summary Stats - Small Boxes -->
                <div class="row">
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="small-box bg-gradient-info">
                            <div class="inner">
                                <h3><?php echo number_format($summary['total_affiliates'] ?? 0); ?></h3>
                                <p>Total Affiliates</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-users"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="small-box bg-gradient-primary">
                            <div class="inner">
                                <h3><?php echo number_format($summary['total_clicks'] ?? 0); ?></h3>
                                <p>Total Clicks</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-mouse-pointer"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="small-box bg-gradient-success">
                            <div class="inner">
                                <h3><?php echo number_format($summary['total_conversions'] ?? 0); ?></h3>
                                <p>Conversions</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="small-box bg-gradient-warning">
                            <div class="inner">
                                <h3><?php echo number_format($avgCR, 2); ?>%</h3>
                                <p>Avg. CR</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-percentage"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="small-box bg-gradient-danger">
                            <div class="inner">
                                <h3>$<?php echo number_format($avgEPC, 4); ?></h3>
                                <p>Avg. EPC</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="small-box bg-gradient-dark">
                            <div class="inner">
                                <h3>$<?php echo number_format($summary['total_revenue'] ?? 0, 2); ?></h3>
                                <p>Total Revenue</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-dollar-sign"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Main Content -->
                    <div class="col-lg-8">
                        <!-- Filters -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-filter mr-2"></i> Filter Affiliates
                                </h3>
                            </div>
                            <div class="card-body">
                                <form method="get" class="filter-row">
                                    <div class="filter-group">
                                        <label for="offer_id"><i class="fas fa-gift mr-1"></i> Select Offer</label>
                                        <select name="offer_id" id="offer_id" class="filter-control">
                                            <option value="all">All Offers</option>
                                            <?php foreach ($offers as $o): ?>
                                                <option value="<?= $o['offer_id'] ?>" 
                                                    <?= $offerId == $o['offer_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($o['offer_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label for="from"><i class="fas fa-calendar-day mr-1"></i> From Date</label>
                                        <input type="date" name="from" id="from" value="<?= $fromDate ?>" class="filter-control">
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label for="to"><i class="fas fa-calendar-day mr-1"></i> To Date</label>
                                        <input type="date" name="to" id="to" value="<?= $toDate ?>" class="filter-control">
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label for="sort"><i class="fas fa-sort mr-1"></i> Sort By</label>
                                        <select name="sort" id="sort" class="filter-control">
                                            <option value="revenue" <?= $sort == 'revenue' ? 'selected' : '' ?>>Revenue</option>
                                            <option value="name" <?= $sort == 'name' ? 'selected' : '' ?>>Name</option>
                                            <option value="clicks" <?= $sort == 'clicks' ? 'selected' : '' ?>>Clicks</option>
                                            <option value="conversions" <?= $sort == 'conversions' ? 'selected' : '' ?>>Conversions</option>
                                            <option value="cr" <?= $sort == 'cr' ? 'selected' : '' ?>>Conversion Rate</option>
                                            <option value="epc" <?= $sort == 'epc' ? 'selected' : '' ?>>EPC</option>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label for="order"><i class="fas fa-sort-amount-down mr-1"></i> Order</label>
                                        <select name="order" id="order" class="filter-control">
                                            <option value="desc" <?= $order == 'desc' ? 'selected' : '' ?>>Descending</option>
                                            <option value="asc" <?= $order == 'asc' ? 'selected' : '' ?>>Ascending</option>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <button type="submit" class="btn-gradient" style="height: 45px; width: 100%;">
                                            <i class="fas fa-search mr-2"></i> Apply Filters
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

                        <!-- Affiliates Table -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-users mr-2"></i> Affiliate Performance Details
                                </h3>
                                <div class="card-tools">
                                    <span class="badge badge-light">
                                        <?php echo count($affiliates); ?> Affiliate<?php echo count($affiliates) !== 1 ? 's' : ''; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($affiliates)): ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-users"></i>
                                        </div>
                                        <h5>No Affiliates Found</h5>
                                        <p class="text-muted">No affiliate data available for the selected filters.</p>
                                        <a href="reports_affiliates.php" class="btn btn-gradient btn-sm">
                                            <i class="fas fa-redo mr-2"></i> Reset Filters
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-dashboard" id="affiliatesTable">
                                            <thead>
                                                <tr>
                                                    <th>
                                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'name', 'order' => $sort == 'name' && $order == 'asc' ? 'desc' : 'asc'])); ?>" 
                                                           class="sort-link">
                                                            Affiliate
                                                            <?php if ($sort == 'name'): ?>
                                                                <i class="fas fa-sort-<?php echo $order == 'asc' ? 'up' : 'down'; ?>"></i>
                                                            <?php endif; ?>
                                                        </a>
                                                    </th>
                                                    <th>
                                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'clicks', 'order' => $sort == 'clicks' && $order == 'asc' ? 'desc' : 'asc'])); ?>" 
                                                           class="sort-link">
                                                            Clicks
                                                            <?php if ($sort == 'clicks'): ?>
                                                                <i class="fas fa-sort-<?php echo $order == 'asc' ? 'up' : 'down'; ?>"></i>
                                                            <?php endif; ?>
                                                        </a>
                                                    </th>
                                                    <th>
                                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'conversions', 'order' => $sort == 'conversions' && $order == 'asc' ? 'desc' : 'asc'])); ?>" 
                                                           class="sort-link">
                                                            Conversions
                                                            <?php if ($sort == 'conversions'): ?>
                                                                <i class="fas fa-sort-<?php echo $order == 'asc' ? 'up' : 'down'; ?>"></i>
                                                            <?php endif; ?>
                                                        </a>
                                                    </th>
                                                    <th>Approved</th>
                                                    <th>
                                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'cr', 'order' => $sort == 'cr' && $order == 'asc' ? 'desc' : 'asc'])); ?>" 
                                                           class="sort-link">
                                                            CR%
                                                            <?php if ($sort == 'cr'): ?>
                                                                <i class="fas fa-sort-<?php echo $order == 'asc' ? 'up' : 'down'; ?>"></i>
                                                            <?php endif; ?>
                                                        </a>
                                                    </th>
                                                    <th>Approval %</th>
                                                    <th>
                                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'revenue', 'order' => $sort == 'revenue' && $order == 'asc' ? 'desc' : 'asc'])); ?>" 
                                                           class="sort-link">
                                                            Revenue
                                                            <?php if ($sort == 'revenue'): ?>
                                                                <i class="fas fa-sort-<?php echo $order == 'asc' ? 'up' : 'down'; ?>"></i>
                                                            <?php endif; ?>
                                                        </a>
                                                    </th>
                                                    <th>
                                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'epc', 'order' => $sort == 'epc' && $order == 'asc' ? 'desc' : 'asc'])); ?>" 
                                                           class="sort-link">
                                                            EPC
                                                            <?php if ($sort == 'epc'): ?>
                                                                <i class="fas fa-sort-<?php echo $order == 'asc' ? 'up' : 'down'; ?>"></i>
                                                            <?php endif; ?>
                                                        </a>
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($affiliates as $a): 
                                                    $approvalRate = ($a['total_conversions'] ?? 0) > 0
                                                        ? (($a['approved_conversions'] ?? 0) / $a['total_conversions']) * 100
                                                        : 0;
                                                    
                                                    $cr = (float)($a['conversion_rate'] ?? 0);
                                                    $performanceClass = 'none';
                                                    if ($cr >= 10) $performanceClass = 'excellent';
                                                    elseif ($cr >= 5) $performanceClass = 'good';
                                                    elseif ($cr >= 2) $performanceClass = 'average';
                                                    elseif ($cr > 0) $performanceClass = 'low';
                                                    
                                                    $approvalClass = 'low';
                                                    if ($approvalRate >= 80) $approvalClass = 'high';
                                                    elseif ($approvalRate >= 50) $approvalClass = 'medium';
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars($a['affiliate_name'] ?? '') ?></strong>
                                                        <div class="affiliate-email">
                                                            <?= htmlspecialchars($a['affiliate_email'] ?? '') ?>
                                                        </div>
                                                        <span class="performance-badge performance-<?= $performanceClass ?>">
                                                            <?= ucfirst($performanceClass) ?>
                                                        </span>
                                                    </td>
                                                    <td><strong><?= number_format($a['total_clicks'] ?? 0) ?></strong></td>
                                                    <td><?= number_format($a['total_conversions'] ?? 0) ?></td>
                                                    <td><?= number_format($a['approved_conversions'] ?? 0) ?></td>
                                                    <td><span class="badge-excellent"><?= $a['conversion_rate'] ?? 0 ?>%</span></td>
                                                    <td>
                                                        <span class="approval-badge approval-<?= $approvalClass ?>">
                                                            <?= number_format($approvalRate, 2) ?>%
                                                        </span>
                                                    </td>
                                                    <td><span class="revenue-value">$<?= number_format($a['approved_revenue'] ?? 0, 2) ?></span></td>
                                                    <td><span class="badge-good">$<?= number_format($a['epc'] ?? 0, 4) ?></span></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar Widgets -->
                    <div class="col-lg-4">
                        <!-- Performance Distribution Chart -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Performance Distribution</h3>
                            </div>
                            <div class="card-body">
                                <?php 
                                $maxCount = max(array_column($performanceDistribution, 'count'));
                                $colors = ['#28a745', '#17a2b8', '#ffc107', '#6c757d', '#dc3545'];
                                ?>
                                <div class="performance-chart">
                                    <?php foreach ($performanceDistribution as $index => $perf): 
                                        $height = $maxCount > 0 ? ($perf['count'] / $maxCount) * 100 : 0;
                                    ?>
                                        <div class="performance-bar" 
                                             style="height: <?= $height ?>%; background: <?= $colors[$index] ?>"
                                             data-count="<?= $perf['count'] ?> affiliates">
                                            <div class="performance-label">
                                                <?= $perf['count'] ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mt-4">
                                    <?php foreach ($performanceDistribution as $index => $perf): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span>
                                                <span class="badge" style="background: <?= $colors[$index] ?>; width: 12px; height: 12px; display: inline-block; border-radius: 50%;"></span>
                                                <?= $perf['level'] ?>
                                            </span>
                                            <div>
                                                <span class="badge badge-light"><?= $perf['count'] ?></span>
                                                <span class="text-success ml-2">$<?= number_format($perf['revenue'], 2) ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Top Performers -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Top Performers</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($topPerformers)): ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-trophy"></i>
                                        </div>
                                        <p class="text-muted">No performance data available.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach($topPerformers as $index => $aff): ?>
                                    <div class="affiliate-card">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div class="d-flex align-items-center">
                                                <span class="badge badge-primary mr-2">#<?= $index + 1 ?></span>
                                                <strong><?= htmlspecialchars($aff['affiliate_name'] ?? '') ?></strong>
                                            </div>
                                            <span class="badge-excellent"><?= $aff['conversion_rate'] ?? 0 ?>%</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-primary">
                                                <i class="fas fa-exchange-alt mr-1"></i>
                                                <?= $aff['total_conversions'] ?? 0 ?> conv
                                            </span>
                                            <span class="text-success">
                                                <strong>$<?= number_format($aff['approved_revenue'] ?? 0, 2) ?></strong>
                                            </span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Quick Actions</h3>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="reports_campaigns.php" class="btn btn-gradient btn-block">
                                        <i class="fas fa-chart-bar mr-2"></i> Campaign Reports
                                    </a>
                                    <a href="reports_campaigns.php" class="btn btn-outline-primary btn-block">
                                        <i class="fas fa-exchange-alt mr-2"></i> Conversion Reports
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline">
            <strong>Advertiser Panel v3.0</strong>
        </div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> GVS Icon Media.</strong> All rights reserved.
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
    $('#affiliatesTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [], // Disable initial sort (we use server-side sorting)
        responsive: true,
        searching: true,
        info: true,
        paging: true,
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search affiliates..."
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
    
    // Date validation
    $('#from, #to').change(function() {
        const fromDate = new Date($('#from').val());
        const toDate = new Date($('#to').val());
        
        if (fromDate > toDate) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Date Range',
                text: 'From date cannot be after To date'
            });
            $('#to').val($('#from').val());
        }
    });
    
    // Small box hover effects
    $('.small-box').hover(
        function() {
            $(this).find('.icon').css('transform', 'translateY(-50%) scale(1.15)');
        },
        function() {
            $(this).find('.icon').css('transform', 'translateY(-50%) scale(1)');
        }
    );
    
    // Performance bar tooltips
    $('.performance-bar').hover(
        function() {
            const count = $(this).data('count');
            $(this).attr('title', count).tooltip('show');
        },
        function() {
            $(this).tooltip('dispose');
        }
    );
    
    // Initialize SweetAlert2 Toast
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });
});

// Print report
window.printReport = function() {
    window.print();
};
</script>

</body>
</html>