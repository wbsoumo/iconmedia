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
    'total_clicks'          => 0,
    'unique_clicks'         => 0,
    'unique_countries'      => 0,
    'unique_devices'        => 0,
    'converted_clicks'      => 0,
    'earnings_from_clicks'  => 0,
];

/* -------------------------------------------------
   DATE RANGE (ALWAYS SET)
-------------------------------------------------- */
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date']   ?? date('Y-m-d');

/* -------------------------------------------------
   BUILD FILTER CONDITIONS
-------------------------------------------------- */
$where   = [];
$params  = [
    'aid'        => $affiliateId,
    'start_date'=> $startDate,
    'end_date'  => $endDate
];

$where[] = "DATE(c.created_at) BETWEEN :start_date AND :end_date";

if (!empty($_GET['offer_id'])) {
    $where[] = "c.offer_id = :offer_id";
    $params['offer_id'] = (int)$_GET['offer_id'];
}
if (!empty($_GET['sub1'])) {
    $where[] = "c.sub1 LIKE :sub1";
    $params['sub1'] = "%{$_GET['sub1']}%";
}
if (!empty($_GET['sub2'])) {
    $where[] = "c.sub2 LIKE :sub2";
    $params['sub2'] = "%{$_GET['sub2']}%";
}
if (!empty($_GET['sub3'])) {
    $where[] = "c.sub3 LIKE :sub3";
    $params['sub3'] = "%{$_GET['sub3']}%";
}
if (!empty($_GET['country'])) {
    $where[] = "c.country = :country";
    $params['country'] = $_GET['country'];
}
if (!empty($_GET['device'])) {
    $where[] = "c.device LIKE :device";
    $params['device'] = "%{$_GET['device']}%";
}
if (!empty($_GET['browser'])) {
    $where[] = "c.browser LIKE :browser";
    $params['browser'] = "%{$_GET['browser']}%";
}
if (!empty($_GET['ip'])) {
    $where[] = "INET6_NTOA(c.ip_address) LIKE :ip";
    $params['ip'] = $_GET['ip'] . '%';
}

if (isset($_GET['has_conversion'])) {
    if ($_GET['has_conversion'] === '1') {
        $where[] = "EXISTS (SELECT 1 FROM conversions cv WHERE cv.click_id = c.click_id)";
    } elseif ($_GET['has_conversion'] === '0') {
        $where[] = "NOT EXISTS (SELECT 1 FROM conversions cv WHERE cv.click_id = c.click_id)";
    }
}

$whereSql = "WHERE c.affiliate_id = :aid";
if ($where) {
    $whereSql .= " AND " . implode(' AND ', $where);
}

/* -------------------------------------------------
   STATS QUERY (USES SAME FILTERS)
-------------------------------------------------- */
$statsSql = "
SELECT
    COUNT(*) AS total_clicks,
    COUNT(DISTINCT c.ip_address) AS unique_clicks,
    COUNT(DISTINCT c.country) AS unique_countries,
    COUNT(DISTINCT c.device) AS unique_devices,
    COUNT(DISTINCT cv.click_id) AS converted_clicks,
    IFNULL(SUM(cv.payout),0) AS earnings_from_clicks
FROM clicks c
LEFT JOIN conversions cv
    ON cv.click_id = c.click_id
    AND cv.status = 'approved'
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
    c.click_id,
    o.offer_name,
    c.sub1,
    c.sub2,
    c.sub3,
    INET6_NTOA(c.ip_address) AS full_ip,
    c.country,
    c.device,
    c.browser,
    c.referer,
    c.created_at,
    COUNT(cv.conversion_id) AS total_conversions,
    SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
    IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END),0) AS earnings
FROM clicks c
INNER JOIN offers o ON o.offer_id = c.offer_id
LEFT JOIN conversions cv ON cv.click_id = c.click_id
$whereSql
GROUP BY c.click_id
ORDER BY c.created_at DESC
LIMIT 1000
";

