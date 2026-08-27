<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('affiliate');

$affiliateId = auth_user_id();
$affiliateName = $_SESSION['user_name'] ?? 'Affiliate';
$success = $error = null;

/* -------------------------------------------------
   FETCH AFFILIATE + ACCOUNT MANAGER
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
        u.created_at,
        u.updated_at,
        am.id    AS manager_id,
        am.name  AS manager_name,
        am.email AS manager_email,
        am.phone AS manager_phone
    FROM users u
    LEFT JOIN account_managers am 
        ON am.id = u.account_manager_id
    WHERE u.user_id = :uid
");
$userStmt->execute(['uid' => $affiliateId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die('Invalid affiliate');
}

/* -------------------------------------------------
   FETCH BANK DETAILS
-------------------------------------------------- */
$bankStmt = $pdo->prepare("
    SELECT 
        bank_name,
        account_holder,
        account_number,
        ifsc_code,
        upi_id,
        is_verified
    FROM affiliate_bank_details
    WHERE affiliate_id = :uid
");
$bankStmt->execute(['uid' => $affiliateId]);
$bank = $bankStmt->fetch(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   FETCH CONVERSION STATS
-------------------------------------------------- */
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_conversions,
        SUM(status = 'approved') AS approved_conversions,
        IFNULL(SUM(CASE WHEN status = 'approved' THEN payout ELSE 0 END),0) AS total_earnings
    FROM conversions
    WHERE affiliate_id = :uid
");
$statsStmt->execute(['uid' => $affiliateId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   UPDATE PROFILE (EMAIL LOCKED)
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'profile') {

    $name     = trim($_POST['name']);
    $mobile   = trim($_POST['mobile']);
    $telegram = trim($_POST['telegram_id']);
    $teams    = trim($_POST['teams_id']);

    if ($name === '') {
        $error = 'Name is required';
    } else {
        $stmt = $pdo->prepare("
            UPDATE users SET
                name = :name,
                mobile = :mobile,
                telegram_id = :telegram,
                teams_id = :teams,
                updated_at = NOW()
            WHERE user_id = :uid
        ");
        $stmt->execute([
            'uid'      => $affiliateId,
            'name'     => $name,
            'mobile'   => $mobile ?: null,
            'telegram' => $telegram ?: null,
            'teams'    => $teams ?: null
        ]);

        $_SESSION['user_name'] = $name;
        $success = 'Profile updated successfully';

        $userStmt->execute(['uid' => $affiliateId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    }
}

/* -------------------------------------------------
   ADD / UPDATE BANK DETAILS
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'bank') {

    $bankName  = trim($_POST['bank_name']);
    $holder    = trim($_POST['account_holder']);
    $account   = trim($_POST['account_number']);
    $ifsc      = strtoupper(trim($_POST['ifsc_code']));
    $upi       = strtolower(trim($_POST['upi_id']));

    if ($bankName === '' || $holder === '') {
        $error = 'Bank name and account holder required';
    } elseif (!preg_match('/^[0-9]{9,18}$/', $account)) {
        $error = 'Invalid account number (9–18 digits)';
    } elseif (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
        $error = 'Invalid IFSC code';
    } elseif ($upi && !preg_match('/^[a-z0-9.\-_]{2,256}@[a-z]{2,64}$/', $upi)) {
        $error = 'Invalid UPI ID';
    } else {

        if ($bank) {
            $stmt = $pdo->prepare("
                UPDATE affiliate_bank_details SET
                    bank_name = :bank,
                    account_holder = :holder,
                    account_number = :account,
                    ifsc_code = :ifsc,
                    upi_id = :upi,
                    is_verified = 0,
                    updated_at = NOW()
                WHERE affiliate_id = :uid
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO affiliate_bank_details
                (affiliate_id, bank_name, account_holder, account_number, ifsc_code, upi_id, is_verified, updated_at)
                VALUES (:uid, :bank, :holder, :account, :ifsc, :upi, 0, NOW())
            ");
        }

        $stmt->execute([
            'uid'     => $affiliateId,
            'bank'    => $bankName,
            'holder'  => $holder,
            'account' => $account,
            'ifsc'    => $ifsc,
            'upi'     => $upi ?: null
        ]);

        $success = 'Bank details saved. Verification pending.';
        $bankStmt->execute(['uid' => $affiliateId]);
        $bank = $bankStmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profile | GVS Icon Media</title>
    
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
        
        .card-profile {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .card-profile .card-header {
            border-radius: 15px 15px 0 0;
            background: white;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            padding: 20px;
        }
        
        .card-profile .card-body {
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
        
        .bank-card {
            background: white;
            border: 1px solid #e3e6f0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .bank-card:hover {
            border-color: #667eea;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.1);
        }
        
        .bank-icon {
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
        
        .ip-address {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 4px;
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
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <!-- Left navbar links -->
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button">
                    <i class="fas fa-bars"></i>
                </a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
            </li>
        </ul>

        <!-- Right navbar links -->
        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <span class="badge badge-warning navbar-badge"><?php echo $stats['approved_conversions'] ?? 0; ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header"><?php echo $stats['approved_conversions'] ?? 0; ?> Approved Conversions</span>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-wallet mr-2 text-success"></i> Balance: $<?php echo number_format($user['balance'], 2); ?>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user mr-2 text-primary"></i> View Profile
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
                    <i class="fas fa-user-circle mr-1"></i> <?php echo htmlspecialchars($affiliateName); ?>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="profile.php" class="dropdown-item active">
                        <i class="fas fa-user mr-2"></i> Profile
                    </a>
                    <a href="settings.php" class="dropdown-item">
                        <i class="fas fa-cog mr-2"></i> Settings
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
                <i class="fas fa-rocket mr-2"></i>
                <strong>GVS Icon Media</strong>
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
                    
                    <li class="nav-header">REPORTS</li>
                    <li class="nav-item">
                        <a href="offers.php" class="nav-link">
                            <i class="nav-icon fas fa-gift"></i>
                            <p>Offer Performance</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports.php" class="nav-link">
                            <i class="fas fa-exchange-alt nav-icon"></i>
                            <p>Reports</p>
                        </a>
                    </li>
                    
                    <li class="nav-header">TOOLS</li>
                    <li class="nav-item">
                        <a href="link-builder.php" class="nav-link">
                            <i class="nav-icon fas fa-link"></i>
                            <p>Link Builder</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="insights.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Smart Insights</p>
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
                        <a href="payouts.php" class="nav-link">
                            <i class="nav-icon fas fa-wallet"></i>
                            <p>Payouts</p>
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
                                <i class="fas fa-user"></i>
                            </div>
                            <button class="btn btn-outline-light btn-sm mt-2" id="changePhotoBtn">
                                <i class="fas fa-camera mr-1"></i> Change Photo
                            </button>
                        </div>
                        <div class="col-md-9">
                            <h2 class="mb-2"><?php echo htmlspecialchars($user['name']); ?></h2>
                            <p class="mb-1">
                                <i class="fas fa-envelope mr-2"></i> <?php echo htmlspecialchars($user['email']); ?>
                            </p>
                            <p class="mb-3">
                                <i class="fas fa-id-badge mr-2"></i> Affiliate ID: #<?php echo $user['user_id']; ?>
                            </p>
                            <div class="d-flex flex-wrap">
                                <span class="status-badge status-<?php echo $user['status']; ?> mr-3 mb-2">
                                    <i class="fas fa-circle mr-1"></i> <?php echo ucfirst($user['status']); ?>
                                </span>
                                <span class="badge badge-light mr-3 mb-2">
                                    <i class="fas fa-calendar mr-1"></i> Joined: <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                </span>
                                <span class="badge badge-light mb-2">
                                    <i class="fas fa-wallet mr-1"></i> Balance: $<?php echo number_format($user['balance'], 2); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="profile-stats">
                        <div class="stat-item">
                            <div class="stat-value">$<?php echo number_format($stats['total_earnings'], 2); ?></div>
                            <div class="stat-label">Total Earnings</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $stats['total_conversions']; ?></div>
                            <div class="stat-label">Conversions</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $bank ? 'Added' : 'None'; ?></div>
                            <div class="stat-label">Bank Details</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $user['updated_at'] ? date('M d', strtotime($user['updated_at'])) : date('M d', strtotime($user['created_at'])); ?></div>
                            <div class="stat-label">Last Updated</div>
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
                        <div class="card card-profile">
                            <div class="card-header">
                                <h3 class="card-title">Account Overview</h3>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo $stats['approved_conversions']; ?></div>
                                            <div class="metric-label">Approved</div>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo $bank ? 'Yes' : 'No'; ?></div>
                                            <div class="metric-label">Bank Setup</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo date('M d', strtotime($user['created_at'])); ?></div>
                                            <div class="metric-label">Since</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="metric-card">
                                            <div class="metric-value">
                                                <?php if ($bank && $bank['is_verified']): ?>
                                                <span class="text-success">Verified</span>
                                                <?php elseif ($bank): ?>
                                                <span class="text-warning">Pending</span>
                                                <?php else: ?>
                                                <span class="text-danger">None</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="metric-label">Bank Status</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Security Status -->
                        <div class="card card-profile">
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
                                        <span>Email Verified</span>
                                        <span class="text-success"><i class="fas fa-check-circle"></i> Verified</span>
                                    </div>
                                    <div class="progress security-progress">
                                        <div class="progress-bar bg-success" style="width: 100%"></div>
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
                        <div class="card card-profile">
                            <div class="card-header">
                                <ul class="nav nav-tabs" id="profileTabs" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link active" id="info-tab" data-toggle="tab" href="#info">
                                            <i class="fas fa-user-circle mr-2"></i> Information
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" id="bank-tab" data-toggle="tab" href="#bank">
                                            <i class="fas fa-university mr-2"></i> Bank Details
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
                                                <div class="info-label">Account Balance</div>
                                                <div class="info-value">$<?php echo number_format($user['balance'], 2); ?></div>
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
                                    
                                    <!-- Bank Tab -->
                                    <div class="tab-pane fade" id="bank" role="tabpanel">
                                        <?php if ($bank): ?>
                                        <div class="bank-card">
                                            <div class="row align-items-center">
                                                <div class="col-md-3 text-center">
                                                    <div class="bank-icon">
                                                        <i class="fas fa-university"></i>
                                                    </div>
                                                </div>
                                                <div class="col-md-9">
                                                    <h4 class="mb-2"><?php echo htmlspecialchars($bank['bank_name']); ?></h4>
                                                    <p class="mb-1">
                                                        <strong>Account Holder:</strong> <?php echo htmlspecialchars($bank['account_holder']); ?>
                                                        <?php if ($bank['is_verified']): ?>
                                                        <span class="verification-badge" title="Bank Verified">
                                                            <i class="fas fa-check"></i>
                                                        </span>
                                                        <?php endif; ?>
                                                    </p>
                                                    <p class="mb-1">
                                                        <strong>Account Number:</strong> ****<?php echo substr($bank['account_number'], -4); ?>
                                                    </p>
                                                    <p class="mb-1">
                                                        <strong>IFSC Code:</strong> <?php echo htmlspecialchars($bank['ifsc_code']); ?>
                                                    </p>
                                                    <?php if ($bank['upi_id']): ?>
                                                    <p class="mb-0">
                                                        <strong>UPI ID:</strong> <?php echo htmlspecialchars($bank['upi_id']); ?>
                                                    </p>
                                                    <?php endif; ?>
                                                    <div class="mt-3">
                                                        <span class="badge badge-<?php echo $bank['is_verified'] ? 'success' : 'warning'; ?>">
                                                            <?php echo $bank['is_verified'] ? 'Verified' : 'Pending Verification'; ?>
                                                        </span>
                                                    </div>
                                                    <div class="text-right mt-3">
                                                        <button class="btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#editBankModal">
                                                            <i class="fas fa-edit mr-1"></i> Edit Details
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php else: ?>
                                        <div class="empty-state">
                                            <div class="empty-state-icon">
                                                <i class="fas fa-university"></i>
                                            </div>
                                            <h5>No Bank Details Added</h5>
                                            <p class="text-muted">Add your bank details to receive payments.</p>
                                            <button class="btn btn-gradient" data-toggle="modal" data-target="#editBankModal">
                                                <i class="fas fa-plus mr-2"></i> Add Bank Details
                                            </button>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="alert alert-info mt-3">
                                            <i class="fas fa-info-circle mr-2"></i>
                                            Bank verification usually takes 1-2 business days after submission. You will receive an email once verified.
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
            <strong>GVS Icon Media v3.0</strong>
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

<!-- Edit Bank Modal -->
<div class="modal fade" id="editBankModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo $bank ? 'Edit' : 'Add'; ?> Bank Details</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="bank">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Bank Name *</label>
                                <input type="text" class="form-control" name="bank_name" 
                                       value="<?php echo htmlspecialchars($bank['bank_name'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Account Holder Name *</label>
                                <input type="text" class="form-control" name="account_holder" 
                                       value="<?php echo htmlspecialchars($bank['account_holder'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Account Number *</label>
                                <input type="text" class="form-control" name="account_number" 
                                       value="<?php echo htmlspecialchars($bank['account_number'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>IFSC Code *</label>
                                <input type="text" class="form-control" name="ifsc_code" 
                                       value="<?php echo htmlspecialchars($bank['ifsc_code'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>UPI ID (Optional)</label>
                                <input type="text" class="form-control" name="upi_id" 
                                       value="<?php echo htmlspecialchars($bank['upi_id'] ?? ''); ?>">
                                <small class="form-text text-muted">e.g., yourname@bank</small>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                Please double-check your bank details before saving. Incorrect information may delay payments.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gradient">Save Details</button>
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
            title: 'Change Profile Photo',
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
                    <p>Or enter this code manually: <code>ABC123DEF456</code></p>
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
});
</script>

</body>
</html>