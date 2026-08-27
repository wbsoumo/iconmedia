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
   FILTER INPUTS
================================ */
$offerId   = isset($_GET['offer_id']) && $_GET['offer_id'] !== 'all' ? (int)$_GET['offer_id'] : null;
$status    = isset($_GET['status']) && in_array($_GET['status'], ['approved', 'pending', 'rejected']) ? $_GET['status'] : null;
$fromDate  = $_GET['from'] ?? date('Y-m-01');
$toDate    = $_GET['to'] ?? date('Y-m-d');
$export    = isset($_GET['export']);

// Validate dates
if (!strtotime($fromDate)) $fromDate = date('Y-m-01');
if (!strtotime($toDate)) $toDate = date('Y-m-d');
if (strtotime($fromDate) > strtotime($toDate)) {
    $fromDate = $toDate;
}

/* ===============================
   FETCH OFFERS FOR FILTER
================================ */
$offersStmt = $pdo->prepare("SELECT offer_id, offer_name FROM offers WHERE advertiser_id = ? ORDER BY offer_name");
$offersStmt->execute([$advertiserId]);
$offers = $offersStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   MAIN REPORT QUERY
================================ */
$sql = "
    SELECT
        o.offer_id,
        o.offer_name,
        o.status AS offer_status,
        o.payout,
        
        COUNT(DISTINCT c.click_id) AS clicks,
        COUNT(DISTINCT cv.conversion_id) AS conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        
        COALESCE(SUM(cv.revenue), 0) AS total_revenue,
        COALESCE(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS approved_revenue

    FROM offers o
    LEFT JOIN clicks c ON c.offer_id = o.offer_id AND DATE(c.created_at) BETWEEN ? AND ?
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id AND DATE(cv.created_at) BETWEEN ? AND ?
    WHERE o.advertiser_id = ?
";

$params = [$fromDate, $toDate, $fromDate, $toDate, $advertiserId];

if ($offerId) {
    $sql .= " AND o.offer_id = ?";
    $params[] = $offerId;
}

if ($status) {
    $sql .= " AND cv.status = ?";
    $params[] = $status;
}

$sql .= " GROUP BY o.offer_id, o.offer_name, o.status, o.payout ORDER BY approved_revenue DESC";

// Execute main query
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   SUMMARY STATISTICS
================================ */
$summary = [
    'total_clicks' => 0,
    'total_conversions' => 0,
    'total_approved_conversions' => 0,
    'total_revenue' => 0,
    'total_approved_revenue' => 0
];

foreach ($rows as $row) {
    $summary['total_clicks'] += (int)($row['clicks'] ?? 0);
    $summary['total_conversions'] += (int)($row['conversions'] ?? 0);
    $summary['total_approved_conversions'] += (int)($row['approved_conversions'] ?? 0);
    $summary['total_revenue'] += (float)($row['total_revenue'] ?? 0);
    $summary['total_approved_revenue'] += (float)($row['approved_revenue'] ?? 0);
}

/* ===============================
   TOP PERFORMING OFFERS
================================ */
$topOffers = [];
if (!empty($rows)) {
    $topSql = "
        SELECT
            o.offer_id,
            o.offer_name,
            COUNT(DISTINCT cv.conversion_id) AS conversions,
            COALESCE(SUM(cv.revenue), 0) AS revenue,
            ROUND(
                COUNT(DISTINCT cv.conversion_id) * 100.0 / 
                NULLIF(COUNT(DISTINCT c.click_id), 0), 2
            ) AS conversion_rate
        FROM offers o
        LEFT JOIN clicks c ON c.offer_id = o.offer_id AND DATE(c.created_at) BETWEEN ? AND ?
        LEFT JOIN conversions cv ON cv.offer_id = o.offer_id AND DATE(cv.created_at) BETWEEN ? AND ?
        WHERE o.advertiser_id = ?
    ";
    
    $topParams = [$fromDate, $toDate, $fromDate, $toDate, $advertiserId];
    
    if ($status) {
        $topSql .= " AND cv.status = ?";
        $topParams[] = $status;
    }
    
    $topSql .= " GROUP BY o.offer_id, o.offer_name HAVING conversions > 0 ORDER BY revenue DESC LIMIT 5";
    
    $topStmt = $pdo->prepare($topSql);
    $topStmt->execute($topParams);
    $topOffers = $topStmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ===============================
   TREND DATA FOR CHART (7 DAYS)
================================ */
$trendStmt = $pdo->prepare("
    SELECT
        DATE(cv.created_at) AS date,
        COUNT(*) AS conversions,
        IFNULL(SUM(cv.revenue), 0) AS revenue
    FROM conversions cv
    INNER JOIN offers o ON o.offer_id = cv.offer_id
    WHERE o.advertiser_id = ?
      AND cv.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(cv.created_at)
    ORDER BY date ASC
");
$trendStmt->execute([$advertiserId]);
$trendData = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   RECENT CONVERSIONS
================================ */
$recentStmt = $pdo->prepare("
    SELECT
        cv.conversion_id,
        cv.transaction_id,
        cv.revenue,
        cv.status,
        cv.created_at,
        o.offer_name,
        c.country,
        c.device
    FROM conversions cv
    INNER JOIN offers o ON o.offer_id = cv.offer_id
    LEFT JOIN clicks c ON c.click_id = cv.click_id
    WHERE o.advertiser_id = ?
    ORDER BY cv.created_at DESC
    LIMIT 5
");
$recentStmt->execute([$advertiserId]);
$recentConversions = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   HANDLE EXPORT
================================ */
if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="campaign-report-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel
    
    fputcsv($output, ['Offer', 'Status', 'Payout', 'Clicks', 'Conversions', 'Approved', 'CR%', 'Revenue', 'Approved Revenue']);
    
    foreach ($rows as $row) {
        $cr = ($row['clicks'] ?? 0) > 0 ? (($row['conversions'] ?? 0) / $row['clicks']) * 100 : 0;
        fputcsv($output, [
            $row['offer_name'],
            $row['offer_status'],
            $row['payout'] ? '$' . number_format($row['payout'], 2) : '-',
            number_format($row['clicks'] ?? 0),
            number_format($row['conversions'] ?? 0),
            number_format($row['approved_conversions'] ?? 0),
            number_format($cr, 2) . '%',
            '$' . number_format($row['total_revenue'] ?? 0, 2),
            '$' . number_format($row['approved_revenue'] ?? 0, 2)
        ]);
    }
    
    fclose($output);
    exit;
}

// Calculate metrics
$overallCR = $summary['total_clicks'] > 0 ? ($summary['total_conversions'] / $summary['total_clicks']) * 100 : 0;
$approvalRate = $summary['total_conversions'] > 0 ? ($summary['total_approved_conversions'] / $summary['total_conversions']) * 100 : 0;
$rpc = $summary['total_clicks'] > 0 ? $summary['total_revenue'] / $summary['total_clicks'] : 0;
$avgRevenue = $summary['total_conversions'] > 0 ? $summary['total_revenue'] / $summary['total_conversions'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Campaign Reports | Advertiser Panel | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* 2x2 Grid for Stat Boxes on Mobile */
        @media (max-width: 767.98px) {
            .stat-boxes-row > [class*="col-"] {
                flex: 0 0 50% !important;
                max-width: 50% !important;
                padding-left: 6px !important;
                padding-right: 6px !important;
            }

            .stat-boxes-row .small-box {
                margin-bottom: 12px !important;
            }

            .stat-boxes-row .small-box .inner {
                padding: 12px 10px !important;
            }

            .stat-boxes-row .small-box h3 {
                font-size: 18px !important;
                margin-bottom: 2px !important;
            }

            .stat-boxes-row .small-box p {
                font-size: 11px !important;
                margin-bottom: 0 !important;
            }

            .stat-boxes-row .small-box .icon {
                display: none !important;
            }

            .stat-boxes-row .small-box-footer {
                padding: 4px 0 !important;
                font-size: 11px !important;
            }
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
        
        .bg-gradient-dark {
            background: var(--dark-gradient) !important;
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
        
        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-approved {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        
        .status-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }
        
        .status-rejected {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }
        
        .status-active {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        
        .status-paused {
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
            color: #2e59d9;
            margin-bottom: 5px;
        }
        
        .metric-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
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
        }
        
        .table-dashboard tbody td {
            padding: 15px;
            border-bottom: 1px solid #f8f9fa;
            vertical-align: middle;
        }
        
        .table-dashboard tbody tr:hover {
            background: #f8f9fc;
        }
        
        .conversion-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f8f9fa;
            transition: background 0.3s ease;
        }
        
        .conversion-item:hover {
            background: #f8f9fc;
        }
        
        .country-badge {
            background: #e3e6f0;
            color: #4e73df;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .device-badge {
            background: #f8f9fc;
            color: #6c757d;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .cr-badge {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .payout-badge {
            background: rgba(32, 201, 151, 0.1);
            color: #20c997;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
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
            color: #2e59d9;
            margin-bottom: 5px;
        }
        
        .quick-stat-label {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
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
        
        .offer-card {
            background: white;
            border: 1px solid #e3e6f0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .offer-card:hover {
            border-color: #667eea;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.1);
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
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
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
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar (Identical to dashboard) -->
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
                <a href="reports_campaigns.php" class="nav-link active">Campaign Reports</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <span class="badge badge-warning navbar-badge"><?php echo count($recentConversions); ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header">Recent Conversions</span>
                    <div class="dropdown-divider"></div>
                    <a href="reports_conversions.php" class="dropdown-item">
                        <i class="fas fa-exchange-alt mr-2 text-primary"></i> Total Revenue: $<?php echo number_format($summary['total_revenue'], 2); ?>
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
                        <?php echo strtoupper(substr($advertiserName, 0, 1)); ?>
                    </div>
                    <span><?php echo htmlspecialchars($advertiserName); ?></span>
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
                        <a href="reports_campaigns.php" class="nav-link active">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Campaign Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_conversions.php" class="nav-link">
                            <i class="fas fa-exchange-alt nav-icon"></i>
                            <p>Conversion Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link">
                            <i class="nav-icon fas fa-users"></i>
                            <p>Affiliate Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="analytics.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-pie"></i>
                            <p>Advanced Analytics</p>
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
                        <h1 class="m-0">Campaign Reports</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="reports.php">Reports</a></li>
                            <li class="breadcrumb-item active">Campaign Reports</li>
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
                            <h2>Campaign Performance Reports</h2>
                            <p class="mb-0">Analyze your campaign performance and track conversions over time.</p>
                        </div>
                        <div class="col-md-4 text-right">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 1])); ?>" class="refresh-btn">
                                <i class="fas fa-download mr-1"></i> Export Report
                            </a>
                        </div>
                    </div>
                    
                    <!-- Date Range Display -->
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
                            <div class="quick-stat-value"><?php echo count($rows); ?></div>
                            <div class="quick-stat-label">Campaigns</div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="quick-stat-value">$<?php echo number_format($summary['total_revenue'], 2); ?></div>
                            <div class="quick-stat-label">Total Revenue</div>
                        </div>
                    </div>
                </div>

                <!-- Summary Stats Cards (Small Boxes like dashboard) -->
                <div class="row stat-boxes-row">
                    <div class="col-md-2">
                        <div class="small-box bg-gradient-info">
                            <div class="inner">
                                <h3><?php echo number_format($summary['total_clicks']); ?></h3>
                                <p>Total Clicks</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-mouse-pointer"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-2">
                        <div class="small-box bg-gradient-success">
                            <div class="inner">
                                <h3><?php echo number_format($summary['total_conversions']); ?></h3>
                                <p>Conversions</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-2">
                        <div class="small-box bg-gradient-primary">
                            <div class="inner">
                                <h3><?php echo number_format($summary['total_approved_conversions']); ?></h3>
                                <p>Approved</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-2">
                        <div class="small-box bg-gradient-warning">
                            <div class="inner">
                                <h3><?php echo number_format($overallCR, 2); ?>%</h3>
                                <p>Conversion Rate</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-percentage"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-2">
                        <div class="small-box bg-gradient-danger">
                            <div class="inner">
                                <h3>$<?php echo number_format($rpc, 4); ?></h3>
                                <p>Rev/Click</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-dollar-sign"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-2">
                        <div class="small-box bg-gradient-dark">
                            <div class="inner">
                                <h3>$<?php echo number_format($summary['total_revenue'], 2); ?></h3>
                                <p>Total Revenue</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-wallet"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Performance Chart -->
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">7-Day Performance Trend</h3>
                                <div class="card-tools">
                                    <button class="btn btn-tool" id="exportChart">
                                        <i class="fas fa-download"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="performanceChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card-dashboard">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-filter mr-2"></i> Filter Reports
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="get" class="filter-row">
                            <div class="filter-group">
                                <label for="offer_id">Select Offer</label>
                                <select name="offer_id" id="offer_id" class="filter-control">
                                    <option value="all">All Offers</option>
                                    <?php foreach ($offers as $offer): ?>
                                        <option value="<?php echo $offer['offer_id']; ?>" 
                                            <?php echo $offerId == $offer['offer_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($offer['offer_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="status">Conversion Status</label>
                                <select name="status" id="status" class="filter-control">
                                    <option value="">All Status</option>
                                    <option value="approved" <?php echo $status == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="rejected" <?php echo $status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="from">From Date</label>
                                <input type="date" name="from" id="from" class="filter-control" value="<?php echo $fromDate; ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label for="to">To Date</label>
                                <input type="date" name="to" id="to" class="filter-control" value="<?php echo $toDate; ?>">
                            </div>
                            
                            <div class="filter-group">
                                <button type="submit" class="btn-gradient" style="height: 45px; width: 100%;">
                                    <i class="fas fa-search mr-2"></i> Apply Filters
                                </button>
                            </div>
                            
                            <div class="filter-group">
                                <a href="reports_campaigns.php" class="btn btn-outline-primary" style="height: 45px; width: 100%; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-redo mr-2"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="row">
                    <!-- Main Report Table -->
                    <div class="col-lg-8">
                        <div class="card card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Campaign Performance Details</h3>
                                <div class="card-tools">
                                    <span class="badge badge-light">
                                        <?php echo count($rows); ?> Campaign<?php echo count($rows) != 1 ? 's' : ''; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($rows)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-chart-bar"></i>
                                    </div>
                                    <h5>No Data Found</h5>
                                    <p class="text-muted">No campaign data matches your selected filters.</p>
                                    <a href="reports_campaigns.php" class="btn btn-gradient btn-sm">
                                        <i class="fas fa-redo mr-2"></i> Reset Filters
                                    </a>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-dashboard" id="campaignTable">
                                        <thead>
                                            <tr>
                                                <th>Campaign</th>
                                                <th>Status</th>
                                                <th>Clicks</th>
                                                <th>Conversions</th>
                                                <th>Approved</th>
                                                <th>CR%</th>
                                                <th>Revenue</th>
                                                <th>Approved Rev.</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($rows as $row): 
                                                $cr = ($row['clicks'] ?? 0) > 0 ? (($row['conversions'] ?? 0) / $row['clicks']) * 100 : 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($row['offer_name']); ?></strong>
                                                    <?php if ($row['payout']): ?>
                                                    <br>
                                                    <span class="payout-badge">
                                                        Payout: $<?php echo number_format($row['payout'], 2); ?>
                                                    </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $row['offer_status']; ?>">
                                                        <?php echo ucfirst($row['offer_status']); ?>
                                                    </span>
                                                </td>
                                                <td><strong><?php echo number_format($row['clicks'] ?? 0); ?></strong></td>
                                                <td><?php echo number_format($row['conversions'] ?? 0); ?></td>
                                                <td><?php echo number_format($row['approved_conversions'] ?? 0); ?></td>
                                                <td>
                                                    <span class="cr-badge"><?php echo number_format($cr, 2); ?>%</span>
                                                </td>
                                                <td class="text-primary">$<?php echo number_format($row['total_revenue'] ?? 0, 2); ?></td>
                                                <td class="text-success">$<?php echo number_format($row['approved_revenue'] ?? 0, 2); ?></td>
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
                        <!-- Top Performing Offers -->
                        <div class="card card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Top Performing Offers</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($topOffers)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-trophy"></i>
                                    </div>
                                    <p class="text-muted">No top performers yet</p>
                                </div>
                                <?php else: ?>
                                    <?php foreach ($topOffers as $offer): ?>
                                    <div class="offer-card">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <strong><?php echo htmlspecialchars($offer['offer_name']); ?></strong>
                                            <span class="cr-badge"><?php echo $offer['conversion_rate'] ?? 0; ?>% CR</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-primary">
                                                <i class="fas fa-exchange-alt mr-1"></i>
                                                <?php echo $offer['conversions'] ?? 0; ?> conv
                                            </span>
                                            <span class="text-success">
                                                <strong>$<?php echo number_format($offer['revenue'] ?? 0, 2); ?></strong>
                                            </span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Quick Insights -->
                        <div class="card card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Quick Insights</h3>
                            </div>
                            <div class="card-body">
                                <div class="metric-card mb-3">
                                    <div class="metric-value"><?php echo number_format($approvalRate, 1); ?>%</div>
                                    <div class="metric-label">Approval Rate</div>
                                </div>
                                
                                <div class="metric-card mb-3">
                                    <div class="metric-value">$<?php echo number_format($rpc, 4); ?></div>
                                    <div class="metric-label">Revenue per Click</div>
                                </div>
                                
                                <div class="metric-card">
                                    <div class="metric-value">$<?php echo number_format($avgRevenue, 2); ?></div>
                                    <div class="metric-label">Avg. Revenue/Conv</div>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Conversions -->
                        <div class="card card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Recent Conversions</h3>
                                <div class="card-tools">
                                    <a href="reports_conversions.php" class="btn btn-tool">
                                        <i class="fas fa-external-link-alt"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recentConversions)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-exchange-alt"></i>
                                    </div>
                                    <p class="text-muted">No recent conversions.</p>
                                </div>
                                <?php else: ?>
                                    <?php foreach($recentConversions as $conv): ?>
                                    <div class="conversion-item">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <strong class="d-block"><?php echo htmlspecialchars($conv['offer_name']); ?></strong>
                                                <small class="text-muted"><?php echo date('H:i', strtotime($conv['created_at'])); ?></small>
                                            </div>
                                            <div>
                                                <span class="status-badge status-<?php echo $conv['status']; ?>">
                                                    <?php echo ucfirst($conv['status']); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="country-badge">
                                                    <i class="fas fa-globe mr-1"></i> <?php echo $conv['country'] ?? 'N/A'; ?>
                                                </span>
                                                <span class="device-badge ml-2">
                                                    <i class="fas fa-<?php echo strpos(strtolower($conv['device'] ?? ''), 'mobile') !== false ? 'mobile-alt' : 'desktop'; ?>"></i>
                                                </span>
                                            </div>
                                            <div>
                                                <strong class="text-success">$<?php echo number_format($conv['revenue'], 2); ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
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
    $('#campaignTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[6, 'desc']],
        responsive: true,
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search campaigns...",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ campaigns",
            infoEmpty: "No campaigns available",
            zeroRecords: "No matching campaigns found"
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
    
    // Initialize Performance Chart
    const ctx = document.getElementById('performanceChart').getContext('2d');
    const performanceChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_map(function($t) { 
                return date('D', strtotime($t['date'] ?? 'now')); 
            }, $trendData)); ?>,
            datasets: [{
                label: 'Revenue',
                data: <?php echo json_encode(array_column($trendData, 'revenue')); ?>,
                borderColor: '#4e73df',
                backgroundColor: 'rgba(78, 115, 223, 0.05)',
                tension: 0.4,
                fill: true,
                yAxisID: 'y'
            }, {
                label: 'Conversions',
                data: <?php echo json_encode(array_column($trendData, 'conversions')); ?>,
                borderColor: '#1cc88a',
                backgroundColor: 'rgba(28, 200, 138, 0.05)',
                tension: 0.4,
                fill: true,
                yAxisID: 'y1'
            }]
        },
        options: {
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            scales: {
                x: {
                    grid: { display: false }
                },
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true,
                    grid: { borderDash: [2] },
                    ticks: {
                        callback: function(value) {
                            return '$' + value;
                        }
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    grid: { drawOnChartArea: false },
                    ticks: {
                        callback: function(value) {
                            return value + ' conv';
                        }
                    }
                }
            },
            plugins: {
                legend: { 
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 20
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                }
            }
        }
    });
    
    // Export chart
    $('#exportChart').click(function() {
        const link = document.createElement('a');
        link.download = 'performance-chart.png';
        link.href = performanceChart.toBase64Image();
        link.click();
        
        Toast.fire({
            icon: 'success',
            title: 'Chart exported successfully!'
        });
    });
    
    // Initialize SweetAlert2 Toast
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer);
            toast.addEventListener('mouseleave', Swal.resumeTimer);
        }
    });
    
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
});
</script>

</body>
</html>