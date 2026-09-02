<?php
define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['admin', 'manager']);

$adminName = $_SESSION['user_name'] ?? 'Admin';

/* ===============================
   FETCH FRAUD SIGNALS DATA
================================ */
// 1. Fast Conversions (< 5 sec)
$fast = $pdo->query("
    SELECT
        c.click_id,
        u.name AS affiliate,
        o.offer_name,
        TIMESTAMPDIFF(SECOND, cl.created_at, c.created_at) AS seconds_diff
    FROM conversions c
    INNER JOIN clicks cl ON cl.click_id = c.click_id
    INNER JOIN users u ON u.user_id = cl.affiliate_id
    INNER JOIN offers o ON o.offer_id = cl.offer_id
    WHERE c.status = 'approved'
      AND TIMESTAMPDIFF(SECOND, cl.created_at, c.created_at) < 5
    ORDER BY seconds_diff ASC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// 2. Multiple Conversions from Same IP
$ips = $pdo->query("
    SELECT
        INET6_NTOA(cl.ip_address) AS ip,
        COUNT(c.conversion_id) AS cnt
    FROM conversions c
    INNER JOIN clicks cl ON cl.click_id = c.click_id
    WHERE c.status = 'approved'
    GROUP BY cl.ip_address
    HAVING cnt >= 3
    ORDER BY cnt DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// 3. High Clicks, Zero Conversions
$badAff = $pdo->query("
    SELECT
        u.name,
        COUNT(cl.click_id) AS clicks
    FROM users u
    LEFT JOIN clicks cl ON cl.affiliate_id = u.user_id
    LEFT JOIN conversions c ON c.click_id = cl.click_id
    WHERE u.role_id = 3
    GROUP BY u.user_id
    HAVING clicks >= 50 AND SUM(CASE WHEN c.conversion_id IS NOT NULL THEN 1 ELSE 0 END) = 0
    ORDER BY clicks DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// 4. Postback Abuse / Failures
$pb = $pdo->query("
    SELECT status, COUNT(*) AS cnt
    FROM postback_logs
    WHERE status IN ('invalid_token','ip_blocked','duplicate')
    GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);

$totalFastConversions = count($fast);
$totalSuspiciousIps   = count($ips);
$totalZeroConvAffs    = count($badAff);
$totalPostbackAbuse   = array_sum(array_column($pb, 'cnt'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Anti-Fraud Security Console | Admin Panel</title>
    
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
            <li class="nav-item d-none d-sm-inline-block"><a href="#" class="nav-link active">Anti-Fraud Security</a></li>
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
                        <a href="reports_subid.php" class="nav-link">
                            <i class="nav-icon fas fa-list"></i>
                            <p>SubID Performance</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="fraud_dashboard.php" class="nav-link active">
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
                        <h1 class="m-0 font-weight-bold"><i class="fas fa-shield-alt text-danger mr-2"></i>Anti-Fraud Security Console</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="reports_campaigns.php">Reports</a></li>
                            <li class="breadcrumb-item active">Anti-Fraud Security</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">

                <!-- KPI Summary Cards -->
                <div class="row stat-boxes-row mb-4">
                    <div class="col-md-3 col-6">
                        <div class="stat-card-custom border-danger">
                            <div class="stat-number text-danger"><?php echo number_format($totalFastConversions); ?></div>
                            <div class="stat-label">Fast Conversions (&lt;5s)</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card-custom border-warning">
                            <div class="stat-number text-warning"><?php echo number_format($totalSuspiciousIps); ?></div>
                            <div class="stat-label">Multi-Conv IPs (&ge;3)</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card-custom border-info">
                            <div class="stat-number text-info"><?php echo number_format($totalZeroConvAffs); ?></div>
                            <div class="stat-label">0-Conv High Click Publishers</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card-custom border-secondary">
                            <div class="stat-number text-secondary"><?php echo number_format($totalPostbackAbuse); ?></div>
                            <div class="stat-label">Postback Abuse Logs</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- SECTION 1: Fast Conversions -->
                    <div class="col-md-6">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-danger mb-3"><i class="fas fa-bolt mr-2"></i>1. Fast Conversions (&lt; 5 Seconds)</h4>
                            <p class="text-muted small">Conversions generated within 5 seconds of the click, indicating bot auto-redirection.</p>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle datatable-table">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Click ID</th>
                                            <th>Publisher</th>
                                            <th>Campaign</th>
                                            <th>Duration</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($fast as $r): ?>
                                            <tr>
                                                <td><code class="small text-muted"><?php echo htmlspecialchars(substr($r['click_id'], 0, 10)); ?>...</code></td>
                                                <td><strong class="text-dark"><?php echo htmlspecialchars($r['affiliate']); ?></strong></td>
                                                <td><small><?php echo htmlspecialchars($r['offer_name']); ?></small></td>
                                                <td><span class="badge badge-danger p-2"><i class="fas fa-stopwatch mr-1"></i><?php echo (int)$r['seconds_diff']; ?>s</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 2: Multiple Conversions Same IP -->
                    <div class="col-md-6">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-warning mb-3"><i class="fas fa-network-wire mr-2"></i>2. Multi-Conversions Same IP (&ge; 3)</h4>
                            <p class="text-muted small">Single IP addresses generating 3 or more approved conversions.</p>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle datatable-table">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>IP Address</th>
                                            <th>Approved Conversions</th>
                                            <th>Risk Level</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ips as $r): ?>
                                            <tr>
                                                <td><strong class="text-dark"><i class="fas fa-desktop text-muted mr-1"></i><?php echo htmlspecialchars($r['ip'] ?: 'Hidden / Proxied'); ?></strong></td>
                                                <td><span class="badge badge-warning p-2"><?php echo number_format((int)$r['cnt']); ?> Conversions</span></td>
                                                <td>
                                                    <?php if ($r['cnt'] >= 10): ?>
                                                        <span class="badge badge-danger p-2">Critical</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning p-2">Moderate</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- SECTION 3: High Clicks Zero Conversions -->
                    <div class="col-md-6">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-info mb-3"><i class="fas fa-ban mr-2"></i>3. Low Quality Traffic (&ge; 50 Clicks, 0 Conversions)</h4>
                            <p class="text-muted small">Publishers with high click volume but zero resulting conversions.</p>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle datatable-table">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Publisher Name</th>
                                            <th>Total Clicks</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($badAff as $r): ?>
                                            <tr>
                                                <td><strong class="text-dark"><?php echo htmlspecialchars($r['name']); ?></strong></td>
                                                <td><span class="badge badge-info p-2"><?php echo number_format((int)$r['clicks']); ?> Clicks</span></td>
                                                <td><span class="badge badge-secondary p-2">0% CR (Review)</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 4: Postback Abuse Logs -->
                    <div class="col-md-6">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-secondary mb-3"><i class="fas fa-exclamation-triangle mr-2"></i>4. Postback Abuse Logs & Failures</h4>
                            <p class="text-muted small">Invalid tokens, blocked IPs, or duplicate conversion attempts.</p>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle datatable-table">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Abuse Status Code</th>
                                            <th>Occurrences Count</th>
                                            <th>Action Required</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pb as $r): ?>
                                            <tr>
                                                <td><strong class="text-dark"><code class="text-danger"><?php echo strtoupper($r['status']); ?></code></strong></td>
                                                <td><span class="badge badge-dark p-2"><?php echo number_format((int)$r['cnt']); ?> Events</span></td>
                                                <td><a href="publisher_postbacks.php" class="btn btn-xs btn-outline-primary font-weight-bold"><i class="fas fa-search mr-1"></i> View Logs</a></td>
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
    $('.datatable-table').DataTable({
        pageLength: 10,
        responsive: true,
        searching: false,
        lengthChange: false
    });
});
</script>
</body>
</html>
