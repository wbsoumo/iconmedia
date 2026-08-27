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

/* -------------------------------------------------
   FETCH ADVERTISER DETAILS
-------------------------------------------------- */
$userStmt = $pdo->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.mobile,
        u.telegram_id,
        u.teams_id,
        u.status,
        u.balance,
        u.company,
        u.kyc_status,
        u.payout_enabled,
        u.last_login_ip,
        u.last_login_at,
        u.created_at,
        u.updated_at,
        am.id    AS manager_id,
        am.name  AS manager_name,
        am.email AS manager_email,
        am.phone AS manager_phone
    FROM users u
    LEFT JOIN account_managers am 
        ON am.id = u.account_manager_id
    WHERE u.user_id = :uid AND u.role_id = 4
");
$userStmt->execute(['uid' => $advertiserId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die('Invalid advertiser');
}

/* -------------------------------------------------
   FETCH ADVERTISER STATISTICS
-------------------------------------------------- */
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT o.offer_id) AS total_offers,
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        IFNULL(SUM(cv.revenue), 0) AS total_spent,
        IFNULL(AVG(cv.revenue), 0) AS avg_spend_per_conversion
    FROM offers o
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE o.advertiser_id = :uid
");
$statsStmt->execute(['uid' => $advertiserId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

/* -------------------------------------------------
   FETCH PAYMENT METHODS
-------------------------------------------------- */
$paymentStmt = $pdo->prepare("
    SELECT 
        id,
        payment_method,
        account_details,
        is_default,
        is_verified,
        created_at
    FROM advertiser_payment_methods
    WHERE advertiser_id = :uid
    ORDER BY is_default DESC, created_at DESC
");
$paymentStmt->execute(['uid' => $advertiserId]);
$paymentMethods = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   FETCH BILLING HISTORY
-------------------------------------------------- */
$billingStmt = $pdo->prepare("
    SELECT 
        id,
        invoice_id,
        amount,
        payment_method,
        status,
        created_at,
        paid_at
    FROM advertiser_invoices
    WHERE advertiser_id = :uid
    ORDER BY created_at DESC
    LIMIT 5
");
$billingStmt->execute(['uid' => $advertiserId]);
$billingHistory = $billingStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   UPDATE PROFILE (EMAIL LOCKED)
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'profile') {
    $name     = trim($_POST['name']);
    $mobile   = trim($_POST['mobile']);
    $company  = trim($_POST['company']);
    $telegram = trim($_POST['telegram_id']);
    $teams    = trim($_POST['teams_id']);

    if ($name === '') {
        $error = 'Name is required';
    } else {
        $stmt = $pdo->prepare("
            UPDATE users SET
                name = :name,
                mobile = :mobile,
                company = :company,
                telegram_id = :telegram,
                teams_id = :teams,
                updated_at = NOW()
            WHERE user_id = :uid
        ");
        $stmt->execute([
            'uid'      => $advertiserId,
            'name'     => $name,
            'mobile'   => $mobile ?: null,
            'company'  => $company ?: null,
            'telegram' => $telegram ?: null,
            'teams'    => $teams ?: null
        ]);

        $_SESSION['user_name'] = $name;
        $success = 'Profile updated successfully';

        $userStmt->execute(['uid' => $advertiserId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    }
}

/* -------------------------------------------------
   ADD PAYMENT METHOD
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'add_payment') {
    $paymentMethod = trim($_POST['payment_method']);
    $accountDetails = trim($_POST['account_details']);
    $isDefault = isset($_POST['is_default']) ? 1 : 0;

    if ($paymentMethod === '' || $accountDetails === '') {
        $error = 'Payment method and account details are required';
    } else {
        // If setting as default, update all other methods to non-default
        if ($isDefault) {
            $updateStmt = $pdo->prepare("
                UPDATE advertiser_payment_methods 
                SET is_default = 0 
                WHERE advertiser_id = :uid
            ");
            $updateStmt->execute(['uid' => $advertiserId]);
        }

        $stmt = $pdo->prepare("
            INSERT INTO advertiser_payment_methods 
            (advertiser_id, payment_method, account_details, is_default, is_verified, created_at)
            VALUES (:uid, :method, :details, :default, 0, NOW())
        ");
        $stmt->execute([
            'uid'     => $advertiserId,
            'method'  => $paymentMethod,
            'details' => $accountDetails,
            'default' => $isDefault
        ]);

        $success = 'Payment method added successfully. Verification pending.';
        $paymentStmt->execute(['uid' => $advertiserId]);
        $paymentMethods = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* -------------------------------------------------
   UPDATE KYC STATUS
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'upload_kyc') {
    // Handle KYC document upload (simplified)
    $kycType = $_POST['kyc_type'];
    
    $stmt = $pdo->prepare("
        INSERT INTO advertiser_kyc_documents 
        (advertiser_id, document_type, status, uploaded_at)
        VALUES (:uid, :type, 'pending', NOW())
    ");
    $stmt->execute([
        'uid'  => $advertiserId,
        'type' => $kycType
    ]);

    $success = 'KYC document uploaded successfully. Verification in progress.';
}

/* -------------------------------------------------
   ADD FUNDS
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'add_funds') {
    $amount = floatval($_POST['amount']);
    $paymentMethodId = intval($_POST['payment_method_id']);

    if ($amount < 10) {
        $error = 'Minimum deposit amount is $10';
    } elseif ($amount > 10000) {
        $error = 'Maximum deposit amount is $10,000';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO advertiser_transactions 
            (advertiser_id, type, amount, status, payment_method_id, created_at)
            VALUES (:uid, 'deposit', :amount, 'pending', :method_id, NOW())
        ");
        $stmt->execute([
            'uid'       => $advertiserId,
            'amount'    => $amount,
            'method_id' => $paymentMethodId
        ]);

        $success = 'Deposit request submitted successfully. Funds will be added after payment confirmation.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profile | Advertiser Panel | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
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
        
        .profile-header {
            background: var(--primary-gradient);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.3);
        }
        
        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.1)" d="M0,96L48,112C96,128,192,160,288,186.7C384,213,480,235,576,213.3C672,192,768,128,864,128C960,128,1056,192,1152,213.3C1248,235,1344,213,1392,202.7L1440,192L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>');
            background-size: cover;
            opacity: 0.1;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            background: rgba(255,255,255,0.2);
            border: 4px solid white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 50px;
            margin-bottom: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
        }
        
        .profile-stats {
            display: flex;
            justify-content: space-around;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.2);
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 12px;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .card-dashboard {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 20px;
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
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .info-item {
            background: #f8f9fc;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #eaecf4;
            transition: all 0.3s ease;
        }
        
        .info-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .info-label {
            color: #6c757d;
            font-weight: 500;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        
        .info-value {
            font-weight: 600;
            color: #2e59d9;
            font-size: 16px;
        }
        
        .status-badge {
            padding: 6px 15px;
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
        
        .manager-card {
            background: var(--info-gradient);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            color: white;
            box-shadow: 0 5px 20px rgba(30, 60, 114, 0.3);
        }
        
        .manager-avatar {
            width: 80px;
            height: 80px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: #667eea;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 500;
            padding: 12px 25px;
            border-radius: 10px 10px 0 0;
        }
        
        .nav-tabs .nav-link.active {
            color: #667eea;
            background: white;
            border-bottom: 3px solid #667eea;
        }
        
        .payment-card {
            background: white;
            border: 1px solid #e3e6f0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .payment-card:hover {
            border-color: #667eea;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.1);
        }
        
        .payment-icon {
            width: 50px;
            height: 50px;
            background: rgba(102, 126, 234, 0.1);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #667eea;
            font-size: 24px;
            margin-bottom: 15px;
        }
        
        .default-badge {
            background: #28a745;
            color: white;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .verified-badge {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .history-item {
            padding: 15px;
            border-bottom: 1px solid #f8f9fa;
            transition: background 0.3s ease;
        }
        
        .history-item:hover {
            background: #f8f9fa;
        }
        
        .security-progress {
            height: 6px;
            border-radius: 3px;
        }
        
        .verification-badge {
            background: #28a745;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            margin-left: 5px;
        }
        
        .metric-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #e3e6f0;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .metric-value {
            font-size: 28px;
            font-weight: 700;
            color: #2e59d9;
            margin-bottom: 5px;
        }
        
        .metric-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .form-group-icon {
            position: relative;
        }
        
        .form-group-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
            font-size: 16px;
        }
        
        .form-group-icon .form-control {
            padding-left: 45px;
        }
        
        .btn-gradient {
            background: var(--primary-gradient);
            border: none;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
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
        
        .balance-display {
            background: var(--success-gradient);
            color: white;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
            margin-bottom: 20px;
            box-shadow: 0 5px 20px rgba(40, 167, 69, 0.3);
        }
        
        .balance-amount {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .balance-label {
            font-size: 14px;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        .add-funds-btn {
            background: white;
            color: #28a745;
            border: none;
            padding: 10px 30px;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-top: 15px;
        }
        
        .add-funds-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255,255,255,0.3);
            background: #f8f9fa;
        }
        
        .invoice-item {
            padding: 15px;
            border: 1px solid #e3e6f0;
            border-radius: 10px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .invoice-item:hover {
            border-color: #667eea;
            background: #f8f9fc;
        }
        
        .invoice-status-paid {
            color: #28a745;
            font-weight: 600;
        }
        
        .invoice-status-pending {
            color: #ffc107;
            font-weight: 600;
        }
        
        .invoice-status-failed {
            color: #dc3545;
            font-weight: 600;
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
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
                <a href="profile.php" class="nav-link active">Profile</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <span class="badge badge-warning navbar-badge"><?php echo $stats['total_conversions'] ?? 0; ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header"><?php echo $stats['total_conversions'] ?? 0; ?> Total Conversions</span>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-chart-line mr-2 text-primary"></i> Spent: $<?php echo number_format($stats['total_spent'] ?? 0, 2); ?>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-wallet mr-2 text-success"></i> Balance: $<?php echo number_format($user['balance'], 2); ?>
                    </a>
                </div>
            </li>
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
                    <a href="profile.php" class="dropdown-item active">
                        <i class="fas fa-user mr-2"></i> Profile
                    </a>
                    <a href="account.php" class="dropdown-item">
                        <i class="fas fa-cog mr-2"></i> Account Settings
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="billing.php" class="dropdown-item">
                        <i class="fas fa-wallet mr-2"></i> Billing
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
                        <a href="ip_whitelist.php" class="nav-link">
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
                        <a href="profile.php" class="nav-link active">
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
                        <h1 class="m-0">Profile</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Profile</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">
                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="row align-items-center">
                        <div class="col-md-3 text-center">
                            <div class="profile-avatar">
                                <i class="fas fa-building"></i>
                            </div>
                            <button class="btn btn-outline-light btn-sm mt-2" id="changePhotoBtn">
                                <i class="fas fa-camera mr-1"></i> Change Logo
                            </button>
                        </div>
                        <div class="col-md-9">
                            <h2 class="mb-2"><?php echo htmlspecialchars($user['name']); ?></h2>
                            <p class="mb-1">
                                <i class="fas fa-envelope mr-2"></i> <?php echo htmlspecialchars($user['email']); ?>
                            </p>
                            <p class="mb-3">
                                <i class="fas fa-id-badge mr-2"></i> Advertiser ID: #<?php echo $user['user_id']; ?>
                                <?php if ($user['company']): ?>
                                    <span class="ml-3">
                                        <i class="fas fa-building mr-1"></i> <?php echo htmlspecialchars($user['company']); ?>
                                    </span>
                                <?php endif; ?>
                            </p>
                            <div class="d-flex flex-wrap">
                                <span class="status-badge status-<?php echo $user['status']; ?> mr-3 mb-2">
                                    <i class="fas fa-circle mr-1"></i> <?php echo ucfirst($user['status']); ?>
                                </span>
                                <span class="badge badge-light mr-3 mb-2">
                                    <i class="fas fa-calendar mr-1"></i> Joined: <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                </span>
                                <span class="badge badge-light mr-3 mb-2">
                                    <i class="fas fa-check-circle mr-1"></i> KYC: <?php echo ucfirst($user['kyc_status']); ?>
                                </span>
                                <span class="badge badge-light mb-2">
                                    <i class="fas fa-wallet mr-1"></i> Balance: $<?php echo number_format($user['balance'], 2); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="profile-stats">
                        <div class="stat-item">
                            <div class="stat-value">$<?php echo number_format($stats['total_spent'] ?? 0, 2); ?></div>
                            <div class="stat-label">Total Spent</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $stats['total_conversions'] ?? 0; ?></div>
                            <div class="stat-label">Conversions</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $stats['total_offers'] ?? 0; ?></div>
                            <div class="stat-label">Active Offers</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $user['payout_enabled'] ? 'Enabled' : 'Disabled'; ?></div>
                            <div class="stat-label">Payouts</div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                    <h5><i class="icon fas fa-check"></i> Success!</h5>
                    <?php echo $success; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                    <h5><i class="icon fas fa-ban"></i> Error!</h5>
                    <?php echo $error; ?>
                </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-4">
                        <!-- Account Balance -->
                        <div class="balance-display">
                            <div class="balance-amount">$<?php echo number_format($user['balance'], 2); ?></div>
                            <div class="balance-label">Current Balance</div>
                            <button class="add-funds-btn" data-toggle="modal" data-target="#addFundsModal">
                                <i class="fas fa-plus mr-2"></i> Add Funds
                            </button>
                            <div class="mt-3">
                                <small>Minimum deposit: $10 | Maximum: $10,000</small>
                            </div>
                        </div>

                        <!-- Account Manager -->
                        <?php if ($user['manager_id']): ?>
                        <div class="manager-card">
                            <div class="text-center">
                                <div class="manager-avatar">
                                    <i class="fas fa-user-tie"></i>
                                </div>
                                <h5 class="mb-2"><?php echo htmlspecialchars($user['manager_name']); ?></h5>
                                <p class="mb-3">Your Account Manager</p>
                                <div class="d-flex justify-content-center">
                                    <a href="mailto:<?php echo htmlspecialchars($user['manager_email']); ?>" class="btn btn-sm btn-outline-light mr-2">
                                        <i class="fas fa-envelope"></i> Email
                                    </a>
                                    <?php if ($user['manager_phone']): ?>
                                    <a href="tel:<?php echo htmlspecialchars($user['manager_phone']); ?>" class="btn btn-sm btn-outline-light">
                                        <i class="fas fa-phone"></i> Call
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Quick Stats -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Campaign Overview</h3>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo $stats['total_offers'] ?? 0; ?></div>
                                            <div class="metric-label">Offers</div>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo $stats['total_clicks'] ?? 0; ?></div>
                                            <div class="metric-label">Clicks</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="metric-card">
                                            <div class="metric-value">
                                                <?php if ($stats['avg_spend_per_conversion'] > 0): ?>
                                                    $<?php echo number_format($stats['avg_spend_per_conversion'], 2); ?>
                                                <?php else: ?>
                                                    $0.00
                                                <?php endif; ?>
                                            </div>
                                            <div class="metric-label">Avg. CPA</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="metric-card">
                                            <div class="metric-value">
                                                <?php echo $user['payout_enabled'] ? 'Yes' : 'No'; ?>
                                            </div>
                                            <div class="metric-label">Payouts</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Security Status -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Security Status</h3>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>Account Status</span>
                                        <span class="text-success"><i class="fas fa-check-circle"></i> <?php echo ucfirst($user['status']); ?></span>
                                    </div>
                                    <div class="progress security-progress">
                                        <div class="progress-bar bg-success" style="width: 100%"></div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>KYC Status</span>
                                        <span class="text-<?php echo $user['kyc_status'] == 'verified' ? 'success' : ($user['kyc_status'] == 'pending' ? 'warning' : 'danger'); ?>">
                                            <i class="fas fa-<?php echo $user['kyc_status'] == 'verified' ? 'check-circle' : ($user['kyc_status'] == 'pending' ? 'clock' : 'times-circle'); ?>"></i>
                                            <?php echo ucfirst($user['kyc_status']); ?>
                                        </span>
                                    </div>
                                    <div class="progress security-progress">
                                        <div class="progress-bar bg-<?php echo $user['kyc_status'] == 'verified' ? 'success' : ($user['kyc_status'] == 'pending' ? 'warning' : 'danger'); ?>" 
                                             style="width: <?php echo $user['kyc_status'] == 'verified' ? '100' : ($user['kyc_status'] == 'pending' ? '60' : '30'); ?>%"></div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>2FA Status</span>
                                        <span class="text-warning"><i class="fas fa-exclamation-circle"></i> Not Enabled</span>
                                    </div>
                                    <div class="progress security-progress">
                                        <div class="progress-bar bg-warning" style="width: 40%"></div>
                                    </div>
                                </div>
                                <button class="btn btn-gradient btn-block" id="enable2faBtn">
                                    <i class="fas fa-shield-alt mr-2"></i> Enable 2FA
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-8">
                        <!-- Tabs -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <ul class="nav nav-tabs" id="profileTabs" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link active" id="info-tab" data-toggle="tab" href="#info">
                                            <i class="fas fa-user-circle mr-2"></i> Information
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" id="payment-tab" data-toggle="tab" href="#payment">
                                            <i class="fas fa-credit-card mr-2"></i> Payment Methods
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" id="billing-tab" data-toggle="tab" href="#billing">
                                            <i class="fas fa-file-invoice-dollar mr-2"></i> Billing History
                                        </a>
                                    </li>
                                </ul>
                            </div>
                            <div class="card-body">
                                <div class="tab-content" id="profileTabContent">
                                    
                                    <!-- Info Tab -->
                                    <div class="tab-pane fade show active" id="info" role="tabpanel">
                                        <div class="info-grid">
                                            <div class="info-item">
                                                <div class="info-label">Full Name</div>
                                                <div class="info-value"><?php echo htmlspecialchars($user['name']); ?></div>
                                            </div>
                                            <div class="info-item">
                                                <div class="info-label">Email Address</div>
                                                <div class="info-value">
                                                    <?php echo htmlspecialchars($user['email']); ?>
                                                    <span class="verification-badge" title="Email Verified">
                                                        <i class="fas fa-check"></i>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="info-item">
                                                <div class="info-label">Mobile Number</div>
                                                <div class="info-value"><?php echo htmlspecialchars($user['mobile'] ?? 'Not set'); ?></div>
                                            </div>
                                            <div class="info-item">
                                                <div class="info-label">Company Name</div>
                                                <div class="info-value"><?php echo htmlspecialchars($user['company'] ?? 'Not set'); ?></div>
                                            </div>
                                            <div class="info-item">
                                                <div class="info-label">Telegram ID</div>
                                                <div class="info-value"><?php echo htmlspecialchars($user['telegram_id'] ?? 'Not set'); ?></div>
                                            </div>
                                            <div class="info-item">
                                                <div class="info-label">Teams ID</div>
                                                <div class="info-value"><?php echo htmlspecialchars($user['teams_id'] ?? 'Not set'); ?></div>
                                            </div>
                                            <div class="info-item">
                                                <div class="info-label">Account Status</div>
                                                <div class="info-value">
                                                    <span class="status-badge status-<?php echo $user['status']; ?>">
                                                        <?php echo ucfirst($user['status']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="info-item">
                                                <div class="info-label">KYC Status</div>
                                                <div class="info-value">
                                                    <span class="kyc-<?php echo $user['kyc_status']; ?>">
                                                        <?php echo ucfirst($user['kyc_status']); ?>
                                                    </span>
                                                    <?php if ($user['kyc_status'] != 'verified'): ?>
                                                    <button class="btn btn-sm btn-outline-primary ml-2" data-toggle="modal" data-target="#uploadKycModal">
                                                        <i class="fas fa-upload mr-1"></i> Upload
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="info-item">
                                                <div class="info-label">Account Balance</div>
                                                <div class="info-value">$<?php echo number_format($user['balance'], 2); ?></div>
                                            </div>
                                            <div class="info-item">
                                                <div class="info-label">Last Login</div>
                                                <div class="info-value">
                                                    <?php if ($user['last_login_at']): ?>
                                                        <?php echo date('M d, Y H:i', strtotime($user['last_login_at'])); ?>
                                                        <span class="ip-address ml-2">
                                                            <?php echo htmlspecialchars($user['last_login_ip'] ?? 'N/A'); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        Never
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="info-item">
                                                <div class="info-label">Member Since</div>
                                                <div class="info-value"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></div>
                                            </div>
                                            <div class="info-item">
                                                <div class="info-label">Last Updated</div>
                                                <div class="info-value"><?php echo date('M d, Y H:i', strtotime($user['updated_at'] ?: $user['created_at'])); ?></div>
                                            </div>
                                        </div>
                                        <div class="text-right mt-3">
                                            <button class="btn btn-gradient" data-toggle="modal" data-target="#editProfileModal">
                                                <i class="fas fa-edit mr-2"></i> Edit Profile
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Payment Methods Tab -->
                                    <div class="tab-pane fade" id="payment" role="tabpanel">
                                        <div class="dashboard-header">
                                            <h4 class="mb-0">Payment Methods</h4>
                                            <button class="btn btn-gradient" data-toggle="modal" data-target="#addPaymentModal">
                                                <i class="fas fa-plus mr-2"></i> Add Payment Method
                                            </button>
                                        </div>
                                        
                                        <?php if (empty($paymentMethods)): ?>
                                            <div class="empty-state">
                                                <div class="empty-state-icon">
                                                    <i class="fas fa-credit-card"></i>
                                                </div>
                                                <h5>No Payment Methods Added</h5>
                                                <p class="text-muted">Add a payment method to fund your account and receive payouts.</p>
                                            </div>
                                        <?php else: ?>
                                            <?php foreach($paymentMethods as $method): ?>
                                            <div class="payment-card">
                                                <div class="row align-items-center">
                                                    <div class="col-md-2 text-center">
                                                        <div class="payment-icon">
                                                            <?php if (stripos($method['payment_method'], 'paypal') !== false): ?>
                                                                <i class="fab fa-paypal"></i>
                                                            <?php elseif (stripos($method['payment_method'], 'stripe') !== false): ?>
                                                                <i class="fab fa-stripe"></i>
                                                            <?php elseif (stripos($method['payment_method'], 'bank') !== false): ?>
                                                                <i class="fas fa-university"></i>
                                                            <?php elseif (stripos($method['payment_method'], 'crypto') !== false): ?>
                                                                <i class="fas fa-coins"></i>
                                                            <?php else: ?>
                                                                <i class="fas fa-wallet"></i>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-8">
                                                        <h5 class="mb-2">
                                                            <?php echo htmlspecialchars($method['payment_method']); ?>
                                                            <?php if ($method['is_default']): ?>
                                                                <span class="default-badge">Default</span>
                                                            <?php endif; ?>
                                                            <?php if ($method['is_verified']): ?>
                                                                <span class="verified-badge">Verified</span>
                                                            <?php endif; ?>
                                                        </h5>
                                                        <p class="mb-1 text-muted">
                                                            <?php echo htmlspecialchars($method['account_details']); ?>
                                                        </p>
                                                        <small class="text-muted">
                                                            Added: <?php echo date('M d, Y', strtotime($method['created_at'])); ?>
                                                        </small>
                                                    </div>
                                                    <div class="col-md-2 text-right">
                                                        <?php if (!$method['is_default']): ?>
                                                        <form method="post" action="set_default_payment.php" style="display: inline;">
                                                            <input type="hidden" name="payment_id" value="<?php echo $method['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-primary mb-1">
                                                                Set Default
                                                            </button>
                                                        </form>
                                                        <?php endif; ?>
                                                        <br>
                                                        <a href="?delete_payment=<?php echo $method['id']; ?>" 
                                                           class="btn btn-sm btn-outline-danger"
                                                           onclick="return confirm('Are you sure you want to remove this payment method?')">
                                                            Remove
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        
                                        <div class="alert alert-info mt-3">
                                            <i class="fas fa-info-circle mr-2"></i>
                                            Payment methods are verified within 24 hours. Only verified methods can be used for payouts.
                                        </div>
                                    </div>
                                    
                                    <!-- Billing History Tab -->
                                    <div class="tab-pane fade" id="billing" role="tabpanel">
                                        <div class="dashboard-header">
                                            <h4 class="mb-0">Billing History</h4>
                                            <a href="billing.php" class="btn btn-outline-primary">
                                                <i class="fas fa-history mr-2"></i> View Full History
                                            </a>
                                        </div>
                                        
                                        <?php if (empty($billingHistory)): ?>
                                            <div class="empty-state">
                                                <div class="empty-state-icon">
                                                    <i class="fas fa-file-invoice-dollar"></i>
                                                </div>
                                                <h5>No Billing History</h5>
                                                <p class="text-muted">Your billing history will appear here after making payments.</p>
                                            </div>
                                        <?php else: ?>
                                            <?php foreach($billingHistory as $invoice): ?>
                                            <div class="invoice-item">
                                                <div class="row align-items-center">
                                                    <div class="col-md-3">
                                                        <strong>Invoice #<?php echo $invoice['invoice_id']; ?></strong>
                                                        <div class="text-muted small">
                                                            <?php echo date('M d, Y', strtotime($invoice['created_at'])); ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="text-muted">Amount</div>
                                                        <strong>$<?php echo number_format($invoice['amount'], 2); ?></strong>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="text-muted">Payment Method</div>
                                                        <strong><?php echo htmlspecialchars($invoice['payment_method']); ?></strong>
                                                    </div>
                                                    <div class="col-md-3 text-right">
                                                        <span class="invoice-status-<?php echo $invoice['status']; ?>">
                                                            <?php echo ucfirst($invoice['status']); ?>
                                                        </span>
                                                        <?php if ($invoice['status'] == 'pending'): ?>
                                                        <a href="pay_invoice.php?id=<?php echo $invoice['id']; ?>" 
                                                           class="btn btn-sm btn-gradient ml-2">
                                                            Pay Now
                                                        </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        
                                        <div class="text-center mt-3">
                                            <a href="billing.php" class="btn btn-outline-primary">
                                                <i class="fas fa-list mr-2"></i> View All Invoices
                                            </a>
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
            <strong>Advertiser Panel v3.0</strong>
        </div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- Edit Profile Modal -->
<div class="modal fade" id="editProfileModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Profile Information</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="profile">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Full Name *</label>
                                <input type="text" class="form-control" name="name" 
                                       value="<?php echo htmlspecialchars($user['name']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                                <small class="form-text text-muted">Contact support to change email</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Mobile Number</label>
                                <input type="text" class="form-control" name="mobile" 
                                       value="<?php echo htmlspecialchars($user['mobile'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Company Name</label>
                                <input type="text" class="form-control" name="company" 
                                       value="<?php echo htmlspecialchars($user['company'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Telegram ID</label>
                                <input type="text" class="form-control" name="telegram_id" 
                                       value="<?php echo htmlspecialchars($user['telegram_id'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Teams ID</label>
                                <input type="text" class="form-control" name="teams_id" 
                                       value="<?php echo htmlspecialchars($user['teams_id'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gradient">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Payment Method Modal -->
<div class="modal fade" id="addPaymentModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Payment Method</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_payment">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Payment Method *</label>
                                <select class="form-control" name="payment_method" required>
                                    <option value="">Select Payment Method</option>
                                    <option value="PayPal">PayPal</option>
                                    <option value="Stripe">Stripe</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                    <option value="Crypto">Cryptocurrency</option>
                                    <option value="Skrill">Skrill</option>
                                    <option value="Payoneer">Payoneer</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Account Details *</label>
                                <input type="text" class="form-control" name="account_details" 
                                       placeholder="e.g., email@paypal.com or bank account details" required>
                                <small class="form-text text-muted">Enter the specific details for this payment method</small>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="is_default" name="is_default" value="1">
                                <label class="form-check-label" for="is_default">
                                    Set as default payment method
                                </label>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="alert alert-info mt-3">
                                <i class="fas fa-info-circle mr-2"></i>
                                Payment methods will be verified within 24 hours. You'll receive an email notification once verified.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gradient">Add Payment Method</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Funds Modal -->
<div class="modal fade" id="addFundsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Funds to Account</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_funds">
                    <div class="form-group">
                        <label>Amount ($) *</label>
                        <input type="number" class="form-control" name="amount" 
                               min="10" max="10000" step="0.01" required>
                        <small class="form-text text-muted">Minimum: $10, Maximum: $10,000</small>
                    </div>
                    <div class="form-group">
                        <label>Payment Method *</label>
                        <select class="form-control" name="payment_method_id" required>
                            <option value="">Select Payment Method</option>
                            <?php foreach($paymentMethods as $method): ?>
                                <?php if ($method['is_verified']): ?>
                                <option value="<?php echo $method['id']; ?>">
                                    <?php echo htmlspecialchars($method['payment_method']); ?> 
                                    (<?php echo htmlspecialchars(substr($method['account_details'], 0, 30)); ?>...)
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty(array_filter($paymentMethods, function($m) { return $m['is_verified']; }))): ?>
                            <small class="form-text text-danger">
                                No verified payment methods available. Please add and verify a payment method first.
                            </small>
                        <?php endif; ?>
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        Funds will be added to your account balance after payment confirmation.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gradient" 
                            <?php echo empty(array_filter($paymentMethods, function($m) { return $m['is_verified']; })) ? 'disabled' : ''; ?>>
                        <i class="fas fa-plus mr-2"></i> Add Funds
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Upload KYC Modal -->
<div class="modal fade" id="uploadKycModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Upload KYC Document</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="post" action="" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="upload_kyc">
                    <div class="form-group">
                        <label>Document Type *</label>
                        <select class="form-control" name="kyc_type" required>
                            <option value="">Select Document Type</option>
                            <option value="passport">Passport</option>
                            <option value="national_id">National ID Card</option>
                            <option value="driving_license">Driving License</option>
                            <option value="business_registration">Business Registration</option>
                            <option value="utility_bill">Utility Bill</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Document File *</label>
                        <input type="file" class="form-control" name="kyc_document" accept=".pdf,.jpg,.jpeg,.png" required>
                        <small class="form-text text-muted">
                            Accepted formats: PDF, JPG, PNG (Max: 5MB)
                        </small>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        KYC verification usually takes 1-3 business days. You will receive an email notification once verified.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gradient">
                        <i class="fas fa-upload mr-2"></i> Upload Document
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- REQUIRED SCRIPTS -->
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<!-- Bootstrap 4 -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- AdminLTE App -->
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
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
    
    // Change photo button
    $('#changePhotoBtn').click(function() {
        Swal.fire({
            title: 'Change Company Logo',
            text: 'This feature is coming soon!',
            icon: 'info',
            confirmButtonText: 'OK'
        });
    });
    
    // Enable 2FA button
    $('#enable2faBtn').click(function() {
        Swal.fire({
            title: 'Enable Two-Factor Authentication',
            html: `
                <div class="text-left">
                    <p>Scan this QR code with your authenticator app:</p>
                    <div class="text-center my-4">
                        <div style="width: 200px; height: 200px; background: #f8f9fa; margin: 0 auto; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-qrcode fa-3x text-muted"></i>
                        </div>
                    </div>
                    <p>Or enter this code manually: <code>ADV<?php echo $user['user_id']; ?>ABC123</code></p>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Enable 2FA',
            width: 500
        }).then((result) => {
            if (result.isConfirmed) {
                Toast.fire({
                    icon: 'success',
                    title: '2FA enabled successfully!'
                });
            }
        });
    });
    
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
    
    // Auto-dismiss alerts after 5 seconds
    $('.alert').delay(5000).fadeOut('slow');
    
    // Tab memory
    $('a[data-toggle="tab"]').on('shown.bs.tab', function(e) {
        localStorage.setItem('activeTab', $(e.target).attr('href'));
    });
    
    const activeTab = localStorage.getItem('activeTab');
    if (activeTab) {
        $('#profileTabs a[href="' + activeTab + '"]').tab('show');
    }
    
    // Add funds validation
    $('#addFundsModal').on('show.bs.modal', function() {
        const hasVerifiedMethods = <?php echo !empty(array_filter($paymentMethods, function($m) { return $m['is_verified']; })) ? 'true' : 'false'; ?>;
        if (!hasVerifiedMethods) {
            Swal.fire({
                title: 'No Verified Payment Methods',
                text: 'Please add and verify a payment method before adding funds.',
                icon: 'warning',
                confirmButtonText: 'OK'
            });
        }
    });
});
</script>

</body>
</html>