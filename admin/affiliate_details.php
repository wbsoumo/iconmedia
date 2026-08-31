<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['admin', 'manager']);

$adminName = $_SESSION['user_name'] ?? 'Admin';
$affiliateId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$affiliateId) {
    header('Location: publishers.php?error=Invalid publisher ID');
    exit;
}

/* ===============================
   FETCH PUBLISHER DETAILS
================================ */
$stmt = $pdo->prepare("
    SELECT 
        u.*,
        am.name AS account_manager_name,
        am.email AS account_manager_email,
        ap.postback_url,
        ap.status as postback_status,
        ap.postback_type,
        ap.name as postback_name
    FROM users u
    LEFT JOIN users am ON am.user_id = u.account_manager_id
    LEFT JOIN affiliate_postbacks ap ON ap.affiliate_id = u.user_id
    WHERE u.user_id = :id AND u.role_id = 3
");
$stmt->execute(['id' => $affiliateId]);
$publisher = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$publisher) {
    header('Location: publishers.php?error=Publisher profile not found');
    exit;
}

/* ===============================
   FETCH OVERALL PERFORMANCE STATS
================================ */
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        SUM(CASE WHEN cv.status = 'pending' THEN 1 ELSE 0 END) AS pending_conversions,
        SUM(CASE WHEN cv.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS total_payout,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS gross_revenue
    FROM clicks c
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    WHERE c.affiliate_id = ?
");
$statsStmt->execute([$affiliateId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$clicks = (int)($stats['total_clicks'] ?? 0);
$approved = (int)($stats['approved_conversions'] ?? 0);
$cr = $clicks > 0 ? number_format(($approved / $clicks) * 100, 2) : '0.00';
$earnedPayout = (float)($stats['total_payout'] ?? 0);
$grossRevenue = (float)($stats['gross_revenue'] ?? 0);
$netMargin = $grossRevenue - $earnedPayout;

/* ===============================
   FETCH CAMPAIGNS PROMOTED BY PUBLISHER
================================ */
$offersStmt = $pdo->prepare("
    SELECT 
        o.offer_id,
        o.offer_name,
        o.category,
        o.payout,
        COUNT(DISTINCT c.click_id) as clicks,
        COUNT(DISTINCT cv.conversion_id) as conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) as approved_leads,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) as total_earned
    FROM clicks c
    INNER JOIN offers o ON o.offer_id = c.offer_id
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    WHERE c.affiliate_id = ?
    GROUP BY o.offer_id
    ORDER BY total_earned DESC
");
$offersStmt->execute([$affiliateId]);
$promotedOffers = $offersStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Publisher Profile & Analytics | Admin Panel</title>
    
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
            background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
            border-radius: 12px;
            padding: 30px;
            color: #ffffff;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(79, 70, 229, 0.2);
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
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link">Dashboard</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="publishers.php" class="nav-link">Publishers</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="affiliate_details.php?id=<?php echo $affiliateId; ?>" class="nav-link active">Publisher Details</a></li>
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
                        <a href="publishers.php" class="nav-link active">
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
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 font-weight-bold">Publisher Profile & Analytics</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="publishers.php">Publishers</a></li>
                            <li class="breadcrumb-item active">Publisher #<?php echo $affiliateId; ?></li>
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
                    <div class="d-flex justify-content-between align-items-start flex-wrap">
                        <div class="mb-3">
                            <span class="badge badge-light text-primary font-weight-bold p-2 mb-2">
                                <i class="fas fa-id-badge mr-1"></i> Publisher ID: #<?php echo $publisher['user_id']; ?>
                            </span>
                            <h1 class="font-weight-bold mb-1"><?php echo htmlspecialchars($publisher['name']); ?></h1>
                            <p class="mb-2 text-white-50"><i class="fas fa-envelope mr-1"></i> <?php echo htmlspecialchars($publisher['email']); ?> | <i class="fas fa-building mr-1"></i> <?php echo htmlspecialchars($publisher['company'] ?: 'Individual Publisher'); ?></p>
                            <div>
                                <span class="badge badge-<?php echo $publisher['status'] === 'active' ? 'success' : 'warning'; ?> p-2 mr-2">Status: <?php echo ucfirst($publisher['status']); ?></span>
                                <span class="badge badge-info p-2">Joined: <?php echo date('M d, Y', strtotime($publisher['created_at'])); ?></span>
                            </div>
                        </div>
                        <div>
                            <a href="publishers.php" class="btn btn-light font-weight-bold mr-1"><i class="fas fa-arrow-left mr-1"></i> Back</a>
                            <a href="publisher_postbacks.php?search=<?php echo urlencode($publisher['email']); ?>" class="btn btn-warning font-weight-bold"><i class="fas fa-link mr-1"></i> Postback Config</a>
                        </div>
                    </div>
                </div>

                <!-- Summary Stat Cards (2x2 Mobile Responsive Grid) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($clicks); ?></div>
                            <div class="stat-label">Total Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($approved); ?></div>
                            <div class="stat-label">Approved Leads</div>
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
                            <div class="stat-number text-success">$<?php echo number_format($earnedPayout, 2); ?></div>
                            <div class="stat-label">Earned Payout</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Publisher Account Info Left -->
                    <div class="col-md-4">
                        <div class="card card-custom p-4">
                            <h5 class="font-weight-bold text-primary mb-3"><i class="fas fa-user-circle mr-2"></i>Account Metadata</h5>
                            <table class="table table-borderless align-middle mb-0">
                                <tr>
                                    <th class="pl-0 text-muted">Phone:</th>
                                    <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($publisher['mobile'] ?: 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <th class="pl-0 text-muted">Account Manager:</th>
                                    <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($publisher['account_manager_name'] ?: 'Unassigned'); ?></td>
                                </tr>
                                <tr>
                                    <th class="pl-0 text-muted">Global Postback:</th>
                                    <td>
                                        <?php if (!empty($publisher['postback_url'])): ?>
                                            <span class="badge badge-success p-2">Configured</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary p-2">Not Configured</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th class="pl-0 text-muted">Current Balance:</th>
                                    <td class="font-weight-bold text-success h5 mb-0">$<?php echo number_format($publisher['balance'] ?? 0, 2); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Promoted Offers Directory Right -->
                    <div class="col-md-8">
                        <div class="card card-custom p-4">
                            <h5 class="font-weight-bold text-primary mb-3"><i class="fas fa-bullhorn mr-2"></i>Campaigns Promoted & Earned</h5>
                            
                            <div class="table-responsive">
                                <table class="table table-hover align-middle" id="publisherOffersTable">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Campaign Offer</th>
                                            <th>Clicks</th>
                                            <th>Approved</th>
                                            <th>Earned Payout</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($promotedOffers as $po): ?>
                                        <tr>
                                            <td>
                                                <a href="offer_details.php?id=<?php echo $po['offer_id']; ?>" class="text-primary font-weight-bold">
                                                    #<?php echo $po['offer_id']; ?> - <?php echo htmlspecialchars($po['offer_name']); ?>
                                                </a>
                                                <small class="text-muted d-block">Category: <?php echo htmlspecialchars($po['category'] ?: 'General'); ?></small>
                                            </td>
                                            <td><strong><?php echo number_format($po['clicks']); ?></strong></td>
                                            <td><strong class="text-success"><?php echo number_format($po['approved_leads']); ?></strong></td>
                                            <td><strong class="text-success font-weight-bold">$<?php echo number_format($po['total_earned'], 2); ?></strong></td>
                                        </tr>
                                        <?php endforeach; ?>
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
        <div class="float-right d-none d-sm-inline"><strong>Admin Panel v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<script>
$(document).ready(function() {
    $('#publisherOffersTable').DataTable({
        pageLength: 10,
        responsive: true
    });
});
</script>
</body>
</html>
