<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

// Get advertiser ID from URL
$advertiserId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$advertiserId) {
    header('Location: advertisers.php?error=Invalid advertiser ID');
    exit;
}

/* ===============================
   FETCH ADVERTISER DATA
================================ */
$stmt = $pdo->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.mobile,
        u.telegram_id,
        u.teams_id,
        u.company,
        u.balance,
        u.status,
        u.kyc_status,
        u.payout_enabled,
        u.account_manager_id,
        u.profile_image,
        u.bio,
        u.department,
        u.designation,
        u.notification_email,
        u.notification_sms,
        u.theme_preference,
        u.two_factor_enabled,
        u.created_at,
        u.last_login_at,
        u.last_login_ip,
        
        -- Account manager info
        am.name AS account_manager_name,
        am.email AS account_manager_email,
        
        -- Stats
        COUNT(DISTINCT o.offer_id) AS total_offers,
        COUNT(DISTINCT CASE WHEN o.status = 'active' THEN o.offer_id END) AS active_offers,
        COUNT(DISTINCT CASE WHEN o.status = 'pending' THEN o.offer_id END) AS pending_offers,
        COUNT(DISTINCT CASE WHEN o.status = 'approved' THEN o.offer_id END) AS approved_offers,
        
        -- Financial stats
        SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END) AS total_revenue,
        SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END) AS total_payout,
        SUM(CASE WHEN cv.status = 'approved' THEN (cv.revenue - cv.payout) ELSE 0 END) AS total_profit,
        
        -- Conversion stats
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        COUNT(DISTINCT CASE WHEN cv.status = 'approved' THEN cv.conversion_id END) AS approved_conversions,
        
        -- Click stats
        COUNT(DISTINCT c.click_id) AS total_clicks
        
    FROM users u
    LEFT JOIN users am ON am.user_id = u.account_manager_id AND am.role_id = 2
    LEFT JOIN offers o ON o.advertiser_id = u.user_id
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE u.user_id = :user_id AND u.role_id = 4
    GROUP BY u.user_id
");

$stmt->execute(['user_id' => $advertiserId]);
$advertiser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$advertiser) {
    header('Location: advertisers.php?error=Advertiser not found');
    exit;
}

