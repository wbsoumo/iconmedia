<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminId   = auth_user_id();
$adminName = $_SESSION['user_name'] ?? 'Admin';

/* ===============================
   TODAY'S PERFORMANCE (FIXED)
================================ */
$todayDate = date('Y-m-d');

$todayStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT c.click_id) AS today_clicks,
        COUNT(DISTINCT cv.conversion_id) AS today_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue END), 0) AS today_revenue,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout END), 0) AS today_payout
    FROM clicks c
    LEFT JOIN conversions cv 
        ON cv.click_id = c.click_id 
       AND DATE(cv.created_at) = :today_conv
    WHERE DATE(c.created_at) = :today_click
");

$todayStmt->execute([
    'today_click' => $todayDate,
    'today_conv'  => $todayDate
]);

$today = $todayStmt->fetch(PDO::FETCH_ASSOC) ?: [];

/* ===============================
   YESTERDAY'S PERFORMANCE (FIXED)
================================ */
$yesterdayDate = date('Y-m-d', strtotime('-1 day'));

$yesterdayStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT c.click_id) AS yesterday_clicks,
        COUNT(DISTINCT cv.conversion_id) AS yesterday_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue END), 0) AS yesterday_revenue,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout END), 0) AS yesterday_payout
    FROM clicks c
    LEFT JOIN conversions cv 
        ON cv.click_id = c.click_id 
       AND DATE(cv.created_at) = :yesterday_conv
    WHERE DATE(c.created_at) = :yesterday_click
");

$yesterdayStmt->execute([
    'yesterday_click' => $yesterdayDate,
    'yesterday_conv'  => $yesterdayDate
]);

$yesterday = $yesterdayStmt->fetch(PDO::FETCH_ASSOC) ?: [];

/* ===============================
   TOTAL USERS
================================ */
$userCounts = $pdo->query("
    SELECT role_id, COUNT(*) AS total
    FROM users
    WHERE role_id IN (3,4)
    GROUP BY role_id
")->fetchAll(PDO::FETCH_KEY_PAIR);

$totalAffiliates  = $userCounts[3] ?? 0;
$totalAdvertisers = $userCounts[4] ?? 0;

/* ===============================
   OFFERS
================================ */
$totalOffers  = (int)$pdo->query("SELECT COUNT(*) FROM offers")->fetchColumn();
$activeOffers = (int)$pdo->query("SELECT COUNT(*) FROM offers WHERE status = 'active'")->fetchColumn();

/* ===============================
   CLICKS & CONVERSIONS
================================ */
$totalClicks = (int)$pdo->query("SELECT COUNT(*) FROM clicks")->fetchColumn();

$convStats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'approved') AS approved,
        SUM(status = 'pending')  AS pending,
        SUM(status = 'rejected') AS rejected
    FROM conversions
")->fetch(PDO::FETCH_ASSOC) ?: [];

$totalConversions    = (int)($convStats['total'] ?? 0);
$approvedConversions = (int)($convStats['approved'] ?? 0);
$pendingConversions  = (int)($convStats['pending'] ?? 0);
$rejectedConversions = (int)($convStats['rejected'] ?? 0);

