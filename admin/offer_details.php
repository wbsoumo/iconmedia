<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Admin';

// Get offer ID from URL
$offerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$offerId) {
    header('Location: offers.php?error=Invalid campaign ID');
    exit;
}

/* ===============================
   FETCH OFFER DETAILS
================================ */
$stmt = $pdo->prepare("
    SELECT 
        o.*,
        u.name AS advertiser_name,
        u.email AS advertiser_email,
        u.company AS advertiser_company,
        u.mobile AS advertiser_mobile,
        u.created_at AS advertiser_joined,
        
        -- Account manager info
        am.name AS account_manager_name,
        am.email AS account_manager_email,
        
        -- Stats
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        SUM(CASE WHEN cv.status = 'pending' THEN 1 ELSE 0 END) AS pending_conversions,
        SUM(CASE WHEN cv.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_conversions,
        
        -- Financial stats
        SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END) AS earned_revenue,
        SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END) AS paid_payout,
        SUM(CASE WHEN cv.status = 'pending' THEN cv.revenue ELSE 0 END) AS pending_revenue,
        SUM(CASE WHEN cv.status = 'pending' THEN cv.payout ELSE 0 END) AS pending_payout,
        
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
    LEFT JOIN users am ON am.user_id = u.account_manager_id
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE o.offer_id = :offer_id
    GROUP BY o.offer_id
");

$stmt->execute(['offer_id' => $offerId]);
$offer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$offer) {
    header('Location: offers.php?error=Campaign not found');
    exit;
}

// Parse comma-separated fields into arrays
$allowedTraffic = !empty($offer['allowed_traffic']) ? explode(',', $offer['allowed_traffic']) : [];
$browserTargeting = !empty($offer['browser_targeting']) ? explode(',', $offer['browser_targeting']) : [];

/* ===============================
   FETCH RECENT CONVERSIONS
================================ */
$recentConversions = $pdo->prepare("
    SELECT 
        cv.conversion_id,
        cv.transaction_id,
        cv.revenue,
        cv.payout,
        cv.status,
        cv.created_at,
        u.name AS affiliate_name,
        u.email AS affiliate_email,
        u.user_id AS affiliate_id
    FROM conversions cv
    LEFT JOIN users u ON u.user_id = cv.affiliate_id
    WHERE cv.offer_id = ?
    ORDER BY cv.created_at DESC
    LIMIT 20
");
$recentConversions->execute([$offerId]);
$conversions = $recentConversions->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH TOP AFFILIATES FOR THIS OFFER
================================ */
$topAffiliates = $pdo->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        COUNT(DISTINCT cv.conversion_id) AS conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END) AS revenue,
        SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END) AS payout,
        COUNT(DISTINCT c.click_id) AS clicks
    FROM users u
    INNER JOIN conversions cv ON cv.affiliate_id = u.user_id
    LEFT JOIN clicks c ON c.affiliate_id = u.user_id AND c.offer_id = cv.offer_id
    WHERE cv.offer_id = ?
    GROUP BY u.user_id
    ORDER BY conversions DESC
    LIMIT 10