/* ===============================
   FETCH ACCOUNT MANAGERS FOR DROPDOWN
================================ */
$accountManagers = $pdo->query("
    SELECT user_id, name, email 
    FROM users 
    WHERE role_id = 2 AND status = 'active'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   HANDLE FORM SUBMIT
================================ */
/* ===============================
   HANDLE FORM SUBMIT
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Get form data with proper defaults
    $name               = trim($_POST['name'] ?? '');
    $email              = trim($_POST['email'] ?? '');
    $mobile             = trim($_POST['mobile'] ?? '');
    $telegramId         = trim($_POST['telegram_id'] ?? '');
    $teamsId            = trim($_POST['teams_id'] ?? '');
    $company            = trim($_POST['company'] ?? '');
    $bio                = trim($_POST['bio'] ?? '');
    $department         = trim($_POST['department'] ?? '');
    $designation        = trim($_POST['designation'] ?? '');
    $status             = $_POST['status'] ?? $advertiser['status'];
    $kycStatus          = $_POST['kyc_status'] ?? $advertiser['kyc_status'];
    $payoutEnabled      = isset($_POST['payout_enabled']) ? 1 : 0;
    $accountManagerId   = !empty($_POST['account_manager_id']) ? (int)$_POST['account_manager_id'] : null;
    $notificationEmail  = isset($_POST['notification_email']) ? 1 : 0;
    $notificationSms    = isset($_POST['notification_sms']) ? 1 : 0;
    $themePreference    = $_POST['theme_preference'] ?? 'light';
    $balance            = (float)($_POST['balance'] ?? 0);

    /* BASIC VALIDATION */
    if ($name === '' || $email === '') {
        $error = 'Name and Email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } else {
        
        // Check if email already exists for another user
        $checkStmt = $pdo->prepare("
            SELECT user_id FROM users 
            WHERE email = :email AND user_id != :user_id AND role_id = 4
        ");
        $checkStmt->execute([
            'email' => $email,
            'user_id' => $advertiserId
        ]);
        
        if ($checkStmt->fetch()) {
            $error = 'Email already exists for another advertiser.';
        } else {
            
            // CORRECTED: All placeholders must match exactly
            $sql = "
                UPDATE users SET
                    name = :name,
                    email = :email,
                    mobile = :mobile,
                    telegram_id = :telegram_id,
                    teams_id = :teams_id,
                    company = :company,
                    bio = :bio,
                    department = :department,
                    designation = :designation,
                    status = :status,
                    kyc_status = :kyc_status,
                    payout_enabled = :payout_enabled,
                    account_manager_id = :account_manager_id,
                    notification_email = :notification_email,
                    notification_sms = :notification_sms,
                    theme_preference = :theme_preference,
                    balance = :balance,
                    updated_at = NOW()
                WHERE user_id = :user_id AND role_id = 4
            ";
            
            $stmt = $pdo->prepare($sql);
            
            // CORRECTED: Parameter array with all placeholders
            $params = [
                'user_id'             => $advertiserId,
                'name'                => $name,
                'email'               => $email,
                'mobile'              => $mobile,
                'telegram_id'         => $telegramId,
                'teams_id'            => $teamsId,
                'company'             => $company,
                'bio'                 => $bio,
                'department'          => $department,
                'designation'         => $designation,
                'status'              => $status,
                'kyc_status'          => $kycStatus,
                'payout_enabled'      => $payoutEnabled,
                'account_manager_id'  => $accountManagerId,
                'notification_email'  => $notificationEmail,
                'notification_sms'    => $notificationSms,
                'theme_preference'    => $themePreference,
                'balance'             => $balance
            ];
            
            // LINE 192 - Execute with params
            $result = $stmt->execute($params);

            if ($result) {
                $success = "Advertiser updated successfully!";
                
                // Refresh advertiser data
                $refreshStmt = $pdo->prepare("
                    SELECT 
                        u.*,
                        am.name AS account_manager_name,
                        am.email AS account_manager_email,
                        COUNT(DISTINCT o.offer_id) AS total_offers,
                        COUNT(DISTINCT CASE WHEN o.status = 'active' THEN o.offer_id END) AS active_offers,
                        SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END) AS total_revenue,
                        SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END) AS total_payout,
                        SUM(CASE WHEN cv.status = 'approved' THEN (cv.revenue - cv.payout) ELSE 0 END) AS total_profit
                    FROM users u
                    LEFT JOIN users am ON am.user_id = u.account_manager_id AND am.role_id = 2
                    LEFT JOIN offers o ON o.advertiser_id = u.user_id
                    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
                    WHERE u.user_id = :user_id AND u.role_id = 4
                    GROUP BY u.user_id
                ");
                $refreshStmt->execute(['user_id' => $advertiserId]);
                $advertiser = $refreshStmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $error = "Failed to update advertiser. Please try again.";
            }
        }
    }
}

