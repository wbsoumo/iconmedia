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

// Determine Client's Current IP Address
$clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (strpos($clientIp, ',') !== false) {
    $clientIp = trim(explode(',', $clientIp)[0]);
}

/* ===============================
   ADD IP / IP RANGE / QUICK MY IP
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_ip'])) {
    $ip = trim($_POST['ip_address'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($ip)) {
        $error = "IP Address or Subnet range is required.";
    } elseif (filter_var($ip, FILTER_VALIDATE_IP)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO advertiser_ip_whitelist (advertiser_id, ip_address, description, created_at)
                VALUES (:aid, INET6_ATON(:ip), :description, NOW())
                ON DUPLICATE KEY UPDATE description = VALUES(description), updated_at = NOW()
            ");
            $stmt->execute([
                'aid' => $advertiserId,
                'ip'  => $ip,
                'description' => $description ?: 'Authorized Server IP'
            ]);
            $success = "IP address <strong>" . htmlspecialchars($ip) . "</strong> added to whitelist.";
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    } else {
        $error = "Invalid IP address format. Example valid IP: <code>192.168.1.100</code>";
    }
}

/* ===============================
   DELETE IP
================================ */
if (isset($_GET['delete'])) {
    $deleteId = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM advertiser_ip_whitelist WHERE id = :id AND advertiser_id = :aid");
    $stmt->execute(['id' => $deleteId, 'aid' => $advertiserId]);
    $success = "Whitelisted IP has been removed.";
}