");
$topAffiliates->execute([$offerId]);
$affiliates = $topAffiliates->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH DAILY STATS FOR CHARTS
================================ */
$dailyStats = $pdo->prepare("
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as conversions,
        SUM(CASE WHEN status = 'approved' THEN revenue ELSE 0 END) as revenue,
        SUM(CASE WHEN status = 'approved' THEN payout ELSE 0 END) as payout
    FROM conversions
    WHERE offer_id = ?
    GROUP BY DATE(created_at)
    ORDER BY date DESC
    LIMIT 30
");
$dailyStats->execute([$offerId]);
$dailyData = $dailyStats->fetchAll(PDO::FETCH_ASSOC);

// Reverse for chart display (oldest to newest)
$dailyData = array_reverse($dailyData);

/* ===============================
   FETCH OFFER NOTES/HISTORY
================================ */
// You would need a notes table for this
// For now, we'll use placeholder data
$notes = [
    [
        'created_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
        'admin_name' => 'Admin',
        'note' => 'Campaign created and submitted for review.',
        'type' => 'info'
    ],
    [
        'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
        'admin_name' => 'Admin',
        'note' => 'Campaign approved and set to active.',
        'type' => 'success'
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Campaign Details | Admin Panel | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
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
        
        .offer-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .offer-header::before {
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
        
        .offer-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .offer-id-badge {
            background: rgba(255,255,255,0.2);
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 14px;
            display: inline-block;
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
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        
        .visibility-private {
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
            height: 100%;
        }

        .stat-card-custom {
            border-radius: 12px;
            background: #ffffff;
            padding: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            text-align: center;
            margin-bottom: 15px;
        }

        .stat-card-custom .stat-number {
            font-size: 24px;
            font-weight: 800;
            color: #1e293b;
        }

        .stat-card-custom .stat-label {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
        }
        
        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .metric-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .metric-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .metric-sub {
            font-size: 14px;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .profit-positive {
            color: #28a745;
            font-weight: 600;
        }
        
        .profit-negative {
            color: #dc3545;
            font-weight: 600;
        }
        
        .info-section {
            background: #f8f9fc;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e3e6f0;
        }
        
        .info-section-title {
            color: #4e73df;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e3e6f0;
            display: flex;
            align-items: center;
        }
        
        .info-section-title i {
            margin-right: 10px;
            font-size: 18px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 12px;
            border-bottom: 1px dashed #e3e6f0;
            padding-bottom: 8px;
        }
        
        .info-label {
            width: 150px;
            font-weight: 600;
            color: #4a5568;
        }
        
        .info-value {
            flex: 1;
            color: #2d3748;
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
        
        .token-box {
            background: #f8f9fc;
            border: 1px solid #e3e6f0;
            border-radius: 8px;
            padding: 12px;
            font-family: monospace;
            word-break: break-all;
            position: relative;
        }
        
        .copy-btn {
            position: absolute;
            right: 10px;
            top: 10px;
            background: white;
            border: 1px solid #e3e6f0;
            border-radius: 6px;
            padding: 5px 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .copy-btn:hover {
            background: var(--primary-gradient);
            color: white;
            border-color: transparent;
        }
        
        .targeting-tag {
            display: inline-block;
            background: #e3e6f0;
            color: #4e73df;
            padding: 4px 10px;
            margin: 3px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .note-item {
            background: #f8f9fc;
            border-left: 4px solid;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 8px;
        }
        
        .note-item.info {
            border-left-color: #4e73df;
        }
        
        .note-item.success {
            border-left-color: #28a745;
        }
        
        .note-item.warning {
            border-left-color: #ffc107;
        }
        
        .note-item.danger {
            border-left-color: #dc3545;
        }
        
        .chart-container {
            height: 300px;
            margin-bottom: 20px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn-action {
            padding: 8px 15px;
            border-radius: 8px;
            font-size: 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
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
        
        .btn-back {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.2);
        }
        
        .btn-back:hover {
            background: rgba(108, 117, 125, 0.2);
            color: #6c757d;
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
        
        .badge-icon {
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
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
                <a href="offers.php" class="nav-link">Campaigns</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="offer_details.php?id=<?php echo $offerId; ?>" class="nav-link active">Campaign Details</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
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
                <i class="fas fa-crown mr-2"></i><strong>Admin</strong>
            </span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>

                    <li class="nav-header">CAMPAIGNS</li>
                    <li class="nav-item">
                        <a href="campaigns.php" class="nav-link active">
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
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 font-weight-bold">Campaign Specifications & Analytics</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="campaigns.php">Campaigns</a></li>
                            <li class="breadcrumb-item active">Campaign #<?php echo $offerId; ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">

                <!-- Offer Header Banner -->
                <div class="offer-header">
                    <div class="d-flex justify-content-between align-items-start flex-wrap">
                        <div class="mb-3">
                            <div class="offer-id-badge mb-2 d-inline-block">
                                <i class="fas fa-hashtag mr-1"></i> Campaign ID: #<?php echo $offer['offer_id']; ?>
                            </div>
                            <h1 class="offer-title text-white mb-2"><?php echo htmlspecialchars($offer['offer_name']); ?></h1>
                            <div class="d-flex align-items-center mb-2">
                                <span class="badge badge-<?php echo ($offer['status'] === 'active' || $offer['status'] === 'approved') ? 'success' : 'secondary'; ?> p-2 mr-2">
                                    <?php echo ucfirst($offer['status']); ?>
                                </span>
                                <span class="badge badge-light p-2">
                                    <i class="fas fa-<?php echo ($offer['visibility'] ?? 'public') == 'public' ? 'globe' : 'lock'; ?> mr-1"></i>
                                    <?php echo ucfirst($offer['visibility'] ?? 'public'); ?>
                                </span>
                            </div>
                            <p class="mb-0 text-white-50 small">
                                <i class="fas fa-calendar-alt mr-1"></i> Created: <?php echo date('F d, Y h:i A', strtotime($offer['created_at'])); ?>
                            </p>
                        </div>
                        <div class="action-buttons mb-3">
                            <a href="campaigns.php" class="btn btn-light font-weight-bold mr-1">
                                <i class="fas fa-arrow-left mr-1"></i> Back
                            </a>
                            <a href="offer_edit.php?id=<?php echo $offer['offer_id']; ?>" class="btn btn-warning font-weight-bold mr-1">
                                <i class="fas fa-edit mr-1"></i> Edit Campaign
                            </a>
                            <?php if ($offer['status'] === 'active'): ?>
                                <a href="campaigns.php?action=pause&id=<?php echo $offer['offer_id']; ?>" class="btn btn-danger font-weight-bold">
                                    <i class="fas fa-pause mr-1"></i> Pause
                                </a>
                            <?php else: ?>
                                <a href="campaigns.php?action=activate&id=<?php echo $offer['offer_id']; ?>" class="btn btn-success font-weight-bold">
                                    <i class="fas fa-play mr-1"></i> Activate
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($offer['total_clicks'] ?? 0); ?></div>
                            <div class="stat-label">Total Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($offer['approved_conversions'] ?? 0); ?></div>
                            <div class="stat-label">Approved Leads</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($offer['conversion_rate'] ?? 0, 2); ?>%</div>
                            <div class="stat-label">CR %</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success">$<?php echo number_format($offer['earned_revenue'] ?? 0, 2); ?></div>
                            <div class="stat-label">Revenue</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning">$<?php echo number_format($offer['paid_payout'] ?? 0, 2); ?></div>
                            <div class="stat-label">Payout</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success font-weight-bold">$<?php echo number_format($offer['profit'] ?? 0, 2); ?></div>
                            <div class="stat-label">Net Profit</div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-chart-line mr-2"></i> 30-Day Performance
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="performanceChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-chart-pie mr-2"></i> Conversion Status
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="chart-container" style="height: 250px;">
                                    <canvas id="statusChart"></canvas>
                                </div>
                                <div class="text-center mt-3">
                                    <div class="row">
                                        <div class="col-4">
                                            <span class="badge badge-success"><?php echo number_format($offer['approved_conversions'] ?? 0); ?></span>
                                            <div class="small">Approved</div>
                                        </div>
                                        <div class="col-4">
                                            <span class="badge badge-warning"><?php echo number_format($offer['pending_conversions'] ?? 0); ?></span>
                                            <div class="small">Pending</div>
                                        </div>
                                        <div class="col-4">
                                            <span class="badge badge-danger"><?php echo number_format($offer['rejected_conversions'] ?? 0); ?></span>
                                            <div class="small">Rejected</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Campaign Details -->
                <div class="row">
                    <div class="col-lg-8">
                        <!-- Basic Information -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-info-circle mr-2"></i> Campaign Information
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="info-section">
                                    <div class="info-section-title">
                                        <i class="fas fa-align-left"></i> Description
                                    </div>
                                    <p><?php echo nl2br(htmlspecialchars($offer['offer_description'] ?? 'No description provided.')); ?></p>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="info-section">
                                            <div class="info-section-title">
                                                <i class="fas fa-tag"></i> Campaign Details
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Objective:</div>
                                                <div class="info-value">
                                                    <span class="badge badge-info"><?php echo ucfirst($offer['objective'] ?? 'conversions'); ?></span>
                                                </div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Category:</div>
                                                <div class="info-value"><?php echo htmlspecialchars($offer['category'] ?? 'Uncategorized'); ?></div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">KPI:</div>
                                                <div class="info-value"><?php echo htmlspecialchars($offer['kpi'] ?? 'Not specified'); ?></div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Payout Type:</div>
                                                <div class="info-value">
                                                    <span class="badge badge-primary"><?php echo strtoupper($offer['payout_type'] ?? 'CPA'); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-section">
                                            <div class="info-section-title">
                                                <i class="fas fa-dollar-sign"></i> Pricing
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Revenue:</div>
                                                <div class="info-value text-success">$<?php echo number_format($offer['revenue'], 2); ?> <?php echo $offer['currency']; ?></div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Payout:</div>
                                                <div class="info-value text-warning">$<?php echo number_format($offer['payout'], 2); ?> <?php echo $offer['currency']; ?></div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Margin:</div>
                                                <div class="info-value <?php echo ($offer['revenue'] - $offer['payout']) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                                    $<?php echo number_format($offer['revenue'] - $offer['payout'], 2); ?> 
                                                    (<?php echo $offer['revenue'] > 0 ? number_format((($offer['revenue'] - $offer['payout']) / $offer['revenue']) * 100, 1) : 0; ?>%)
                                                </div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Currency:</div>
                                                <div class="info-value"><?php echo $offer['currency']; ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="info-section">
                                            <div class="info-section-title">
                                                <i class="fas fa-link"></i> URLs
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Campaign URL:</div>
                                                <div class="info-value">
                                                    <a href="<?php echo htmlspecialchars($offer['campaign_url']); ?>" target="_blank">
                                                        <?php echo htmlspecialchars(substr($offer['campaign_url'], 0, 50)) . '...'; ?>
                                                        <i class="fas fa-external-link-alt ml-1 small"></i>
                                                    </a>
                                                </div>
                                            </div>
                                            <?php if ($offer['preview_url']): ?>
                                            <div class="info-row">
                                                <div class="info-label">Preview URL:</div>
                                                <div class="info-value">
                                                    <a href="<?php echo htmlspecialchars($offer['preview_url']); ?>" target="_blank">
                                                        <?php echo htmlspecialchars(substr($offer['preview_url'], 0, 50)) . '...'; ?>
                                                        <i class="fas fa-external-link-alt ml-1 small"></i>
                                                    </a>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-section">
                                            <div class="info-section-title">
                                                <i class="fas fa-key"></i> Tracking
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Postback Token:</div>
                                                <div class="info-value">
                                                    <div class="token-box">
                                                        <?php echo htmlspecialchars($offer['postback_token']); ?>
                                                        <span class="copy-btn" onclick="copyToken()">
                                                            <i class="fas fa-copy"></i> Copy
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Tracking Method:</div>
                                                <div class="info-value">
                                                    <span class="badge badge-secondary"><?php echo ucfirst($offer['conversion_tracking'] ?? 'postback'); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Targeting Information -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-crosshairs mr-2"></i> Targeting & Restrictions
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="info-section">
                                            <div class="info-section-title">
                                                <i class="fas fa-globe"></i> Geographic Targeting
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Geo (Legacy):</div>
                                                <div class="info-value"><?php echo $offer['geo'] ?? 'ALL'; ?></div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Primary Country:</div>
                                                <div class="info-value"><?php echo $offer['country'] ?? 'Not specified'; ?></div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Allowed Countries:</div>
                                                <div class="info-value">
                                                    <?php if ($offer['allowed_countries']): ?>
                                                        <?php foreach(explode(',', $offer['allowed_countries']) as $country): ?>
                                                            <span class="targeting-tag"><?php echo trim($country); ?></span>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        All Countries
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Blocked Countries:</div>
                                                <div class="info-value">
                                                    <?php if ($offer['blocked_countries']): ?>
                                                        <?php foreach(explode(',', $offer['blocked_countries']) as $country): ?>
                                                            <span class="targeting-tag"><?php echo trim($country); ?></span>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        None
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-section">
                                            <div class="info-section-title">
                                                <i class="fas fa-mobile-alt"></i> Device & Browser
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Device Type:</div>
                                                <div class="info-value">
                                                    <span class="targeting-tag"><?php echo ucfirst($offer['device_type'] ?? 'All'); ?></span>
                                                </div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Browser Targeting:</div>
                                                <div class="info-value">
                                                    <?php if (!empty($browserTargeting)): ?>
                                                        <?php foreach($browserTargeting as $browser): ?>
                                                            <span class="targeting-tag"><?php echo trim($browser); ?></span>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        All Browsers
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Traffic Channels:</div>
                                                <div class="info-value">
                                                    <?php if (!empty($allowedTraffic)): ?>
                                                        <?php foreach($allowedTraffic as $channel): ?>
                                                            <span class="targeting-tag"><?php echo trim($channel); ?></span>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        All Channels
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mt-3">
                                    <div class="col-md-4">
                                        <div class="info-section">
                                            <div class="info-section-title">
                                                <i class="fas fa-chart-line"></i> Caps
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Daily Cap:</div>
                                                <div class="info-value"><?php echo $offer['daily_cap'] > 0 ? number_format($offer['daily_cap']) : 'Unlimited'; ?></div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Total Cap:</div>
                                                <div class="info-value"><?php echo $offer['total_cap'] > 0 ? number_format($offer['total_cap']) : 'Unlimited'; ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="info-section">
                                            <div class="info-section-title">
                                                <i class="fas fa-calendar"></i> Schedule
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Start Date:</div>
                                                <div class="info-value"><?php echo $offer['start_date'] ? date('M d, Y', strtotime($offer['start_date'])) : 'Not set'; ?></div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">End Date:</div>
                                                <div class="info-value"><?php echo $offer['end_date'] ? date('M d, Y', strtotime($offer['end_date'])) : 'Not set'; ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="info-section">
                                            <div class="info-section-title">
                                                <i class="fas fa-file-contract"></i> Requirements
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Terms Required:</div>
                                                <div class="info-value">
                                                    <?php if ($offer['terms_required']): ?>
                                                        <span class="badge badge-success">Yes</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary">No</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Top Affiliates -->
                        <?php if (!empty($affiliates)): ?>
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-users mr-2"></i> Top Performing Affiliates
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-dashboard">
                                        <thead>
                                            <tr>
                                                <th>Affiliate</th>
                                                <th>Clicks</th>
                                                <th>Conversions</th>
                                                <th>Revenue</th>
                                                <th>Payout</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($affiliates as $aff): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($aff['name']); ?></strong>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($aff['email']); ?></div>
                                                </td>
                                                <td><?php echo number_format($aff['clicks'] ?? 0); ?></td>
                                                <td><?php echo number_format($aff['conversions']); ?></td>
                                                <td class="text-success">$<?php echo number_format($aff['revenue'], 2); ?></td>
                                                <td class="text-warning">$<?php echo number_format($aff['payout'], 2); ?></td>
                                                <td>
                                                    <a href="affiliate_details.php?id=<?php echo $aff['user_id']; ?>" class="btn-action btn-view">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-lg-4">
                        <!-- Advertiser Information -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-building mr-2"></i> Advertiser
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="badge-icon mr-3">
                                        <i class="fas fa-user-tie"></i>
                                    </div>
                                    <div>
                                        <h5 class="mb-0"><?php echo htmlspecialchars($offer['advertiser_name']); ?></h5>
                                        <small class="text-muted"><?php echo htmlspecialchars($offer['advertiser_email']); ?></small>
                                    </div>
                                </div>
                                
                                <?php if ($offer['advertiser_company']): ?>
                                <div class="info-row">
                                    <div class="info-label">Company:</div>
                                    <div class="info-value"><?php echo htmlspecialchars($offer['advertiser_company']); ?></div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($offer['advertiser_mobile']): ?>
                                <div class="info-row">
                                    <div class="info-label">Mobile:</div>
                                    <div class="info-value"><?php echo htmlspecialchars($offer['advertiser_mobile']); ?></div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="info-row">
                                    <div class="info-label">Joined:</div>
                                    <div class="info-value"><?php echo date('M d, Y', strtotime($offer['advertiser_joined'])); ?></div>
                                </div>
                                
                                <?php if ($offer['account_manager_name']): ?>
                                <div class="info-row">
                                    <div class="info-label">Account Manager:</div>
                                    <div class="info-value">
                                        <?php echo htmlspecialchars($offer['account_manager_name']); ?>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($offer['account_manager_email']); ?></small>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mt-3">
                                    <a href="advertiser_edit.php?id=<?php echo $offer['advertiser_id']; ?>" class="btn btn-sm btn-outline-primary btn-block">
                                        <i class="fas fa-eye mr-2"></i> View Advertiser Profile
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Campaign Notes -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-sticky-note mr-2"></i> Internal Notes
                                </h3>
                            </div>
                            <div class="card-body">
                                <?php if ($offer['internal_note']): ?>
                                <div class="note-item info">
                                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($offer['internal_note'])); ?></p>
                                </div>
                                <?php else: ?>
                                <p class="text-muted text-center">No internal notes available.</p>
                                <?php endif; ?>

                                <div class="mt-3">
                                    <h6>Recent Activity</h6>
                                    <?php foreach ($notes as $note): ?>
                                    <div class="note-item <?php echo $note['type']; ?>">
                                        <div class="d-flex justify-content-between">
                                            <strong><?php echo htmlspecialchars($note['admin_name']); ?></strong>
                                            <small class="text-muted"><?php echo date('M d, H:i', strtotime($note['created_at'])); ?></small>
                                        </div>
                                        <p class="mb-0 mt-1 small"><?php echo htmlspecialchars($note['note']); ?></p>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Conversions -->
                <?php if (!empty($conversions)): ?>
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-exchange-alt mr-2"></i> Recent Conversions
                                </h3>
                                <div class="card-tools">
                                    <a href="reports_conversions.php?offer_id=<?php echo $offerId; ?>" class="btn btn-sm btn-outline-primary">
                                        View All <i class="fas fa-arrow-right ml-1"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-dashboard">
                                        <thead>
                                            <tr>
                                                <th>Transaction ID</th>
                                                <th>Affiliate</th>
                                                <th>Revenue</th>
                                                <th>Payout</th>
                                                <th>Status</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($conversions as $conv): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($conv['transaction_id'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <a href="affiliate_details.php?id=<?php echo $conv['affiliate_id']; ?>">
                                                        <?php echo htmlspecialchars($conv['affiliate_name'] ?? 'Unknown'); ?>
                                                    </a>
                                                </td>
                                                <td class="text-success">$<?php echo number_format($conv['revenue'], 2); ?></td>
                                                <td class="text-warning">$<?php echo number_format($conv['payout'], 2); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $conv['status']; ?>" style="padding: 3px 8px;">
                                                        <?php echo ucfirst($conv['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M d, H:i', strtotime($conv['created_at'])); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
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
    
    // Initialize DataTables
    $('.table-dashboard').DataTable({
        pageLength: 10,
        lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
        responsive: true,
        language: {
            emptyTable: "No data available"
        }
    });
    
    // Initialize Performance Chart
    const ctx = document.getElementById('performanceChart').getContext('2d');
    const performanceChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_map(function($d) { 
                return date('M d', strtotime($d['date'])); 
            }, $dailyData)); ?>,
            datasets: [{
                label: 'Revenue',
                data: <?php echo json_encode(array_column($dailyData, 'revenue')); ?>,
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                tension: 0.4,
                fill: true,
                yAxisID: 'y'
            }, {
                label: 'Conversions',
                data: <?php echo json_encode(array_column($dailyData, 'conversions')); ?>,
                borderColor: '#4e73df',
                backgroundColor: 'rgba(78, 115, 223, 0.1)',
                tension: 0.4,
                fill: true,
                yAxisID: 'y1'
            }]
        },
        options: {
            maintainAspectRatio: false,
            responsive: true,
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
                            return value;
                        }
                    }
                }
            }
        }
    });
    
    // Initialize Status Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: ['Approved', 'Pending', 'Rejected'],
            datasets: [{
                data: [
                    <?php echo $offer['approved_conversions'] ?? 0; ?>,
                    <?php echo $offer['pending_conversions'] ?? 0; ?>,
                    <?php echo $offer['rejected_conversions'] ?? 0; ?>
                ],
                backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                borderWidth: 0
            }]
        },
        options: {
            maintainAspectRatio: false,
            responsive: true,
            cutout: '70%',
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
    
    // Auto-dismiss alerts after 5 seconds
    $('.alert').delay(5000).fadeOut('slow');
});

function copyToken() {
    const token = document.querySelector('.token-box').innerText.split('Copy')[0].trim();
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

// Initialize SweetAlert2 Toast
const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true
});
</script>

</body>
</html>