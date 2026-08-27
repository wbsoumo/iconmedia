<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('advertiser');

$advertiserId   = auth_user_id();
$advertiserName = $_SESSION['user_name'] ?? 'Advertiser';

/* ===============================
   OVERALL STATS
================================ */
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT o.offer_id)                         AS total_offers,
        COUNT(DISTINCT c.click_id)                         AS total_clicks,
        COUNT(DISTINCT cv.conversion_id)                   AS total_conversions,
        IFNULL(SUM(cv.revenue), 0)                          AS total_revenue
    FROM offers o
    LEFT JOIN clicks c 
        ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv 
        ON cv.offer_id = o.offer_id
    WHERE o.advertiser_id = :aid
");
$statsStmt->execute(['aid' => $advertiserId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_offers' => 0,
    'total_clicks' => 0,
    'total_conversions' => 0,
    'total_revenue' => 0
];

/* ===============================
   TODAY PERFORMANCE (SAFE)
================================ */
$todayStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT c.click_id)       AS today_clicks,
        COUNT(DISTINCT cv.conversion_id) AS today_conversions,
        IFNULL(SUM(cv.revenue),0)        AS today_revenue
    FROM offers o
    LEFT JOIN clicks c 
        ON c.offer_id = o.offer_id
       AND DATE(c.created_at) = CURDATE()
    LEFT JOIN conversions cv 
        ON cv.click_id = c.click_id
       AND DATE(cv.created_at) = CURDATE()
    WHERE o.advertiser_id = :aid
");
$todayStmt->execute(['aid' => $advertiserId]);
$today = $todayStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'today_clicks' => 0,
    'today_conversions' => 0,
    'today_revenue' => 0
];

