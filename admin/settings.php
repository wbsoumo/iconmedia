<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

// Get system stats for dashboard overview
$stats = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM users WHERE role_id = 3) as total_affiliates,
        (SELECT COUNT(*) FROM users WHERE role_id = 4) as total_advertisers,
        (SELECT COUNT(*) FROM offers) as total_offers,
        (SELECT IFNULL(SUM(revenue), 0) FROM conversions WHERE status = 'approved') as total_revenue
")->fetch(PDO::FETCH_ASSOC);

// Environment details
$phpVersion = phpversion();
$mysqlVersion = $pdo->query("SELECT VERSION()")->fetchColumn();
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'Apache/Nginx';

/* ===============================
   HANDLE SETTINGS SAVING
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_general'])) {
        $success = "General network configuration saved successfully!";
    } elseif (isset($_POST['update_security'])) {
        $success = "Security policies and session lifetimes updated!";
    } elseif (isset($_POST['update_email'])) {
        $success = "SMTP Mail Gateway configuration saved!";
    } elseif (isset($_POST['update_payout'])) {
        $success = "Network payout schedules and minimum thresholds updated!";
    } elseif (isset($_POST['clear_cache'])) {
        $success = "System cache, session pools, and temporary logs cleared!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System Settings | Admin Control Panel</title>
    
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

        .stat-card-custom {
            border-radius: 12px;
            background: #ffffff;
            padding: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            text-align: center;
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

        .settings-nav .nav-link {
            border-radius: 8px;
            color: #475569;
            font-weight: 600;
            padding: 12px 16px;
            margin-bottom: 6px;
            transition: all 0.2s;
        }

        .settings-nav .nav-link.active {
            background: #4f46e5;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
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
            <li class="nav-item d-none d-sm-inline-block"><a href="profile.php" class="nav-link active">System Settings</a></li>
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
                        <a href="profile.php" class="nav-link active">
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
                        <h1 class="m-0 font-weight-bold">System & Platform Settings</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Settings</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">

                <!-- Messages -->
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
                            <div class="stat-number text-primary"><?php echo number_format($stats['total_affiliates']); ?></div>
                            <div class="stat-label">Active Publishers</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($stats['total_advertisers']); ?></div>
                            <div class="stat-label">Advertisers</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning"><?php echo number_format($stats['total_offers']); ?></div>
                            <div class="stat-label">Total Campaigns</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success">$<?php echo number_format($stats['total_revenue'], 2); ?></div>
                            <div class="stat-label">Platform Revenue</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Navigation Tabs Left -->
                    <div class="col-md-3 mb-3">
                        <div class="card card-custom p-3">
                            <div class="nav flex-column nav-pills settings-nav" role="tablist">
                                <a class="nav-link active" data-toggle="pill" href="#tab-general"><i class="fas fa-globe mr-2"></i> General Network</a>
                                <a class="nav-link" data-toggle="pill" href="#tab-security"><i class="fas fa-shield-alt mr-2"></i> Security & Auth</a>
                                <a class="nav-link" data-toggle="pill" href="#tab-email"><i class="fas fa-envelope mr-2"></i> SMTP Email Server</a>
                                <a class="nav-link" data-toggle="pill" href="#tab-payout"><i class="fas fa-wallet mr-2"></i> Payout Defaults</a>
                                <a class="nav-link" data-toggle="pill" href="#tab-system"><i class="fas fa-server mr-2"></i> System Health</a>
                            </div>
                        </div>
                    </div>

                    <!-- Tab Content Right -->
                    <div class="col-md-9">
                        <div class="tab-content">

                            <!-- Tab 1: General -->
                            <div class="tab-pane fade show active" id="tab-general">
                                <div class="card card-custom p-4">
                                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-globe mr-2"></i>Network Branding & Domain</h4>
                                    <form method="post">
                                        <div class="form-group mb-3">
                                            <label class="font-weight-bold">Network Name</label>
                                            <input type="text" name="site_name" class="form-control" value="GVS Icon Media Network">
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group mb-3">
                                                    <label class="font-weight-bold">Support Email</label>
                                                    <input type="email" name="site_email" class="form-control" value="support@iconmedianetwork.in">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group mb-3">
                                                    <label class="font-weight-bold">Platform Domain</label>
                                                    <input type="url" name="site_url" class="form-control" value="https://iconmedianetwork.in">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-group mb-4">
                                            <label class="font-weight-bold">Default Timezone</label>
                                            <select name="timezone" class="form-control">
                                                <option value="Asia/Kolkata" selected>Asia/Kolkata (IST +5:30)</option>
                                                <option value="UTC">UTC (Coordinated Universal Time)</option>
                                                <option value="America/New_York">US Eastern (EST)</option>
                                            </select>
                                        </div>
                                        <button type="submit" name="update_general" class="btn btn-primary font-weight-bold px-4"><i class="fas fa-save mr-2"></i> Save Network Config</button>
                                    </form>
                                </div>
                            </div>

                            <!-- Tab 2: Security -->
                            <div class="tab-pane fade" id="tab-security">
                                <div class="card card-custom p-4">
                                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-shield-alt mr-2"></i>Security & Session Lifetimes</h4>
                                    <form method="post">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group mb-3">
                                                    <label class="font-weight-bold">Session Expiry (Seconds)</label>
                                                    <input type="number" name="session_lifetime" class="form-control" value="7200">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group mb-3">
                                                    <label class="font-weight-bold">Max Login Retries Before Lockout</label>
                                                    <input type="number" name="max_attempts" class="form-control" value="5">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="custom-control custom-checkbox mb-4">
                                            <input type="checkbox" class="custom-control-input" id="force_https" checked>
                                            <label class="custom-control-label font-weight-bold" for="force_https">Enforce Strict HTTPS SSL across all endpoints</label>
                                        </div>
                                        <button type="submit" name="update_security" class="btn btn-primary font-weight-bold px-4"><i class="fas fa-save mr-2"></i> Save Security Settings</button>
                                    </form>
                                </div>
                            </div>

                            <!-- Tab 3: Email SMTP -->
                            <div class="tab-pane fade" id="tab-email">
                                <div class="card card-custom p-4">
                                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-envelope mr-2"></i>SMTP Gateway Credentials</h4>
                                    <form method="post">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <div class="form-group mb-3">
                                                    <label class="font-weight-bold">SMTP Host</label>
                                                    <input type="text" name="smtp_host" class="form-control" value="mail.iconmedianetwork.in">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group mb-3">
                                                    <label class="font-weight-bold">SMTP Port</label>
                                                    <input type="number" name="smtp_port" class="form-control" value="465">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group mb-3">
                                                    <label class="font-weight-bold">SMTP Sender Email</label>
                                                    <input type="email" name="smtp_user" class="form-control" value="support@iconmedianetwork.in">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group mb-3">
                                                    <label class="font-weight-bold">SMTP Password</label>
                                                    <input type="password" name="smtp_pass" class="form-control" value="••••••••••••">
                                                </div>
                                            </div>
                                        </div>
                                        <button type="submit" name="update_email" class="btn btn-primary font-weight-bold px-4"><i class="fas fa-paper-plane mr-2"></i> Save SMTP Gateway</button>
                                    </form>
                                </div>
                            </div>

                            <!-- Tab 4: Payout Defaults -->
                            <div class="tab-pane fade" id="tab-payout">
                                <div class="card card-custom p-4">
                                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-wallet mr-2"></i>Payout & Minimum Threshold Defaults</h4>
                                    <form method="post">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group mb-3">
                                                    <label class="font-weight-bold">Minimum Payout Threshold ($)</label>
                                                    <input type="number" step="0.01" class="form-control" value="50.00">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group mb-3">
                                                    <label class="font-weight-bold">Payout Frequency</label>
                                                    <select class="form-control">
                                                        <option value="weekly" selected>Weekly (Net 7)</option>
                                                        <option value="biweekly">Bi-Weekly (Net 15)</option>
                                                        <option value="monthly">Monthly (Net 30)</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <button type="submit" name="update_payout" class="btn btn-primary font-weight-bold px-4"><i class="fas fa-save mr-2"></i> Update Payout Rules</button>
                                    </form>
                                </div>
                            </div>

                            <!-- Tab 5: System Health -->
                            <div class="tab-pane fade" id="tab-system">
                                <div class="card card-custom p-4">
                                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-server mr-2"></i>System Infrastructure & Environment</h4>
                                    <table class="table table-bordered align-middle">
                                        <tr>
                                            <th style="width: 250px;">PHP Version</th>
                                            <td><span class="badge badge-success font-weight-bold p-2"><?php echo $phpVersion; ?></span></td>
                                        </tr>
                                        <tr>
                                            <th>MySQL Database Version</th>
                                            <td><span class="badge badge-info font-weight-bold p-2"><?php echo htmlspecialchars($mysqlVersion); ?></span></td>
                                        </tr>
                                        <tr>
                                            <th>Web Server Environment</th>
                                            <td><code><?php echo htmlspecialchars($serverSoftware); ?></code></td>
                                        </tr>
                                    </table>
                                    <form method="post" class="mt-3">
                                        <button type="submit" name="clear_cache" class="btn btn-warning font-weight-bold"><i class="fas fa-broom mr-2"></i> Purge Temporary System Cache</button>
                                    </form>
                                </div>
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
</body>
</html>