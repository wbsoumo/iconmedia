<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['admin', 'manager']);

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

/* ===============================
   HANDLE SINGLE & BULK ACTIONS
================================ */
if (!empty($_GET['action']) && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $act = $_GET['action'];
    if ($act === 'activate') {
        $pdo->exec("UPDATE offers SET status='active', updated_at=NOW() WHERE offer_id=$id");
        $success = "Campaign #$id activated successfully.";
    } elseif ($act === 'pause') {
        $pdo->exec("UPDATE offers SET status='paused', updated_at=NOW() WHERE offer_id=$id");
        $success = "Campaign #$id paused successfully.";
    } elseif ($act === 'archive') {
        $pdo->exec("UPDATE offers SET status='archived', updated_at=NOW() WHERE offer_id=$id");
        $success = "Campaign #$id archived.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $ids = $_POST['selected_offers'] ?? [];
    if (!empty($ids)) {
        $idList = implode(',', array_map('intval', $ids));
        if ($action === 'activate') {
            $pdo->exec("UPDATE offers SET status='active', updated_at=NOW() WHERE offer_id IN ($idList)");
            $success = "Selected campaigns activated.";
        } elseif ($action === 'pause') {
            $pdo->exec("UPDATE offers SET status='paused', updated_at=NOW() WHERE offer_id IN ($idList)");
            $success = "Selected campaigns paused.";
        }
    }
}

/* ===============================
   FETCH ALL CAMPAIGNS & STATS
================================ */
$sql = "
    SELECT 
        o.offer_id,
        o.offer_name,
        o.payout,
        o.revenue,
        o.status,
        o.category,
        o.campaign_url,
        o.created_at,
        u.name AS advertiser_name,
        u.email AS advertiser_email,
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS earned_revenue,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS paid_payout
    FROM offers o
    LEFT JOIN users u ON u.user_id = o.advertiser_id
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    GROUP BY o.offer_id
    ORDER BY o.created_at DESC
";

$offers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Totals
$summary = [
    'total' => count($offers),
    'active' => 0,
    'clicks' => 0,
    'conversions' => 0,
    'revenue' => 0.00
];

foreach ($offers as $of) {
    if ($of['status'] === 'active' || $of['status'] === 'approved') $summary['active']++;
    $summary['clicks'] += (int)$of['total_clicks'];
    $summary['conversions'] += (int)$of['approved_conversions'];
    $summary['revenue'] += (float)$of['earned_revenue'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Campaigns | Admin Panel</title>
    
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
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link">Dashboard</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="campaigns.php" class="nav-link active">Manage Campaigns</a></li>
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
                        <h1 class="m-0 font-weight-bold">Campaign Management Hub</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Campaigns</li>
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

                <!-- Summary Stat Cards (2x2 Mobile Responsive Grid) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($summary['total']); ?></div>
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
                            <div class="stat-number text-info"><?php echo number_format($summary['clicks']); ?></div>
                            <div class="stat-label">Total Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning">$<?php echo number_format($summary['revenue'], 2); ?></div>
                            <div class="stat-label">Total Revenue</div>
                        </div>
                    </div>
                </div>

                <!-- Campaigns Catalog Table -->
                <div class="card card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="font-weight-bold text-primary mb-0"><i class="fas fa-bullhorn mr-2"></i>Network Campaigns Catalog</h4>
                        <a href="create_campaign.php" class="btn btn-success font-weight-bold shadow-sm">
                            <i class="fas fa-plus-circle mr-1"></i> Create New Campaign
                        </a>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="adminCampaignsTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>ID & Campaign Title</th>
                                    <th>Advertiser</th>
                                    <th>Payout / Revenue</th>
                                    <th>Status</th>
                                    <th>Clicks</th>
                                    <th>Conversions</th>
                                    <th>Total Revenue</th>
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
                                        <small class="text-muted">Category: <?php echo htmlspecialchars($of['category'] ?: 'General'); ?></small>
                                    </td>
                                    <td>
                                        <strong class="text-dark"><?php echo htmlspecialchars($of['advertiser_name'] ?: 'N/A'); ?></strong>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($of['advertiser_email'] ?: ''); ?></small>
                                    </td>
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
                                            <a href="offer_edit.php?id=<?php echo $of['offer_id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit Campaign"><i class="fas fa-edit"></i></a>
                                            <?php if ($of['status'] === 'active'): ?>
                                                <a href="?action=pause&id=<?php echo $of['offer_id']; ?>" class="btn btn-sm btn-outline-warning" title="Pause"><i class="fas fa-pause"></i></a>
                                            <?php else: ?>
                                                <a href="?action=activate&id=<?php echo $of['offer_id']; ?>" class="btn btn-sm btn-outline-success" title="Activate"><i class="fas fa-play"></i></a>
                                            <?php endif; ?>
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
    $('#adminCampaignsTable').DataTable({
        pageLength: 10,
        responsive: true,
        order: [[0, 'desc']]
    });
});
</script>
</body>
</html>