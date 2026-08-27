<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('advertiser');

$advertiserId   = auth_user_id();
$advertiserName = $_SESSION['user_name'] ?? 'Advertiser';
$success = $error = null;

/* ===============================
   HANDLE STATUS TOGGLE / SINGLE ACTIONS
================================ */
if (!empty($_GET['action']) && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $action = $_GET['action'];
    
    switch ($action) {
        case 'activate':
            $pdo->exec("UPDATE offers SET status='active', updated_at=NOW() WHERE offer_id = $id AND advertiser_id = " . (int)$advertiserId);
            $success = 'Campaign #' . $id . ' activated successfully.';
            break;
        case 'pause':
            $pdo->exec("UPDATE offers SET status='paused', updated_at=NOW() WHERE offer_id = $id AND advertiser_id = " . (int)$advertiserId);
            $success = 'Campaign #' . $id . ' paused successfully.';
            break;
        case 'archive':
            $pdo->exec("UPDATE offers SET status='archived', updated_at=NOW() WHERE offer_id = $id AND advertiser_id = " . (int)$advertiserId);
            $success = 'Campaign #' . $id . ' archived successfully.';
            break;
    }
}

/* ===============================
   HANDLE BULK ACTIONS
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $ids = $_POST['selected_campaigns'] ?? [];
    
    if (empty($ids)) {
        $error = 'No campaigns selected for bulk action.';
    } else {
        $idList = implode(',', array_map('intval', $ids));
        if ($action === 'activate') {
            $pdo->exec("UPDATE offers SET status='active', updated_at=NOW() WHERE offer_id IN ($idList) AND advertiser_id = " . (int)$advertiserId);
            $success = 'Selected campaigns activated.';
        } elseif ($action === 'pause') {
            $pdo->exec("UPDATE offers SET status='paused', updated_at=NOW() WHERE offer_id IN ($idList) AND advertiser_id = " . (int)$advertiserId);
            $success = 'Selected campaigns paused.';
        } elseif ($action === 'archive') {
            $pdo->exec("UPDATE offers SET status='archived', updated_at=NOW() WHERE offer_id IN ($idList) AND advertiser_id = " . (int)$advertiserId);
            $success = 'Selected campaigns archived.';
        }
    }
}

/* ===============================
   FETCH ALL ADVERTISER CAMPAIGNS & STATS
================================ */
$statusFilter = $_GET['status'] ?? '';
$categoryFilter = $_GET['category'] ?? '';

$sql = "
    SELECT 
        o.*,
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        IFNULL(SUM(cv.revenue), 0) AS total_revenue
    FROM offers o
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id AND cv.status = 'approved'
    WHERE o.advertiser_id = :aid
";

$params = ['aid' => $advertiserId];

if ($statusFilter && $statusFilter !== 'all') {
    $sql .= " AND o.status = :status";
    $params['status'] = $statusFilter;
}
if ($categoryFilter && $categoryFilter !== 'all') {
    $sql .= " AND o.category = :category";
    $params['category'] = $categoryFilter;
}

$sql .= " GROUP BY o.offer_id ORDER BY o.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Dashboard Totals
$dashboardStats = [
    'total_campaigns' => count($campaigns),
    'active_campaigns' => 0,
    'total_clicks' => 0,
    'total_conversions' => 0,
    'total_revenue' => 0.00
];

foreach ($campaigns as $c) {
    if ($c['status'] === 'active') $dashboardStats['active_campaigns']++;
    $dashboardStats['total_clicks'] += $c['total_clicks'];
    $dashboardStats['total_conversions'] += $c['total_conversions'];
    $dashboardStats['total_revenue'] += $c['total_revenue'];
}

