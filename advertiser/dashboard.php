<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('advertiser');

$advertiserId   = auth_user_id();
$advertiserName = $_SESSION['user_name'] ?? 'Advertiser';

/* -------------------------------------------------
   OVERALL METRICS & BALANCE
-------------------------------------------------- */
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT o.offer_id) AS total_offers,
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS total_revenue
    FROM offers o
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE o.advertiser_id = ?
");
$statsStmt->execute([$advertiserId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$totalOffers = (int)($stats['total_offers'] ?? 0);
$totalClicks = (int)($stats['total_clicks'] ?? 0);
$totalConversions = (int)($stats['total_conversions'] ?? 0);
$totalRevenue = (float)($stats['total_revenue'] ?? 0);
$cr = $totalClicks > 0 ? number_format(($totalConversions / $totalClicks) * 100, 2) : '0.00';

/* -------------------------------------------------
   TODAY PERFORMANCE
-------------------------------------------------- */
$todayStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT c.click_id) AS today_clicks,
        COUNT(DISTINCT cv.conversion_id) AS today_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS today_revenue
    FROM offers o
    LEFT JOIN clicks c ON c.offer_id = o.offer_id AND DATE(c.created_at) = CURDATE()
    LEFT JOIN conversions cv ON cv.click_id = c.click_id AND DATE(cv.created_at) = CURDATE()
    WHERE o.advertiser_id = ?
");
$todayStmt->execute([$advertiserId]);
$today = $todayStmt->fetch(PDO::FETCH_ASSOC);

$todayClicks = (int)($today['today_clicks'] ?? 0);
$todayConversions = (int)($today['today_conversions'] ?? 0);
$todayRevenue = (float)($today['today_revenue'] ?? 0);

/* -------------------------------------------------
   TOP CAMPAIGN OFFERS
-------------------------------------------------- */
$topOffersStmt = $pdo->prepare("
    SELECT
        o.offer_id,
        o.offer_name,
        o.category,
        o.status,
        o.payout,
        COUNT(DISTINCT c.click_id) AS clicks,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS gross_revenue
    FROM offers o
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE o.advertiser_id = ?
    GROUP BY o.offer_id
    ORDER BY gross_revenue DESC
    LIMIT 5
");
$topOffersStmt->execute([$advertiserId]);
$topOffers = $topOffersStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   RECENT CONVERSION LOGS
-------------------------------------------------- */
$recentConvStmt = $pdo->prepare("
    SELECT
        cv.conversion_id,
        cv.transaction_id,
        cv.revenue,
        cv.status,
        cv.created_at,
        o.offer_name,
        c.country
    FROM conversions cv
    INNER JOIN offers o ON o.offer_id = cv.offer_id
    LEFT JOIN clicks c ON c.click_id = cv.click_id
    WHERE o.advertiser_id = ?
    ORDER BY cv.created_at DESC
    LIMIT 5
");
$recentConvStmt->execute([$advertiserId]);
$recentConversions = $recentConvStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Advertiser Dashboard | GVS Icon Media</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">

    <style>
        .card-custom {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 18px rgba(0,0,0,0.06);
            margin-bottom: 25px;
            background: #ffffff;
        }

        .hero-banner {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            border-radius: 12px;
            padding: 25px 30px;
            color: #ffffff;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.25);
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

        @media (max-width: 767.98px) {
            .stat-boxes-row > [class*="col-"] {
                flex: 0 0 50% !important;
                max-width: 50% !important;
                padding-left: 6px !important;
                padding-right: 6px !important;
            }
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item"><a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link active">Dashboard</a></li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <a class="nav-link text-danger font-weight-bold" href="../logout.php"><i class="fas fa-sign-out-alt mr-1"></i> Logout</a>
            </li>
        </ul>
    </nav>

    <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.4rem;">
                <i class="fas fa-bullhorn mr-2"></i><strong>Advertiser Portal</strong>
            </span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link active">
                            <i class="nav-icon fas fa-tachometer-alt"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>

                    <li class="nav-header">CAMPAIGNS & OFFERS</li>
                    <li class="nav-item">
                        <a href="campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-bullhorn"></i>
                            <p>Manage Campaigns</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="create_offer.php" class="nav-link">
                            <i class="nav-icon fas fa-plus-circle"></i>
                            <p>Create New Offer</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="optimization.php" class="nav-link">
                            <i class="nav-icon fas fa-rocket"></i>
                            <p>Campaign Optimization</p>
                        </a>
                    </li>

                    <li class="nav-header">ANALYTICS & REPORTS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Campaign Performance</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_conversions.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <p>Conversion Logs</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link">
                            <i class="nav-icon fas fa-users"></i>
                            <p>Publisher Breakdown</p>
                        </a>
                    </li>

                    <li class="nav-header">BILLING & INTEGRATION</li>
                    <li class="nav-item">
                        <a href="billing.php" class="nav-link">
                            <i class="nav-icon fas fa-wallet"></i>
                            <p>Billing & Deposit</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="postback.php" class="nav-link">
                            <i class="nav-icon fas fa-code"></i>
                            <p>S2S Postback Integration</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="ip_whitelist.php" class="nav-link">
                            <i class="nav-icon fas fa-shield-alt"></i>
                            <p>IP Whitelist</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="api.php" class="nav-link">
                            <i class="nav-icon fas fa-key"></i>
                            <p>API Access Keys</p>
                        </a>
                    </li>

                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user-cog"></i>
                            <p>Account Profile</p>
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
                    <div class="col-sm-6"><h1 class="m-0 font-weight-bold">Advertiser Command Center</h1></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Dashboard</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">

                <!-- Hero Banner -->
                <div class="hero-banner">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <h2 class="font-weight-bold mb-1"><i class="fas fa-bullhorn mr-2"></i>Welcome, <?php echo htmlspecialchars($advertiserName); ?>!</h2>
                            <p class="mb-0 text-white-50">Manage campaign budgets, monitor lead postbacks, and configure API integrations.</p>
                        </div>
                        <div class="mt-3 mt-md-0">
                            <a href="create_offer.php" class="btn btn-light font-weight-bold mr-1"><i class="fas fa-plus-circle mr-1"></i> Create Offer</a>
                            <a href="billing.php" class="btn btn-warning font-weight-bold mr-1"><i class="fas fa-wallet mr-1"></i> Deposit Funds</a>
                            <a href="/contact.php" class="btn btn-info font-weight-bold"><i class="fas fa-headset mr-1"></i> Support</a>
                        </div>
                    </div>
                </div>

                <!-- 2x2 Mobile Stat Grid -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($totalOffers); ?></div>
                            <div class="stat-label">Active Campaigns</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($todayClicks); ?></div>
                            <div class="stat-label">Today's Traffic Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($todayConversions); ?></div>
                            <div class="stat-label">Today's Conversions</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success">$<?php echo number_format($totalRevenue, 2); ?></div>
                            <div class="stat-label">Gross Revenue Spend</div>
                        </div>
                    </div>
                </div>

                <!-- Top Campaigns & Conversion Activity Row -->
                <div class="row">
                    <!-- Active Campaigns Overview -->
                    <div class="col-lg-7">
                        <div class="card card-custom p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h4 class="font-weight-bold text-primary mb-0"><i class="fas fa-bullhorn mr-2"></i>My Live Campaigns</h4>
                                <a href="campaigns.php" class="btn btn-sm btn-outline-primary">Manage All</a>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Campaign</th>
                                            <th>Payout</th>
                                            <th>Clicks</th>
                                            <th>Conversions</th>
                                            <th>Spend Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($topOffers)): ?>
                                            <tr><td colspan="5" class="text-center text-muted py-3">No active campaign offers created yet.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($topOffers as $to): ?>
                                            <tr>
                                                <td>
                                                    <a href="offer_edit.php?id=<?php echo $to['offer_id']; ?>" class="text-primary font-weight-bold">
                                                        #<?php echo $to['offer_id']; ?> - <?php echo htmlspecialchars($to['offer_name']); ?>
                                                    </a>
                                                </td>
                                                <td><strong class="text-dark">$<?php echo number_format($to['payout'], 2); ?></strong></td>
                                                <td><strong><?php echo number_format($to['clicks']); ?></strong></td>
                                                <td><strong class="text-success"><?php echo number_format($to['conversions']); ?></strong></td>
                                                <td><strong class="text-success">$<?php echo number_format($to['gross_revenue'], 2); ?></strong></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Conversion Stream -->
                    <div class="col-lg-5">
                        <div class="card card-custom p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h4 class="font-weight-bold text-primary mb-0"><i class="fas fa-chart-line mr-2"></i>Recent Conversions</h4>
                                <a href="reports_conversions.php" class="btn btn-sm btn-outline-primary">View Logs</a>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Offer</th>
                                            <th>Revenue</th>
                                            <th>Status</th>
                                            <th>Time</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($recentConversions)): ?>
                                            <tr><td colspan="4" class="text-center text-muted py-3">No recent conversions recorded.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($recentConversions as $rc): ?>
                                            <tr>
                                                <td><strong class="text-dark"><?php echo htmlspecialchars($rc['offer_name']); ?></strong></td>
                                                <td><strong class="text-success">$<?php echo number_format($rc['revenue'], 2); ?></strong></td>
                                                <td>
                                                    <?php if ($rc['status'] === 'approved'): ?>
                                                        <span class="badge badge-success">Approved</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning">Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><small class="text-muted"><?php echo date('M d H:i', strtotime($rc['created_at'])); ?></small></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline"><strong>Advertiser Portal v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
</body>
</html>