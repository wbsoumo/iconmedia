<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('affiliate');

$affiliateId = auth_user_id();
$affiliateName = $_SESSION['user_name'] ?? 'Affiliate';

/* -------------------------------------------------
   FETCH SUMMARY STATS
-------------------------------------------------- */

// Revenue & Conversions
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS total_earned,
        IFNULL(SUM(CASE WHEN cv.status = 'pending' THEN cv.payout ELSE 0 END), 0) AS pending_payout
    FROM clicks c
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    WHERE c.affiliate_id = ?
");
$statsStmt->execute([$affiliateId]);
$mStats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$totalClicks = (int)($mStats['total_clicks'] ?? 0);
$totalConversions = (int)($mStats['total_conversions'] ?? 0);
$approvedConversions = (int)($mStats['approved_conversions'] ?? 0);
$totalEarned = (float)($mStats['total_earned'] ?? 0);
$pendingPayout = (float)($mStats['pending_payout'] ?? 0);
$cr = $totalClicks > 0 ? number_format(($approvedConversions / $totalClicks) * 100, 2) : '0.00';

// Today's Clicks
$todayStmt = $pdo->prepare("SELECT COUNT(*) FROM clicks WHERE affiliate_id = ? AND DATE(created_at) = CURDATE()");
$todayStmt->execute([$affiliateId]);
$todayClicks = (int)$todayStmt->fetchColumn();

// Unique Clicks
$uniqueStmt = $pdo->prepare("SELECT COUNT(DISTINCT INET6_NTOA(ip_address)) FROM clicks WHERE affiliate_id = ?");
$uniqueStmt->execute([$affiliateId]);
$uniqueClicks = (int)$uniqueStmt->fetchColumn();

/* -------------------------------------------------
   FETCH TOP PERFORMING CAMPAIGNS FOR THIS AFFILIATE
-------------------------------------------------- */
$topOffersStmt = $pdo->prepare("
    SELECT 
        o.offer_id,
        o.offer_name,
        o.category,
        o.payout,
        COUNT(DISTINCT c.click_id) AS clicks,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS total_earned
    FROM offers o
    INNER JOIN clicks c ON c.offer_id = o.offer_id AND c.affiliate_id = ?
    LEFT JOIN conversions cv ON cv.click_id = c.click_id AND cv.affiliate_id = ?
    GROUP BY o.offer_id
    ORDER BY total_earned DESC
    LIMIT 5
");
$topOffersStmt->execute([$affiliateId, $affiliateId]);
$topOffers = $topOffersStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   FETCH RECENT CLICK LOGS
-------------------------------------------------- */
$clickLogsStmt = $pdo->prepare("
    SELECT 
        c.click_id,
        o.offer_name,
        c.sub1,
        c.sub2,
        INET6_NTOA(c.ip_address) AS full_ip,
        c.country,
        c.device,
        c.created_at,
        (SELECT COUNT(*) FROM conversions cv WHERE cv.click_id = c.click_id AND cv.status = 'approved') AS is_converted
    FROM clicks c
    INNER JOIN offers o ON o.offer_id = c.offer_id
    WHERE c.affiliate_id = ?
    ORDER BY c.created_at DESC
    LIMIT 10
");
$clickLogsStmt->execute([$affiliateId]);
$recentClicks = $clickLogsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Publisher Dashboard | GVS Icon Media</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">

    <style>
        .card-custom {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 18px rgba(0,0,0,0.06);
            margin-bottom: 25px;
            background: #ffffff;
        }

        .hero-banner {
            background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%);
            border-radius: 12px;
            padding: 25px 30px;
            color: #ffffff;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(37, 99, 235, 0.2);
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
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
            </li>
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
                <i class="fas fa-rocket mr-2"></i><strong>Icon Media</strong>
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
                        <a href="clicks.php" class="nav-link">
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
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 font-weight-bold">Publisher Control Center</h1>
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

                <!-- Hero Banner -->
                <div class="hero-banner">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <h2 class="font-weight-bold mb-1"><i class="fas fa-chart-pie mr-2"></i>Welcome, <?php echo htmlspecialchars($affiliateName); ?>!</h2>
                            <p class="mb-0 text-white-50">Track live traffic, monitor real-time conversions, and build tracking URLs.</p>
                        </div>
                        <div class="mt-2 mt-md-0">
                            <a href="link-builder.php" class="btn btn-light font-weight-bold mr-1"><i class="fas fa-link mr-1"></i> Link Builder</a>
                            <a href="postback.php" class="btn btn-warning font-weight-bold"><i class="fas fa-code mr-1"></i> Global Postback</a>
                        </div>
                    </div>
                </div>

                <!-- 2x2 Mobile Stat Grid (4 Metrics) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($totalClicks); ?></div>
                            <div class="stat-label">Total Traffic Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($approvedConversions); ?></div>
                            <div class="stat-label">Approved Conversions</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo $cr; ?>%</div>
                            <div class="stat-label">CR %</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success">$<?php echo number_format($totalEarned, 2); ?></div>
                            <div class="stat-label">Total Earned Payout</div>
                        </div>
                    </div>
                </div>

                <!-- Top Campaigns & Recent Traffic Row -->
                <div class="row">
                    <!-- Top Campaigns -->
                    <div class="col-lg-6">
                        <div class="card card-custom p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h4 class="font-weight-bold text-primary mb-0"><i class="fas fa-trophy mr-2"></i>My Top Campaigns</h4>
                                <a href="offers.php" class="btn btn-sm btn-outline-primary">View All</a>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Campaign</th>
                                            <th>Clicks</th>
                                            <th>Conversions</th>
                                            <th>Earned</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($topOffers)): ?>
                                            <tr><td colspan="4" class="text-center text-muted py-3">No active campaign stats yet.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($topOffers as $to): ?>
                                            <tr>
                                                <td>
                                                    <a href="offer_view.php?id=<?php echo $to['offer_id']; ?>" class="text-primary font-weight-bold">
                                                        #<?php echo $to['offer_id']; ?> - <?php echo htmlspecialchars($to['offer_name']); ?>
                                                    </a>
                                                </td>
                                                <td><strong><?php echo number_format($to['clicks']); ?></strong></td>
                                                <td><strong class="text-success"><?php echo number_format($to['conversions']); ?></strong></td>
                                                <td><strong class="text-success">$<?php echo number_format($to['total_earned'], 2); ?></strong></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Traffic Click Logs -->
                    <div class="col-lg-6">
                        <div class="card card-custom p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h4 class="font-weight-bold text-primary mb-0"><i class="fas fa-mouse-pointer mr-2"></i>Recent Traffic Clicks</h4>
                                <a href="clicks.php" class="btn btn-sm btn-outline-primary">Full Click Logs</a>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Offer</th>
                                            <th>Country</th>
                                            <th>IP</th>
                                            <th>Time</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($recentClicks)): ?>
                                            <tr><td colspan="4" class="text-center text-muted py-3">No click logs recorded.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($recentClicks as $rc): ?>
                                            <tr>
                                                <td><strong class="text-dark"><?php echo htmlspecialchars($rc['offer_name']); ?></strong></td>
                                                <td><span class="badge badge-light"><?php echo htmlspecialchars($rc['country'] ?: 'Global'); ?></span></td>
                                                <td><small class="text-muted"><?php echo htmlspecialchars($rc['full_ip'] ?: 'N/A'); ?></small></td>
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
        <div class="float-right d-none d-sm-inline"><strong>Affiliate Portal v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
</body>
</html>