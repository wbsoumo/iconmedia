<?php
define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['admin', 'manager']);

$adminName = $_SESSION['user_name'] ?? 'Admin';
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');

$where = "WHERE cl.sub1 IS NOT NULL AND cl.sub1 != ''";
$params = [];

if ($from && $to) {
    $where .= " AND DATE(cl.created_at) BETWEEN :from AND :to";
    $params['from'] = $from;
    $params['to']   = $to;
}

$sql = "
SELECT
    cl.sub1,
    COUNT(cl.click_id) AS clicks,
    COUNT(c.conversion_id) AS conversions,
    SUM(CASE WHEN c.status='approved' THEN c.payout ELSE 0 END) AS payout
FROM clicks cl
LEFT JOIN conversions c ON c.click_id = cl.click_id
{$where}
GROUP BY cl.sub1
ORDER BY payout DESC
LIMIT 100
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totals
$totalClicks = 0;
$totalConversions = 0;
$totalPayout = 0;
foreach ($rows as $r) {
    $totalClicks += (int)$r['clicks'];
    $totalConversions += (int)$r['conversions'];
    $totalPayout += (float)$r['payout'];
}
$avgCr = $totalClicks > 0 ? ($totalConversions / $totalClicks) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SubID Performance Report | Admin Panel</title>
    
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
            <li class="nav-item d-none d-sm-inline-block"><a href="#" class="nav-link active">SubID Performance Report</a></li>
        </ul>
    </nav>

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
                        <a href="advertisers.php" class="nav-link">
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
                        <a href="reports_subid.php" class="nav-link active">
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
                        <h1 class="m-0 font-weight-bold"><i class="fas fa-tags text-indigo mr-2"></i>SubID Performance Report</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="reports_campaigns.php">Reports</a></li>
                            <li class="breadcrumb-item active">SubID Analytics</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">

                <!-- KPI Summary Cards -->
                <div class="row stat-boxes-row">
                    <div class="col-md-3 col-6">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format(count($rows)); ?></div>
                            <div class="stat-label">Tracked SubIDs</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($totalClicks); ?></div>
                            <div class="stat-label">Total Clicks</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($totalConversions); ?></div>
                            <div class="stat-label">Conversions</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning">$<?php echo number_format($totalPayout, 2); ?></div>
                            <div class="stat-label">Total Payout</div>
                        </div>
                    </div>
                </div>

                <!-- Date Filter Form -->
                <div class="card card-custom p-4">
                    <form method="get" class="form-inline">
                        <div class="form-group mr-3 mb-2">
                            <label for="from" class="mr-2 font-weight-bold text-muted">From Date:</label>
                            <input type="date" id="from" name="from" class="form-control" value="<?php echo htmlspecialchars($from); ?>">
                        </div>
                        <div class="form-group mr-3 mb-2">
                            <label for="to" class="mr-2 font-weight-bold text-muted">To Date:</label>
                            <input type="date" id="to" name="to" class="form-control" value="<?php echo htmlspecialchars($to); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary mb-2 font-weight-bold px-4"><i class="fas fa-filter mr-1"></i> Apply Filter</button>
                    </form>
                </div>

                <!-- SubID Data Table -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-dark mb-3"><i class="fas fa-list text-primary mr-2"></i>SubID Breakdown (sub1)</h4>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="subidTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>SubID (sub1)</th>
                                    <th>Clicks</th>
                                    <th>Conversions</th>
                                    <th>Payout ($)</th>
                                    <th>Conversion Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rows)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">No SubID tracking data found for the selected date range.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $r): 
                                        $cr = $r['clicks'] > 0 ? ($r['conversions'] / $r['clicks']) * 100 : 0;
                                    ?>
                                        <tr>
                                            <td><strong class="text-dark"><i class="fas fa-tag text-muted mr-1"></i><?php echo htmlspecialchars($r['sub1']); ?></strong></td>
                                            <td><span class="badge badge-info p-2"><?php echo number_format((int)$r['clicks']); ?> Clicks</span></td>
                                            <td><strong class="text-dark"><?php echo number_format((int)$r['conversions']); ?></strong></td>
                                            <td><strong class="text-success">$<?php echo number_format((float)$r['payout'], 2); ?></strong></td>
                                            <td><span class="badge badge-primary p-2"><?php echo number_format($cr, 2); ?>%</span></td>
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
    $('#subidTable').DataTable({
        pageLength: 25,
        order: [[3, 'desc']],
        responsive: true
    });
});
</script>
</body>
</html>