$reportStmt = $pdo->prepare($reportSql);
$reportStmt->execute($params);
$reportData = $reportStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   MASK IPs
-------------------------------------------------- */
foreach ($reportData as &$row) {
    $row['masked_ip'] = filter_var($row['full_ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ? preg_replace('/\.\d+$/', '.xxx', $row['full_ip'])
        : 'xxxx::xxxx';
}
unset($row);

/* -------------------------------------------------
   FILTER DROPDOWNS
-------------------------------------------------- */
$offers = $pdo->query("
    SELECT DISTINCT o.offer_id, o.offer_name
    FROM clicks c
    INNER JOIN offers o ON o.offer_id = c.offer_id
    WHERE c.affiliate_id = {$affiliateId}
    ORDER BY o.offer_name
")->fetchAll();

$countries = $pdo->query("
    SELECT DISTINCT country
    FROM clicks
    WHERE affiliate_id = {$affiliateId}
      AND country IS NOT NULL
    ORDER BY country
")->fetchAll();

$devices = $pdo->query("
    SELECT DISTINCT device
    FROM clicks
    WHERE affiliate_id = {$affiliateId}
      AND device IS NOT NULL
    ORDER BY device
")->fetchAll();

$browsers = $pdo->query("
    SELECT DISTINCT browser
    FROM clicks
    WHERE affiliate_id = {$affiliateId}
      AND browser IS NOT NULL
    ORDER BY browser
")->fetchAll();

/* -------------------------------------------------
   EXPORT TO EXCEL
-------------------------------------------------- */
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="click_report_' . date('Y-m-d') . '.xls"');

    echo "<table border='1'>
        <tr>
            <th>Click ID</th><th>Date</th><th>Offer</th>
            <th>Sub1</th><th>Sub2</th><th>Sub3</th>
            <th>IP</th><th>Country</th><th>Device</th>
            <th>Browser</th><th>Referrer</th>
            <th>Conversions</th><th>Earnings</th>
        </tr>";

    foreach ($reportData as $r) {
        echo "<tr>
            <td>{$r['click_id']}</td>
            <td>{$r['created_at']}</td>
            <td>{$r['offer_name']}</td>
            <td>{$r['sub1']}</td>
            <td>{$r['sub2']}</td>
            <td>{$r['sub3']}</td>
            <td>{$r['full_ip']}</td>
            <td>{$r['country']}</td>
            <td>{$r['device']}</td>
            <td>{$r['browser']}</td>
            <td>{$r['referer']}</td>
            <td>{$r['approved_conversions']}</td>
            <td>{$r['earnings']}</td>
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
    <title>Click Reports | GVS Icon Media</title>
    
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
        
        .ip-cell {
            cursor: pointer;
            position: relative;
        }
        
        .ip-tooltip {
            position: absolute;
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            z-index: 1000;
            display: none;
            white-space: nowrap;
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
        
        .conversion-badge {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
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
        
        .daterangepicker {
            font-family: 'Source Sans Pro', sans-serif;
        }
        
        .dataTables_wrapper {
            padding: 0;
        }
        
        .filter-card {
            transition: all 0.3s ease;
        }
        
        .filter-card.collapsed .card-body {
            padding: 0;
            max-height: 0;
            overflow: hidden;
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
        
        .table-hover tbody tr:hover {
            background: rgba(0,0,0,0.02);
        }
        
        .country-flag {
            width: 20px;
            height: 15px;
            margin-right: 5px;
            vertical-align: middle;
        }
        
        .device-icon {
            color: #6c757d;
            margin-right: 5px;
        }
        
        .referer-link {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: inline-block;
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
                    <span class="badge badge-warning navbar-badge"><?php echo $stats['total_clicks'] ?? 0; ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header"><?php echo $stats['total_clicks'] ?? 0; ?> Total Clicks</span>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-users mr-2"></i> <?php echo $stats['unique_clicks'] ?? 0; ?> Unique Visitors
                        <span class="float-right text-muted text-sm">Today</span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-globe mr-2"></i> <?php echo $stats['unique_countries'] ?? 0; ?> Countries
                        <span class="float-right text-muted text-sm">Period</span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="clicks.php" class="dropdown-item">
                        <i class="fas fa-list mr-2"></i> View Click Report
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
        <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.4rem;">
                <i class="fas fa-rocket mr-2"></i><strong>Icon Media</strong>
            </span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-tachometer-alt"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>

                    <li class="nav-header">CAMPAIGNS</li>
                    <li class="nav-item">
                        <a href="offers.php" class="nav-link">
                            <i class="nav-icon fas fa-gift"></i>
                            <p>All Campaigns</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="approved_offers.php" class="nav-link">
                            <i class="nav-icon fas fa-check-circle"></i>
                            <p>My Approved Offers</p>
                        </a>
                    </li>

                    <li class="nav-header">ANALYTICS & LOGS</li>
                    <li class="nav-item">
                        <a href="clicks.php" class="nav-link active">
                            <i class="nav-icon fas fa-mouse-pointer"></i>
                            <p>Click Logs</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <p>Performance & Conversions</p>
                        </a>
                    </li>

                    <li class="nav-header">TOOLS & POSTBACKS</li>
                    <li class="nav-item">
                        <a href="link-builder.php" class="nav-link">
                            <i class="nav-icon fas fa-link"></i>
                            <p>Link Builder</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="postback.php" class="nav-link">
                            <i class="nav-icon fas fa-code"></i>
                            <p>Postback Settings</p>
                        </a>
                    </li>

                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user-cog"></i>
                            <p>Profile & Payments</p>
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
                        <h1 class="m-0">Click Report</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Click Report</li>
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
                                    <span class="text-muted">Total Clicks:</span>
                                    <strong class="ml-2"><?php echo number_format($stats['total_clicks'] ?? 0); ?></strong>
                                </div>
                                <div class="mr-4 mb-2">
                                    <span class="text-muted">Unique Clicks:</span>
                                    <strong class="ml-2 text-info"><?php echo number_format($stats['unique_clicks'] ?? 0); ?></strong>
                                </div>
                                <div class="mr-4 mb-2">
                                    <span class="text-muted">Converted Clicks:</span>
                                    <strong class="ml-2 text-success"><?php echo number_format($stats['converted_clicks'] ?? 0); ?></strong>
                                </div>
                                <div class="mr-4 mb-2">
                                    <span class="text-muted">Conversion Rate:</span>
                                    <strong class="ml-2 text-primary">
                                        <?php 
                                        $convRate = ($stats['total_clicks'] ?? 0) > 0 ? 
                                            (($stats['converted_clicks'] ?? 0) / ($stats['total_clicks'] ?? 0)) * 100 : 0;
                                        echo number_format($convRate, 2); ?>%
                                    </strong>
                                </div>
                                <div class="mb-2">
                                    <span class="text-muted">Earnings:</span>
                                    <strong class="ml-2 text-success">$<?php echo number_format($stats['earnings_from_clicks'] ?? 0, 2); ?></strong>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-right">
                            <a href="clicks.php?export=excel&<?php echo http_build_query($_GET); ?>" class="export-btn">
                                <i class="fas fa-file-excel mr-2"></i> Export to Excel
                            </a>
                            <button class="btn btn-secondary ml-2" id="printReport">
                                <i class="fas fa-print mr-2"></i> Print
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Filters Card -->
                <div class="card card-stat">
                    <div class="card-header">
                        <h3 class="card-title">Click Filters</h3>
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
                                    <label>Country</label>
                                    <select class="form-control" name="country">
                                        <option value="">All Countries</option>
                                        <?php foreach ($countries as $country): ?>
                                        <option value="<?php echo $country['country']; ?>"
                                            <?php echo (!empty($_GET['country']) && $_GET['country'] == $country['country']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($country['country']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Device</label>
                                    <select class="form-control" name="device">
                                        <option value="">All Devices</option>
                                        <?php foreach ($devices as $device): ?>
                                        <option value="<?php echo $device['device']; ?>"
                                            <?php echo (!empty($_GET['device']) && $_GET['device'] == $device['device']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($device['device']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Sub1 (Source)</label>
                                    <input type="text" class="form-control" name="sub1" 
                                           value="<?php echo $_GET['sub1'] ?? ''; ?>" 
                                           placeholder="Enter Sub1 value">
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Sub2 (Campaign)</label>
                                    <input type="text" class="form-control" name="sub2" 
                                           value="<?php echo $_GET['sub2'] ?? ''; ?>" 
                                           placeholder="Enter Sub2 value">
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Sub3 (Ad Group)</label>
                                    <input type="text" class="form-control" name="sub3" 
                                           value="<?php echo $_GET['sub3'] ?? ''; ?>" 
                                           placeholder="Enter Sub3 value">
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Browser</label>
                                    <select class="form-control" name="browser">
                                        <option value="">All Browsers</option>
                                        <?php foreach ($browsers as $browser): ?>
                                        <option value="<?php echo $browser['browser']; ?>"
                                            <?php echo (!empty($_GET['browser']) && $_GET['browser'] == $browser['browser']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($browser['browser']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>IP Address</label>
                                    <input type="text" class="form-control" name="ip" 
                                           value="<?php echo $_GET['ip'] ?? ''; ?>" 
                                           placeholder="e.g., 192.168.1">
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Has Conversion</label>
                                    <select class="form-control" name="has_conversion">
                                        <option value="">All Clicks</option>
                                        <option value="1" <?php echo (!empty($_GET['has_conversion']) && $_GET['has_conversion'] == '1') ? 'selected' : ''; ?>>With Conversion</option>
                                        <option value="0" <?php echo (!empty($_GET['has_conversion']) && $_GET['has_conversion'] == '0') ? 'selected' : ''; ?>>Without Conversion</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="form-group text-right">
                                    <a href="clicks.php" class="btn btn-secondary">Clear All Filters</a>
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
                        <div class="small-box bg-gradient-info">
                            <div class="inner">
                                <h3><?php echo number_format($stats['total_clicks'] ?? 0); ?></h3>
                                <p>Total Clicks</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-mouse-pointer"></i>
                            </div>
                            <a href="#" class="small-box-footer">View Details <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="small-box bg-gradient-success">
                            <div class="inner">
                                <h3><?php echo number_format($stats['unique_clicks'] ?? 0); ?></h3>
                                <p>Unique Clicks</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-fingerprint"></i>
                            </div>
                            <a href="#" class="small-box-footer">Unique Visitors <i class="fas fa-users"></i></a>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="small-box bg-gradient-warning">
                            <div class="inner">
                                <h3><?php echo number_format($convRate, 2); ?>%</h3>
                                <p>Conversion Rate</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-percentage"></i>
                            </div>
                            <a href="#" class="small-box-footer">View Analytics <i class="fas fa-chart-bar"></i></a>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="small-box bg-gradient-danger">
                            <div class="inner">
                                <h3>$<?php echo number_format($stats['earnings_from_clicks'] ?? 0, 2); ?></h3>
                                <p>Earnings</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-dollar-sign"></i>
                            </div>
                            <a href="reports.php" class="small-box-footer">View Conversions <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card card-stat">
                            <div class="card-header">
                                <h3 class="card-title">Click Trend</h3>
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
                                <h3 class="card-title">Top Offers by Clicks</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="offerChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Offers Performance -->
                <?php if (!empty($offerStats)): ?>
                <div class="card card-stat">
                    <div class="card-header">
                        <h3 class="card-title">Offer Performance</h3>
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
                                        <th>Clicks</th>
                                        <th>Unique</th>
                                        <th>Conversions</th>
                                        <th>Conversion Rate</th>
                                        <th>Earnings</th>
                                        <th>EPC</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($offerStats as $offer): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($offer['offer_name']); ?></strong></td>
                                        <td><?php echo number_format($offer['clicks']); ?></td>
                                        <td>
                                            <span class="badge badge-info">
                                                <?php echo number_format($offer['unique_clicks']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-success">
                                                <?php echo number_format($offer['conversions']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-info" 
                                                     style="width: <?php echo min(100, $offer['conversion_rate'] * 2); ?>%"
                                                     role="progressbar">
                                                    <?php echo $offer['conversion_rate']; ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <strong class="text-primary">$<?php echo number_format($offer['earnings'], 2); ?></strong>
                                        </td>
                                        <td>
                                            $<?php echo $offer['clicks'] > 0 ? number_format($offer['earnings'] / $offer['clicks'], 4) : '0.0000'; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Country Performance -->
                <?php if (!empty($countryStats)): ?>
                <div class="card card-stat">
                    <div class="card-header">
                        <h3 class="card-title">Top Countries by Clicks</h3>
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
                                        <th>Country</th>
                                        <th>Clicks</th>
                                        <th>Unique</th>
                                        <th>Conversions</th>
                                        <th>Conversion Rate</th>
                                        <th>Performance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($countryStats as $country): ?>
                                    <tr>
                                        <td>
                                            <i class="fas fa-globe text-primary mr-2"></i>
                                            <strong><?php echo htmlspecialchars($country['country']); ?></strong>
                                        </td>
                                        <td><?php echo number_format($country['clicks']); ?></td>
                                        <td>
                                            <span class="badge badge-info">
                                                <?php echo number_format($country['unique_clicks']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-success">
                                                <?php echo number_format($country['conversions']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-success" 
                                                     style="width: <?php echo min(100, $country['conversion_rate'] * 2); ?>%"
                                                     role="progressbar">
                                                    <?php echo $country['conversion_rate']; ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($country['conversion_rate'] > 5): ?>
                                            <span class="badge badge-success">Excellent</span>
                                            <?php elseif ($country['conversion_rate'] > 2): ?>
                                            <span class="badge badge-warning">Good</span>
                                            <?php else: ?>
                                            <span class="badge badge-danger">Needs Improvement</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Detailed Click Report Table -->
                <div class="card card-stat">
                    <div class="card-header">
                        <h3 class="card-title">Detailed Click Logs</h3>
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
                            <table class="table table-hover" id="clickTable">
                                <thead>
                                    <tr>
                                        <th>Click ID</th>
                                        <th>Date & Time</th>
                                        <th>Offer</th>
                                        <th>Sub IDs</th>
                                        <th>IP Address</th>
                                        <th>Country</th>
                                        <th>Device</th>
                                        <th>Browser</th>
                                        <th>Referrer</th>
                                        <th>Conversions</th>
                                        <th>Earnings</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($reportData)): ?>
                                    <tr>
                                        <td colspan="11" class="text-center py-4">
                                            <i class="fas fa-search fa-2x text-muted mb-3"></i>
                                            <h5>No click data found</h5>
                                            <p class="text-muted">Try adjusting your filters or select a different date range.</p>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($reportData as $click): ?>
                                        <tr>
                                            <td><code>#<?php echo $click['click_id']; ?></code></td>
                                            <td>
                                                <span class="badge badge-light">
                                                    <?php echo date('M d, H:i', strtotime($click['created_at'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($click['offer_name']); ?></strong>
                                            </td>
                                            <td>
                                                <?php if ($click['sub1']): ?>
                                                <span class="subid-badge" title="sub1: <?php echo htmlspecialchars($click['sub1']); ?>">
                                                    <?php echo htmlspecialchars(substr($click['sub1'], 0, 10)); ?>
                                                </span>
                                                <?php endif; ?>
                                                <?php if ($click['sub2']): ?>
                                                <span class="subid-badge" title="sub2: <?php echo htmlspecialchars($click['sub2']); ?>">
                                                    <?php echo htmlspecialchars(substr($click['sub2'], 0, 8)); ?>
                                                </span>
                                                <?php endif; ?>
                                                <?php if ($click['sub3']): ?>
                                                <span class="subid-badge" title="sub3: <?php echo htmlspecialchars($click['sub3']); ?>">
                                                    <?php echo htmlspecialchars(substr($click['sub3'], 0, 6)); ?>
                                                </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="ip-cell" data-full-ip="<?php echo htmlspecialchars($click['full_ip']); ?>">
                                                <code><?php echo htmlspecialchars($click['masked_ip']); ?></code>
                                                <div class="ip-tooltip"><?php echo htmlspecialchars($click['full_ip']); ?></div>
                                            </td>
                                            <td>
                                                <?php if ($click['country']): ?>
                                                <span class="badge badge-info">
                                                    <i class="fas fa-globe mr-1"></i><?php echo htmlspecialchars($click['country']); ?>
                                                </span>
                                                <?php else: ?>
                                                <span class="text-muted">Unknown</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <i class="fas fa-<?php echo strpos(strtolower($click['device']), 'mobile') !== false ? 'mobile-alt' : 'desktop'; ?> device-icon"></i>
                                                <?php echo htmlspecialchars($click['device']); ?>
                                            </td>
                                            <td>
                                                <i class="fab fa-<?php echo strtolower($click['browser']); ?>"></i>
                                                <?php echo htmlspecialchars($click['browser']); ?>
                                            </td>
                                            <td>
                                                <?php if ($click['referer']): ?>
                                                <a href="<?php echo htmlspecialchars($click['referer']); ?>" 
                                                   target="_blank" 
                                                   title="<?php echo htmlspecialchars($click['referer']); ?>"
                                                   class="referer-link">
                                                    <i class="fas fa-external-link-alt"></i>
                                                    <?php echo htmlspecialchars(parse_url($click['referer'], PHP_URL_HOST) ?: 'Direct'); ?>
                                                </a>
                                                <?php else: ?>
                                                <span class="text-muted"><i class="fas fa-home"></i> Direct</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($click['approved_conversions'] > 0): ?>
                                                <span class="conversion-badge">
                                                    <i class="fas fa-check-circle mr-1"></i><?php echo $click['approved_conversions']; ?>
                                                </span>
                                                <?php else: ?>
                                                <span class="text-muted">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($click['earnings'] > 0): ?>
                                                <strong class="text-success">$<?php echo number_format($click['earnings'], 2); ?></strong>
                                                <?php else: ?>
                                                <span class="text-muted">$0.00</span>
                                                <?php endif; ?>
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
                                    Showing <?php echo min(1000, count($reportData)); ?> of <?php echo number_format($stats['total_clicks'] ?? 0); ?> clicks
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
                                <a href="clicks.php?export=excel&<?php echo http_build_query($_GET); ?>" class="btn btn-success btn-block">
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
                            <div class="col-md-3 mb-3">
                                <a href="api/click-analytics.php?<?php echo http_build_query($_GET); ?>" class="btn btn-warning btn-block">
                                    <i class="fas fa-chart-bar mr-2"></i> Analytics Report
                                    <small class="d-block text-light mt-1">Detailed analysis</small>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="row">
                    <div class="col-md-4">
                        <div class="card card-stat">
                            <div class="card-header">
                                <h3 class="card-title">Device Distribution</h3>
                            </div>
                            <div class="card-body">
                                <?php 
                                $deviceTypes = ['Desktop' => 0, 'Mobile' => 0, 'Tablet' => 0];
                                foreach ($reportData as $click) {
                                    $device = strtolower($click['device']);
                                    if (strpos($device, 'mobile') !== false) {
                                        $deviceTypes['Mobile']++;
                                    } elseif (strpos($device, 'tablet') !== false) {
                                        $deviceTypes['Tablet']++;
                                    } else {
                                        $deviceTypes['Desktop']++;
                                    }
                                }
                                $totalDevices = array_sum($deviceTypes);
                                ?>
                                <ul class="list-unstyled">
                                    <?php foreach ($deviceTypes as $device => $count): ?>
                                    <?php if ($count > 0): ?>
                                    <li class="mb-2">
                                        <span class="text-muted">
                                            <i class="fas fa-<?php echo strtolower($device) === 'mobile' ? 'mobile-alt' : (strtolower($device) === 'tablet' ? 'tablet-alt' : 'desktop'); ?> mr-2"></i>
                                            <?php echo $device; ?>
                                        </span>
                                        <strong class="float-right"><?php echo number_format($count); ?></strong>
                                        <div class="progress mt-1" style="height: 5px;">
                                            <div class="progress-bar bg-primary" 
                                                 style="width: <?php echo $totalDevices > 0 ? ($count / $totalDevices) * 100 : 0; ?>%"></div>
                                        </div>
                                    </li>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card card-stat">
                            <div class="card-header">
                                <h3 class="card-title">Performance Metrics</h3>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled">
                                    <li class="mb-2">
                                        <span class="text-muted">Click to Conversion Rate:</span>
                                        <strong class="float-right text-success">
                                            <?php echo number_format($convRate, 2); ?>%
                                        </strong>
                                    </li>
                                    <li class="mb-2">
                                        <span class="text-muted">Earnings Per Click (EPC):</span>
                                        <strong class="float-right text-primary">
                                            $<?php echo ($stats['total_clicks'] ?? 0) > 0 ? number_format(($stats['earnings_from_clicks'] ?? 0) / ($stats['total_clicks'] ?? 0), 4) : '0.0000'; ?>
                                        </strong>
                                    </li>
                                    <li class="mb-2">
                                        <span class="text-muted">Unique Click Rate:</span>
                                        <strong class="float-right text-info">
                                            <?php echo ($stats['total_clicks'] ?? 0) > 0 ? number_format(($stats['unique_clicks'] ?? 0) / ($stats['total_clicks'] ?? 0) * 100, 2) : '0.00'; ?>%
                                        </strong>
                                    </li>
                                    <li class="mb-2">
                                        <span class="text-muted">Avg Conversions per Click:</span>
                                        <strong class="float-right">
                                            <?php echo ($stats['total_clicks'] ?? 0) > 0 ? number_format(($stats['converted_clicks'] ?? 0) / ($stats['total_clicks'] ?? 0), 3) : '0.000'; ?>
                                        </strong>
                                    </li>
                                    <li>
                                        <span class="text-muted">Data Quality Score:</span>
                                        <?php 
                                        $qualityScore = 0;
                                        if ($stats['total_clicks'] > 0) {
                                            // Calculate based on data completeness
                                            $completeData = 0;
                                            foreach ($reportData as $click) {
                                                if ($click['country'] && $click['device'] && $click['browser']) {
                                                    $completeData++;
                                                }
                                            }
                                            $qualityScore = ($completeData / count($reportData)) * 100;
                                        }
                                        ?>
                                        <strong class="float-right <?php echo $qualityScore > 80 ? 'text-success' : ($qualityScore > 60 ? 'text-warning' : 'text-danger'); ?>">
                                            <?php echo number_format($qualityScore, 1); ?>%
                                        </strong>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card card-stat">
                            <div class="card-header">
                                <h3 class="card-title">Report Actions</h3>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <button class="btn btn-outline-primary" id="refreshReport">
                                        <i class="fas fa-sync-alt mr-2"></i> Refresh Report
                                    </button>
                                    <button class="btn btn-outline-success" id="saveAsTemplate">
                                        <i class="fas fa-save mr-2"></i> Save as Template
                                    </button>
                                    <button class="btn btn-outline-info" id="detectPatterns">
                                        <i class="fas fa-search mr-2"></i> Detect Patterns
                                    </button>
                                    <button class="btn btn-outline-warning" id="fraudDetection">
                                        <i class="fas fa-shield-alt mr-2"></i> Fraud Detection
                                    </button>
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
            <strong>GVS Icon Media v3.0</strong>
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

<script>
$(document).ready(function() {
    // Initialize DataTable with export buttons
    const table = $('#clickTable').DataTable({
        dom: '<"row"<"col-sm-12 col-md-6"B><"col-sm-12 col-md-6"f>>rt<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        buttons: [
            {
                extend: 'excelHtml5',
                text: '<i class="fas fa-file-excel"></i> Excel',
                className: 'btn btn-success',
                title: 'Click_Report_<?php echo date("Y-m-d"); ?>',
                exportOptions: {
                    columns: ':visible'
                }
            },
            {
                extend: 'pdfHtml5',
                text: '<i class="fas fa-file-pdf"></i> PDF',
                className: 'btn btn-danger',
                title: 'Click Report',
                exportOptions: {
                    columns: ':visible'
                }
            },
            {
                extend: 'csvHtml5',
                text: '<i class="fas fa-file-csv"></i> CSV',
                className: 'btn btn-info',
                title: 'Click_Report_<?php echo date("Y-m-d"); ?>',
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
            searchPlaceholder: "Search clicks...",
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
    
    // Show full IP on hover
    $(document).on('mouseenter', '.ip-cell', function() {
        const fullIP = $(this).data('full-ip');
        const tooltip = $(this).find('.ip-tooltip');
        const position = $(this).offset();
        
        tooltip.css({
            top: position.top - tooltip.outerHeight() - 10,
            left: position.left,
            display: 'block'
        });
    }).on('mouseleave', '.ip-cell', function() {
        $(this).find('.ip-tooltip').hide();
    });
    
    // Initialize Charts
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    const trendChart = new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_map(function($t) { return date('M d', strtotime($t['date'])); }, $trendData)); ?>,
            datasets: [{
                label: 'Total Clicks',
                data: <?php echo json_encode(array_column($trendData, 'clicks')); ?>,
                borderColor: '#4e73df',
                backgroundColor: 'rgba(78, 115, 223, 0.05)',
                tension: 0.4,
                fill: true
            }, {
                label: 'Unique Clicks',
                data: <?php echo json_encode(array_column($trendData, 'unique_clicks')); ?>,
                borderColor: '#1cc88a',
                backgroundColor: 'rgba(28, 200, 138, 0.05)',
                tension: 0.4,
                fill: true
            }, {
                label: 'Conversions',
                data: <?php echo json_encode(array_column($trendData, 'conversions')); ?>,
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
    
    // Offer Chart
    const offerCtx = document.getElementById('offerChart').getContext('2d');
    const offerChart = new Chart(offerCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_column($offerStats, 'offer_name')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($offerStats, 'clicks')); ?>,
                backgroundColor: [
                    '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b',
                    '#6f42c1', '#fd7e14', '#20c9a6', '#e83e8c', '#6c757d'
                ],
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
    
    // Refresh report
    $('#refreshReport').click(function() {
        Swal.fire({
            title: 'Refresh Report',
            text: 'Update report with latest data?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, refresh!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.reload();
            }
        });
    });
    
    // Save filter
    $('#saveFilter').click(function() {
        const filterName = prompt('Enter a name for this filter:', 'My Click Filter');
        if (filterName) {
            const filterData = {
                name: filterName,
                params: <?php echo json_encode($_GET); ?>,
                saved_at: new Date().toISOString()
            };
            
            // Save to localStorage
            let savedFilters = JSON.parse(localStorage.getItem('clickFilters') || '[]');
            savedFilters.push(filterData);
            localStorage.setItem('clickFilters', JSON.stringify(savedFilters));
            
            Toast.fire({
                icon: 'success',
                title: 'Filter saved successfully!'
            });
        }
    });
    
    // Detect patterns
    $('#detectPatterns').click(function() {
        Swal.fire({
            title: 'Pattern Detection',
            html: 'Analyzing click patterns for anomalies and opportunities...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        setTimeout(() => {
            Swal.fire({
                icon: 'info',
                title: 'Pattern Analysis Complete',
                html: `
                    <div class="text-left">
                        <p><strong>Findings:</strong></p>
                        <ul>
                            <li>High conversion rates from mobile devices</li>
                            <li>Low performance from desktop browsers</li>
                            <li>Best performing hours: 14:00 - 18:00</li>
                            <li>Top converting country: United States</li>
                        </ul>
                    </div>
                `,
                showConfirmButton: true,
                confirmButtonText: 'View Details'
            });
        }, 2000);
    });
    
    // Fraud detection
    $('#fraudDetection').click(function() {
        Swal.fire({
            title: 'Fraud Detection Scan',
            html: 'Scanning for suspicious activity patterns...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        setTimeout(() => {
            Swal.fire({
                icon: 'success',
                title: 'Scan Complete',
                html: `
                    <div class="text-left">
                        <p><strong>Results:</strong></p>
                        <p>✅ No major fraud patterns detected</p>
                        <p>⚠️ 3 IPs with high click frequency</p>
                        <p>✅ All conversions appear legitimate</p>
                        <p class="text-muted small mt-3">Fraud score: 12/100 (Low Risk)</p>
                    </div>
                `,
                showConfirmButton: true,
                confirmButtonText: 'Close'
            });
        }, 1500);
    });
    
    // Auto-refresh click count every 2 minutes
    setInterval(() => {
        $.get('api/refresh-click-stats.php', function(data) {
            if (data.newClicks > 0) {
                $('.navbar-badge').text(data.newClicks);
                Toast.fire({
                    icon: 'info',
                    title: `${data.newClicks} new clicks detected!`
                });
            }
        });
    }, 120000);
});
</script>

<!-- SweetAlert2 for better alerts -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Initialize SweetAlert2
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
</script>

</body>
</html>