// Categories list
$catStmt = $pdo->prepare("SELECT DISTINCT category FROM offers WHERE advertiser_id = :aid AND category IS NOT NULL AND category != ''");
$catStmt->execute(['aid' => $advertiserId]);
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Campaign Management Hub | Advertiser Panel</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
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
                <a href="campaigns.php" class="nav-link active">Manage Campaigns</a>
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
                        <a href="campaigns.php" class="nav-link active"><i class="nav-icon fas fa-bullhorn"></i><p>Manage Campaigns</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="offers.php" class="nav-link"><i class="nav-icon fas fa-gift"></i><p>All Offers</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="create_offer.php" class="nav-link"><i class="nav-icon fas fa-plus-circle"></i><p>Create New Offer</p></a>
                    </li>
                    <li class="nav-header">REPORTS & ANALYTICS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link"><i class="nav-icon fas fa-chart-bar"></i><p>Campaign Reports</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_conversions.php" class="nav-link"><i class="fas fa-exchange-alt nav-icon"></i><p>Conversion Reports</p></a>
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
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                    <h5><i class="icon fas fa-check-circle"></i> Success!</h5>
                    <p class="mb-0"><?php echo $success; ?></p>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <h5><i class="icon fas fa-exclamation-triangle"></i> Action Required</h5>
                    <p class="mb-0"><?php echo $error; ?></p>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
                <?php endif; ?>

                <!-- Summary Stat Cards Row (2x2 Mobile Responsive Grid) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($dashboardStats['total_campaigns']); ?></div>
                            <div class="stat-label">Total Campaigns</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($dashboardStats['active_campaigns']); ?></div>
                            <div class="stat-label">Active Campaigns</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($dashboardStats['total_clicks']); ?></div>
                            <div class="stat-label">Total Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning">$<?php echo number_format($dashboardStats['total_revenue'], 2); ?></div>
                            <div class="stat-label">Total Revenue</div>
                        </div>
                    </div>
                </div>

                <!-- Campaigns List & Actions Card -->
                <div class="card card-custom p-4">
                    <form method="post" id="campaignsBulkForm">
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                            <h4 class="font-weight-bold text-primary mb-0"><i class="fas fa-bullhorn mr-2"></i>All Advertiser Campaigns</h4>
                            
                            <div class="d-flex align-items-center gap-2">
                                <a href="create_offer.php" class="btn btn-success font-weight-bold mr-3 shadow-sm">
                                    <i class="fas fa-plus-circle mr-1"></i> Create New Campaign
                                </a>
                                <select name="bulk_action" class="form-control mr-2" style="width: auto;">
                                    <option value="">Bulk Actions...</option>
                                    <option value="activate">Activate Selected</option>
                                    <option value="pause">Pause Selected</option>
                                    <option value="archive">Archive Selected</option>
                                </select>
                                <button type="submit" class="btn btn-outline-secondary font-weight-bold">Apply</button>
                            </div>
                        </div>

                        <?php if (empty($campaigns)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-bullhorn fa-3x text-muted mb-3"></i>
                            <h5 class="text-dark font-weight-bold">No Campaigns Created Yet</h5>
                            <p class="text-muted mb-3">Launch your first performance affiliate campaign in under 2 minutes.</p>
                            <a href="create_offer.php" class="btn btn-primary font-weight-bold"><i class="fas fa-plus mr-1"></i>Create Campaign Wizard</a>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="campaignsDataTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width: 30px;"><input type="checkbox" id="selectAll"></th>
                                        <th>ID & Campaign Title</th>
                                        <th>Objective & Category</th>
                                        <th>Status</th>
                                        <th>Payout / Revenue</th>
                                        <th>Clicks</th>
                                        <th>Conversions (CR)</th>
                                        <th>Total Revenue</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($campaigns as $camp): 
                                        $clicks = (int)$camp['total_clicks'];
                                        $convs = (int)$camp['total_conversions'];
                                        $cr = $clicks > 0 ? round(($convs / $clicks) * 100, 1) : 0.0;
                                    ?>
                                    <tr>
                                        <td><input type="checkbox" name="selected_campaigns[]" value="<?php echo $camp['offer_id']; ?>" class="camp-select-cb"></td>
                                        <td>
                                            <a href="offer_details.php?id=<?php echo $camp['offer_id']; ?>" class="text-primary font-weight-bold d-block text-decoration-none">
                                                #<?php echo $camp['offer_id']; ?> - <?php echo htmlspecialchars($camp['offer_name']); ?>
                                            </a>
                                            <small class="text-muted">Target: <?php echo htmlspecialchars(substr($camp['campaign_url'], 0, 45)); ?>...</small>
                                        </td>
                                        <td>
                                            <span class="badge badge-info p-1 px-2"><?php echo strtoupper($camp['objective'] ?? 'CPA'); ?></span>
                                            <small class="d-block text-muted"><?php echo htmlspecialchars($camp['category'] ?: 'General'); ?></small>
                                        </td>
                                        <td>
                                            <?php 
                                            $stClass = 'secondary';
                                            if ($camp['status'] === 'active') $stClass = 'success';
                                            elseif ($camp['status'] === 'pending') $stClass = 'warning';
                                            elseif ($camp['status'] === 'paused') $stClass = 'danger';
                                            ?>
                                            <span class="badge badge-<?php echo $stClass; ?> p-2"><?php echo ucfirst($camp['status']); ?></span>
                                        </td>
                                        <td>
                                            <strong class="text-primary">$<?php echo number_format($camp['payout'], 2); ?></strong>
                                            <small class="d-block text-muted">Rev: $<?php echo number_format($camp['revenue'], 2); ?></small>
                                        </td>
                                        <td><strong><?php echo number_format($clicks); ?></strong></td>
                                        <td>
                                            <strong class="text-success"><?php echo number_format($convs); ?></strong>
                                            <small class="d-block text-muted"><?php echo $cr; ?>% CR</small>
                                        </td>
                                        <td><strong class="text-dark font-weight-bold">$<?php echo number_format($camp['total_revenue'], 2); ?></strong></td>
                                        <td>
                                            <div class="btn-group">
                                                <?php if ($camp['status'] === 'active'): ?>
                                                    <a href="?action=pause&id=<?php echo $camp['offer_id']; ?>" class="btn btn-sm btn-outline-warning" title="Pause Campaign"><i class="fas fa-pause"></i></a>
                                                <?php else: ?>
                                                    <a href="?action=activate&id=<?php echo $camp['offer_id']; ?>" class="btn btn-sm btn-outline-success" title="Activate Campaign"><i class="fas fa-play"></i></a>
                                                <?php endif; ?>
                                                <a href="offer_edit.php?id=<?php echo $camp['offer_id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit Offer"><i class="fas fa-edit"></i></a>
                                                <a href="?action=archive&id=<?php echo $camp['offer_id']; ?>" class="btn btn-sm btn-outline-danger" title="Archive" onclick="return confirm('Archive this campaign?')"><i class="fas fa-archive"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </form>
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
    $('#campaignsDataTable').DataTable({
        pageLength: 10,
        responsive: true,
        order: [[1, 'desc']]
    });

    $('#selectAll').on('change', function() {
        $('.camp-select-cb').prop('checked', $(this).prop('checked'));
    });
});
</script>
</body>
</html>