/* ===============================
   REVENUE / PAYOUT / PROFIT
================================ */
$money = $pdo->query("
    SELECT
        IFNULL(SUM(revenue),0) AS total_revenue,
        IFNULL(SUM(payout),0)  AS total_payout,
        IFNULL(SUM(CASE WHEN status='approved' THEN revenue END),0) AS approved_revenue,
        IFNULL(SUM(CASE WHEN status='approved' THEN payout END),0)  AS approved_payout
    FROM conversions
")->fetch(PDO::FETCH_ASSOC) ?: [];

$totalRevenue    = (float)$money['total_revenue'];
$totalPayout     = (float)$money['total_payout'];
$approvedRevenue = (float)$money['approved_revenue'];
$approvedPayout  = (float)$money['approved_payout'];
$netProfit       = $approvedRevenue - $approvedPayout;

/* ===============================
   7-DAY TREND (NO PARAM ISSUES)
================================ */
$trendStmt = $pdo->query("
    SELECT 
        DATE(created_at) AS date,
        COUNT(*) AS conversions,
        SUM(CASE WHEN status='approved' THEN revenue ELSE 0 END) AS revenue,
        SUM(CASE WHEN status='approved' THEN payout  ELSE 0 END) AS payout
    FROM conversions
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");

$trendData = $trendStmt->fetchAll(PDO::FETCH_ASSOC);


/* ===============================
   TOP PERFORMERS
================================ */
// Top Affiliates
$topAffiliates = $pdo->query("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        COUNT(DISTINCT cv.conversion_id) AS conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout END), 0) AS earnings,
        COUNT(DISTINCT c.click_id) AS clicks,
        ROUND(
            COUNT(DISTINCT cv.conversion_id) * 100.0 / 
            GREATEST(COUNT(DISTINCT c.click_id), 1), 2
        ) AS conversion_rate
    FROM users u
    LEFT JOIN clicks c ON c.affiliate_id = u.user_id
    LEFT JOIN conversions cv ON cv.affiliate_id = u.user_id
    WHERE u.role_id = 3
    GROUP BY u.user_id
    ORDER BY earnings DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Top Advertisers
$topAdvertisers = $pdo->query("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.company,
        COUNT(DISTINCT o.offer_id) AS offers,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue END), 0) AS spent,
        COUNT(DISTINCT cv.conversion_id) AS conversions
    FROM users u
    LEFT JOIN offers o ON o.advertiser_id = u.user_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE u.role_id = 4
    GROUP BY u.user_id
    ORDER BY spent DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Top Campaigns
$topCampaigns = $pdo->query("
    SELECT 
        o.offer_id,
        o.offer_name,
        o.status,
        u.name AS advertiser_name,
        COUNT(DISTINCT cv.conversion_id) AS conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue END), 0) AS revenue,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout END), 0) AS payout,
        ROUND(
            IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue END), 0) - 
            IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout END), 0), 2
        ) AS profit
    FROM offers o
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    LEFT JOIN users u ON u.user_id = o.advertiser_id
    GROUP BY o.offer_id
    ORDER BY revenue DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   RECENT ACTIVITY
