<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('affiliate');

$affiliateId   = auth_user_id();
$affiliateName = $_SESSION['user_name'] ?? 'Affiliate';

/* -------------------------------------------------
   DEFAULT STATS (SAFE FALLBACK)
-------------------------------------------------- */
$stats = [
    'total'          => 0,
    'approved'       => 0,
    'pending'        => 0,
    'rejected'       => 0,
    'total_earnings' => 0,
    'unique_offers'  => 0,
    'avg_payout'     => 0
];


/* -------------------------------------------------
   DATE RANGE DEFAULTS (ALWAYS DEFINED)
-------------------------------------------------- */

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date']   ?? date('Y-m-d');

/* -------------------------------------------------
   DATE FILTER
-------------------------------------------------- */

if (!empty($startDate) && !empty($endDate)) {

    $where[] = "cv.created_at BETWEEN :start_date AND :end_date";

    $params['start_date'] = $startDate . ' 00:00:00';
    $params['end_date']   = $endDate   . ' 23:59:59';
}



/* -------------------------------------------------
   BUILD FILTER CONDITIONS
-------------------------------------------------- */
$where  = [];
$params = ['aid' => $affiliateId];

/* -------------------------------------------------
   DATE FILTER (ONLY IF PROVIDED)
-------------------------------------------------- */
if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {

    $startDate = $_GET['start_date'] . ' 00:00:00';
    $endDate   = $_GET['end_date'] . ' 23:59:59';

    $where[] = "cv.created_at BETWEEN :start_date AND :end_date";
    $params['start_date'] = $startDate;
    $params['end_date']   = $endDate;
}

/* -------------------------------------------------
   OPTIONAL FILTERS
-------------------------------------------------- */

if (!empty($_GET['offer_id'])) {
    $where[] = "cv.offer_id = :offer_id";
    $params['offer_id'] = (int)$_GET['offer_id'];
}

if (!empty($_GET['status'])) {
    $where[] = "cv.status = :status";
    $params['status'] = $_GET['status'];
}

if (!empty($_GET['conversion_id'])) {
    $where[] = "cv.conversion_id = :conversion_id";
    $params['conversion_id'] = (int)$_GET['conversion_id'];
}

if (!empty($_GET['click_id'])) {
    $where[] = "cv.click_id = :click_id";
    $params['click_id'] = $_GET['click_id'];
}

if (!empty($_GET['transaction_id'])) {
    $where[] = "cv.transaction_id LIKE :transaction_id";
    $params['transaction_id'] = '%' . $_GET['transaction_id'] . '%';
}

if (!empty($_GET['source'])) {
    $where[] = "cv.source = :source";
    $params['source'] = $_GET['source'];
}

if (!empty($_GET['payout_min'])) {
    $where[] = "cv.payout >= :payout_min";
    $params['payout_min'] = (float)$_GET['payout_min'];
}

if (!empty($_GET['payout_max'])) {
    $where[] = "cv.payout <= :payout_max";
    $params['payout_max'] = (float)$_GET['payout_max'];
}

if (isset($_GET['has_click'])) {
    if ($_GET['has_click'] === '1') {
        $where[] = "cv.click_id IS NOT NULL AND cv.click_id != ''";
    } elseif ($_GET['has_click'] === '0') {
        $where[] = "(cv.click_id IS NULL OR cv.click_id = '')";
    }
}

/* -------------------------------------------------
   FINAL WHERE SQL
-------------------------------------------------- */
$whereSql = "WHERE cv.affiliate_id = :aid";

if (!empty($where)) {
    $whereSql .= " AND " . implode(' AND ', $where);
}

/* -------------------------------------------------
   STATS QUERY
-------------------------------------------------- */
$statsSql = "
SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved,
    SUM(CASE WHEN cv.status = 'pending' THEN 1 ELSE 0 END) AS pending,
    SUM(CASE WHEN cv.status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
    COALESCE(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END),0) AS total_earnings,
    COUNT(DISTINCT cv.offer_id) AS unique_offers,
    COALESCE(AVG(CASE WHEN cv.status = 'approved' THEN cv.payout END),0) AS avg_payout
FROM conversions cv
$whereSql
";

$statsStmt = $pdo->prepare($statsSql);
$statsStmt->execute($params);
$row = $statsStmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    $stats = array_merge($stats, $row);
}

/* -------------------------------------------------
   MAIN REPORT DATA
-------------------------------------------------- */
$reportSql = "
SELECT
    cv.conversion_id,
    cv.click_id,
    cv.offer_id,
    o.offer_name,
    cv.payout,
    cv.revenue,
    cv.status,
    cv.source,
    cv.created_at,
    cv.updated_at,
    cv.transaction_id,
    c.country,
    c.device,
    c.browser,
    c.sub1,
    c.sub2,
    c.sub3,
    c.referer,
    INET6_NTOA(c.ip_address) AS full_ip
FROM conversions cv
INNER JOIN offers o ON o.offer_id = cv.offer_id
LEFT JOIN clicks c ON c.click_id = cv.click_id
$whereSql
ORDER BY cv.created_at DESC
LIMIT 1000
";