/* ===============================
   BULK ACTIONS (DELETE, ENABLE, DISABLE)
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selectedIps = $_POST['selected_ips'] ?? [];

    if (!empty($selectedIps)) {
        $placeholders = implode(',', array_fill(0, count($selectedIps), '?'));
        
        if ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM advertiser_ip_whitelist WHERE id IN ($placeholders) AND advertiser_id = ?");
            $stmt->execute(array_merge($selectedIps, [$advertiserId]));
            $success = count($selectedIps) . " IP entries removed.";
        } elseif ($action === 'enable') {
            $stmt = $pdo->prepare("UPDATE advertiser_ip_whitelist SET is_active = 1 WHERE id IN ($placeholders) AND advertiser_id = ?");
            $stmt->execute(array_merge($selectedIps, [$advertiserId]));
            $success = count($selectedIps) . " IP entries enabled.";
        } elseif ($action === 'disable') {
            $stmt = $pdo->prepare("UPDATE advertiser_ip_whitelist SET is_active = 0 WHERE id IN ($placeholders) AND advertiser_id = ?");
            $stmt->execute(array_merge($selectedIps, [$advertiserId]));
            $success = count($selectedIps) . " IP entries disabled.";
        }
    }
}

/* ===============================
   FETCH WHITELISTED IPS
================================ */
$stmt = $pdo->prepare("
    SELECT 
        id,
        INET6_NTOA(ip_address) as ip_address,
        description,
        is_active,
        created_at,
        updated_at
    FROM advertiser_ip_whitelist
    WHERE advertiser_id = :aid
    ORDER BY created_at DESC
");
$stmt->execute(['aid' => $advertiserId]);
$whitelistedIps = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   STATISTICS
================================ */
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_ips,
        SUM(is_active = 1) as active_ips,
        SUM(is_active = 0) as inactive_ips
    FROM advertiser_ip_whitelist
    WHERE advertiser_id = :aid
");
$statsStmt->execute(['aid' => $advertiserId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IP Whitelist Manager | Advertiser Panel</title>
    
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
            font-size: 28px;
            font-weight: 800;
            color: #1e293b;
        }

        .stat-card-custom .stat-label {
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
        }

        .current-ip-banner {
            background: linear-gradient(135deg, #e0e7ff 0%, #e0f2fe 100%);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #c7d2fe;
        }

        .ip-code-badge {
            font-family: monospace;
            font-size: 16px;
            font-weight: 700;
            color: #3730a3;
            background: #ffffff;
            padding: 6px 14px;
            border-radius: 8px;
            border: 1px solid #c7d2fe;
            display: inline-block;
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
                <a href="ip_whitelist.php" class="nav-link active">IP Whitelist</a>
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
                        <a href="reports_campaigns.php" class="nav-link"><i class="fas fa-exchange-alt nav-icon"></i><p>Conversion Reports</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link"><i class="nav-icon fas fa-users"></i><p>Affiliate Reports</p></a>
                    </li>
                    <li class="nav-header">TOOLS</li>
                    <li class="nav-item">
                        <a href="ip_whitelist.php" class="nav-link active"><i class="nav-icon fas fa-tower-broadcast"></i><p>IP Whitelist</p></a>
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
                        <h1 class="m-0 font-weight-bold">IP Whitelist Security Center</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">IP Whitelist</li>
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

                <!-- Current IP & Quick Add Banner -->
                <div class="current-ip-banner mb-4">
                    <div class="row align-items-center">
                        <div class="col-md-7">
                            <span class="text-uppercase text-muted font-weight-bold small d-block">Your Current Connection IP:</span>
                            <span class="ip-code-badge mr-2"><?php echo htmlspecialchars($clientIp); ?></span>
                            <small class="text-muted d-block mt-1"><i class="fas fa-shield-alt mr-1"></i>Only whitelisted server IPs are allowed to send S2S postback conversions & API calls.</small>
                        </div>
                        <div class="col-md-5 text-md-right mt-3 mt-md-0">
                            <button class="btn btn-indigo btn-primary font-weight-bold px-3 shadow-sm" onclick="quickAddCurrentIp('<?php echo htmlspecialchars($clientIp); ?>')">
                                <i class="fas fa-plus-circle mr-1"></i> 1-Click Whitelist My IP
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Stat Boxes Row (2x2 Mobile Responsive Grid) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-4">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($stats['total_ips'] ?? 0); ?></div>
                            <div class="stat-label">Total Whitelisted IPs</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($stats['active_ips'] ?? 0); ?></div>
                            <div class="stat-label">Active Protected IPs</div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4 mt-3 mt-md-0">
                        <div class="stat-card-custom">
                            <div class="stat-number text-secondary"><?php echo number_format($stats['inactive_ips'] ?? 0); ?></div>
                            <div class="stat-label">Disabled Entries</div>
                        </div>
                    </div>
                </div>

                <!-- Add IP Form Card -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-plus-circle mr-2"></i>Add Server IP to Security Whitelist</h4>
                    <form method="post" id="addIpForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold text-dark">IP Address / Subnet <span class="text-danger">*</span></label>
                                    <input type="text" name="ip_address" id="input_ip_address" class="form-control form-control-lg" placeholder="e.g. 192.168.1.100" required>
                                    <small class="text-muted">Supports IPv4 and IPv6 standard addresses.</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold text-dark">Label / Description</label>
                                    <input type="text" name="description" id="input_description" class="form-control form-control-lg" placeholder="e.g. Production Postback Server #1">
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <button type="submit" name="add_ip" class="btn btn-success btn-lg font-weight-bold px-4 shadow">
                                <i class="fas fa-shield-alt mr-2"></i> Save & Whitelist IP
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Whitelisted IPs List with Bulk Actions -->
                <div class="card card-custom p-4">
                    <form method="post" id="bulkForm">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="font-weight-bold text-primary mb-0"><i class="fas fa-list-check mr-2"></i>Whitelisted Server Addresses</h4>
                            
                            <div class="d-flex gap-2">
                                <select name="bulk_action" class="form-control mr-2" style="width: auto;">
                                    <option value="">Bulk Actions...</option>
                                    <option value="enable">Enable Selected</option>
                                    <option value="disable">Disable Selected</option>
                                    <option value="delete">Delete Selected</option>
                                </select>
                                <button type="submit" class="btn btn-outline-secondary font-weight-bold">Apply</button>
                            </div>
                        </div>

                        <?php if (empty($whitelistedIps)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-tower-broadcast fa-3x text-muted mb-3"></i>
                            <h5 class="text-dark font-weight-bold">No Whitelisted IPs Found</h5>
                            <p class="text-muted">Add your server IP addresses to ensure secure postback execution.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="ipTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width: 40px;"><input type="checkbox" id="selectAll"></th>
                                        <th>IP Address</th>
                                        <th>Description Label</th>
                                        <th>Status</th>
                                        <th>Date Added</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($whitelistedIps as $ip): ?>
                                    <tr>
                                        <td><input type="checkbox" name="selected_ips[]" value="<?php echo $ip['id']; ?>" class="ip-select-cb"></td>
                                        <td><code class="p-2 bg-light rounded text-primary font-weight-bold" style="font-size: 15px;"><?php echo htmlspecialchars($ip['ip_address']); ?></code></td>
                                        <td><strong class="text-dark"><?php echo htmlspecialchars($ip['description'] ?: 'Server IP'); ?></strong></td>
                                        <td>
                                            <span class="badge badge-<?php echo $ip['is_active'] ? 'success' : 'secondary'; ?> p-2">
                                                <?php echo $ip['is_active'] ? 'Active Protected' : 'Disabled'; ?>
                                            </span>
                                        </td>
                                        <td><small class="text-muted"><?php echo date('M d, Y H:i', strtotime($ip['created_at'])); ?></small></td>
                                        <td>
                                            <a href="?delete=<?php echo $ip['id']; ?>" class="btn btn-sm btn-outline-danger font-weight-bold" onclick="return confirm('Are you sure you want to remove this IP from your whitelist?')">
                                                <i class="fas fa-trash-alt mr-1"></i> Remove
                                            </a>
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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    $('#ipTable').DataTable({
        pageLength: 10,
        responsive: true
    });

    $('#selectAll').on('change', function() {
        $('.ip-select-cb').prop('checked', $(this).prop('checked'));
    });
});

function quickAddCurrentIp(ip) {
    $('#input_ip_address').val(ip);
    $('#input_description').val('My Current Browser / Workstation IP');
    window.scrollTo({ top: 300, behavior: 'smooth' });
}
</script>
</body>
</html>