/* ===============================
   FETCH RECENT OFFERS FOR THIS ADVERTISER
================================ */
$recentOffers = $pdo->prepare("
    SELECT 
        o.offer_id,
        o.offer_name,
        o.payout,
        o.revenue,
        o.status,
        o.created_at,
        COUNT(DISTINCT c.click_id) AS clicks,
        COUNT(DISTINCT cv.conversion_id) AS conversions
    FROM offers o
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE o.advertiser_id = ?
    GROUP BY o.offer_id
    ORDER BY o.created_at DESC
    LIMIT 10
");
$recentOffers->execute([$advertiserId]);
$offers = $recentOffers->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Advertiser | Admin Panel | GVS Icon Media</title>
    
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
            --purple-gradient: linear-gradient(135deg, #9f7aea 0%, #667eea 100%);
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
            padding: 30px;
        }
        
        .form-section {
            background: #f8f9fc;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid #e3e6f0;
        }
        
        .form-section-title {
            color: #4e73df;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e3e6f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .form-section-title i {
            margin-right: 10px;
            font-size: 20px;
        }
        
        .form-group-enhanced {
            margin-bottom: 25px;
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
        
        .form-control:disabled, .form-control[readonly] {
            background-color: #f8f9fc;
            opacity: 1;
        }
        
        .btn-gradient {
            background: var(--primary-gradient);
            border: none;
            color: white;
            font-weight: 600;
            padding: 12px 30px;
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
        
        .btn-danger {
            background: #dc3545;
            border: none;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e3e6f0;
        }
        
        .info-box {
            background: linear-gradient(135deg, #f8f9fc 0%, #eaecf4 100%);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #e3e6f0;
        }
        
        .info-box-icon {
            width: 40px;
            height: 40px;
            background: var(--primary-gradient);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            margin-bottom: 10px;
        }
        
        .required::after {
            content: ' *';
            color: #e74a3b;
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
        
        .advertiser-avatar-lg {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            font-weight: 600;
            margin: 0 auto 20px;
            border: 4px solid white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
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
        }
        
        .status-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }
        
        .status-blocked {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }
        
        .kyc-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .kyc-verified {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        
        .kyc-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }
        
        .kyc-rejected {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }
        
        .metric-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            border: 1px solid #e3e6f0;
        }
        
        .metric-value {
            font-size: 24px;
            font-weight: 700;
            color: #4e73df;
        }
        
        .metric-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
        }
        
        .performance-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .profit-positive {
            color: #28a745;
        }
        
        .profit-negative {
            color: #dc3545;
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
        
        .avatar-upload {
            position: relative;
            display: inline-block;
        }
        
        .avatar-upload-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            background: var(--primary-gradient);
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 2px solid white;
        }
        
        .avatar-upload input[type="file"] {
            display: none;
        }
        
        .manager-badge {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
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
                <a href="advertisers.php" class="nav-link">Advertisers</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="advertiser_edit.php?id=<?php echo $advertiserId; ?>" class="nav-link active">Edit Advertiser</a>
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
                    <a href="settings.php" class="dropdown-item">
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
                    <li class="nav-item">
                        <a href="advertiser_edit.php?id=<?php echo $advertiserId; ?>" class="nav-link active">
                            <i class="nav-icon fas fa-edit"></i>
                            <p>Edit Advertiser</p>
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
        <!-- Content Header -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0">Edit Advertiser</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="advertisers.php">Advertisers</a></li>
                            <li class="breadcrumb-item active">Edit Advertiser #<?php echo $advertiserId; ?></li>
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
                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                    <h5><i class="icon fas fa-check"></i> Success!</h5>
                    <p><?php echo htmlspecialchars($success); ?></p>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                    <h5><i class="icon fas fa-ban"></i> Error!</h5>
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
                <?php endif; ?>

                <!-- Advertiser Summary -->
                <div class="row">
                    <div class="col-md-4">
                        <div class="card-dashboard">
                            <div class="card-body text-center">
                                <div class="avatar-upload">
                                    <div class="advertiser-avatar-lg">
                                        <?php echo strtoupper(substr($advertiser['name'], 0, 1)); ?>
                                    </div>
                                    <form method="post" enctype="multipart/form-data" id="avatarForm">
                                        <label for="profile_image" class="avatar-upload-btn">
                                            <i class="fas fa-camera"></i>
                                        </label>
                                        <input type="file" id="profile_image" name="profile_image" accept="image/*">
                                        <input type="hidden" name="update_image" value="1">
                                    </form>
                                </div>
                                <h4 class="mt-3"><?php echo htmlspecialchars($advertiser['name']); ?></h4>
                                <p class="text-muted">
                                    <i class="fas fa-envelope mr-1"></i> <?php echo htmlspecialchars($advertiser['email']); ?>
                                </p>
                                <div class="d-flex justify-content-center gap-2">
                                    <span class="status-badge status-<?php echo $advertiser['status']; ?>">
                                        <?php echo ucfirst($advertiser['status']); ?>
                                    </span>
                                    <span class="kyc-badge kyc-<?php echo $advertiser['kyc_status'] ?? 'pending'; ?>">
                                        KYC: <?php echo ucfirst($advertiser['kyc_status'] ?? 'pending'); ?>
                                    </span>
                                </div>
                                <div class="mt-3">
                                    <small class="text-muted">Member since: <?php echo date('F d, Y', strtotime($advertiser['created_at'])); ?></small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-chart-pie mr-2"></i> Quick Stats
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="metric-card mb-3">
                                    <div class="metric-label">Total Balance</div>
                                    <div class="metric-value">$<?php echo number_format($advertiser['balance'] ?? 0, 2); ?></div>
                                </div>
                                <div class="metric-card mb-3">
                                    <div class="metric-label">Total Revenue</div>
                                    <div class="metric-value">$<?php echo number_format($advertiser['total_revenue'] ?? 0, 2); ?></div>
                                </div>
                                <div class="metric-card mb-3">
                                    <div class="metric-label">Total Payout</div>
                                    <div class="metric-value">$<?php echo number_format($advertiser['total_payout'] ?? 0, 2); ?></div>
                                </div>
                                <div class="metric-card">
                                    <div class="metric-label">Total Profit</div>
                                    <div class="metric-value <?php echo ($advertiser['total_profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                        $<?php echo number_format($advertiser['total_profit'] ?? 0, 2); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-8">
                        <!-- Edit Form -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-edit mr-2"></i> Edit Advertiser Information
                                </h3>
                                <div class="card-tools">
                                    <span class="badge badge-light">ID: #<?php echo $advertiserId; ?></span>
                                </div>
                            </div>
                            
                            <form method="post" id="editAdvertiserForm">
                                <div class="card-body">
                                    <!-- Basic Information -->
                                    <div class="form-section">
                                        <div class="form-section-title">
                                            <i class="fas fa-user"></i> Basic Information
                                        </div>
                                        
                                        <div class="form-row">
                                            <div class="form-group-enhanced">
                                                <label class="required">Full Name</label>
                                                <input type="text" name="name" class="form-control" required 
                                                       value="<?php echo htmlspecialchars($advertiser['name']); ?>">
                                            </div>
                                            
                                            <div class="form-group-enhanced">
                                                <label class="required">Email Address</label>
                                                <input type="email" name="email" class="form-control" required 
                                                       value="<?php echo htmlspecialchars($advertiser['email']); ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="form-row">
                                            <div class="form-group-enhanced">
                                                <label>Mobile Number</label>
                                                <input type="text" name="mobile" class="form-control" 
                                                       value="<?php echo htmlspecialchars($advertiser['mobile'] ?? ''); ?>">
                                            </div>
                                            
                                            <div class="form-group-enhanced">
                                                <label>Company</label>
                                                <input type="text" name="company" class="form-control" 
                                                       value="<?php echo htmlspecialchars($advertiser['company'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="form-row">
                                            <div class="form-group-enhanced">
                                                <label>Telegram ID</label>
                                                <input type="text" name="telegram_id" class="form-control" 
                                                       value="<?php echo htmlspecialchars($advertiser['telegram_id'] ?? ''); ?>">
                                            </div>
                                            
                                            <div class="form-group-enhanced">
                                                <label>Teams ID</label>
                                                <input type="text" name="teams_id" class="form-control" 
                                                       value="<?php echo htmlspecialchars($advertiser['teams_id'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="form-group-enhanced">
                                            <label>Bio / Description</label>
                                            <textarea name="bio" class="form-control" rows="3"><?php echo htmlspecialchars($advertiser['bio'] ?? ''); ?></textarea>
                                        </div>
                                        
                                        <div class="form-row">
                                            <div class="form-group-enhanced">
                                                <label>Department</label>
                                                <input type="text" name="department" class="form-control" 
                                                       value="<?php echo htmlspecialchars($advertiser['department'] ?? ''); ?>">
                                            </div>
                                            
                                            <div class="form-group-enhanced">
                                                <label>Designation</label>
                                                <input type="text" name="designation" class="form-control" 
                                                       value="<?php echo htmlspecialchars($advertiser['designation'] ?? ''); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Account Settings -->
                                    <div class="form-section">
                                        <div class="form-section-title">
                                            <i class="fas fa-cog"></i> Account Settings
                                        </div>
                                        
                                        <div class="form-row">
                                            <div class="form-group-enhanced">
                                                <label>Status</label>
                                                <select name="status" class="form-control">
                                                    <option value="pending" <?php echo $advertiser['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="active" <?php echo $advertiser['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                                    <option value="blocked" <?php echo $advertiser['status'] == 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                                                </select>
                                            </div>
                                            
                                            <div class="form-group-enhanced">
                                                <label>KYC Status</label>
                                                <select name="kyc_status" class="form-control">
                                                    <option value="pending" <?php echo ($advertiser['kyc_status'] ?? 'pending') == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="verified" <?php echo ($advertiser['kyc_status'] ?? '') == 'verified' ? 'selected' : ''; ?>>Verified</option>
                                                    <option value="rejected" <?php echo ($advertiser['kyc_status'] ?? '') == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="form-row">
                                            <div class="form-group-enhanced">
                                                <label>Account Manager</label>
                                                <select name="account_manager_id" class="form-control select2">
                                                    <option value="">-- Unassigned --</option>
                                                    <?php foreach ($accountManagers as $am): ?>
                                                    <option value="<?php echo $am['user_id']; ?>" <?php echo $advertiser['account_manager_id'] == $am['user_id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($am['name']); ?> (<?php echo htmlspecialchars($am['email']); ?>)
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="form-group-enhanced">
                                                <label>Account Balance</label>
                                                <input type="number" step="0.01" name="balance" class="form-control" 
                                                       value="<?php echo htmlspecialchars($advertiser['balance'] ?? 0); ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="form-row">
                                            <div class="form-group-enhanced">
                                                <label>Theme Preference</label>
                                                <select name="theme_preference" class="form-control">
                                                    <option value="light" <?php echo ($advertiser['theme_preference'] ?? 'light') == 'light' ? 'selected' : ''; ?>>Light</option>
                                                    <option value="dark" <?php echo ($advertiser['theme_preference'] ?? '') == 'dark' ? 'selected' : ''; ?>>Dark</option>
                                                    <option value="auto" <?php echo ($advertiser['theme_preference'] ?? '') == 'auto' ? 'selected' : ''; ?>>Auto</option>
                                                </select>
                                            </div>
                                            
                                            <div class="form-group-enhanced">
                                                <label>Two Factor Authentication</label>
                                                <div class="form-control" readonly style="background: #f8f9fc;">
                                                    <?php echo $advertiser['two_factor_enabled'] ? 'Enabled' : 'Disabled'; ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group-enhanced">
                                            <div class="custom-control custom-switch">
                                                <input type="checkbox" class="custom-control-input" id="payout_enabled" name="payout_enabled" 
                                                       <?php echo $advertiser['payout_enabled'] ? 'checked' : ''; ?>>
                                                <label class="custom-control-label" for="payout_enabled">Enable Payouts for this Advertiser</label>
                                            </div>
                                        </div>
                                        
                                        <div class="form-row">
                                            <div class="form-group-enhanced col-md-6">
                                                <div class="custom-control custom-switch">
                                                    <input type="checkbox" class="custom-control-input" id="notification_email" name="notification_email" 
                                                           <?php echo $advertiser['notification_email'] ? 'checked' : ''; ?>>
                                                    <label class="custom-control-label" for="notification_email">Email Notifications</label>
                                                </div>
                                            </div>
                                            
                                            <div class="form-group-enhanced col-md-6">
                                                <div class="custom-control custom-switch">
                                                    <input type="checkbox" class="custom-control-input" id="notification_sms" name="notification_sms" 
                                                           <?php echo $advertiser['notification_sms'] ? 'checked' : ''; ?>>
                                                    <label class="custom-control-label" for="notification_sms">SMS Notifications</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- System Information -->
                                    <div class="form-section">
                                        <div class="form-section-title">
                                            <i class="fas fa-info-circle"></i> System Information
                                        </div>
                                        
                                        <div class="form-row">
                                            <div class="form-group-enhanced col-md-6">
                                                <label>Account Created</label>
                                                <div class="form-control" readonly style="background: #f8f9fc;">
                                                    <?php echo date('F d, Y h:i A', strtotime($advertiser['created_at'])); ?>
                                                </div>
                                            </div>
                                            
                                            <div class="form-group-enhanced col-md-6">
                                                <label>Last Login</label>
                                                <div class="form-control" readonly style="background: #f8f9fc;">
                                                    <?php echo $advertiser['last_login_at'] ? date('F d, Y h:i A', strtotime($advertiser['last_login_at'])) : 'Never'; ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <?php if ($advertiser['last_login_ip']): ?>
                                        <div class="form-row">
                                            <div class="form-group-enhanced">
                                                <label>Last Login IP</label>
                                                <div class="form-control" readonly style="background: #f8f9fc;">
                                                    <?php echo inet_ntop($advertiser['last_login_ip']); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Form Actions -->
                                    <div class="form-actions">
                                        <div>
                                            <a href="advertisers.php" class="btn btn-outline-primary">
                                                <i class="fas fa-arrow-left mr-2"></i> Back to Advertisers
                                            </a>
                                            <a href="advertiser_view.php?id=<?php echo $advertiserId; ?>" class="btn btn-outline-info ml-2">
                                                <i class="fas fa-eye mr-2"></i> View Details
                                            </a>
                                        </div>
                                        <div>
                                            <button type="reset" class="btn btn-outline-secondary mr-2">
                                                <i class="fas fa-undo mr-2"></i> Reset
                                            </button>
                                            <button type="submit" class="btn-gradient" id="submitBtn">
                                                <i class="fas fa-save mr-2"></i> Save Changes
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Recent Offers -->
                <?php if (!empty($offers)): ?>
                <div class="row">
                    <div class="col-md-12">
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-bullhorn mr-2"></i> Recent Campaigns
                                </h3>
                                <div class="card-tools">
                                    <a href="offers.php?advertiser=<?php echo $advertiserId; ?>" class="btn btn-sm btn-outline-primary">
                                        View All <i class="fas fa-arrow-right ml-1"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-dashboard">
                                        <thead>
                                            <tr>
                                                <th>Campaign</th>
                                                <th>Status</th>
                                                <th>Payout</th>
                                                <th>Revenue</th>
                                                <th>Clicks</th>
                                                <th>Conversions</th>
                                                <th>Created</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($offers as $offer): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($offer['offer_name']); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $offer['status']; ?>">
                                                        <?php echo ucfirst($offer['status']); ?>
                                                    </span>
                                                </td>
                                                <td>$<?php echo number_format($offer['payout'], 2); ?></td>
                                                <td>$<?php echo number_format($offer['revenue'], 2); ?></td>
                                                <td><?php echo number_format($offer['clicks'] ?? 0); ?></td>
                                                <td><?php echo number_format($offer['conversions'] ?? 0); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($offer['created_at'])); ?></td>
                                                <td>
                                                    <a href="offer_edit.php?id=<?php echo $offer['offer_id']; ?>" class="btn-action btn-edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
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
                <?php endif; ?>
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
        placeholder: "Select Account Manager",
        allowClear: true
    });
    
    // Auto-submit avatar form
    $('#profile_image').change(function() {
        $('#avatarForm').submit();
    });
    
    // Form submission
    $('#editAdvertiserForm').submit(function(e) {
        const name = $('input[name="name"]').val().trim();
        const email = $('input[name="email"]').val().trim();
        
        if (!name) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Name is required',
                icon: 'error'
            });
            return;
        }
        
        if (!email) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Email is required',
                icon: 'error'
            });
            return;
        }
        
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Please enter a valid email address',
                icon: 'error'
            });
            return;
        }
        
        // Show loading
        const submitBtn = $('#submitBtn');
        submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i> Saving...');
    });
    
    // Auto-dismiss alerts after 5 seconds
    $('.alert').delay(5000).fadeOut('slow');
    
    // Initialize SweetAlert2 Toast
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });
});
</script>

</body>
</html>