$reportStmt = $pdo->prepare($reportSql);
$reportStmt->execute($params);
$reportData = $reportStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   TREND DATA
-------------------------------------------------- */
$trendSql = "
SELECT
    DATE(cv.created_at) as date,
    COUNT(*) as total,
    SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN cv.status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN cv.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END) as earnings
FROM conversions cv
$whereSql
GROUP BY DATE(cv.created_at)
ORDER BY date ASC
";

$trendStmt = $pdo->prepare($trendSql);
$trendStmt->execute($params);
$trendData = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   OFFER PERFORMANCE
-------------------------------------------------- */
$offerStatsSql = "
SELECT
    o.offer_id,
    o.offer_name,
    COUNT(*) as total,
    SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN cv.status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN cv.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END) as earnings,
    AVG(CASE WHEN cv.status = 'approved' THEN cv.payout END) as avg_payout
FROM conversions cv
INNER JOIN offers o ON o.offer_id = cv.offer_id
$whereSql
GROUP BY o.offer_id, o.offer_name
ORDER BY earnings DESC
LIMIT 10
";

$offerStatsStmt = $pdo->prepare($offerStatsSql);
$offerStatsStmt->execute($params);
$offerStats = $offerStatsStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   STATUS BREAKDOWN
-------------------------------------------------- */
$statusStatsSql = "
SELECT
    cv.status,
    COUNT(*) as count,
    SUM(cv.payout) as total_payout,
    AVG(cv.payout) as avg_payout
FROM conversions cv
$whereSql
GROUP BY cv.status
";

$statusStatsStmt = $pdo->prepare($statusStatsSql);
$statusStatsStmt->execute($params);
$statusStats = $statusStatsStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   SOURCE BREAKDOWN
-------------------------------------------------- */
$sourceStatsSql = "
SELECT
    cv.source,
    COUNT(*) as count,
    SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END) as earnings
FROM conversions cv
$whereSql
GROUP BY cv.source
";

