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
        u.last_login_ip,
        u.last_login_at,
        u.created_at,
        u.updated_at
    FROM users u
    WHERE u.user_id = :uid
");
$userStmt->execute(['uid' => $advertiserId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die('Invalid advertiser account');
}

/* -------------------------------------------------
   FETCH STATS SUMMARY
-------------------------------------------------- */
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT o.offer_id) AS total_offers,
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        IFNULL(SUM(cv.revenue), 0) AS total_spent
    FROM offers o
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id AND cv.status = 'approved'
    WHERE o.advertiser_id = :uid
");
$statsStmt->execute(['uid' => $advertiserId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

/* -------------------------------------------------
   PROFILE UPDATE (POST)
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'profile') {
    $name     = trim($_POST['name']);
    $mobile   = trim($_POST['mobile']);
    $company  = trim($_POST['company']);
    $telegram = trim($_POST['telegram_id']);
    $teams    = trim($_POST['teams_id']);

    if (empty($name)) {
        $error = 'Full Name is required.';
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
        $success = 'Profile details updated successfully!';
        
        $userStmt->execute(['uid' => $advertiserId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    }
}

/* -------------------------------------------------
   PASSWORD CHANGE (POST)
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'password') {
    $currentPass = $_POST['current_password'] ?? '';
    $newPass     = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (empty($currentPass) || empty($newPass) || empty($confirmPass)) {
        $error = 'All password fields are required.';
    } elseif ($newPass !== $confirmPass) {
        $error = 'New password and confirmation do not match.';
    } elseif (strlen($newPass) < 6) {
        $error = 'New password must be at least 6 characters long.';
    } else {
        $passStmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $passStmt->execute([$advertiserId]);
        $hash = $passStmt->fetchColumn();

        if (!password_verify($currentPass, $hash)) {
            $error = 'Current password is incorrect.';
        } else {
            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $upStmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
            $upStmt->execute([$newHash, $advertiserId]);
            $success = 'Password changed successfully!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profile Settings | Advertiser Panel</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
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
            font-size: 26px;
            font-weight: 800;
            color: #1e293b;
        }

        .stat-card-custom .stat-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
        }

        .avatar-circle {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: var(--primary-gradient);
            color: white;
            font-size: 38px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px auto;
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);
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
                <a href="profile.php" class="nav-link active">Profile Settings</a>
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
                        <a href="reports_conversions.php" class="nav-link"><i class="fas fa-exchange-alt nav-icon"></i><p>Conversion Reports</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link"><i class="nav-icon fas fa-users"></i><p>Affiliate Reports</p></a>
                    </li>
                    <li class="nav-header">TOOLS</li>
                    <li class="nav-item">
                        <a href="ip_whitelist.php" class="nav-link"><i class="nav-icon fas fa-tower-broadcast"></i><p>IP Whitelist</p></a>
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
                        <a href="profile.php" class="nav-link active"><i class="nav-icon fas fa-user"></i><p>Profile</p></a>
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
                        <h1 class="m-0 font-weight-bold">Account Profile & Security</h1>
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

                <!-- Alert Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
                <?php endif; ?>

                <!-- Summary Stat Boxes (2x2 Mobile Grid) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($stats['total_offers'] ?? 0); ?></div>
                            <div class="stat-label">Active Campaigns</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($stats['total_clicks'] ?? 0); ?></div>
                            <div class="stat-label">Total Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($stats['total_conversions'] ?? 0); ?></div>
                            <div class="stat-label">Approved Conversions</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning">$<?php echo number_format($stats['total_spent'] ?? 0, 2); ?></div>
                            <div class="stat-label">Total Ad Spend</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Left Column: User Summary Card -->
                    <div class="col-md-4">
                        <div class="card card-custom p-4 text-center">
                            <div class="avatar-circle">
                                <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                            </div>
                            <h4 class="font-weight-bold text-dark mb-1"><?php echo htmlspecialchars($user['name']); ?></h4>
                            <p class="text-muted small mb-3"><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($user['company'] ?: 'Independent Advertiser'); ?></p>
                            
                            <div class="text-left border-top pt-3">
                                <div class="mb-2">
                                    <small class="text-muted d-block">Account Status</small>
                                    <span class="badge badge-success p-2"><i class="fas fa-check-circle mr-1"></i>Active</span>
                                </div>
                                <div class="mb-2">
                                    <small class="text-muted d-block">Email Address</small>
                                    <strong class="text-dark"><i class="fas fa-envelope mr-1 text-primary"></i><?php echo htmlspecialchars($user['email']); ?></strong>
                                </div>
                                <div class="mb-2">
                                    <small class="text-muted d-block">Member Since</small>
                                    <strong class="text-dark"><i class="fas fa-calendar-alt mr-1 text-info"></i><?php echo date('M d, Y', strtotime($user['created_at'])); ?></strong>
                                </div>
                                <div>
                                    <small class="text-muted d-block">Last Login IP</small>
                                    <code class="p-1 bg-light rounded text-dark font-weight-bold"><?php echo htmlspecialchars($user['last_login_ip'] ?: '127.0.0.1'); ?></code>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Settings Tabs -->
                    <div class="col-md-8">
                        <div class="card card-custom">
                            <div class="card-header p-2 bg-light">
                                <ul class="nav nav-pills" id="profileTabs">
                                    <li class="nav-item">
                                        <a class="nav-link active font-weight-bold" href="#personal" data-toggle="tab">
                                            <i class="fas fa-user-edit mr-1"></i> Personal Details
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link font-weight-bold" href="#security" data-toggle="tab">
                                            <i class="fas fa-lock mr-1"></i> Security & Password
                                        </a>
                                    </li>
                                </ul>
                            </div>

                            <div class="card-body p-4">
                                <div class="tab-content">
                                    
                                    <!-- TAB 1: Personal Details -->
                                    <div class="tab-pane active" id="personal">
                                        <form method="post" action="profile.php">
                                            <input type="hidden" name="action" value="profile">
                                            
                                            <div class="form-group">
                                                <label class="font-weight-bold">Full Name <span class="text-danger">*</span></label>
                                                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                                            </div>

                                            <div class="form-group">
                                                <label class="font-weight-bold">Email Address <span class="badge badge-secondary ml-1">Read Only</span></label>
                                                <input type="email" class="form-control bg-light" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                                                <small class="text-muted">Contact support to update your registered email.</small>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label class="font-weight-bold">Company / Brand Name</label>
                                                        <input type="text" name="company" class="form-control" value="<?php echo htmlspecialchars($user['company'] ?? ''); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label class="font-weight-bold">Mobile / Phone Number</label>
                                                        <input type="text" name="mobile" class="form-control" value="<?php echo htmlspecialchars($user['mobile'] ?? ''); ?>">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label class="font-weight-bold">Telegram Username</label>
                                                        <div class="input-group">
                                                            <div class="input-group-prepend"><span class="input-group-text"><i class="fab fa-telegram-plane"></i></span></div>
                                                            <input type="text" name="telegram_id" class="form-control" value="<?php echo htmlspecialchars($user['telegram_id'] ?? ''); ?>" placeholder="@username">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label class="font-weight-bold">Skype / MS Teams ID</label>
                                                        <div class="input-group">
                                                            <div class="input-group-prepend"><span class="input-group-text"><i class="fab fa-skype"></i></span></div>
                                                            <input type="text" name="teams_id" class="form-control" value="<?php echo htmlspecialchars($user['teams_id'] ?? ''); ?>" placeholder="live:skype_id">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="text-right mt-3">
                                                <button type="submit" class="btn btn-primary font-weight-bold shadow-sm">
                                                    <i class="fas fa-save mr-1"></i> Save Changes
                                                </button>
                                            </div>
                                        </form>
                                    </div>

                                    <!-- TAB 2: Password Change -->
                                    <div class="tab-pane" id="security">
                                        <form method="post" action="profile.php">
                                            <input type="hidden" name="action" value="password">
                                            
                                            <div class="form-group">
                                                <label class="font-weight-bold">Current Password <span class="text-danger">*</span></label>
                                                <input type="password" name="current_password" class="form-control" required>
                                            </div>

                                            <div class="form-group">
                                                <label class="font-weight-bold">New Password <span class="text-danger">*</span></label>
                                                <input type="password" name="new_password" class="form-control" required minlength="6">
                                            </div>

                                            <div class="form-group">
                                                <label class="font-weight-bold">Confirm New Password <span class="text-danger">*</span></label>
                                                <input type="password" name="confirm_password" class="form-control" required minlength="6">
                                            </div>

                                            <div class="text-right mt-3">
                                                <button type="submit" class="btn btn-danger font-weight-bold shadow-sm">
                                                    <i class="fas fa-key mr-1"></i> Update Password
                                                </button>
                                            </div>
                                        </form>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>
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
</body>
</html>