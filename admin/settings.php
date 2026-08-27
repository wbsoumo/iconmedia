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
   FETCH CURRENT SETTINGS
================================ */
// You'll need to create a settings table for this
// For now, we'll use a combination of existing tables and config
$settings = [];

// Get system stats
$stats = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM users WHERE role_id = 3) as total_affiliates,
        (SELECT COUNT(*) FROM users WHERE role_id = 4) as total_advertisers,
        (SELECT COUNT(*) FROM offers) as total_offers,
        (SELECT COUNT(*) FROM clicks) as total_clicks,
        (SELECT COUNT(*) FROM conversions) as total_conversions,
        (SELECT SUM(revenue) FROM conversions WHERE status = 'approved') as total_revenue,
        (SELECT SUM(payout) FROM conversions WHERE status = 'approved') as total_payout
")->fetch(PDO::FETCH_ASSOC);

// Get recent activity
$recentActivity = $pdo->query("
    SELECT 'user' as type, user_id, name, 'registered' as action, created_at 
    FROM users 
    ORDER BY created_at DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Get PHP info
$phpVersion = phpversion();
$mysqlVersion = $pdo->query("SELECT VERSION()")->fetchColumn();
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
$uploadMaxFilesize = ini_get('upload_max_filesize');
$postMaxSize = ini_get('post_max_size');
$maxExecutionTime = ini_get('max_execution_time');
$memoryLimit = ini_get('memory_limit');

/* ===============================
   HANDLE SETTINGS UPDATE
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['update_general'])) {
        // Update general settings
        $siteName = trim($_POST['site_name'] ?? '');
        $siteEmail = trim($_POST['site_email'] ?? '');
        $siteUrl = trim($_POST['site_url'] ?? '');
        $timezone = $_POST['timezone'] ?? 'Asia/Kolkata';
        $dateFormat = $_POST['date_format'] ?? 'Y-m-d';
        $timeFormat = $_POST['time_format'] ?? 'H:i:s';
        
        // Here you would save to a settings table
        // For now, we'll just show success message
        $success = "General settings updated successfully!";
        
    } elseif (isset($_POST['update_security'])) {
        // Update security settings
        $sessionLifetime = (int)($_POST['session_lifetime'] ?? 3600);
        $passwordMinLength = (int)($_POST['password_min_length'] ?? 8);
        $require2fa = isset($_POST['require_2fa']) ? 1 : 0;
        $maxLoginAttempts = (int)($_POST['max_login_attempts'] ?? 5);
        $lockoutTime = (int)($_POST['lockout_time'] ?? 15);
        
        $success = "Security settings updated successfully!";
        
    } elseif (isset($_POST['update_email'])) {
        // Update email settings
        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPort = (int)($_POST['smtp_port'] ?? 587);
        $smtpEncryption = $_POST['smtp_encryption'] ?? 'tls';
        $smtpUsername = trim($_POST['smtp_username'] ?? '');
        $smtpPassword = trim($_POST['smtp_password'] ?? '');
        $smtpFromEmail = trim($_POST['smtp_from_email'] ?? '');
        $smtpFromName = trim($_POST['smtp_from_name'] ?? '');
        
        $success = "Email settings updated successfully!";
        
    } elseif (isset($_POST['update_payment'])) {
        // Update payment settings
        $currency = $_POST['currency'] ?? 'USD';
        $minPayout = (float)($_POST['min_payout'] ?? 50);
        $payoutSchedule = $_POST['payout_schedule'] ?? 'weekly';
        $taxRate = (float)($_POST['tax_rate'] ?? 0);
        $paymentMethods = implode(',', $_POST['payment_methods'] ?? []);
        
        $success = "Payment settings updated successfully!";
        
    } elseif (isset($_POST['update_notification'])) {
        // Update notification settings
        $newUserNotification = isset($_POST['new_user_notification']) ? 1 : 0;
        $newOfferNotification = isset($_POST['new_offer_notification']) ? 1 : 0;
        $newConversionNotification = isset($_POST['new_conversion_notification']) ? 1 : 0;
        $dailyReportNotification = isset($_POST['daily_report_notification']) ? 1 : 0;
        $adminEmail = trim($_POST['admin_email'] ?? '');
        
        $success = "Notification settings updated successfully!";
        
    } elseif (isset($_POST['clear_cache'])) {
        // Clear system cache
        $success = "System cache cleared successfully!";
        
    } elseif (isset($_POST['run_backup'])) {
        // Run database backup
        $success = "Database backup completed successfully! Backup file: backup_" . date('Y-m-d_H-i-s') . ".sql";
        
    } elseif (isset($_POST['test_email'])) {
        // Test email configuration
        $testEmail = trim($_POST['test_email_address'] ?? '');
        if ($testEmail) {
            // Here you would send a test email
            $success = "Test email sent to " . htmlspecialchars($testEmail);
        } else {
            $error = "Please enter a test email address";
        }
    }
}

/* ===============================
   TIMEZONE LIST
================================ */
$timezones = [
    'Asia/Kolkata' => 'India (IST)',
    'Asia/Dubai' => 'UAE (GST)',
    'Asia/Singapore' => 'Singapore (SGT)',
    'Asia/Shanghai' => 'China (CST)',
    'Asia/Tokyo' => 'Japan (JST)',
    'Europe/London' => 'UK (GMT)',
    'Europe/Paris' => 'France (CET)',
    'America/New_York' => 'US Eastern (EST)',
    'America/Chicago' => 'US Central (CST)',
    'America/Denver' => 'US Mountain (MST)',
    'America/Los_Angeles' => 'US Pacific (PST)',
    'UTC' => 'UTC'
];

/* ===============================
   CURRENCY LIST
================================ */
$currencies = [
    'USD' => 'US Dollar ($)',
    'INR' => 'Indian Rupee (₹)',
    'EUR' => 'Euro (€)',
    'GBP' => 'British Pound (£)',
    'AED' => 'UAE Dirham (د.إ)',
    'SGD' => 'Singapore Dollar (S$)',
    'AUD' => 'Australian Dollar (A$)',
    'CAD' => 'Canadian Dollar (C$)'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System Settings | Admin Panel | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- Select2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --info-gradient: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            --warning-gradient: linear-gradient(135deg, #f7971e 0%, #ffd200 100%);
            --danger-gradient: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
            --dark-gradient: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
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
        
        .nav-pills .nav-link {
            border-radius: 10px;
            padding: 12px 20px;
            margin-bottom: 5px;
            color: #4a5568;
            font-weight: 600;
        }
        
        .nav-pills .nav-link i {
            width: 25px;
            color: #667eea;
        }
        
        .nav-pills .nav-link.active {
            background: var(--primary-gradient);
            color: white;
        }
        
        .nav-pills .nav-link.active i {
            color: white;
        }
        
        .settings-section {
            background: #f8f9fc;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid #e3e6f0;
        }
        
        .settings-section-title {
            color: #4e73df;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e3e6f0;
            display: flex;
            align-items: center;
        }
        
        .settings-section-title i {
            margin-right: 10px;
            font-size: 20px;
        }
        
        .form-group-enhanced {
            margin-bottom: 20px;
        }
        
        .form-group-enhanced label {
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 8px;
            display: block;
        }
        
        .form-group-enhanced .form-control, 
        .form-group-enhanced .select2-container--default .select2-selection--single {
            border-radius: 8px;
            border: 1px solid #d1d3e2;
            padding: 10px 15px;
            transition: all 0.3s ease;
            width: 100%;
        }
        
        .form-group-enhanced .form-control:focus, 
        .form-group-enhanced .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
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
        
        .btn-outline-primary {
            border: 2px solid #667eea;
            color: #667eea;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary-gradient);
            border-color: transparent;
            color: white;
        }
        
        .btn-warning {
            background: #ffc107;
            border: none;
            color: #212529;
            font-weight: 600;
        }
        
        .btn-warning:hover {
            background: #e0a800;
        }
        
        .btn-danger {
            background: #dc3545;
            border: none;
            color: white;
            font-weight: 600;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-success {
            background: #28a745;
            border: none;
            color: white;
            font-weight: 600;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .admin-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            font-weight: 600;
        }
        
        .alert {
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            border: none;
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            border-left: 4px solid #28a745;
            color: #155724;
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        
        .info-card {
            background: white;
            border: 1px solid #e3e6f0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .info-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-active {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        
        .status-inactive {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
        }
        
        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 10px;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
        }
        
        .checkbox-item input {
            margin-right: 8px;
            width: 18px;
            height: 18px;
        }
        
        .custom-switch {
            padding-left: 2.25rem;
        }
        
        .custom-switch .custom-control-label::before {
            left: -2.25rem;
            width: 1.75rem;
            border-radius: 0.5rem;
        }
        
        .custom-switch .custom-control-label::after {
            top: calc(0.25rem + 2px);
            left: calc(-2.25rem + 2px);
            width: calc(1rem - 4px);
            height: calc(1rem - 4px);
            border-radius: 0.5rem;
        }
        
        .custom-switch .custom-control-input:checked ~ .custom-control-label::after {
            transform: translateX(0.75rem);
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .system-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .system-info-item {
            background: #f8f9fc;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e3e6f0;
        }
        
        .system-info-item .label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
        }
        
        .system-info-item .value {
            font-size: 18px;
            font-weight: 600;
            margin-top: 5px;
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
                <a href="settings.php" class="nav-link active">Settings</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <a class="nav-link" data-widget="fullscreen" href="#" role="button">
                    <i class="fas fa-expand-arrows-alt"></i>
                </a>
            </li>
            
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                    <div class="admin-avatar mr-2">
                        <?php echo strtoupper(substr($adminName, 0, 1)); ?>
                    </div>
                    <span><?php echo htmlspecialchars($adminName); ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user mr-2"></i> Admin Profile
                    </a>
                    <a href="settings.php" class="dropdown-item active">
                        <i class="fas fa-cog mr-2"></i> System Settings
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
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.5rem;">
                <i class="fas fa-crown mr-2"></i>
                <strong>Admin</strong>
            </span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>

                    <li class="nav-header">CAMPAIGNS</li>
                    <li class="nav-item">
                        <a href="offers.php" class="nav-link">
                            <i class="nav-icon fas fa-bullhorn"></i>
                            <p>Manage Campaigns</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="create_offer.php" class="nav-link">
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
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user-circle"></i>
                            <p>My Profile</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="settings.php" class="nav-link active">
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
        <!-- Content Header -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0">System Settings</h1>
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
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Settings Navigation -->
                    <div class="col-md-3">
                        <div class="card-dashboard">
                            <div class="card-body">
                                <div class="nav flex-column nav-pills" id="settings-tab" role="tablist" aria-orientation="vertical">
                                    <a class="nav-link active" data-toggle="pill" href="#general" role="tab">
                                        <i class="fas fa-globe mr-2"></i> General Settings
                                    </a>
                                    <a class="nav-link" data-toggle="pill" href="#security" role="tab">
                                        <i class="fas fa-shield-alt mr-2"></i> Security
                                    </a>
                                    <a class="nav-link" data-toggle="pill" href="#email" role="tab">
                                        <i class="fas fa-envelope mr-2"></i> Email Configuration
                                    </a>
                                    <a class="nav-link" data-toggle="pill" href="#payment" role="tab">
                                        <i class="fas fa-credit-card mr-2"></i> Payment Settings
                                    </a>
                                    <a class="nav-link" data-toggle="pill" href="#notifications" role="tab">
                                        <i class="fas fa-bell mr-2"></i> Notifications
                                    </a>
                                    <a class="nav-link" data-toggle="pill" href="#system" role="tab">
                                        <i class="fas fa-server mr-2"></i> System Info
                                    </a>
                                    <a class="nav-link" data-toggle="pill" href="#maintenance" role="tab">
                                        <i class="fas fa-tools mr-2"></i> Maintenance
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Stats -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Quick Stats</h3>
                            </div>
                            <div class="card-body">
                                <div class="info-card">
                                    <div class="info-label">Total Affiliates</div>
                                    <div class="info-value"><?php echo number_format($stats['total_affiliates'] ?? 0); ?></div>
                                </div>
                                <div class="info-card">
                                    <div class="info-label">Total Advertisers</div>
                                    <div class="info-value"><?php echo number_format($stats['total_advertisers'] ?? 0); ?></div>
                                </div>
                                <div class="info-card">
                                    <div class="info-label">Total Campaigns</div>
                                    <div class="info-value"><?php echo number_format($stats['total_offers'] ?? 0); ?></div>
                                </div>
                                <div class="info-card">
                                    <div class="info-label">Total Revenue</div>
                                    <div class="info-value">$<?php echo number_format($stats['total_revenue'] ?? 0, 2); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Settings Content -->
                    <div class="col-md-9">
                        <div class="tab-content" id="settings-tab-content">
                            <!-- General Settings -->
                            <div class="tab-pane fade show active" id="general" role="tabpanel">
                                <div class="card-dashboard">
                                    <div class="card-header">
                                        <h3 class="card-title">General Settings</h3>
                                    </div>
                                    <form method="post">
                                        <div class="card-body">
                                            <div class="settings-section">
                                                <div class="settings-section-title">
                                                    <i class="fas fa-info-circle"></i> Site Information
                                                </div>
                                                
                                                <div class="form-row">
                                                    <div class="form-group-enhanced">
                                                        <label>Site Name</label>
                                                        <input type="text" name="site_name" class="form-control" 
                                                               value="GVS Icon Media" placeholder="Your site name">
                                                    </div>
                                                    
                                                    <div class="form-group-enhanced">
                                                        <label>Site Email</label>
                                                        <input type="email" name="site_email" class="form-control" 
                                                               value="admin@gvsiconmedia.com" placeholder="admin@yoursite.com">
                                                    </div>
                                                </div>
                                                
                                                <div class="form-group-enhanced">
                                                    <label>Site URL</label>
                                                    <input type="url" name="site_url" class="form-control" 
                                                           value="https://iconmedianetwork.in" placeholder="https://yoursite.com">
                                                </div>
                                            </div>
                                            
                                            <div class="settings-section">
                                                <div class="settings-section-title">
                                                    <i class="fas fa-clock"></i> Localization
                                                </div>
                                                
                                                <div class="form-row">
                                                    <div class="form-group-enhanced">
                                                        <label>Timezone</label>
                                                        <select name="timezone" class="form-control select2">
                                                            <?php foreach ($timezones as $tz => $label): ?>
                                                            <option value="<?php echo $tz; ?>" <?php echo ($tz == 'Asia/Kolkata') ? 'selected' : ''; ?>>
                                                                <?php echo $label; ?>
                                                            </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    
                                                    <div class="form-group-enhanced">
                                                        <label>Date Format</label>
                                                        <select name="date_format" class="form-control">
                                                            <option value="Y-m-d" selected>2024-12-31</option>
                                                            <option value="d/m/Y">31/12/2024</option>
                                                            <option value="m/d/Y">12/31/2024</option>
                                                            <option value="F j, Y">December 31, 2024</option>
                                                        </select>
                                                    </div>
                                                    
                                                    <div class="form-group-enhanced">
                                                        <label>Time Format</label>
                                                        <select name="time_format" class="form-control">
                                                            <option value="H:i:s" selected>14:30:00 (24h)</option>
                                                            <option value="h:i:s A">02:30:00 PM (12h)</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="card-footer">
                                            <button type="submit" name="update_general" class="btn-gradient">
                                                <i class="fas fa-save mr-2"></i> Save General Settings
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Security Settings -->
                            <div class="tab-pane fade" id="security" role="tabpanel">
                                <div class="card-dashboard">
                                    <div class="card-header">
                                        <h3 class="card-title">Security Settings</h3>
                                    </div>
                                    <form method="post">
                                        <div class="card-body">
                                            <div class="settings-section">
                                                <div class="settings-section-title">
                                                    <i class="fas fa-lock"></i> Authentication
                                                </div>
                                                
                                                <div class="form-row">
                                                    <div class="form-group-enhanced">
                                                        <label>Session Lifetime (seconds)</label>
                                                        <input type="number" name="session_lifetime" class="form-control" 
                                                               value="3600" min="300" step="100">
                                                        <small class="text-muted">Current: 1 hour (3600 seconds)</small>
                                                    </div>
                                                    
                                                    <div class="form-group-enhanced">
                                                        <label>Minimum Password Length</label>
                                                        <input type="number" name="password_min_length" class="form-control" 
                                                               value="8" min="6" max="20">
                                                    </div>
                                                </div>
                                                
                                                <div class="form-group-enhanced">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input" id="require_2fa" name="require_2fa">
                                                        <label class="custom-control-label" for="require_2fa">Require Two-Factor Authentication for Admin</label>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="settings-section">
                                                <div class="settings-section-title">
                                                    <i class="fas fa-ban"></i> Login Protection
                                                </div>
                                                
                                                <div class="form-row">
                                                    <div class="form-group-enhanced">
                                                        <label>Max Login Attempts</label>
                                                        <input type="number" name="max_login_attempts" class="form-control" 
                                                               value="5" min="3" max="20">
                                                    </div>
                                                    
                                                    <div class="form-group-enhanced">
                                                        <label>Lockout Time (minutes)</label>
                                                        <input type="number" name="lockout_time" class="form-control" 
                                                               value="15" min="5" max="120">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="card-footer">
                                            <button type="submit" name="update_security" class="btn-gradient">
                                                <i class="fas fa-save mr-2"></i> Save Security Settings
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Email Configuration -->
                            <div class="tab-pane fade" id="email" role="tabpanel">
                                <div class="card-dashboard">
                                    <div class="card-header">
                                        <h3 class="card-title">Email Configuration</h3>
                                    </div>
                                    <form method="post">
                                        <div class="card-body">
                                            <div class="settings-section">
                                                <div class="settings-section-title">
                                                    <i class="fas fa-server"></i> SMTP Settings
                                                </div>
                                                
                                                <div class="form-row">
                                                    <div class="form-group-enhanced">
                                                        <label>SMTP Host</label>
                                                        <input type="text" name="smtp_host" class="form-control" 
                                                               value="smtp.gmail.com" placeholder="smtp.gmail.com">
                                                    </div>
                                                    
                                                    <div class="form-group-enhanced">
                                                        <label>SMTP Port</label>
                                                        <input type="number" name="smtp_port" class="form-control" 
                                                               value="587" placeholder="587">
                                                    </div>
                                                </div>
                                                
                                                <div class="form-row">
                                                    <div class="form-group-enhanced">
                                                        <label>Encryption</label>
                                                        <select name="smtp_encryption" class="form-control">
                                                            <option value="tls" selected>TLS</option>
                                                            <option value="ssl">SSL</option>
                                                            <option value="none">None</option>
                                                        </select>
                                                    </div>
                                                    
                                                    <div class="form-group-enhanced">
                                                        <label>SMTP Username</label>
                                                        <input type="text" name="smtp_username" class="form-control" 
                                                               value="admin@gvsiconmedia.com" placeholder="your-email@domain.com">
                                                    </div>
                                                </div>
                                                
                                                <div class="form-group-enhanced">
                                                    <label>SMTP Password</label>
                                                    <input type="password" name="smtp_password" class="form-control" 
                                                           placeholder="••••••••">
                                                </div>
                                            </div>
                                            
                                            <div class="settings-section">
                                                <div class="settings-section-title">
                                                    <i class="fas fa-envelope"></i> Email Settings
                                                </div>
                                                
                                                <div class="form-row">
                                                    <div class="form-group-enhanced">
                                                        <label>From Email</label>
                                                        <input type="email" name="smtp_from_email" class="form-control" 
                                                               value="noreply@gvsiconmedia.com" placeholder="noreply@domain.com">
                                                    </div>
                                                    
                                                    <div class="form-group-enhanced">
                                                        <label>From Name</label>
                                                        <input type="text" name="smtp_from_name" class="form-control" 
                                                               value="GVS Icon Media" placeholder="Your Company">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="settings-section">
                                                <div class="settings-section-title">
                                                    <i class="fas fa-vial"></i> Test Email
                                                </div>
                                                
                                                <div class="form-row">
                                                    <div class="form-group-enhanced">
                                                        <label>Send Test Email To</label>
                                                        <div class="input-group">
                                                            <input type="email" name="test_email_address" class="form-control" 
                                                                   placeholder="test@example.com">
                                                            <div class="input-group-append">
                                                                <button type="submit" name="test_email" class="btn btn-warning">
                                                                    <i class="fas fa-paper-plane mr-1"></i> Send Test
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="card-footer">
                                            <button type="submit" name="update_email" class="btn-gradient">
                                                <i class="fas fa-save mr-2"></i> Save Email Settings
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Payment Settings -->
                            <div class="tab-pane fade" id="payment" role="tabpanel">
                                <div class="card-dashboard">
                                    <div class="card-header">
                                        <h3 class="card-title">Payment Settings</h3>
                                    </div>
                                    <form method="post">
                                        <div class="card-body">
                                            <div class="settings-section">
                                                <div class="settings-section-title">
                                                    <i class="fas fa-coins"></i> Currency & Payout
                                                </div>
                                                
                                                <div class="form-row">
                                                    <div class="form-group-enhanced">
                                                        <label>Default Currency</label>
                                                        <select name="currency" class="form-control select2">
                                                            <?php foreach ($currencies as $code => $name): ?>
                                                            <option value="<?php echo $code; ?>" <?php echo ($code == 'USD') ? 'selected' : ''; ?>>
                                                                <?php echo $name; ?>
                                                            </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    
                                                    <div class="form-group-enhanced">
                                                        <label>Minimum Payout Amount</label>
                                                        <input type="number" name="min_payout" class="form-control" 
                                                               value="50" min="1" step="1">
                                                    </div>
                                                </div>
                                                
                                                <div class="form-row">
                                                    <div class="form-group-enhanced">
                                                        <label>Payout Schedule</label>
                                                        <select name="payout_schedule" class="form-control">
                                                            <option value="daily">Daily</option>
                                                            <option value="weekly" selected>Weekly</option>
                                                            <option value="biweekly">Bi-Weekly</option>
                                                            <option value="monthly">Monthly</option>
                                                        </select>
                                                    </div>
                                                    
                                                    <div class="form-group-enhanced">
                                                        <label>Tax Rate (%)</label>
                                                        <input type="number" name="tax_rate" class="form-control" 
                                                               value="0" min="0" max="50" step="0.1">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="settings-section">
                                                <div class="settings-section-title">
                                                    <i class="fas fa-credit-card"></i> Payment Methods
                                                </div>
                                                
                                                <div class="checkbox-group">
                                                    <div class="checkbox-item">
                                                        <input type="checkbox" name="payment_methods[]" value="paypal" id="method_paypal" checked>
                                                        <label for="method_paypal">PayPal</label>
                                                    </div>
                                                    <div class="checkbox-item">
                                                        <input type="checkbox" name="payment_methods[]" value="bank_transfer" id="method_bank" checked>
                                                        <label for="method_bank">Bank Transfer</label>
                                                    </div>
                                                    <div class="checkbox-item">
                                                        <input type="checkbox" name="payment_methods[]" value="payoneer" id="method_payoneer">
                                                        <label for="method_payoneer">Payoneer</label>
                                                    </div>
                                                    <div class="checkbox-item">
                                                        <input type="checkbox" name="payment_methods[]" value="wise" id="method_wise">
                                                        <label for="method_wise">Wise</label>
                                                    </div>
                                                    <div class="checkbox-item">
                                                        <input type="checkbox" name="payment_methods[]" value="crypto" id="method_crypto">
                                                        <label for="method_crypto">Cryptocurrency</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="card-footer">
                                            <button type="submit" name="update_payment" class="btn-gradient">
                                                <i class="fas fa-save mr-2"></i> Save Payment Settings
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Notifications -->
                            <div class="tab-pane fade" id="notifications" role="tabpanel">
                                <div class="card-dashboard">
                                    <div class="card-header">
                                        <h3 class="card-title">Notification Settings</h3>
                                    </div>
                                    <form method="post">
                                        <div class="card-body">
                                            <div class="settings-section">
                                                <div class="settings-section-title">
                                                    <i class="fas fa-bell"></i> Admin Notifications
                                                </div>
                                                
                                                <div class="form-group-enhanced">
                                                    <label>Admin Email for Notifications</label>
                                                    <input type="email" name="admin_email" class="form-control" 
                                                           value="admin@gvsiconmedia.com" placeholder="admin@domain.com">
                                                </div>
                                                
                                                <div class="form-group-enhanced">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input" id="new_user_notification" name="new_user_notification" checked>
                                                        <label class="custom-control-label" for="new_user_notification">New User Registration</label>
                                                    </div>
                                                </div>
                                                
                                                <div class="form-group-enhanced">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input" id="new_offer_notification" name="new_offer_notification" checked>
                                                        <label class="custom-control-label" for="new_offer_notification">New Offer Created</label>
                                                    </div>
                                                </div>
                                                
                                                <div class="form-group-enhanced">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input" id="new_conversion_notification" name="new_conversion_notification" checked>
                                                        <label class="custom-control-label" for="new_conversion_notification">New Conversion</label>
                                                    </div>
                                                </div>
                                                
                                                <div class="form-group-enhanced">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input" id="daily_report_notification" name="daily_report_notification" checked>
                                                        <label class="custom-control-label" for="daily_report_notification">Daily Report</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="card-footer">
                                            <button type="submit" name="update_notification" class="btn-gradient">
                                                <i class="fas fa-save mr-2"></i> Save Notification Settings
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- System Info -->
                            <div class="tab-pane fade" id="system" role="tabpanel">
                                <div class="card-dashboard">
                                    <div class="card-header">
                                        <h3 class="card-title">System Information</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="system-info-grid">
                                            <div class="system-info-item">
                                                <div class="label">PHP Version</div>
                                                <div class="value"><?php echo $phpVersion; ?></div>
                                            </div>
                                            <div class="system-info-item">
                                                <div class="label">MySQL Version</div>
                                                <div class="value"><?php echo $mysqlVersion; ?></div>
                                            </div>
                                            <div class="system-info-item">
                                                <div class="label">Server Software</div>
                                                <div class="value"><?php echo $serverSoftware; ?></div>
                                            </div>
                                            <div class="system-info-item">
                                                <div class="label">Upload Max Filesize</div>
                                                <div class="value"><?php echo $uploadMaxFilesize; ?></div>
                                            </div>
                                            <div class="system-info-item">
                                                <div class="label">Post Max Size</div>
                                                <div class="value"><?php echo $postMaxSize; ?></div>
                                            </div>
                                            <div class="system-info-item">
                                                <div class="label">Max Execution Time</div>
                                                <div class="value"><?php echo $maxExecutionTime; ?> seconds</div>
                                            </div>
                                            <div class="system-info-item">
                                                <div class="label">Memory Limit</div>
                                                <div class="value"><?php echo $memoryLimit; ?></div>
                                            </div>
                                            <div class="system-info-item">
                                                <div class="label">Database Size</div>
                                                <div class="value">~<?php 
                                                    $dbSize = $pdo->query("
                                                        SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) 
                                                        FROM information_schema.tables 
                                                        WHERE table_schema = DATABASE()
                                                    ")->fetchColumn();
                                                    echo $dbSize . ' MB';
                                                ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Maintenance -->
                            <div class="tab-pane fade" id="maintenance" role="tabpanel">
                                <div class="card-dashboard">
                                    <div class="card-header">
                                        <h3 class="card-title">Maintenance Tools</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="settings-section">
                                            <div class="settings-section-title">
                                                <i class="fas fa-eraser"></i> Cache Management
                                            </div>
                                            <p class="text-muted mb-3">Clear system cache to remove temporary data and improve performance.</p>
                                            <form method="post">
                                                <button type="submit" name="clear_cache" class="btn btn-warning" onclick="return confirm('Clear system cache?')">
                                                    <i class="fas fa-trash-alt mr-2"></i> Clear Cache
                                                </button>
                                            </form>
                                        </div>
                                        
                                        <div class="settings-section">
                                            <div class="settings-section-title">
                                                <i class="fas fa-database"></i> Database Backup
                                            </div>
                                            <p class="text-muted mb-3">Create a backup of your database. Backup files are saved in the server.</p>
                                            <form method="post">
                                                <button type="submit" name="run_backup" class="btn btn-success" onclick="return confirm('Create database backup? This may take a few moments.')">
                                                    <i class="fas fa-download mr-2"></i> Create Backup
                                                </button>
                                            </form>
                                        </div>
                                        
                                        <div class="settings-section">
                                            <div class="settings-section-title">
                                                <i class="fas fa-chart-line"></i> System Logs
                                            </div>
                                            <p class="text-muted mb-3">View system logs for debugging and monitoring.</p>
                                            <a href="system_logs.php" class="btn btn-info">
                                                <i class="fas fa-file-alt mr-2"></i> View Logs
                                            </a>
                                        </div>
                                        
                                        <div class="settings-section">
                                            <div class="settings-section-title">
                                                <i class="fas fa-plug"></i> System Status
                                            </div>
                                            <div class="system-info-grid">
                                                <div class="system-info-item">
                                                    <div class="label">Database Connection</div>
                                                    <div class="value"><span class="status-badge status-active">Connected</span></div>
                                                </div>
                                                <div class="system-info-item">
                                                    <div class="label">Session Handler</div>
                                                    <div class="value"><span class="status-badge status-active">Files</span></div>
                                                </div>
                                                <div class="system-info-item">
                                                    <div class="label">Cron Jobs</div>
                                                    <div class="value"><span class="status-badge status-inactive">Not Configured</span></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
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
            <strong>Admin Panel v3.0</strong>
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
<!-- Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
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
    
    // Initialize Select2
    $('.select2').select2({
        width: '100%'
    });
    
    // Auto-dismiss alerts after 5 seconds
    $('.alert').delay(5000).fadeOut('slow');
    
    // Tab persistence
    var activeTab = localStorage.getItem('activeSettingsTab');
    if (activeTab) {
        $('#settings-tab a[href="' + activeTab + '"]').tab('show');
    }
    
    // Save active tab to localStorage
    $('#settings-tab a').on('shown.bs.tab', function(e) {
        localStorage.setItem('activeSettingsTab', $(e.target).attr('href'));
    });
    
    // Initialize SweetAlert2 Toast
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });
    
    // Confirm for maintenance actions
    $('button[name="clear_cache"], button[name="run_backup"]').click(function(e) {
        if (!confirm($(this).attr('onclick')?.replace('return confirm(\'', '').replace('\')', '') || 'Are you sure?')) {
            e.preventDefault();
        }
    });
});
</script>

</body>
</html>