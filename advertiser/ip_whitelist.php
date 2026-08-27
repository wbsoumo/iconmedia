<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('advertiser');

$advertiserId = auth_user_id();
$advertiserName = $_SESSION['user_name'] ?? 'Advertiser';

/* ===============================
   ADD IP TO WHITELIST
================================ */
if (isset($_POST['add_ip'])) {
    $ip = trim($_POST['ip_address'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    // Validate IP address
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO advertiser_ip_whitelist (advertiser_id, ip_address, description, created_at)
                VALUES (:aid, INET6_ATON(:ip), :description, NOW())
                ON DUPLICATE KEY UPDATE description = VALUES(description), updated_at = NOW()
            ");
            $stmt->execute([
                'aid' => $advertiserId,
                'ip' => $ip,
                'description' => $description
            ]);
            
            $_SESSION['success_message'] = "IP address $ip has been added to whitelist.";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error adding IP: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "Invalid IP address format.";
    }
}

/* ===============================
   REMOVE IP FROM WHITELIST
================================ */
if (isset($_GET['delete'])) {
    $deleteId = (int)$_GET['delete'];
    
    $stmt = $pdo->prepare("
        DELETE FROM advertiser_ip_whitelist 
        WHERE id = :id AND advertiser_id = :aid
    ");
    $stmt->execute(['id' => $deleteId, 'aid' => $advertiserId]);
    
    $_SESSION['success_message'] = "IP address has been removed from whitelist.";
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/* ===============================
   BULK IP MANAGEMENT
================================ */
if (isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selectedIps = $_POST['selected_ips'] ?? [];
    
    if (!empty($selectedIps)) {
        $placeholders = implode(',', array_fill(0, count($selectedIps), '?'));
        
        if ($action === 'delete') {
            $stmt = $pdo->prepare("
                DELETE FROM advertiser_ip_whitelist 
                WHERE id IN ($placeholders) AND advertiser_id = ?
            ");
            $params = array_merge($selectedIps, [$advertiserId]);
            $stmt->execute($params);
            
            $_SESSION['success_message'] = count($selectedIps) . " IP address(es) have been removed.";
        } elseif ($action === 'enable') {
            $stmt = $pdo->prepare("
                UPDATE advertiser_ip_whitelist 
                SET is_active = 1, updated_at = NOW()
                WHERE id IN ($placeholders) AND advertiser_id = ?
            ");
            $params = array_merge($selectedIps, [$advertiserId]);
            $stmt->execute($params);
            
            $_SESSION['success_message'] = count($selectedIps) . " IP address(es) have been enabled.";
        } elseif ($action === 'disable') {
            $stmt = $pdo->prepare("
                UPDATE advertiser_ip_whitelist 
                SET is_active = 0, updated_at = NOW()
                WHERE id IN ($placeholders) AND advertiser_id = ?
            ");
            $params = array_merge($selectedIps, [$advertiserId]);
            $stmt->execute($params);
            
            $_SESSION['success_message'] = count($selectedIps) . " IP address(es) have been disabled.";
        }
        
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
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
        SUM(is_active = 0) as inactive_ips,
        MIN(created_at) as first_added,
        MAX(created_at) as last_added
    FROM advertiser_ip_whitelist
    WHERE advertiser_id = :aid
");
$statsStmt->execute(['aid' => $advertiserId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

/* ===============================
   IP RANGE VALIDATION FUNCTION
================================ */
function validateIpRange($ip) {
    // Check if it's a single IP
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return true;
    }
    
    // Check if it's an IP range (CIDR notation)
    if (strpos($ip, '/') !== false) {
        list($network, $prefix) = explode('/', $ip);
        if (filter_var($network, FILTER_VALIDATE_IP) && 
            is_numeric($prefix) && 
            $prefix >= 0 && 
            $prefix <= 128) {
            return true;
        }
    }
    
    // Check if it's an IP range with dash
    if (strpos($ip, '-') !== false) {
        $parts = explode('-', $ip);
        if (count($parts) === 2 && 
            filter_var(trim($parts[0]), FILTER_VALIDATE_IP) && 
            filter_var(trim($parts[1]), FILTER_VALIDATE_IP)) {
            return true;
        }
    }
    
    return false;
}

/* ===============================
   RECENT ACTIVITY LOG
================================ */
$activityStmt = $pdo->prepare("
    SELECT 
        INET6_NTOA(ip_address) as ip,
        action,
        created_at
    FROM advertiser_ip_activity
    WHERE advertiser_id = :aid
    ORDER BY created_at DESC
    LIMIT 10
");
$activityStmt->execute(['aid' => $advertiserId]);
$recentActivity = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IP Whitelist Management | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
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
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --info-gradient: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            --warning-gradient: linear-gradient(135deg, #f7971e 0%, #ffd200 100%);
            --danger-gradient: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
        }
        
        .card-dashboard {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            overflow: hidden;
        }
        
        .card-dashboard .card-header {
            border-radius: 15px 15px 0 0;
            background: white;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            padding: 20px;
        }
        
        .card-dashboard .card-body {
            padding: 25px;
        }
        
        .btn-gradient {
            background: var(--primary-gradient);
            border: none;
            color: white;
            font-weight: 600;
            padding: 10px 25px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }
        
        .form-control-custom {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e3e6f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #f8f9fc;
        }
        
        .form-control-custom:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: white;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #6c757d;
            font-size: 14px;
            font-weight: 600;
        }
        
        .metric-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #e3e6f0;
            text-align: center;
            transition: all 0.3s ease;
            flex: 1;
            min-width: 150px;
        }
        
        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .metric-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .metric-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .summary-stats {
            display: flex;
            gap: 15px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        
        .total-value {
            color: #4e73df;
            font-weight: 700;
        }
        
        .active-value {
            color: #28a745;
            font-weight: 700;
        }
        
        .inactive-value {
            color: #6c757d;
            font-weight: 700;
        }
        
        .date-value {
            color: #6610f2;
            font-weight: 700;
        }
        
        .table-dashboard {
            border: none;
            width: 100%;
        }
        
        .table-dashboard thead th {
            border: none;
            background: #f8f9fc;
            color: #4e73df;
            font-weight: 600;
            padding: 15px;
            border-bottom: 2px solid #e3e6f0;
            text-align: left;
        }
        
        .table-dashboard tbody td {
            padding: 15px;
            border-bottom: 1px solid #f8f9fa;
            vertical-align: middle;
        }
        
        .table-dashboard tbody tr:hover {
            background: #f8f9fc;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-active {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .status-inactive {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.2);
        }
        
        .ip-address {
            font-family: 'Courier New', monospace;
            font-size: 14px;
            font-weight: 600;
            color: #343a40;
        }
        
        .ip-description {
            font-size: 12px;
            color: #6c757d;
            margin-top: 3px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn-action {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        
        .btn-edit {
            background: rgba(32, 201, 151, 0.1);
            color: #20c997;
            border: 1px solid rgba(32, 201, 151, 0.2);
        }
        
        .btn-edit:hover {
            background: rgba(32, 201, 151, 0.2);
            color: #20c997;
        }
        
        .btn-delete {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .btn-delete:hover {
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }
        
        .bulk-actions {
            background: #f8f9fc;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .select-all-checkbox {
            margin-right: 10px;
        }
        
        .info-box {
            background: #f8f9fc;
            border-left: 4px solid #4e73df;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .info-box .info-title {
            color: #4e73df;
            font-weight: 600;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-box .info-content {
            color: #6c757d;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .ip-examples {
            background: #f8f9fc;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
        }
        
        .ip-examples h6 {
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .ip-examples ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .ip-examples li {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: #343a40;
            padding: 3px 0;
        }
        
        .activity-item {
            padding: 10px 0;
            border-bottom: 1px solid #f8f9fa;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .activity-icon.add {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }
        
        .activity-icon.delete {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        .activity-icon.update {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }
        
        .activity-details {
            flex: 1;
        }
        
        .activity-ip {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: #343a40;
        }
        
        .activity-time {
            font-size: 11px;
            color: #6c757d;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .empty-state-icon {
            font-size: 50px;
            color: #e3e6f0;
            margin-bottom: 15px;
        }
        
        .alert-message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            border-left-color: #28a745;
            color: #155724;
        }
        
        .alert-error {
            background: rgba(220, 53, 69, 0.1);
            border-left-color: #dc3545;
            color: #721c24;
        }
        
        .ip-validation {
            font-size: 12px;
            margin-top: 5px;
        }
        
        .validation-valid {
            color: #28a745;
        }
        
        .validation-invalid {
            color: #dc3545;
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .dataTables_wrapper {
            padding: 0;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button">
                    <i class="fas fa-bars"></i>
                </a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="security.php" class="nav-link active">Security</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="ip_whitelist.php" class="nav-link">IP Whitelist</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <a class="nav-link" data-widget="fullscreen" href="#" role="button">
                    <i class="fas fa-expand-arrows-alt"></i>
                </a>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                    <i class="fas fa-user-circle mr-1"></i> <?php echo htmlspecialchars($advertiserName); ?>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user mr-2"></i> Profile
                    </a>
                    <a href="account.php" class="dropdown-item">
                        <i class="fas fa-cog mr-2"></i> Account Settings
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="../logout.php" class="dropdown-item">
                        <i class="fas fa-sign-out-alt mr-2"></i> Logout
                    </a>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#" id="darkModeToggle">
                    <i class="fas fa-moon"></i>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <!-- Brand Logo -->
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.5rem;">
                <i class="fas fa-chart-line mr-2"></i>
                <strong>Advertiser</strong>
            </span>
        </a>

        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Sidebar Menu -->
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-tachometer-alt"></i>
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
                        <a href="offers.php" class="nav-link">
                            <i class="nav-icon fas fa-gift"></i>
                            <p>All Offers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="create_offer.php" class="nav-link">
                            <i class="nav-icon fas fa-plus-circle"></i>
                            <p>Create New Offer</p>
                        </a>
                    </li>
                    
                    <li class="nav-header">REPORTS & ANALYTICS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Campaign Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_conversions.php" class="nav-link">
                            <i class="fas fa-exchange-alt nav-icon"></i>
                            <p>Conversion Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link">
                            <i class="nav-icon fas fa-users"></i>
                            <p>Affiliate Reports</p>
                        </a>
                    </li>
                    
                    <li class="nav-header">TOOLS</li>
                    <li class="nav-item">
                        <a href="ip_whitelist.php" class="nav-link active">
                            <i class="nav-icon fas fa-tower-broadcast"></i>
                            <p>IP Whitelist</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="postback.php" class="nav-link">
                            <i class="nav-icon fas fa-code"></i>
                            <p>Postback Manager</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="api.php" class="nav-link">
                            <i class="nav-icon fas fa-plug"></i>
                            <p>API Integration</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="optimization.php" class="nav-link">
                            <i class="nav-icon fas fa-rocket"></i>
                            <p>Optimization Tools</p>
                        </a>
                    </li>
                    
                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user"></i>
                            <p>Profile</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="billing.php" class="nav-link">
                            <i class="nav-icon fas fa-wallet"></i>
                            <p>Billing & Payments</p>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    </aside>

    <!-- Content Wrapper -->
    <div class="content-wrapper">
        <!-- Content Header -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0">IP Whitelist Management</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="security.php">Security</a></li>
                            <li class="breadcrumb-item active">IP Whitelist</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">
                <!-- Dashboard Header -->
                <div class="dashboard-header">
                    <h2 class="mb-0">Manage IP Whitelist</h2>
                    <div class="action-buttons">
                        <button class="btn btn-outline-primary" onclick="window.print()">
                            <i class="fas fa-print mr-2"></i> Print List
                        </button>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert-message alert-success">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                        <?php unset($_SESSION['success_message']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert-message alert-error">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                        <?php unset($_SESSION['error_message']); ?>
                    </div>
                <?php endif; ?>

                <!-- Summary Stats -->
                <div class="summary-stats">
                    <div class="metric-card">
                        <div class="metric-value total-value"><?php echo number_format($stats['total_ips'] ?? 0); ?></div>
                        <div class="metric-label">Total IPs</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value active-value"><?php echo number_format($stats['active_ips'] ?? 0); ?></div>
                        <div class="metric-label">Active IPs</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value inactive-value"><?php echo number_format($stats['inactive_ips'] ?? 0); ?></div>
                        <div class="metric-label">Inactive IPs</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value date-value">
                            <?php echo $stats['first_added'] ? date('M d, Y', strtotime($stats['first_added'])) : 'N/A'; ?>
                        </div>
                        <div class="metric-label">First Added</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value date-value">
                            <?php echo $stats['last_added'] ? date('M d, Y', strtotime($stats['last_added'])) : 'N/A'; ?>
                        </div>
                        <div class="metric-label">Last Added</div>
                    </div>
                </div>

                <div class="row">
                    <!-- Main Content -->
                    <div class="col-lg-8">
                        <!-- Add IP Form -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-plus-circle mr-2"></i> Add New IP Address
                                </h3>
                            </div>
                            <div class="card-body">
                                <form method="post" id="addIpForm">
                                    <div class="form-group">
                                        <label for="ip_address">
                                            <i class="fas fa-network-wired mr-1"></i> IP Address / Range
                                        </label>
                                        <input type="text" 
                                               name="ip_address" 
                                               id="ip_address" 
                                               class="form-control-custom" 
                                               placeholder="e.g., 192.168.1.1 or 192.168.1.0/24"
                                               required
                                               oninput="validateIp(this.value)">
                                        <div id="ipValidation" class="ip-validation"></div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="description">
                                            <i class="fas fa-file-alt mr-1"></i> Description (Optional)
                                        </label>
                                        <input type="text" 
                                               name="description" 
                                               id="description" 
                                               class="form-control-custom" 
                                               placeholder="e.g., Office Network, Home IP, VPN Server">
                                    </div>
                                    
                                    <div class="d-flex justify-content-between">
                                        <button type="submit" name="add_ip" class="btn-gradient">
                                            <i class="fas fa-save mr-2"></i> Add to Whitelist
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('addIpForm').reset();">
                                            <i class="fas fa-redo mr-2"></i> Clear Form
                                        </button>
                                    </div>
                                </form>
                                
                                <!-- IP Format Examples -->
                                <div class="ip-examples">
                                    <h6><i class="fas fa-info-circle mr-2"></i> Accepted IP Formats:</h6>
                                    <ul>
                                        <li>Single IP: <code>192.168.1.1</code></li>
                                        <li>CIDR Range: <code>192.168.1.0/24</code></li>
                                        <li>IP Range: <code>192.168.1.1-192.168.1.100</code></li>
                                        <li>IPv6: <code>2001:0db8:85a3:0000:0000:8a2e:0370:7334</code></li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Whitelisted IPs Table -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-list mr-2"></i> Whitelisted IP Addresses
                                </h3>
                                <div class="card-tools">
                                    <span class="badge badge-light">
                                        <?php echo count($whitelistedIps); ?> IP<?php echo count($whitelistedIps) !== 1 ? 's' : ''; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($whitelistedIps)): ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-lock"></i>
                                        </div>
                                        <h5>No IP Addresses Whitelisted</h5>
                                        <p class="text-muted">Add your first IP address to start restricting access.</p>
                                    </div>
                                <?php else: ?>
                                    <!-- Bulk Actions -->
                                    <form method="post" id="bulkForm" onsubmit="return confirmBulkAction()">
                                        <div class="bulk-actions">
                                            <div class="form-check select-all-checkbox">
                                                <input type="checkbox" class="form-check-input" id="selectAll">
                                                <label class="form-check-label" for="selectAll">Select All</label>
                                            </div>
                                            
                                            <select name="bulk_action" class="form-control-custom" style="width: auto;">
                                                <option value="">Bulk Actions</option>
                                                <option value="enable">Enable Selected</option>
                                                <option value="disable">Disable Selected</option>
                                                <option value="delete">Delete Selected</option>
                                            </select>
                                            
                                            <button type="submit" class="btn btn-outline-primary btn-sm">
                                                <i class="fas fa-play mr-1"></i> Apply
                                            </button>
                                        </div>
                                        
                                        <div class="table-responsive">
                                            <table class="table table-dashboard" id="ipTable">
                                                <thead>
                                                    <tr>
                                                        <th style="width: 40px;">
                                                            <input type="checkbox" class="form-check-input" id="checkAll">
                                                        </th>
                                                        <th>IP Address</th>
                                                        <th>Description</th>
                                                        <th>Status</th>
                                                        <th>Added On</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($whitelistedIps as $ip): ?>
                                                    <tr>
                                                        <td>
                                                            <input type="checkbox" 
                                                                   name="selected_ips[]" 
                                                                   value="<?php echo $ip['id']; ?>" 
                                                                   class="form-check-input ip-checkbox">
                                                        </td>
                                                        <td>
                                                            <div class="ip-address"><?php echo htmlspecialchars($ip['ip_address']); ?></div>
                                                            <?php if ($ip['updated_at']): ?>
                                                            <div class="ip-description">
                                                                Updated: <?php echo date('M d, Y', strtotime($ip['updated_at'])); ?>
                                                            </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($ip['description']): ?>
                                                                <span><?php echo htmlspecialchars($ip['description']); ?></span>
                                                            <?php else: ?>
                                                                <span class="text-muted">No description</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="status-badge status-<?php echo $ip['is_active'] ? 'active' : 'inactive'; ?>">
                                                                <?php echo $ip['is_active'] ? 'Active' : 'Inactive'; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div><?php echo date('M d, Y', strtotime($ip['created_at'])); ?></div>
                                                            <small class="text-muted"><?php echo date('H:i', strtotime($ip['created_at'])); ?></small>
                                                        </td>
                                                        <td>
                                                            <div class="action-buttons">
                                                                <a href="edit_ip.php?id=<?php echo $ip['id']; ?>" class="btn-action btn-edit">
                                                                    <i class="fas fa-edit"></i>
                                                                </a>
                                                                <a href="?delete=<?php echo $ip['id']; ?>" 
                                                                   class="btn-action btn-delete"
                                                                   onclick="return confirm('Are you sure you want to delete this IP address?')">
                                                                    <i class="fas fa-trash"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar Widgets -->
                    <div class="col-lg-4">
                        <!-- Security Information -->
                        <div class="card card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Security Information</h3>
                            </div>
                            <div class="card-body">
                                <div class="info-box">
                                    <div class="info-title">
                                        <i class="fas fa-info-circle"></i> How IP Whitelisting Works
                                    </div>
                                    <div class="info-content">
                                        <p>IP whitelisting restricts access to your account to specific IP addresses only.</p>
                                        <p>When enabled, only connections from these IPs will be allowed to access your advertiser panel.</p>
                                    </div>
                                </div>
                                
                                <div class="info-box">
                                    <div class="info-title">
                                        <i class="fas fa-exclamation-triangle"></i> Important Notes
                                    </div>
                                    <div class="info-content">
                                        <ul style="margin-left: 15px; padding-left: 0;">
                                            <li>Always keep at least one active IP address</li>
                                            <li>Update your IP list when changing locations</li>
                                            <li>Consider adding your VPN server IPs</li>
                                            <li>Inactive IPs won't block access</li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="d-grid gap-2 mt-3">
                                    <button class="btn btn-gradient" onclick="enableWhitelisting()">
                                        <i class="fas fa-toggle-on mr-2"></i> Enable Whitelisting
                                    </button>
                                    <a href="security.php" class="btn btn-outline-primary">
                                        <i class="fas fa-shield-alt mr-2"></i> Security Settings
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Activity -->
                        <div class="card card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Recent IP Activity</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recentActivity)): ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-history"></i>
                                        </div>
                                        <p class="text-muted">No recent activity.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach($recentActivity as $activity): ?>
                                    <div class="activity-item">
                                        <?php 
                                        $iconClass = '';
                                        if ($activity['action'] === 'ADD') $iconClass = 'add';
                                        elseif ($activity['action'] === 'DELETE') $iconClass = 'delete';
                                        else $iconClass = 'update';
                                        ?>
                                        <div class="activity-icon <?php echo $iconClass; ?>">
                                            <?php if ($activity['action'] === 'ADD'): ?>
                                                <i class="fas fa-plus"></i>
                                            <?php elseif ($activity['action'] === 'DELETE'): ?>
                                                <i class="fas fa-trash"></i>
                                            <?php else: ?>
                                                <i class="fas fa-edit"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="activity-details">
                                            <div class="activity-ip"><?php echo htmlspecialchars($activity['ip']); ?></div>
                                            <div class="activity-time">
                                                <?php echo date('M d, H:i', strtotime($activity['created_at'])); ?>
                                                &nbsp;•&nbsp; <?php echo $activity['action']; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="card card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Quick Actions</h3>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <button class="btn btn-outline-primary" onclick="addCurrentIp()">
                                        <i class="fas fa-plus-circle mr-2"></i> Add Current IP
                                    </button>
                                    <button class="btn btn-outline-success" onclick="exportIpList()">
                                        <i class="fas fa-download mr-2"></i> Export IP List
                                    </button>
                                    <button class="btn btn-outline-danger" onclick="clearAllIps()">
                                        <i class="fas fa-trash mr-2"></i> Clear All IPs
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline">
            <strong>IP Whitelist v1.0</strong>
        </div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- REQUIRED SCRIPTS -->
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<!-- Bootstrap 4 -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- AdminLTE App -->
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#ipTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[4, 'desc']], // Sort by date descending
        responsive: true,
        searching: true,
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search IPs..."
        }
    });
    
    // Dark mode toggle
    $('#darkModeToggle').click(function(e) {
        e.preventDefault();
        $('body').toggleClass('dark-mode');
        $(this).find('i').toggleClass('fa-moon fa-sun');
        localStorage.setItem('darkMode', $('body').hasClass('dark-mode'));
    });
    
    if (localStorage.getItem('darkMode') === 'true') {
        $('body').addClass('dark-mode');
        $('#darkModeToggle i').removeClass('fa-moon').addClass('fa-sun');
    }
    
    // Initialize SweetAlert2
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    });
    
    // Select all functionality
    $('#selectAll, #checkAll').click(function() {
        $('.ip-checkbox').prop('checked', $(this).prop('checked'));
    });
    
    // Update "Select All" checkbox when individual checkboxes change
    $('.ip-checkbox').change(function() {
        if ($('.ip-checkbox:checked').length === $('.ip-checkbox').length) {
            $('#selectAll, #checkAll').prop('checked', true);
        } else {
            $('#selectAll, #checkAll').prop('checked', false);
        }
    });
});

// Validate IP address in real-time
function validateIp(ip) {
    const validationDiv = document.getElementById('ipValidation');
    
    if (!ip.trim()) {
        validationDiv.innerHTML = '';
        return;
    }
    
    // Check if it's a valid IP format
    let isValid = false;
    
    // Single IP validation
    if (ip.includes('/')) {
        // CIDR notation
        const parts = ip.split('/');
        if (parts.length === 2) {
            const ipPart = parts[0];
            const cidrPart = parseInt(parts[1]);
            isValid = validateSingleIp(ipPart) && !isNaN(cidrPart) && cidrPart >= 0 && cidrPart <= 128;
        }
    } else if (ip.includes('-')) {
        // IP range
        const parts = ip.split('-');
        if (parts.length === 2) {
            isValid = validateSingleIp(parts[0].trim()) && validateSingleIp(parts[1].trim());
        }
    } else {
        // Single IP
        isValid = validateSingleIp(ip);
    }
    
    if (isValid) {
        validationDiv.innerHTML = '<span class="validation-valid"><i class="fas fa-check-circle mr-1"></i> Valid IP format</span>';
        validationDiv.className = 'ip-validation validation-valid';
    } else {
        validationDiv.innerHTML = '<span class="validation-invalid"><i class="fas fa-times-circle mr-1"></i> Invalid IP format</span>';
        validationDiv.className = 'ip-validation validation-invalid';
    }
}

// Helper function to validate single IP
function validateSingleIp(ip) {
    // IPv4 validation
    const ipv4Pattern = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/;
    const ipv4Match = ip.match(ipv4Pattern);
    
    if (ipv4Match) {
        for (let i = 1; i <= 4; i++) {
            const octet = parseInt(ipv4Match[i]);
            if (octet < 0 || octet > 255) {
                return false;
            }
        }
        return true;
    }
    
    // IPv6 validation (simplified)
    const ipv6Pattern = /^([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}$/;
    if (ipv6Pattern.test(ip)) {
        return true;
    }
    
    // IPv6 compressed format
    const ipv6CompressedPattern = /^(([0-9a-fA-F]{1,4}:){0,6}[0-9a-fA-F]{1,4})?::(([0-9a-fA-F]{1,4}:){0,6}[0-9a-fA-F]{1,4})?$/;
    if (ipv6CompressedPattern.test(ip)) {
        return true;
    }
    
    return false;
}

// Add current IP address
function addCurrentIp() {
    // Get current IP using a service
    fetch('https://api.ipify.org?format=json')
        .then(response => response.json())
        .then(data => {
            document.getElementById('ip_address').value = data.ip;
            document.getElementById('description').value = 'Current IP - ' + new Date().toLocaleDateString();
            validateIp(data.ip);
            
            Toast.fire({
                icon: 'success',
                title: 'Current IP address detected and added to form.'
            });
        })
        .catch(error => {
            Toast.fire({
                icon: 'error',
                title: 'Could not detect current IP address.'
            });
        });
}

// Export IP list
function exportIpList() {
    const table = $('#ipTable').DataTable();
    const data = table.rows({ search: 'applied' }).data();
    let csvContent = "IP Address,Description,Status,Created\n";
    
    data.each(function(value, index) {
        const ip = value[1];
        const desc = value[2];
        const status = value[3];
        const created = value[4];
        csvContent += `"${ip}","${desc}","${status}","${created}"\n`;
    });
    
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'ip-whitelist-' + new Date().toISOString().split('T')[0] + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    
    Toast.fire({
        icon: 'success',
        title: 'IP list exported successfully!'
    });
}

// Clear all IPs
function clearAllIps() {
    Swal.fire({
        title: 'Clear All IPs?',
        text: 'This will remove all whitelisted IP addresses. This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, clear all',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = '?clear_all=1';
        }
    });
}

// Enable whitelisting
function enableWhitelisting() {
    Swal.fire({
        title: 'Enable IP Whitelisting?',
        text: 'When enabled, only whitelisted IPs can access your account. Make sure you have added your current IP.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Enable Whitelisting',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('api/enable-whitelist.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Toast.fire({
                        icon: 'success',
                        title: 'IP whitelisting has been enabled!'
                    });
                } else {
                    Toast.fire({
                        icon: 'error',
                        title: 'Failed to enable whitelisting.'
                    });
                }
            })
            .catch(error => {
                Toast.fire({
                    icon: 'error',
                    title: 'Error enabling whitelisting.'
                });
            });
        }
    });
}

// Confirm bulk action
function confirmBulkAction() {
    const action = document.querySelector('select[name="bulk_action"]').value;
    const selectedCount = document.querySelectorAll('.ip-checkbox:checked').length;
    
    if (!action) {
        Toast.fire({
            icon: 'warning',
            title: 'Please select a bulk action.'
        });
        return false;
    }
    
    if (selectedCount === 0) {
        Toast.fire({
            icon: 'warning',
            title: 'Please select at least one IP address.'
        });
        return false;
    }
    
    let message = '';
    switch(action) {
        case 'delete':
            message = `Are you sure you want to delete ${selectedCount} IP address(es)?`;
            break;
        case 'enable':
            message = `Are you sure you want to enable ${selectedCount} IP address(es)?`;
            break;
        case 'disable':
            message = `Are you sure you want to disable ${selectedCount} IP address(es)?`;
            break;
    }
    
    return confirm(message);
}

// Print report
window.printReport = function() {
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>IP Whitelist - <?php echo date('Y-m-d'); ?></title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h1 { color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th { background: #f8f9fc; color: #4e73df; padding: 12px; text-align: left; border-bottom: 2px solid #e3e6f0; }
                td { padding: 10px; border-bottom: 1px solid #eee; }
                .summary { background: #f8f9fc; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
                .active { color: green; }
                .inactive { color: gray; }
            </style>
        </head>
        <body>
            <h1>IP Whitelist Report</h1>
            <div class="summary">
                <strong>Generated:</strong> <?php echo date('F j, Y \a\t g:i A'); ?><br>
                <strong>Total IPs:</strong> <?php echo count($whitelistedIps); ?>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>IP Address</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Added On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($whitelistedIps as $ip): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($ip['ip_address']); ?></td>
                        <td><?php echo htmlspecialchars($ip['description'] ?? '-'); ?></td>
                        <td class="<?php echo $ip['is_active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $ip['is_active'] ? 'Active' : 'Inactive'; ?>
                        </td>
                        <td><?php echo date('Y-m-d', strtotime($ip['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="footer">
                Generated by <?php echo htmlspecialchars($advertiserName); ?> | GVS Icon Media
            </div>
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
};
</script>

</body>
</html>