$sourceStatsStmt = $pdo->prepare($sourceStatsSql);
$sourceStatsStmt->execute($params);
$sourceStats = $sourceStatsStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   FILTER DROPDOWNS
-------------------------------------------------- */
$offers = $pdo->prepare("
    SELECT DISTINCT o.offer_id, o.offer_name
    FROM conversions cv
    INNER JOIN offers o ON o.offer_id = cv.offer_id
    WHERE cv.affiliate_id = :aid
    ORDER BY o.offer_name
");
$offers->execute(['aid' => $affiliateId]);
$offers = $offers->fetchAll(PDO::FETCH_ASSOC);

$sources = $pdo->prepare("
    SELECT DISTINCT source
    FROM conversions
    WHERE affiliate_id = :aid AND source IS NOT NULL
    ORDER BY source
");
$sources->execute(['aid' => $affiliateId]);
$sources = $sources->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   EXPORT TO EXCEL
-------------------------------------------------- */
if (isset($_GET['export']) && $_GET['export'] === 'excel') {

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="conversion_report_' . date('Y-m-d') . '.xls"');

    echo "<table border='1'>
    <tr>
        <th>Conversion ID</th>
        <th>Date & Time</th>
        <th>Offer</th>
        <th>Click ID</th>
        <th>Transaction ID</th>
        <th>Payout</th>
        <th>Revenue</th>
        <th>Status</th>
        <th>Source</th>
        <th>Country</th>
        <th>Device</th>
        <th>Browser</th>
        <th>Sub1</th>
        <th>Sub2</th>
        <th>Sub3</th>
        <th>IP Address</th>
    </tr>";

    foreach ($reportData as $r) {
        echo "<tr>
            <td>{$r['conversion_id']}</td>
            <td>{$r['created_at']}</td>
            <td>" . htmlspecialchars($r['offer_name']) . "</td>
            <td>{$r['click_id']}</td>
            <td>" . htmlspecialchars($r['transaction_id']) . "</td>
            <td>\${$r['payout']}</td>
            <td>\${$r['revenue']}</td>
            <td>" . ucfirst($r['status']) . "</td>
            <td>" . ucfirst($r['source']) . "</td>
            <td>{$r['country']}</td>
            <td>{$r['device']}</td>
            <td>{$r['browser']}</td>
            <td>{$r['sub1']}</td>
            <td>{$r['sub2']}</td>
            <td>{$r['sub3']}</td>
            <td>{$r['full_ip']}</td>
        </tr>";
    }

    echo "</table>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conversion Reports | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Ionicons -->
    <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap4.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Date Range Picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --info-gradient: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            --warning-gradient: linear-gradient(135deg, #f7971e 0%, #ffd200 100%);
            --danger-gradient: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
        }
        
        .small-box {
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
            color: white;
        }
        
        .small-box:hover {
            transform: translateY(-5px);
        }
        
        .small-box .icon {
            font-size: 70px;
            opacity: 0.3;
            transition: all 0.3s ease;
        }
        
        .small-box:hover .icon {
            opacity: 0.5;
            transform: scale(1.1);
        }

        /* 2x2 Grid strictly for 4 main stat boxes on Mobile */
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
        
        .card-stat {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .card-stat .card-header {
            border-radius: 15px 15px 0 0;
            background: white;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
        
        .conversion-detail {
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .conversion-detail:hover {
            background: rgba(0,0,0,0.02);
        }
        
        .export-btn {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
            color: white;
        }
        
        .report-summary {
            background: linear-gradient(135deg, #f8f9fc 0%, #eaecf4 100%);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .metric-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            border: 1px solid #e3e6f0;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .metric-value {
            font-size: 24px;
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
        
        .progress-status {
            height: 8px;
            border-radius: 4px;
        }
        
        .progress-approved {
            background: linear-gradient(90deg, #28a745, #20c997);
        }
        
        .progress-pending {
            background: linear-gradient(90deg, #ffc107, #fd7e14);
        }
        
        .progress-rejected {
            background: linear-gradient(90deg, #dc3545, #c82333);
        }
        
        .earnings-positive {
            color: #28a745;
            font-weight: 600;
        }
        
        .transaction-id {
            font-family: monospace;
            font-size: 11px;
            background: #f8f9fa;
            padding: 2px 5px;
            border-radius: 3px;
        }
        
        .source-badge {
            background: rgba(23, 162, 184, 0.15);
            color: #17a2b8;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .subid-badge {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 12px;
            padding: 2px 8px;
            font-size: 11px;
            margin: 2px;
            display: inline-block;
            cursor: help;
        }
        
        .status-filter {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .status-filter-item {
            flex: 1;
            min-width: 100px;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        
        .status-filter-item.active {
            border-color: #007bff;
            transform: translateY(-2px);
        }
        
        .status-filter-item.approved {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }
        
        .status-filter-item.pending {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }
        
        .status-filter-item.rejected {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <!-- Left navbar links -->
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button">
                    <i class="fas fa-bars"></i>
                </a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
            </li>
        </ul>

        <!-- Right navbar links -->
        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <span class="badge badge-warning navbar-badge"><?php echo $stats['pending'] ?? 0; ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header"><?php echo $stats['pending'] ?? 0; ?> Pending Conversions</span>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-check-circle mr-2 text-success"></i> <?php echo $stats['approved'] ?? 0; ?> Approved
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-hourglass-half mr-2 text-warning"></i> <?php echo $stats['pending'] ?? 0; ?> Pending
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-times-circle mr-2 text-danger"></i> <?php echo $stats['rejected'] ?? 0; ?> Rejected
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="reports.php" class="dropdown-item">
                        <i class="fas fa-list mr-2"></i> View All Conversions
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
                    <i class="fas fa-user-circle mr-1"></i> <?php echo htmlspecialchars($affiliateName); ?>
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
                <i class="fas fa-rocket mr-2"></i>
                <strong>GVS Icon Media</strong>
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
                    
                    <li class="nav-header">REPORTS</li>
                    <li class="nav-item">
                        <a href="offers.php" class="nav-link">
                            <i class="nav-icon fas fa-gift"></i>
                            <p>Offer Performance</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="clicks.php" class="nav-link">
                            <i class="nav-icon fas fa-mouse-pointer"></i>
                            <p>Click Report</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports.php" class="nav-link active">
                            <i class="nav-icon fas fa-exchange-alt"></i>
                            <p>Conversions</p>
                        </a>
                    </li>
                    
                    <li class="nav-header">TOOLS</li>
                    <li class="nav-item">
                        <a href="link-builder.php" class="nav-link">
                            <i class="nav-icon fas fa-link"></i>
                            <p>Link Builder</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="postback.php" class="nav-link">
                            <i class="nav-icon fas fa-code"></i>
                            <p>Postback URL</p>
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
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-wallet"></i>
                            <p>Payouts</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
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
                        <h1 class="m-0">Conversion Report</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Conversions</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">
                <!-- Report Summary -->
                <div class="report-summary">
                    <div class="row">
                        <div class="col-md-8">
                            <h4 class="mb-3">Report Period: <?php echo date('M d, Y', strtotime($startDate)); ?> to <?php echo date('M d, Y', strtotime($endDate)); ?></h4>
                            <div class="d-flex flex-wrap">
                                <div class="mr-4 mb-2">
                                    <span class="text-muted">Total Conversions:</span>
                                    <strong class="ml-2"><?php echo number_format($stats['total'] ?? 0); ?></strong>
                                </div>
                                <div class="mr-4 mb-2">
                                    <span class="text-muted">Approved:</span>
                                    <strong class="ml-2 text-success"><?php echo number_format($stats['approved'] ?? 0); ?></strong>
                                </div>
                                <div class="mr-4 mb-2">
                                    <span class="text-muted">Pending:</span>
                                    <strong class="ml-2 text-warning"><?php echo number_format($stats['pending'] ?? 0); ?></strong>
                                </div>
                                <div class="mr-4 mb-2">
                                    <span class="text-muted">Rejected:</span>
                                    <strong class="ml-2 text-danger"><?php echo number_format($stats['rejected'] ?? 0); ?></strong>
                                </div>
                                <div class="mr-4 mb-2">
                                    <span class="text-muted">Unique Offers:</span>
                                    <strong class="ml-2 text-info"><?php echo number_format($stats['unique_offers'] ?? 0); ?></strong>
                                </div>
                                <div class="mb-2">
                                    <span class="text-muted">Total Earnings:</span>
                                    <strong class="ml-2 text-success">$<?php echo number_format($stats['total_earnings'] ?? 0, 2); ?></strong>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-right">
                            <a href="reports.php?export=excel&<?php echo http_build_query($_GET); ?>" class="export-btn">
                                <i class="fas fa-file-excel mr-2"></i> Export to Excel
                            </a>
                            <button class="btn btn-secondary ml-2" id="printReport">
                                <i class="fas fa-print mr-2"></i> Print
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Quick Status Filter -->
                <div class="card card-stat">
                    <div class="card-header">
                        <h3 class="card-title">Quick Status Filter</h3>
                    </div>
                    <div class="card-body">
                        <div class="status-filter">
                            <div class="status-filter-item approved <?php echo (!isset($_GET['status']) || $_GET['status'] == '') ? 'active' : ''; ?>" onclick="filterByStatus('')">
                                <i class="fas fa-list mb-2" style="font-size: 24px;"></i>
                                <h5 class="mb-0">All</h5>
                                <small><?php echo number_format($stats['total'] ?? 0); ?> conversions</small>
                            </div>
                            <div class="status-filter-item approved <?php echo (isset($_GET['status']) && $_GET['status'] == 'approved') ? 'active' : ''; ?>" onclick="filterByStatus('approved')">
                                <i class="fas fa-check-circle mb-2" style="font-size: 24px;"></i>
                                <h5 class="mb-0">Approved</h5>
                                <small><?php echo number_format($stats['approved'] ?? 0); ?> conversions</small>
                            </div>
                            <div class="status-filter-item pending <?php echo (isset($_GET['status']) && $_GET['status'] == 'pending') ? 'active' : ''; ?>" onclick="filterByStatus('pending')">
                                <i class="fas fa-hourglass-half mb-2" style="font-size: 24px;"></i>
                                <h5 class="mb-0">Pending</h5>
                                <small><?php echo number_format($stats['pending'] ?? 0); ?> conversions</small>
                            </div>
                            <div class="status-filter-item rejected <?php echo (isset($_GET['status']) && $_GET['status'] == 'rejected') ? 'active' : ''; ?>" onclick="filterByStatus('rejected')">
                                <i class="fas fa-times-circle mb-2" style="font-size: 24px;"></i>
                                <h5 class="mb-0">Rejected</h5>
                                <small><?php echo number_format($stats['rejected'] ?? 0); ?> conversions</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters Card -->
                <div class="card card-stat">
                    <div class="card-header">
                        <h3 class="card-title">Conversion Filters</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="get" action="" class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Date Range</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text">
                                                <i class="far fa-calendar-alt"></i>
                                            </span>
                                        </div>
                                        <input type="text" class="form-control" id="dateRange" 
                                               value="<?php echo date('m/d/Y', strtotime($startDate)); ?> - <?php echo date('m/d/Y', strtotime($endDate)); ?>">
                                        <input type="hidden" name="start_date" id="startDate" value="<?php echo $startDate; ?>">
                                        <input type="hidden" name="end_date" id="endDate" value="<?php echo $endDate; ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Offer</label>
                                    <select class="form-control" name="offer_id">
                                        <option value="">All Offers</option>
                                        <?php foreach ($offers as $offer): ?>
                                        <option value="<?php echo $offer['offer_id']; ?>" 
                                            <?php echo (!empty($_GET['offer_id']) && $_GET['offer_id'] == $offer['offer_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($offer['offer_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Status</label>
                                    <select class="form-control" name="status">
                                        <option value="">All Statuses</option>
                                        <option value="approved" <?php echo (!empty($_GET['status']) && $_GET['status'] == 'approved') ? 'selected' : ''; ?>>Approved</option>
                                        <option value="pending" <?php echo (!empty($_GET['status']) && $_GET['status'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                                        <option value="rejected" <?php echo (!empty($_GET['status']) && $_GET['status'] == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Source</label>
                                    <select class="form-control" name="source">
                                        <option value="">All Sources</option>
                                        <?php foreach ($sources as $source): ?>
                                        <option value="<?php echo $source['source']; ?>" 
                                            <?php echo (!empty($_GET['source']) && $_GET['source'] == $source['source']) ? 'selected' : ''; ?>>
                                            <?php echo ucfirst($source['source']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Conversion ID</label>
                                    <input type="number" class="form-control" name="conversion_id" 
                                           value="<?php echo $_GET['conversion_id'] ?? ''; ?>" 
                                           placeholder="Enter ID">
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Click ID</label>
                                    <input type="text" class="form-control" name="click_id" 
                                           value="<?php echo $_GET['click_id'] ?? ''; ?>" 
                                           placeholder="Enter Click ID">
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Transaction ID</label>
                                    <input type="text" class="form-control" name="transaction_id" 
                                           value="<?php echo $_GET['transaction_id'] ?? ''; ?>" 
                                           placeholder="Enter Transaction ID">
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Min Payout</label>
                                    <input type="number" step="0.01" class="form-control" name="payout_min" 
                                           value="<?php echo $_GET['payout_min'] ?? ''; ?>" 
                                           placeholder="0.00">
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Max Payout</label>
                                    <input type="number" step="0.01" class="form-control" name="payout_max" 
                                           value="<?php echo $_GET['payout_max'] ?? ''; ?>" 
                                           placeholder="100.00">
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Has Click</label>
                                    <select class="form-control" name="has_click">
                                        <option value="">All</option>
                                        <option value="1" <?php echo (!empty($_GET['has_click']) && $_GET['has_click'] == '1') ? 'selected' : ''; ?>>With Click Data</option>
                                        <option value="0" <?php echo (!empty($_GET['has_click']) && $_GET['has_click'] == '0') ? 'selected' : ''; ?>>Without Click</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="form-group text-right">
                                    <a href="reports.php" class="btn btn-secondary">Clear All Filters</a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter mr-1"></i> Apply Filters
                                    </button>
                                    <button type="button" class="btn btn-success" id="saveFilter">
                                        <i class="fas fa-save mr-1"></i> Save Filter
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Metrics Overview -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="small-box bg-gradient-success">
                            <div class="inner">
                                <h3><?php echo number_format($stats['approved'] ?? 0); ?></h3>
                                <p>Approved Conversions</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <a href="#" class="small-box-footer">Approved: $<?php echo number_format($stats['total_earnings'] ?? 0, 2); ?> <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="small-box bg-gradient-warning">
                            <div class="inner">
                                <h3><?php echo number_format($stats['pending'] ?? 0); ?></h3>
                                <p>Pending Conversions</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-hourglass-half"></i>
                            </div>
                            <a href="#" class="small-box-footer">Awaiting Approval <i class="fas fa-clock"></i></a>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="small-box bg-gradient-danger">
                            <div class="inner">
                                <h3><?php echo number_format($stats['rejected'] ?? 0); ?></h3>
                                <p>Rejected Conversions</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-times-circle"></i>
                            </div>
                            <a href="#" class="small-box-footer">View Rejected <i class="fas fa-search"></i></a>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="small-box bg-gradient-info">
                            <div class="inner">
                                <h3>$<?php echo number_format($stats['avg_payout'] ?? 0, 2); ?></h3>
                                <p>Avg. Payout</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <a href="#" class="small-box-footer"><?php echo number_format($stats['unique_offers'] ?? 0); ?> Active Offers <i class="fas fa-gift"></i></a>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card card-stat">
                            <div class="card-header">
                                <h3 class="card-title">Conversion Trend</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="trendChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <div class="card card-stat">
                            <div class="card-header">
                                <h3 class="card-title">Status Breakdown</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="statusChart"></canvas>
                                </div>
                                <div class="mt-3">
                                    <?php foreach ($statusStats as $status): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <span class="status-badge status-<?php echo $status['status']; ?>">
                                                <?php echo ucfirst($status['status']); ?>
                                            </span>
                                        </div>
                                        <div>
                                            <strong><?php echo number_format($status['count']); ?></strong>
                                            <small class="text-muted ml-2">($<?php echo number_format($status['total_payout'] ?? 0, 2); ?>)</small>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Source Breakdown -->
                <div class="row">
                    <div class="col-12">
                        <div class="card card-stat">
                            <div class="card-header">
                                <h3 class="card-title">Conversion Sources</h3>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach ($sourceStats as $source): ?>
                                    <div class="col-md-4">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo number_format($source['count']); ?></div>
                                            <div class="metric-label">
                                                <span class="source-badge"><?php echo ucfirst($source['source']); ?></span>
                                            </div>
                                            <small class="text-muted">Earnings: $<?php echo number_format($source['earnings'], 2); ?></small>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Offers Performance -->
                <?php if (!empty($offerStats)): ?>
                <div class="card card-stat">
                    <div class="card-header">
                        <h3 class="card-title">Top Performing Offers</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Offer</th>
                                        <th>Total</th>
                                        <th>Approved</th>
                                        <th>Pending</th>
                                        <th>Rejected</th>
                                        <th>Approval Rate</th>
                                        <th>Earnings</th>
                                        <th>Avg Payout</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($offerStats as $offer): 
                                        $approvalRate = $offer['total'] > 0 ? ($offer['approved'] / $offer['total']) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($offer['offer_name']); ?></strong></td>
                                        <td><?php echo number_format($offer['total']); ?></td>
                                        <td>
                                            <span class="badge badge-success">
                                                <?php echo number_format($offer['approved']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-warning">
                                                <?php echo number_format($offer['pending']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-danger">
                                                <?php echo number_format($offer['rejected']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-success" 
                                                     style="width: <?php echo $approvalRate; ?>%"
                                                     role="progressbar">
                                                    <?php echo number_format($approvalRate, 1); ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <strong class="text-primary">$<?php echo number_format($offer['earnings'], 2); ?></strong>
                                        </td>
                                        <td>
                                            $<?php echo number_format($offer['avg_payout'] ?? 0, 2); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Detailed Conversion Report Table -->
                <div class="card card-stat">
                    <div class="card-header">
                        <h3 class="card-title">Detailed Conversion Logs</h3>
                        <div class="card-tools">
                            <div class="btn-group">
                                <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                    <i class="fas fa-minus"></i>
                                </button>
                                <button type="button" class="btn btn-tool" data-card-widget="maximize">
                                    <i class="fas fa-expand"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover" id="conversionTable">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Date & Time</th>
                                        <th>Offer</th>
                                        <th>Click ID</th>
                                        <th>Transaction ID</th>
                                        <th>Payout</th>
                                        <th>Status</th>
                                        <th>Source</th>
                                        <th>Country</th>
                                        <th>Device</th>
                                        <th>Sub IDs</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($reportData)): ?>
                                    <tr>
                                        <td colspan="12" class="text-center py-4">
                                            <i class="fas fa-search fa-2x text-muted mb-3"></i>
                                            <h5>No conversion data found</h5>
                                            <p class="text-muted">Try adjusting your filters or select a different date range.</p>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($reportData as $conversion): ?>
                                        <tr class="conversion-row" data-id="<?php echo $conversion['conversion_id']; ?>">
                                            <td>
                                                <code>#<?php echo $conversion['conversion_id']; ?></code>
                                            </td>
                                            <td>
                                                <span class="badge badge-light">
                                                    <?php echo date('M d, H:i:s', strtotime($conversion['created_at'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($conversion['offer_name']); ?></strong>
                                            </td>
                                            <td>
                                                <?php if (!empty($conversion['click_id'])): ?>
                                                <a href="clicks.php?click_id=<?php echo urlencode($conversion['click_id']); ?>" title="View Click Details">
                                                    <code><?php echo substr($conversion['click_id'], 0, 8); ?>...</code>
                                                </a>
                                                <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($conversion['transaction_id'])): ?>
                                                <span class="transaction-id" title="<?php echo htmlspecialchars($conversion['transaction_id']); ?>">
                                                    <?php echo substr($conversion['transaction_id'], 0, 12); ?>...
                                                </span>
                                                <?php else: ?>
                                                <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong class="<?php echo $conversion['status'] == 'approved' ? 'earnings-positive' : 'text-muted'; ?>">
                                                    $<?php echo number_format($conversion['payout'], 2); ?>
                                                </strong>
                                                <small class="text-muted d-block">Rev: $<?php echo number_format($conversion['revenue'], 2); ?></small>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $conversion['status']; ?>">
                                                    <?php echo ucfirst($conversion['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="source-badge">
                                                    <?php echo ucfirst($conversion['source']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($conversion['country'])): ?>
                                                <span class="badge badge-info">
                                                    <i class="fas fa-globe mr-1"></i><?php echo htmlspecialchars($conversion['country']); ?>
                                                </span>
                                                <?php else: ?>
                                                <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <i class="fas fa-<?php echo strpos(strtolower($conversion['device'] ?? ''), 'mobile') !== false ? 'mobile-alt' : 'desktop'; ?> device-icon"></i>
                                                <?php echo htmlspecialchars($conversion['device'] ?? 'Unknown'); ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($conversion['sub1'])): ?>
                                                <span class="subid-badge" title="sub1: <?php echo htmlspecialchars($conversion['sub1']); ?>">
                                                    S1:<?php echo htmlspecialchars(substr($conversion['sub1'], 0, 8)); ?>
                                                </span>
                                                <?php endif; ?>
                                                <?php if (!empty($conversion['sub2'])): ?>
                                                <span class="subid-badge" title="sub2: <?php echo htmlspecialchars($conversion['sub2']); ?>">
                                                    S2:<?php echo htmlspecialchars(substr($conversion['sub2'], 0, 8)); ?>
                                                </span>
                                                <?php endif; ?>
                                                <?php if (!empty($conversion['sub3'])): ?>
                                                <span class="subid-badge" title="sub3: <?php echo htmlspecialchars($conversion['sub3']); ?>">
                                                    S3:<?php echo htmlspecialchars(substr($conversion['sub3'], 0, 8)); ?>
                                                </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <button class="btn btn-sm btn-outline-primary view-details" 
                                                            data-id="<?php echo $conversion['conversion_id']; ?>"
                                                            title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="card-footer">
                        <div class="row">
                            <div class="col-sm-6">
                                <p>
                                    <i class="fas fa-info-circle text-primary mr-1"></i>
                                    Showing <?php echo min(1000, count($reportData)); ?> of <?php echo number_format($stats['total'] ?? 0); ?> conversions
                                    <?php if (!empty($_GET)): ?>
                                    <span class="badge badge-light ml-2">Filtered</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="col-sm-6 text-right">
                                <small class="text-muted">
                                    <i class="fas fa-database mr-1"></i>
                                    Limited to 1000 most recent records. Use filters for specific searches.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Export Options -->
                <div class="card card-stat">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-download mr-2"></i>Export Options</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <a href="reports.php?export=excel&<?php echo http_build_query($_GET); ?>" class="btn btn-success btn-block">
                                    <i class="fas fa-file-excel mr-2"></i> Export Excel
                                    <small class="d-block text-light mt-1">All filtered data</small>
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <button class="btn btn-primary btn-block" id="exportPDF">
                                    <i class="fas fa-file-pdf mr-2"></i> Export PDF
                                    <small class="d-block text-light mt-1">Print-ready document</small>
                                </button>
                            </div>
                            <div class="col-md-3 mb-3">
                                <button class="btn btn-info btn-block" id="exportCSV">
                                    <i class="fas fa-file-csv mr-2"></i> Export CSV
                                    <small class="d-block text-light mt-1">Comma-separated values</small>
                                </button>
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
            <strong>GVS Icon Media v3.0</strong>
        </div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- Conversion Details Modal -->
<div class="modal fade" id="conversionModal" tabindex="-1" role="dialog" aria-labelledby="conversionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="conversionModalLabel">Conversion Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="conversionModalBody">
                Loading...
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- REQUIRED SCRIPTS -->
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<!-- Bootstrap 4 -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- AdminLTE App -->
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap4.min.js"></script>
<!-- DataTables Buttons -->
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>
<!-- Date Range Picker -->
<script src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Initialize SweetAlert2 Toast
const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true,
    didOpen: (toast) => {
        toast.addEventListener('mouseenter', Swal.stopTimer)
        toast.addEventListener('mouseleave', Swal.resumeTimer)
    }
});

$(document).ready(function() {
    // Initialize DataTable with export buttons
    const table = $('#conversionTable').DataTable({
        dom: '<"row"<"col-sm-12 col-md-6"B><"col-sm-12 col-md-6"f>>rt<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        buttons: [
            {
                extend: 'excelHtml5',
                text: '<i class="fas fa-file-excel"></i> Excel',
                className: 'btn btn-success',
                title: 'Conversion_Report_<?php echo date("Y-m-d"); ?>',
                exportOptions: {
                    columns: ':visible'
                }
            },
            {
                extend: 'pdfHtml5',
                text: '<i class="fas fa-file-pdf"></i> PDF',
                className: 'btn btn-danger',
                title: 'Conversion Report',
                exportOptions: {
                    columns: ':visible'
                }
            },
            {
                extend: 'csvHtml5',
                text: '<i class="fas fa-file-csv"></i> CSV',
                className: 'btn btn-info',
                title: 'Conversion_Report_<?php echo date("Y-m-d"); ?>',
                exportOptions: {
                    columns: ':visible'
                }
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print"></i> Print',
                className: 'btn btn-warning',
                exportOptions: {
                    columns: ':visible'
                }
            },
            {
                text: '<i class="fas fa-columns"></i> Columns',
                className: 'btn btn-secondary',
                extend: 'colvis'
            }
        ],
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[0, 'desc']],
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search conversions...",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            infoFiltered: "(filtered from _MAX_ total entries)"
        },
        responsive: true,
        scrollX: true
    });
    
    // Date Range Picker
    $('#dateRange').daterangepicker({
        opens: 'left',
        startDate: moment('<?php echo $startDate; ?>'),
        endDate: moment('<?php echo $endDate; ?>'),
        ranges: {
           'Today': [moment(), moment()],
           'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
           'Last 7 Days': [moment().subtract(6, 'days'), moment()],
           'Last 30 Days': [moment().subtract(29, 'days'), moment()],
           'This Month': [moment().startOf('month'), moment().endOf('month')],
           'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
        },
        locale: {
            format: 'MM/DD/YYYY'
        }
    }, function(start, end, label) {
        $('#startDate').val(start.format('YYYY-MM-DD'));
        $('#endDate').val(end.format('YYYY-MM-DD'));
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
    
    // View conversion details modal
    $('.view-details').click(function() {
        const id = $(this).data('id');
        const row = $(this).closest('tr').next('.details-row');
        
        // Find the conversion data from the table
        const conversion = <?php echo json_encode($reportData); ?>.find(c => c.conversion_id == id);
        
        if (conversion) {
            let detailsHtml = `
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-info-circle mr-2"></i>Conversion Details</h6>
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th style="width: 120px;">Conversion ID:</th>
                                <td><code>${conversion.conversion_id}</code></td>
                            </tr>
                            <tr>
                                <th>Click ID:</th>
                                <td><code>${conversion.click_id || 'N/A'}</code></td>
                            </tr>
                            <tr>
                                <th>Transaction ID:</th>
                                <td><code>${conversion.transaction_id || 'N/A'}</code></td>
                            </tr>
                            <tr>
                                <th>Offer:</th>
                                <td><strong>${escapeHtml(conversion.offer_name)}</strong></td>
                            </tr>
                            <tr>
                                <th>Created:</th>
                                <td>${conversion.created_at}</td>
                            </tr>
                            <tr>
                                <th>Last Updated:</th>
                                <td>${conversion.updated_at || 'N/A'}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-chart-line mr-2"></i>Performance Metrics</h6>
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th style="width: 120px;">Payout:</th>
                                <td><strong class="text-success">$${parseFloat(conversion.payout).toFixed(2)}</strong></td>
                            </tr>
                            <tr>
                                <th>Revenue:</th>
                                <td><strong class="text-primary">$${parseFloat(conversion.revenue).toFixed(2)}</strong></td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>
                                    <span class="status-badge status-${conversion.status}">
                                        ${ucfirst(conversion.status)}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Source:</th>
                                <td><span class="source-badge">${ucfirst(conversion.source)}</span></td>
                            </tr>
                            <tr>
                                <th>Country:</th>
                                <td>${conversion.country || 'Unknown'}</td>
                            </tr>
                            <tr>
                                <th>Device:</th>
                                <td>${conversion.device || 'Unknown'} / ${conversion.browser || 'Unknown'}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            `;
            
            $('#conversionModalBody').html(detailsHtml);
            $('#conversionModal').modal('show');
        }
    });
    
    // Helper functions
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function ucfirst(string) {
        return string.charAt(0).toUpperCase() + string.slice(1);
    }
    
    // Initialize Charts
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    const trendChart = new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_map(function($t) { return date('M d', strtotime($t['date'])); }, $trendData)); ?>,
            datasets: [{
                label: 'Total Conversions',
                data: <?php echo json_encode(array_column($trendData, 'total')); ?>,
                borderColor: '#4e73df',
                backgroundColor: 'rgba(78, 115, 223, 0.05)',
                tension: 0.4,
                fill: true
            }, {
                label: 'Approved',
                data: <?php echo json_encode(array_column($trendData, 'approved')); ?>,
                borderColor: '#1cc88a',
                backgroundColor: 'rgba(28, 200, 138, 0.05)',
                tension: 0.4,
                fill: true
            }, {
                label: 'Earnings ($)',
                data: <?php echo json_encode(array_column($trendData, 'earnings')); ?>,
                borderColor: '#f6c23e',
                backgroundColor: 'rgba(246, 194, 62, 0.05)',
                tension: 0.4,
                fill: true,
                yAxisID: 'y1'
            }]
        },
        options: {
            maintainAspectRatio: false,
            scales: {
                x: {
                    grid: { display: false }
                },
                y: {
                    beginAtZero: true,
                    grid: { borderDash: [2] }
                },
                y1: {
                    position: 'right',
                    beginAtZero: true,
                    grid: { drawOnChartArea: false }
                }
            },
            plugins: {
                legend: { position: 'top' }
            }
        }
    });
    
    // Status Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    const statusChart = new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: ['Approved', 'Pending', 'Rejected'],
            datasets: [{
                data: [
                    <?php echo $stats['approved'] ?? 0; ?>,
                    <?php echo $stats['pending'] ?? 0; ?>,
                    <?php echo $stats['rejected'] ?? 0; ?>
                ],
                backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
                legend: { 
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        font: {
                            size: 10
                        }
                    }
                }
            }
        }
    });
    
    // Filter by status
    window.filterByStatus = function(status) {
        const url = new URL(window.location.href);
        if (status) {
            url.searchParams.set('status', status);
        } else {
            url.searchParams.delete('status');
        }
        window.location.href = url.toString();
    };
    
    // Export buttons
    $('#exportPDF').click(function() {
        Toast.fire({
            icon: 'info',
            title: 'Generating PDF report...'
        });
        setTimeout(() => {
            table.button('.buttons-pdf').trigger();
        }, 1000);
    });
    
    $('#exportCSV').click(function() {
        table.button('.buttons-csv').trigger();
    });
    
    $('#printReport').click(function() {
        table.button('.buttons-print').trigger();
    });
    
    // Save filter
    $('#saveFilter').click(function() {
        const filterName = prompt('Enter a name for this filter:', 'My Conversion Filter');
        if (filterName) {
            const filterData = {
                name: filterName,
                params: <?php echo json_encode($_GET); ?>,
                saved_at: new Date().toISOString()
            };
            
            // Save to localStorage
            let savedFilters = JSON.parse(localStorage.getItem('conversionFilters') || '[]');
            savedFilters.push(filterData);
            localStorage.setItem('conversionFilters', JSON.stringify(savedFilters));
            
            Toast.fire({
                icon: 'success',
                title: 'Filter saved successfully!'
            });
        }
    });
});
</script>

</body>
</html>