/* ===============================
   OFFER PERFORMANCE (TOP 10)
================================ */
$offersStmt = $pdo->prepare("
    SELECT
        o.offer_id,
        o.offer_name,
        o.status,
        o.payout,
        COUNT(DISTINCT c.click_id)       AS clicks,
        COUNT(DISTINCT cv.conversion_id) AS conversions,
        IFNULL(SUM(cv.revenue),0)        AS revenue
    FROM offers o
    LEFT JOIN clicks c 
        ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv 
        ON cv.offer_id = o.offer_id
    WHERE o.advertiser_id = :aid
    GROUP BY o.offer_id
    ORDER BY revenue DESC
    LIMIT 10
");
$offersStmt->execute(['aid' => $advertiserId]);
$offers = $offersStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   RECENT CONVERSIONS
================================ */
$conversionsStmt = $pdo->prepare("
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
    INNER JOIN offers o 
        ON o.offer_id = cv.offer_id
    LEFT JOIN clicks c 
        ON c.click_id = cv.click_id
    WHERE o.advertiser_id = :aid
    ORDER BY cv.created_at DESC
    LIMIT 5
");
$conversionsStmt->execute(['aid' => $advertiserId]);
$recentConversions = $conversionsStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   7-DAY TREND
================================ */
$trendStmt = $pdo->prepare("
    SELECT
        DATE(cv.created_at) AS date,
        COUNT(*)            AS conversions,
        IFNULL(SUM(cv.revenue),0) AS revenue
    FROM conversions cv
    INNER JOIN offers o 
        ON o.offer_id = cv.offer_id
    WHERE o.advertiser_id = :aid
      AND cv.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(cv.created_at)
    ORDER BY date ASC
");
$trendStmt->execute(['aid' => $advertiserId]);
$trendData = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   TOP AFFILIATES (NO ROLE COLUMN)
================================ */
$affiliatesStmt = $pdo->prepare("
    SELECT
        u.user_id,
        u.name AS affiliate_name,
        COUNT(DISTINCT c.click_id)       AS clicks,
        COUNT(DISTINCT cv.conversion_id) AS conversions,
        IFNULL(SUM(cv.revenue),0)        AS revenue
    FROM clicks c
    INNER JOIN users u 
        ON u.user_id = c.affiliate_id
    LEFT JOIN conversions cv 
        ON cv.click_id = c.click_id
    INNER JOIN offers o 
        ON o.offer_id = c.offer_id
    WHERE o.advertiser_id = :aid
    GROUP BY u.user_id
    ORDER BY revenue DESC
    LIMIT 5
");
$affiliatesStmt->execute(['aid' => $advertiserId]);
$topAffiliates = $affiliatesStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Advertiser Dashboard | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* 2x2 Grid strictly for the 4 main stat boxes on Mobile */
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
        
        .metric-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #e3e6f0;
            text-align: center;
            transition: all 0.3s ease;
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
        
        .btn-gradient {
            background: var(--primary-gradient);
            border: none;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
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
        
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 500;
            padding: 12px 25px;
            border-radius: 10px 10px 0 0;
        }
        
        .nav-tabs .nav-link.active {
            color: #667eea;
            background: white;
            border-bottom: 3px solid #667eea;
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
            <li class="nav-item d-none d-sm-inline-block">
                <a href="campaigns.php" class="nav-link">Campaigns</a>
            </li>
        </ul>

        <!-- Right navbar links -->
        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <span class="badge badge-warning navbar-badge"><?php echo $today['today_conversions'] ?? 0; ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header"><?php echo $today['today_conversions'] ?? 0; ?> New Conversions Today</span>
                    <div class="dropdown-divider"></div>
                    <a href="reports_conversions.php" class="dropdown-item">
                        <i class="fas fa-exchange-alt mr-2 text-primary"></i> Today's Revenue: $<?php echo number_format($today['today_revenue'] ?? 0, 2); ?>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-chart-line mr-2 text-success"></i> Total Revenue: $<?php echo number_format($stats['total_revenue'] ?? 0, 2); ?>
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
                    <i class="fas fa-user-circle mr-1"></i> <?php echo htmlspecialchars($advertiserName); ?>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user mr-2"></i> Profile
                    </a>
                    <a href="account.php" class="dropdown-item">
                        <i class="fas fa-cog mr-2"></i> Account Settings
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="billing.php" class="dropdown-item">
                        <i class="fas fa-wallet mr-2"></i> Billing
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
                        <a href="dashboard.php" class="nav-link active">
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
                        <h1 class="m-0">Dashboard</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Dashboard</li>
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
                            <h2>Welcome back, <?php echo htmlspecialchars($advertiserName); ?>!</h2>
                            <p class="mb-0">Track your campaign performance and optimize for maximum ROI.</p>
                        </div>
                        <div class="col-md-4 text-right">
                            <button class="refresh-btn" id="refreshDashboard">
                                <i class="fas fa-sync-alt mr-1"></i> Refresh Data
                            </button>
                        </div>
                    </div>
                    
                    <!-- Today's Quick Stats -->
                    <div class="quick-stats mt-4">
                        <div class="quick-stat-item">
                            <div class="quick-stat-value"><?php echo $today['today_clicks'] ?? 0; ?></div>
                            <div class="quick-stat-label">Today's Clicks</div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="quick-stat-value"><?php echo $today['today_conversions'] ?? 0; ?></div>
                            <div class="quick-stat-label">Today's Conversions</div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="quick-stat-value">$<?php echo number_format($today['today_revenue'] ?? 0, 2); ?></div>
                            <div class="quick-stat-label">Today's Revenue</div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="quick-stat-value">
                                <?php 
                                $todayCR = ($today['today_clicks'] > 0) ? ($today['today_conversions'] / $today['today_clicks']) * 100 : 0;
                                echo number_format($todayCR, 2) . '%';
                                ?>
                            </div>
                            <div class="quick-stat-label">Today's CR%</div>
                        </div>
                    </div>
                </div>

                <!-- Main Stats Cards -->
                <div class="row stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="small-box bg-gradient-primary">
                            <div class="inner">
                                <h3><?php echo $stats['total_offers']; ?></h3>
                                <p>Total Offers</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-gift"></i>
                            </div>
                            <a href="offers.php" class="small-box-footer">
                                View All Offers <i class="fas fa-arrow-circle-right"></i>
                            </a>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-3">
                        <div class="small-box bg-gradient-info">
                            <div class="inner">
                                <h3><?php echo $stats['total_clicks']; ?></h3>
                                <p>Total Clicks</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-mouse-pointer"></i>
                            </div>
                            <a href="reports_campaigns.php" class="small-box-footer">
                                View Click Report <i class="fas fa-arrow-circle-right"></i>
                            </a>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-3">
                        <div class="small-box bg-gradient-success">
                            <div class="inner">
                                <h3><?php echo $stats['total_conversions']; ?></h3>
                                <p>Total Conversions</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                            <a href="reports_conversions.php" class="small-box-footer">
                                View Conversions <i class="fas fa-arrow-circle-right"></i>
                            </a>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-3">
                        <div class="small-box bg-gradient-warning">
                            <div class="inner">
                                <h3>$<?php echo number_format($stats['total_revenue'], 2); ?></h3>
                                <p>Total Revenue</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-dollar-sign"></i>
                            </div>
                            <a href="billing.php" class="small-box-footer">
                                View Billing <i class="fas fa-arrow-circle-right"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Performance Chart -->
                    <div class="col-lg-8">
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
                    
                    <!-- Quick Actions -->
                    <div class="col-lg-4">
                        <div class="card card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Quick Actions</h3>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-3">
                                    <button class="btn btn-gradient" onclick="window.location.href='create_offer.php'">
                                        <i class="fas fa-plus-circle mr-2"></i> Create New Offer
                                    </button>
                                    <button class="btn btn-outline-primary" onclick="window.location.href='reports_campaigns.php'">
                                        <i class="fas fa-chart-bar mr-2"></i> View Reports
                                    </button>
                                    <button class="btn btn-outline-success" onclick="window.location.href='optimization.php'">
                                        <i class="fas fa-rocket mr-2"></i> Optimize Campaigns
                                    </button>
                                    <button class="btn btn-outline-info" onclick="window.location.href='support.php'">
                                        <i class="fas fa-headset mr-2"></i> Contact Support
                                    </button>
                                </div>
                            </div>
                        </div>

                        
                    </div>
                </div>

                <div class="row">
                    <!-- Top Offers -->
                    <div class="col-lg-8">
                        <div class="card card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Top Performing Offers</h3>
                                <div class="card-tools">
                                    <button class="btn btn-tool" onclick="window.location.href='offers.php'">
                                        <i class="fas fa-external-link-alt"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-dashboard">
                                        <thead>
                                            <tr>
                                                <th>Offer Name</th>
                                                <th>Status</th>
                                                <th>Clicks</th>
                                                <th>Conversions</th>
                                                <th>Revenue</th>
                                                <th>CR%</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($offers)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center py-4">
                                                    <div class="empty-state">
                                                        <div class="empty-state-icon">
                                                            <i class="fas fa-gift"></i>
                                                        </div>
                                                        <p class="text-muted">No offers created yet.</p>
                                                        <a href="create_offer.php" class="btn btn-gradient btn-sm">
                                                            <i class="fas fa-plus mr-2"></i> Create First Offer
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($offers as $o): 
                                                    $cr = $o['clicks'] ? ($o['conversions'] / $o['clicks']) * 100 : 0;
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($o['offer_name']); ?></strong>
                                                        <br>
                                                        <small class="text-muted">Payout: $<?php echo number_format($o['payout'], 2); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $o['status']; ?>">
                                                            <?php echo ucfirst($o['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo $o['clicks']; ?></td>
                                                    <td><?php echo $o['conversions']; ?></td>
                                                    <td>
                                                        <strong class="text-primary">$<?php echo number_format($o['revenue'], 2); ?></strong>
                                                    </td>
                                                    <td>
                                                        <span class="cr-badge"><?php echo number_format($cr, 2); ?>%</span>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-primary" onclick="window.location.href='edit_offer.php?id=<?php echo $o['offer_id']; ?>'">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="col-lg-4">
                        <div class="card card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Recent Conversions</h3>
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
                                                    <i class="fas fa-<?php echo strpos(strtolower($conv['device']), 'mobile') !== false ? 'mobile-alt' : 'desktop'; ?>"></i>
                                                </span>
                                            </div>
                                            <div>
                                                <strong class="text-success">$<?php echo number_format($conv['revenue'], 2); ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    <div class="text-center mt-3">
                                        <a href="reports_conversions.php" class="btn btn-outline-primary btn-sm">
                                            View All Conversions <i class="fas fa-arrow-right ml-1"></i>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Top Affiliates -->
                        <div class="card card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Top Affiliates</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($topAffiliates)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <p class="text-muted">No affiliate data yet.</p>
                                </div>
                                <?php else: ?>
                                    <?php foreach($topAffiliates as $aff): ?>
                                    <div class="affiliate-card">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <strong><?php echo htmlspecialchars($aff['affiliate_name']); ?></strong>
                                            <span class="cr-badge">
                                                <?php 
                                                $affCR = $aff['clicks'] ? ($aff['conversions'] / $aff['clicks']) * 100 : 0;
                                                echo number_format($affCR, 2) . '%';
                                                ?>
                                            </span>
                                        </div>
                                        <div class="d-flex justify-content-between small">
                                            <span><?php echo $aff['clicks']; ?> clicks</span>
                                            <span><?php echo $aff['conversions']; ?> conversions</span>
                                            <span class="text-success">$<?php echo number_format($aff['revenue'], 2); ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    <div class="text-center mt-3">
                                        <a href="reports_affiliates.php" class="btn btn-outline-primary btn-sm">
                                            View All Affiliates <i class="fas fa-arrow-right ml-1"></i>
                                        </a>
                                    </div>
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
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
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
    
    // Refresh dashboard
    $('#refreshDashboard').click(function() {
        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Refreshing...');
        
        setTimeout(() => {
            window.location.reload();
        }, 1000);
    });
    
    // Initialize Performance Chart
    const ctx = document.getElementById('performanceChart').getContext('2d');
    const performanceChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_map(function($t) { 
                return date('D', strtotime($t['date'])); 
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
    
    // Auto-refresh every 5 minutes
    setInterval(() => {
        $.get('api/refresh-stats.php', function(data) {
            if (data.newConversions > 0) {
                $('.navbar-badge').text(data.newConversions);
                Toast.fire({
                    icon: 'info',
                    title: `${data.newConversions} new conversions!`
                });
            }
        });
    }, 300000);
    
    // Quick stat hover effects
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