<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('advertiser');

$advertiserId = auth_user_id();
$advertiserName = $_SESSION['user_name'] ?? 'Advertiser';
$success = $error = null;

// Messages
if (isset($_GET['deleted'])) $success = 'Offer deleted successfully';
elseif (isset($_GET['created'])) $success = 'Offer created successfully';
elseif (isset($_GET['updated'])) $success = 'Offer updated successfully';

/* ===============================
   FETCH ALL OFFERS WITH STATS
================================ */
$sql = "
    SELECT 
        o.offer_id,
        o.offer_name,
        o.offer_description,
        o.payout,
        o.revenue,
        o.currency,
        o.status,
        o.visibility,
        o.category,
        o.campaign_url,
        o.created_at,
        
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS earned_revenue,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS paid_payout
    FROM offers o
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE o.advertiser_id = :aid
    GROUP BY o.offer_id
    ORDER BY o.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute(['aid' => $advertiserId]);
$offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totals
$summary = [
    'total_offers' => count($offers),
    'active' => 0,
    'total_clicks' => 0,
    'approved_conversions' => 0,
    'earned_revenue' => 0.00
];

foreach ($offers as $of) {
    if ($of['status'] === 'active' || $of['status'] === 'approved') $summary['active']++;
    $summary['total_clicks'] += (int)$of['total_clicks'];
    $summary['approved_conversions'] += (int)$of['approved_conversions'];
    $summary['earned_revenue'] += (float)$of['earned_revenue'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Campaigns & Offers | Advertiser Panel</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
            --accent-color: #4f46e5;
        }

        .card-custom {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 18px rgba(0,0,0,0.06);
            margin-bottom: 25px;
            background: #ffffff;
        }

        .stat-card-custom {
            border-radius: 12px;
            background: #ffffff;
            padding: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            text-align: center;
        }

        .stat-card-custom .stat-number {
            font-size: 26px;
            font-weight: 800;
            color: #1e293b;
        }

        .stat-card-custom .stat-label {
            font-size: 12px;
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
            <li class="nav-item d-none d-sm-inline-block">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="offers.php" class="nav-link active">My Campaigns</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <a class="nav-link" href="#" id="darkModeToggle"><i class="fas fa-moon"></i></a>
            </li>
        </ul>
    </nav>

    <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.5rem;">
                <i class="fas fa-chart-line mr-2"></i><strong>Advertiser</strong>
            </span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link"><i class="nav-icon fas fa-tachometer-alt"></i><p>Dashboard</p></a>
                    </li>
                    <li class="nav-header">CAMPAIGNS</li>
                    <li class="nav-item">
                        <a href="campaigns.php" class="nav-link"><i class="nav-icon fas fa-bullhorn"></i><p>Manage Campaigns</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="offers.php" class="nav-link active"><i class="nav-icon fas fa-gift"></i><p>All Offers</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="create_offer.php" class="nav-link"><i class="nav-icon fas fa-plus-circle"></i><p>Create New Offer</p></a>
                    </li>
                    <li class="nav-header">REPORTS & ANALYTICS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link"><i class="nav-icon fas fa-chart-bar"></i><p>Campaign Reports</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link"><i class="fas fa-exchange-alt nav-icon"></i><p>Conversion Reports</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link"><i class="nav-icon fas fa-users"></i><p>Affiliate Reports</p></a>
                    </li>
                    <li class="nav-header">TOOLS</li>
                    <li class="nav-item">
                        <a href="ip_whitelist.php" class="nav-link"><i class="nav-icon fas fa-tower-broadcast"></i><p>IP Whitelist</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="postback.php" class="nav-link"><i class="nav-icon fas fa-code"></i><p>Postback Manager</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="api.php" class="nav-link"><i class="nav-icon fas fa-plug"></i><p>API Integration</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="optimization.php" class="nav-link"><i class="nav-icon fas fa-rocket"></i><p>Optimization Tools</p></a>
                    </li>
                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link"><i class="nav-icon fas fa-user"></i><p>Profile</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="billing.php" class="nav-link"><i class="nav-icon fas fa-wallet"></i><p>Billing & Payments</p></a>
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
                        <h1 class="m-0 font-weight-bold">My Campaigns & Offers Directory</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">My Campaigns</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">

                <!-- Alert Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
                <?php endif; ?>

                <!-- Summary Stat Cards (2x2 Mobile Grid) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($summary['total_offers']); ?></div>
                            <div class="stat-label">Total Campaigns</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($summary['active']); ?></div>
                            <div class="stat-label">Active / Live</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($summary['total_clicks']); ?></div>
                            <div class="stat-label">Total Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning">$<?php echo number_format($summary['earned_revenue'], 2); ?></div>
                            <div class="stat-label">Total Revenue</div>
                        </div>
                    </div>
                </div>

                <!-- Offers Catalog Table -->
                <div class="card card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="font-weight-bold text-primary mb-0"><i class="fas fa-gift mr-2"></i>Campaign Offers Catalog</h4>
                        <a href="create_offer.php" class="btn btn-primary font-weight-bold shadow-sm">
                            <i class="fas fa-plus-circle mr-1"></i> Create New Campaign
                        </a>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="advertiserOffersTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>ID & Campaign Title</th>
                                    <th>Category</th>
                                    <th>Payout / Revenue</th>
                                    <th>Status</th>
                                    <th>Clicks</th>
                                    <th>Conversions</th>
                                    <th>Earned Revenue</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($offers as $of): 
                                    $clicks = (int)$of['total_clicks'];
                                    $convs = (int)$of['approved_conversions'];
                                    $rev = (float)$of['earned_revenue'];
                                ?>
                                <tr>
                                    <td>
                                        <a href="offer_details.php?id=<?php echo $of['offer_id']; ?>" class="text-primary font-weight-bold d-block text-decoration-none">
                                            #<?php echo $of['offer_id']; ?> - <?php echo htmlspecialchars($of['offer_name']); ?>
                                        </a>
                                        <small class="text-muted"><?php echo htmlspecialchars(substr($of['offer_description'] ?? '', 0, 45)); ?>...</small>
                                    </td>
                                    <td><span class="badge badge-info p-2"><?php echo htmlspecialchars($of['category'] ?: 'General'); ?></span></td>
                                    <td>
                                        <strong class="text-success">$<?php echo number_format($of['payout'], 2); ?></strong>
                                        <small class="d-block text-muted">Rev: $<?php echo number_format($of['revenue'], 2); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo ($of['status'] === 'active' || $of['status'] === 'approved') ? 'success' : 'secondary'; ?> p-2">
                                            <?php echo ucfirst($of['status']); ?>
                                        </span>
                                    </td>
                                    <td><strong><?php echo number_format($clicks); ?></strong></td>
                                    <td><strong><?php echo number_format($convs); ?></strong></td>
                                    <td><strong class="text-success font-weight-bold">$<?php echo number_format($rev, 2); ?></strong></td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="offer_details.php?id=<?php echo $of['offer_id']; ?>" class="btn btn-sm btn-outline-info" title="View Specs"><i class="fas fa-eye"></i></a>
                                            <a href="offer_stats.php?id=<?php echo $of['offer_id']; ?>" class="btn btn-sm btn-outline-success" title="View Analytics"><i class="fas fa-chart-bar"></i></a>
                                            <a href="offer_edit.php?id=<?php echo $of['offer_id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit Campaign"><i class="fas fa-edit"></i></a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline"><strong>Advertiser Panel v3.0</strong></div>
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
    $('#advertiserOffersTable').DataTable({
        pageLength: 10,
        responsive: true,
        order: [[6, 'desc']]
    });
});
</script>
</body>
</html>