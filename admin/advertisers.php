<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

/* ===============================
   HANDLE STATUS UPDATES
================================ */
if (!empty($_GET['action']) && !empty($_GET['id'])) {
    $advId = (int)$_GET['id'];
    $act = $_GET['action'];
    if ($act === 'approve' || $act === 'activate') {
        $pdo->exec("UPDATE users SET status='active', updated_at=NOW() WHERE user_id=$advId AND role_id=4");
        $success = "Advertiser account #$advId activated successfully.";
    } elseif ($act === 'block' || $act === 'deactivate') {
        $pdo->exec("UPDATE users SET status='blocked', updated_at=NOW() WHERE user_id=$advId AND role_id=4");
        $success = "Advertiser account #$advId blocked.";
    }
}

/* ===============================
   FETCH ALL ADVERTISERS & METRICS
================================ */
$sql = "
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.mobile,
        u.company,
        u.balance,
        u.status,
        u.created_at,
        COUNT(DISTINCT o.offer_id) AS total_offers,
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS total_revenue
    FROM users u
    LEFT JOIN offers o ON o.advertiser_id = u.user_id
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE u.role_id = 4
    GROUP BY u.user_id
    ORDER BY u.created_at DESC
";

$advertisers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Totals
$stats = [
    'total' => count($advertisers),
    'active' => 0,
    'offers' => 0,
    'revenue' => 0.00
];

foreach ($advertisers as $adv) {
    if ($adv['status'] === 'active') $stats['active']++;
    $stats['offers'] += (int)$adv['total_offers'];
    $stats['revenue'] += (float)$adv['total_revenue'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Advertisers | Admin Panel</title>
    
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
            <li class="nav-item d-none d-sm-inline-block"><a href="advertisers.php" class="nav-link active">Advertisers</a></li>
        </ul>
    </nav>

    <!-- Sidebar -->
        <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.4rem;">
                <i class="fas fa-crown mr-2"></i><strong>Admin Console</strong>
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

                    <li class="nav-header">CAMPAIGNS & OFFERS</li>
                    <li class="nav-item">
                        <a href="campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-bullhorn"></i>
                            <p>Manage Campaigns</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="create_campaign.php" class="nav-link">
                            <i class="nav-icon fas fa-plus-circle"></i>
                            <p>Create Campaign</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="campaign_access.php" class="nav-link">
                            <i class="nav-icon fas fa-key"></i>
                            <p>Offer Approval Rules</p>
                        </a>
                    </li>

                    <li class="nav-header">USER MANAGEMENT</li>
                    <li class="nav-item">
                        <a href="users.php" class="nav-link">
                            <i class="nav-icon fas fa-users"></i>
                            <p>All System Users</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="publishers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-friends"></i>
                            <p>Publishers / Affiliates</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="advertisers.php" class="nav-link active">
                            <i class="nav-icon fas fa-briefcase"></i>
                            <p>Advertisers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="account_managers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-tie"></i>
                            <p>Account Managers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="pending_kyc.php" class="nav-link">
                            <i class="nav-icon fas fa-id-card"></i>
                            <p>Pending KYC Approvals</p>
                        </a>
                    </li>

                    <li class="nav-header">ANALYTICS & REPORTS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Campaign Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <p>Affiliate Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_advertisers.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-pie"></i>
                            <p>Advertiser Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_subid.php" class="nav-link">
                            <i class="nav-icon fas fa-list"></i>
                            <p>SubID Performance</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="fraud_dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-shield-alt"></i>
                            <p>Anti-Fraud Security</p>
                        </a>
                    </li>

                                        <li class="nav-header">TOOLS & TESTING</li>
                    <li class="nav-item">
                        <a href="link_tester.php" class="nav-link">
                            <i class="nav-icon fas fa-vial"></i>
                            <p>Link Tester</p>
                        </a>
                    </li>

                    <li class="nav-header">SYSTEM & POSTBACKS</li>
                    <li class="nav-item">
                        <a href="publisher_postbacks.php" class="nav-link">
                            <i class="nav-icon fas fa-code"></i>
                            <p>Global Postbacks Log</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="settings.php" class="nav-link">
                            <i class="nav-icon fas fa-cogs"></i>
                            <p>System Settings</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user-cog"></i>
                            <p>My Profile</p>
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
                        <h1 class="m-0 font-weight-bold">Advertiser Management Hub</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Advertisers</li>
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
                            <div class="stat-number text-primary"><?php echo number_format($stats['total']); ?></div>
                            <div class="stat-label">Total Advertisers</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($stats['active']); ?></div>
                            <div class="stat-label">Active Brands</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($stats['offers']); ?></div>
                            <div class="stat-label">Live Campaigns</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success">$<?php echo number_format($stats['revenue'], 2); ?></div>
                            <div class="stat-label">Total Gross Revenue</div>
                        </div>
                    </div>
                </div>

                <!-- Advertisers DataTables Directory -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-briefcase mr-2"></i>Advertiser Accounts Directory</h4>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="advertisersTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>Advertiser & Company</th>
                                    <th>Contact</th>
                                    <th>Campaigns</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th>Date Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($advertisers as $adv): ?>
                                <tr>
                                    <td>
                                        <a href="advertiser_edit.php?id=<?php echo $adv['user_id']; ?>" class="text-primary font-weight-bold">
                                            <?php echo htmlspecialchars($adv['name']); ?>
                                        </a>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($adv['company'] ?: 'Individual Brand'); ?></small>
                                    </td>
                                    <td>
                                        <small class="d-block text-dark"><?php echo htmlspecialchars($adv['email']); ?></small>
                                        <small class="text-muted"><?php echo htmlspecialchars($adv['mobile'] ?: 'N/A'); ?></small>
                                    </td>
                                    <td><strong class="text-info"><?php echo number_format($adv['total_offers']); ?></strong></td>
                                    <td><strong class="text-success">$<?php echo number_format($adv['balance'] ?? 0, 2); ?></strong></td>
                                    <td>
                                        <span class="badge badge-<?php echo $adv['status'] === 'active' ? 'success' : 'secondary'; ?> p-2">
                                            <?php echo ucfirst($adv['status']); ?>
                                        </span>
                                    </td>
                                    <td><small class="text-muted"><?php echo date('Y-m-d', strtotime($adv['created_at'])); ?></small></td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="advertiser_edit.php?id=<?php echo $adv['user_id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit Profile"><i class="fas fa-edit"></i></a>
                                            <?php if ($adv['status'] === 'active'): ?>
                                                <a href="?action=block&id=<?php echo $adv['user_id']; ?>" class="btn btn-sm btn-outline-danger" title="Block"><i class="fas fa-ban"></i></a>
                                            <?php else: ?>
                                                <a href="?action=activate&id=<?php echo $adv['user_id']; ?>" class="btn btn-sm btn-outline-success" title="Activate"><i class="fas fa-check"></i></a>
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
    $('#advertisersTable').DataTable({
        pageLength: 10,
        responsive: true,
        order: [[0, 'asc']]
    });
});
</script>
</body>
</html>