================================ */
$recentActivity = $pdo->query("
    SELECT 
        'conversion' as type,
        cv.conversion_id as id,
        cv.transaction_id,
        cv.revenue,
        cv.status,
        cv.created_at,
        o.offer_name,
        aff.name as affiliate_name,
        adv.name as advertiser_name
    FROM conversions cv
    LEFT JOIN offers o ON o.offer_id = cv.offer_id
    LEFT JOIN users aff ON aff.user_id = cv.affiliate_id
    LEFT JOIN users adv ON adv.user_id = o.advertiser_id
    UNION ALL
    SELECT 
        'click' as type,
        c.click_id as id,
        NULL as transaction_id,
        NULL as revenue,
        NULL as status,
        c.created_at,
        o.offer_name,
        aff.name as affiliate_name,
        adv.name as advertiser_name
    FROM clicks c
    LEFT JOIN offers o ON o.offer_id = c.offer_id
    LEFT JOIN users aff ON aff.user_id = c.affiliate_id
    LEFT JOIN users adv ON adv.user_id = o.advertiser_id
    ORDER BY created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   PENDING APPROVALS
================================ */
$pendingApprovals = $pdo->query("
    SELECT 
        'conversion' as type,
        cv.conversion_id as id,
        cv.transaction_id,
        cv.revenue,
        cv.payout,
        cv.created_at,
        o.offer_name,
        aff.name as affiliate_name,
        adv.name as advertiser_name
    FROM conversions cv
    LEFT JOIN offers o ON o.offer_id = cv.offer_id
    LEFT JOIN users aff ON aff.user_id = cv.affiliate_id
    LEFT JOIN users adv ON adv.user_id = o.advertiser_id
    WHERE cv.status = 'pending'
    ORDER BY cv.created_at DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   SYSTEM STATS
================================ */
$systemStats = $pdo->query("
    SELECT 
        'pending_kyc' as stat_type,
        COUNT(*) as count
    FROM users 
    WHERE kyc_status = 'pending' AND role_id IN (3, 4)
    UNION ALL
    SELECT 
        'pending_payouts' as stat_type,
        COUNT(*) as count
    FROM affiliate_payout_requests 
    WHERE status = 'pending'
    UNION ALL
    SELECT 
        'pending_deposits' as stat_type,
        COUNT(*) as count
    FROM advertiser_transactions 
    WHERE type = 'deposit' AND status = 'pending'
")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard | GVS Icon Media</title>
    
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
            --purple-gradient: linear-gradient(135deg, #9f7aea 0%, #667eea 100%);
            --teal-gradient: linear-gradient(135deg, #00b5b8 0%, #38ef7d 100%);
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
        
        .bg-gradient-purple {
            background: var(--purple-gradient) !important;
        }
        
        .bg-gradient-teal {
            background: var(--teal-gradient) !important;
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
        
        .performance-card {
            background: white;
            border: 1px solid #e3e6f0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .performance-card:hover {
            border-color: #667eea;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.1);
        }
        
        .cr-badge {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .activity-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f8f9fa;
            transition: background 0.3s ease;
        }
        
        .activity-item:hover {
            background: #f8f9fc;
        }
        
        .type-badge {
            background: #e3e6f0;
            color: #4e73df;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .type-badge.click {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
        }
        
        .type-badge.conversion {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
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
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .today-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .today-indicator.up {
            background: #28a745;
        }
        
        .today-indicator.down {
            background: #dc3545;
        }
        
        .today-indicator.equal {
            background: #6c757d;
        }
        
        .comparison-text {
            font-size: 12px;
            margin-left: 5px;
        }
        
        .comparison-text.positive {
            color: #28a745;
        }
        
        .comparison-text.negative {
            color: #dc3545;
        }
        
        .comparison-text.neutral {
            color: #6c757d;
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
        
        .pending-alert {
            background: rgba(255, 193, 7, 0.1);
            border-left: 4px solid #ffc107;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .alert-count {
            background: #ffc107;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            margin-right: 10px;
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
                <a href="dashboard.php" class="nav-link active">Dashboard</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="reports_campaigns.php" class="nav-link">Reports</a>
            </li>
        </ul>

        <!-- Right navbar links -->
        <ul class="navbar-nav ml-auto">
            <!-- Notifications -->
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <?php $totalAlerts = $pendingConversions + ($systemStats['pending_kyc'] ?? 0) + ($systemStats['pending_payouts'] ?? 0) + ($systemStats['pending_deposits'] ?? 0); ?>
                    <?php if ($totalAlerts > 0): ?>
                    <span class="badge badge-warning navbar-badge"><?php echo $totalAlerts; ?></span>
                    <?php endif; ?>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header"><?php echo $totalAlerts; ?> Pending Items</span>
                    <div class="dropdown-divider"></div>
                    <?php if ($pendingConversions > 0): ?>
                    <a href="reports_campaigns.php" class="dropdown-item">
                        <i class="fas fa-exchange-alt mr-2 text-warning"></i>
                        <?php echo $pendingConversions; ?> Pending Conversions
                    </a>
                    <?php endif; ?>
                    <?php if (($systemStats['pending_kyc'] ?? 0) > 0): ?>
                    <a href="pending_kyc.php" class="dropdown-item">
                        <i class="fas fa-id-card mr-2 text-info"></i>
                        <?php echo $systemStats['pending_kyc']; ?> Pending KYC
                    </a>
                    <?php endif; ?>
                    <?php if (($systemStats['pending_payouts'] ?? 0) > 0): ?>
                    <a href="reports_affiliates.php" class="dropdown-item">
                        <i class="fas fa-money-bill-wave mr-2 text-success"></i>
                        <?php echo $systemStats['pending_payouts']; ?> Pending Payouts
                    </a>
                    <?php endif; ?>
                    <?php if (($systemStats['pending_deposits'] ?? 0) > 0): ?>
                    <a href="reports_advertisers.php" class="dropdown-item">
                        <i class="fas fa-wallet mr-2 text-primary"></i>
                        <?php echo $systemStats['pending_deposits']; ?> Pending Deposits
                    </a>
                    <?php endif; ?>
                </div>
            </li>
            
            <!-- Fullscreen -->
            <li class="nav-item">
                <a class="nav-link" data-widget="fullscreen" href="#" role="button">
                    <i class="fas fa-expand-arrows-alt"></i>
                </a>
            </li>
            
            <!-- Admin User -->
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
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-cog mr-2"></i> System Settings
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="../logout.php" class="dropdown-item">
                        <i class="fas fa-sign-out-alt mr-2"></i> Logout
                    </a>
                </div>
            </li>
            
            <!-- Dark Mode Toggle -->
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
                        <a href="dashboard.php" class="nav-link active">
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
                        <h1 class="m-0">Admin Dashboard</h1>
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
                            <h2>Welcome back, <?php echo htmlspecialchars($adminName); ?>!</h2>
                            <p class="mb-0">Monitor network performance and manage your CPA network efficiently.</p>
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
                            <div class="quick-stat-value"><?php echo number_format($today['today_clicks'] ?? 0); ?></div>
                            <div class="quick-stat-label">Today's Clicks</div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="quick-stat-value"><?php echo number_format($today['today_conversions'] ?? 0); ?></div>
                            <div class="quick-stat-label">Today's Conversions</div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="quick-stat-value">$<?php echo number_format($today['today_revenue'] ?? 0, 2); ?></div>
                            <div class="quick-stat-label">Today's Revenue</div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="quick-stat-value">$<?php echo number_format($today['today_payout'] ?? 0, 2); ?></div>
                            <div class="quick-stat-label">Today's Payout</div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="quick-stat-value">
                                <?php 
                                $todayProfit = ($today['today_revenue'] ?? 0) - ($today['today_payout'] ?? 0);
                                echo '$' . number_format($todayProfit, 2);
                                ?>
                            </div>
                            <div class="quick-stat-label">Today's Profit</div>
                        </div>
                    </div>
                </div>

                <!-- Pending Alerts -->
                <?php if ($pendingConversions > 0 || ($systemStats['pending_kyc'] ?? 0) > 0 || ($systemStats['pending_payouts'] ?? 0) > 0 || ($systemStats['pending_deposits'] ?? 0) > 0): ?>
                <div class="pending-alert">
                    <div class="d-flex align-items-center">
                        <span class="alert-count"><?php echo $totalAlerts; ?></span>
                        <strong>Pending Actions Required:</strong>
                        <div class="ml-3">
                            <?php if ($pendingConversions > 0): ?>
                            <a href="reports_campaigns.php" class="badge badge-warning mr-2">
                                <?php echo $pendingConversions; ?> Conversions
                            </a>
                            <?php endif; ?>
                            <?php if (($systemStats['pending_kyc'] ?? 0) > 0): ?>
                            <a href="pending_kyc.php" class="badge badge-info mr-2">
                                <?php echo $systemStats['pending_kyc']; ?> KYC
                            </a>
                            <?php endif; ?>
                            <?php if (($systemStats['pending_payouts'] ?? 0) > 0): ?>
                            <a href="reports_affiliates.php" class="badge badge-success mr-2">
                                <?php echo $systemStats['pending_payouts']; ?> Payouts
                            </a>
                            <?php endif; ?>
                            <?php if (($systemStats['pending_deposits'] ?? 0) > 0): ?>
                            <a href="reports_advertisers.php" class="badge badge-primary">
                                <?php echo $systemStats['pending_deposits']; ?> Deposits
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Main Stats Cards -->
                <div class="row stat-boxes-row">
                    <!-- Revenue & Profit -->
                    <div class="col-lg-3 col-md-6">
                        <div class="small-box bg-gradient-success">
                            <div class="inner">
                                <h3>$<?php echo number_format($approvedRevenue, 2); ?></h3>
                                <p>Total Revenue</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <a href="reports_campaigns.php" class="small-box-footer">
                                View Revenue Report <i class="fas fa-arrow-circle-right"></i>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Payout -->
                    <div class="col-lg-3 col-md-6">
                        <div class="small-box bg-gradient-warning">
                            <div class="inner">
                                <h3>$<?php echo number_format($approvedPayout, 2); ?></h3>
                                <p>Total Payout</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-wallet"></i>
                            </div>
                            <a href="reports_affiliates.php" class="small-box-footer">
                                Manage Payouts <i class="fas fa-arrow-circle-right"></i>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Profit -->
                    <div class="col-lg-3 col-md-6">
                        <div class="small-box bg-gradient-<?php echo $netProfit >= 0 ? 'success' : 'danger'; ?>">
                            <div class="inner">
                                <h3>$<?php echo number_format($netProfit, 2); ?></h3>
                                <p>Net Profit</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <a href="reports_campaigns.php" class="small-box-footer">
                                View Profit Report <i class="fas fa-arrow-circle-right"></i>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Conversions -->
                    <div class="col-lg-3 col-md-6">
                        <div class="small-box bg-gradient-info">
                            <div class="inner">
                                <h3><?php echo number_format($totalConversions); ?></h3>
                                <p>Total Conversions</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                            <a href="reports_campaigns.php" class="small-box-footer">
                                View All Conversions <i class="fas fa-arrow-circle-right"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Second Row Stats -->
                <div class="row">
                    <!-- Users -->
                    <div class="col-lg-3 col-md-6">
                        <div class="small-box bg-gradient-purple">
                            <div class="inner">
                                <h3><?php echo number_format($totalAffiliates); ?></h3>
                                <p>Total Affiliates</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-user-friends"></i>
                            </div>
                            <a href="publishers.php" class="small-box-footer">
                                Manage Affiliates <i class="fas fa-arrow-circle-right"></i>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Advertisers -->
                    <div class="col-lg-3 col-md-6">
                        <div class="small-box bg-gradient-teal">
                            <div class="inner">
                                <h3><?php echo number_format($totalAdvertisers); ?></h3>
                                <p>Total Advertisers</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-briefcase"></i>
                            </div>
                            <a href="advertisers.php" class="small-box-footer">
                                Manage Advertisers <i class="fas fa-arrow-circle-right"></i>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Offers -->
                    <div class="col-lg-3 col-md-6">
                        <div class="small-box bg-gradient-primary">
                            <div class="inner">
                                <h3><?php echo number_format($totalOffers); ?></h3>
                                <p>Total Offers</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-gift"></i>
                            </div>
                            <a href="campaigns.php" class="small-box-footer">
                                Manage Offers <i class="fas fa-arrow-circle-right"></i>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Clicks -->
                    <div class="col-lg-3 col-md-6">
                        <div class="small-box bg-gradient-dark">
                            <div class="inner">
                                <h3><?php echo number_format($totalClicks); ?></h3>
                                <p>Total Clicks</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-mouse-pointer"></i>
                            </div>
                            <a href="reports_subid.php" class="small-box-footer">
                                View Click Report <i class="fas fa-arrow-circle-right"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Charts and Top Lists -->
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
                                    <button class="btn btn-gradient" onclick="window.location.href='create_campaign.php'">
                                        <i class="fas fa-plus-circle mr-2"></i> Create New Campaign
                                    </button>
                                    <button class="btn btn-outline-primary" onclick="window.location.href='reports_campaigns.php'">
                                        <i class="fas fa-exchange-alt mr-2"></i> Review Conversions
                                    </button>
                                    <button class="btn btn-outline-success" onclick="window.location.href='reports_affiliates.php'">
                                        <i class="fas fa-money-bill-wave mr-2"></i> Process Payouts
                                    </button>
                                    <button class="btn btn-outline-info" onclick="window.location.href='profile.php'">
                                        <i class="fas fa-cog mr-2"></i> System Settings
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Conversion Status -->
                        <div class="card card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Conversion Status</h3>
                            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span>Approved</span>
                                    <div>
                                        <strong class="text-success"><?php echo $approvedConversions; ?></strong>
                                        <small class="text-muted ml-2">
                                            <?php echo $totalConversions > 0 ? round(($approvedConversions / $totalConversions) * 100, 1) : 0; ?>%
                                        </small>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span>Pending</span>
                                    <div>
                                        <strong class="text-warning"><?php echo $pendingConversions; ?></strong>
                                        <small class="text-muted ml-2">
                                            <?php echo $totalConversions > 0 ? round(($pendingConversions / $totalConversions) * 100, 1) : 0; ?>%
                                        </small>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>Rejected</span>
                                    <div>
                                        <strong class="text-danger"><?php echo $rejectedConversions; ?></strong>
                                        <small class="text-muted ml-2">
                                            <?php echo $totalConversions > 0 ? round(($rejectedConversions / $totalConversions) * 100, 1) : 0; ?>%
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Performers -->
                <div class="row">
                    <!-- Top Affiliates -->
                    <div class="col-lg-4">
                        <div class="card card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Top Affiliates</h3>
                                <div class="card-tools">
                                    <button class="btn btn-tool" onclick="window.location.href='reports_affiliates.php'">
                                        <i class="fas fa-external-link-alt"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($topAffiliates)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <p class="text-muted">No affiliate data available.</p>
                                </div>
                                <?php else: ?>
                                    <?php foreach($topAffiliates as $index => $aff): ?>
                                    <div class="performance-card">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <strong class="d-block"><?php echo htmlspecialchars($aff['name']); ?></strong>
                                                <small class="text-muted"><?php echo htmlspecialchars($aff['email']); ?></small>
                                            </div>
                                            <span class="badge badge-light">#<?php echo $index + 1; ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="text-primary">
                                                    <i class="fas fa-exchange-alt mr-1"></i>
                                                    <?php echo $aff['conversions']; ?> conv
                                                </span>
                                                <span class="cr-badge ml-2">
                                                    <?php echo $aff['conversion_rate']; ?>%
                                                </span>
                                            </div>
                                            <div>
                                                <strong class="text-success">$<?php echo number_format($aff['earnings'], 2); ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Top Advertisers -->
                    <div class="col-lg-4">
                        <div class="card card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Top Advertisers</h3>
                                <div class="card-tools">
                                    <button class="btn btn-tool" onclick="window.location.href='reports_advertisers.php'">
                                        <i class="fas fa-external-link-alt"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($topAdvertisers)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-building"></i>
                                    </div>
                                    <p class="text-muted">No advertiser data available.</p>
                                </div>
                                <?php else: ?>
                                    <?php foreach($topAdvertisers as $index => $adv): ?>
                                    <div class="performance-card">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <strong class="d-block"><?php echo htmlspecialchars($adv['name']); ?></strong>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($adv['company'] ?? $adv['email']); ?>
                                                </small>
                                            </div>
                                            <span class="badge badge-light">#<?php echo $index + 1; ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="text-primary">
                                                    <i class="fas fa-gift mr-1"></i>
                                                    <?php echo $adv['offers']; ?> offers
                                                </span>
                                            </div>
                                            <div>
                                                <strong class="text-success">$<?php echo number_format($adv['spent'], 2); ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Top Campaigns -->
                    <div class="col-lg-4">
                        <div class="card card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Top Campaigns</h3>
                                <div class="card-tools">
                                    <button class="btn btn-tool" onclick="window.location.href='reports_campaigns.php'">
                                        <i class="fas fa-external-link-alt"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($topCampaigns)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-bullhorn"></i>
                                    </div>
                                    <p class="text-muted">No campaign data available.</p>
                                </div>
                                <?php else: ?>
                                    <?php foreach($topCampaigns as $index => $camp): ?>
                                    <div class="performance-card">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <strong class="d-block"><?php echo htmlspecialchars($camp['offer_name']); ?></strong>
                                                <small class="text-muted">
                                                    By: <?php echo htmlspecialchars($camp['advertiser_name']); ?>
                                                </small>
                                            </div>
                                            <div>
                                                <span class="status-badge status-<?php echo $camp['status']; ?>">
                                                    <?php echo ucfirst($camp['status']); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="text-primary">
                                                    <i class="fas fa-exchange-alt mr-1"></i>
                                                    <?php echo $camp['conversions']; ?> conv
                                                </span>
                                            </div>
                                            <div>
                                                <div class="text-right">
                                                    <div class="text-success">$<?php echo number_format($camp['revenue'], 2); ?></div>
                                                    <small class="text-muted">Profit: $<?php echo number_format($camp['profit'], 2); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Recent Activity</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recentActivity)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-history"></i>
                                    </div>
                                    <p class="text-muted">No recent activity.</p>
                                </div>
                                <?php else: ?>
                                    <?php foreach($recentActivity as $activity): ?>
                                    <div class="activity-item">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <span class="type-badge <?php echo $activity['type']; ?>">
                                                    <i class="fas fa-<?php echo $activity['type'] == 'conversion' ? 'exchange-alt' : 'mouse-pointer'; ?>"></i>
                                                    <?php echo ucfirst($activity['type']); ?>
                                                </span>
                                                <strong class="ml-2">
                                                    <?php if ($activity['type'] == 'conversion'): ?>
                                                        #<?php echo $activity['id']; ?>
                                                        <?php if ($activity['transaction_id']): ?>
                                                            <small class="text-muted">(<?php echo htmlspecialchars($activity['transaction_id']); ?>)</small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        #<?php echo $activity['id']; ?>
                                                    <?php endif; ?>
                                                </strong>
                                            </div>
                                            <div>
                                                <?php if ($activity['type'] == 'conversion'): ?>
                                                    <?php if ($activity['status']): ?>
                                                        <span class="status-badge status-<?php echo $activity['status']; ?> ml-2">
                                                            <?php echo ucfirst($activity['status']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($activity['revenue']): ?>
                                                        <span class="text-success ml-2">
                                                            $<?php echo number_format($activity['revenue'], 2); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="text-primary">
                                                    <i class="fas fa-gift mr-1"></i>
                                                    <?php echo htmlspecialchars($activity['offer_name']); ?>
                                                </span>
                                                <span class="text-muted ml-3">
                                                    <i class="fas fa-user mr-1"></i>
                                                    <?php echo htmlspecialchars($activity['affiliate_name'] ?? 'N/A'); ?>
                                                </span>
                                                <?php if ($activity['advertiser_name']): ?>
                                                <span class="text-muted ml-3">
                                                    <i class="fas fa-building mr-1"></i>
                                                    <?php echo htmlspecialchars($activity['advertiser_name']); ?>
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <small class="text-muted">
                                                    <?php echo date('H:i', strtotime($activity['created_at'])); ?>
                                                </small>
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
                label: 'Payout',
                data: <?php echo json_encode(array_column($trendData, 'payout')); ?>,
                borderColor: '#ffc107',
                backgroundColor: 'rgba(255, 193, 7, 0.05)',
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
        link.download = 'admin-performance-chart.png';
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
        $.get('api/refresh-admin-stats.php', function(data) {
            if (data.newConversions > 0 || data.newUsers > 0) {
                $('.navbar-badge').text(data.totalAlerts);
                
                if (data.newConversions > 0) {
                    Toast.fire({
                        icon: 'info',
                        title: `${data.newConversions} new conversions!`
                    });
                }
                
                if (data.newUsers > 0) {
                    Toast.fire({
                        icon: 'success',
                        title: `${data.newUsers} new users registered!`
                    });
                }
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
    
    // Performance card hover
    $('.performance-card').hover(
        function() {
            $(this).css('transform', 'translateY(-2px)');
        },
        function() {
            $(this).css('transform', 'translateY(0)');
        }
    );
    
    // Activity item click
    $('.activity-item').click(function() {
        const type = $(this).find('.type-badge').text().trim().toLowerCase();
        const id = $(this).find('strong').text().replace('#', '');
        
        if (type === 'conversion') {
            window.location.href = `conversion_details.php?id=${id}`;
        } else if (type === 'click') {
            window.location.href = `click_details.php?id=${id}`;
        }
    });
});
</script>

</body>
</html>