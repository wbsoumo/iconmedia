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
   FETCH AFFILIATE DETAILS
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
        u.last_login_ip,
        u.created_at,
        u.updated_at,
        am.name  AS manager_name,
        am.email AS manager_email,
        am.phone AS manager_phone
    FROM users u
    LEFT JOIN account_managers am ON am.id = u.account_manager_id
    WHERE u.user_id = :uid
");
$userStmt->execute(['uid' => $affiliateId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die('Invalid affiliate account');
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
        COUNT(DISTINCT click_id) AS total_clicks,
        COUNT(DISTINCT conversion_id) AS total_conversions,
        SUM(status = 'approved') AS approved_conversions,
        IFNULL(SUM(CASE WHEN status = 'approved' THEN payout ELSE 0 END), 0) AS total_earnings
    FROM conversions
    WHERE affiliate_id = :uid
");
$statsStmt->execute(['uid' => $affiliateId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   UPDATE PROFILE (POST)
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'profile') {
    $name     = trim($_POST['name']);
    $mobile   = trim($_POST['mobile']);
    $telegram = trim($_POST['telegram_id']);
    $teams    = trim($_POST['teams_id']);

    if (empty($name)) {
        $error = 'Full Name is required.';
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
        $success = 'Profile details updated successfully!';
        
        $userStmt->execute(['uid' => $affiliateId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    }
}

/* -------------------------------------------------
   UPDATE BANK / PAYMENT DETAILS (POST)
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bank') {
    $bankName  = trim($_POST['bank_name']);
    $holder    = trim($_POST['account_holder']);
    $account   = trim($_POST['account_number']);
    $ifsc      = strtoupper(trim($_POST['ifsc_code']));
    $upi       = strtolower(trim($_POST['upi_id']));

    if (empty($bankName) || empty($holder)) {
        $error = 'Bank Name and Account Holder are required.';
    } elseif (!empty($account) && !preg_match('/^[0-9]{9,18}$/', $account)) {
        $error = 'Invalid Account Number (9–18 digits required).';
    } elseif (!empty($ifsc) && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
        $error = 'Invalid IFSC Code format.';
    } else {
        if ($bank) {
            $stmt = $pdo->prepare("
                UPDATE affiliate_bank_details SET
                    bank_name = :bank,
                    account_holder = :holder,
                    account_number = :account,
                    ifsc_code = :ifsc,
                    upi_id = :upi,
                    updated_at = NOW()
                WHERE affiliate_id = :uid
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO affiliate_bank_details
                (affiliate_id, bank_name, account_holder, account_number, ifsc_code, upi_id, is_verified, created_at, updated_at)
                VALUES (:uid, :bank, :holder, :account, :ifsc, :upi, 0, NOW(), NOW())
            ");
        }

        $stmt->execute([
            'uid'     => $affiliateId,
            'bank'    => $bankName,
            'holder'  => $holder,
            'account' => $account ?: null,
            'ifsc'    => $ifsc ?: null,
            'upi'     => $upi ?: null
        ]);

        $success = 'Payment details saved successfully!';
        $bankStmt->execute(['uid' => $affiliateId]);
        $bank = $bankStmt->fetch(PDO::FETCH_ASSOC);
    }
}

/* -------------------------------------------------
   UPDATE PASSWORD (POST)
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
        $error = 'New password must be at least 6 characters.';
    } else {
        $passStmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $passStmt->execute([$affiliateId]);
        $hash = $passStmt->fetchColumn();

        if (!password_verify($currentPass, $hash)) {
            $error = 'Current password is incorrect.';
        } else {
            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $upStmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
            $upStmt->execute([$newHash, $affiliateId]);
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
    <title>Profile Settings | Affiliate Hub</title>
    
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
                <a href="profile.php" class="nav-link active">Profile & Payments</a>
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
                <i class="fas fa-rocket mr-2"></i><strong>Icon Media</strong>
            </span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link"><i class="nav-icon fas fa-tachometer-alt"></i><p>Dashboard</p></a>
                    </li>
                    <li class="nav-header">CAMPAIGNS & OFFERS</li>
                    <li class="nav-item">
                        <a href="offers.php" class="nav-link"><i class="nav-icon fas fa-gift"></i><p>My Campaigns</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="clicks.php" class="nav-link"><i class="nav-icon fas fa-mouse-pointer"></i><p>Click Logs</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="reports.php" class="nav-link"><i class="fas fa-exchange-alt nav-icon"></i><p>Conversions & Reports</p></a>
                    </li>
                    <li class="nav-header">TOOLS</li>
                    <li class="nav-item">
                        <a href="link-builder.php" class="nav-link"><i class="nav-icon fas fa-link"></i><p>Link Builder</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="postback.php" class="nav-link"><i class="nav-icon fas fa-code"></i><p>Postback Settings</p></a>
                    </li>
                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link active"><i class="nav-icon fas fa-user"></i><p>Profile & Payments</p></a>
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
                        <h1 class="m-0 font-weight-bold">Profile & Payment Settings</h1>
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
                            <div class="stat-number text-primary"><?php echo number_format($stats['total_clicks'] ?? 0); ?></div>
                            <div class="stat-label">Total Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($stats['total_conversions'] ?? 0); ?></div>
                            <div class="stat-label">Total Conversions</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning"><?php echo number_format($stats['approved_conversions'] ?? 0); ?></div>
                            <div class="stat-label">Approved Conversions</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success">$<?php echo number_format($stats['total_earnings'] ?? 0, 2); ?></div>
                            <div class="stat-label">Lifetime Earnings</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Left Column: Publisher Card -->
                    <div class="col-md-4">
                        <div class="card card-custom p-4 text-center">
                            <div class="avatar-circle">
                                <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                            </div>
                            <h4 class="font-weight-bold text-dark mb-1"><?php echo htmlspecialchars($user['name']); ?></h4>
                            <p class="text-muted small mb-3"><i class="fas fa-id-badge mr-1"></i>Affiliate ID: #<?php echo $user['user_id']; ?></p>
                            
                            <div class="text-left border-top pt-3">
                                <div class="mb-2">
                                    <small class="text-muted d-block">Account Status</small>
                                    <span class="badge badge-success p-2"><i class="fas fa-check-circle mr-1"></i>Approved Publisher</span>
                                </div>
                                <div class="mb-2">
                                    <small class="text-muted d-block">Registered Email</small>
                                    <strong class="text-dark"><i class="fas fa-envelope mr-1 text-primary"></i><?php echo htmlspecialchars($user['email']); ?></strong>
                                </div>
                                <div class="mb-2">
                                    <small class="text-muted d-block">Account Manager</small>
                                    <strong class="text-dark"><i class="fas fa-user-shield mr-1 text-info"></i><?php echo htmlspecialchars($user['manager_name'] ?: 'Network AM'); ?></strong>
                                </div>
                                <div class="mb-2">
                                    <small class="text-muted d-block">Joined Date</small>
                                    <strong class="text-dark"><i class="fas fa-calendar-alt mr-1 text-warning"></i><?php echo date('M d, Y', strtotime($user['created_at'])); ?></strong>
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
                                <ul class="nav nav-pills" id="affProfileTabs">
                                    <li class="nav-item">
                                        <a class="nav-link active font-weight-bold" href="#personal" data-toggle="tab">
                                            <i class="fas fa-user-edit mr-1"></i> Personal Profile
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link font-weight-bold" href="#bank" data-toggle="tab">
                                            <i class="fas fa-university mr-1"></i> Bank & Payout Methods
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link font-weight-bold" href="#security" data-toggle="tab">
                                            <i class="fas fa-lock mr-1"></i> Change Password
                                        </a>
                                    </li>
                                </ul>
                            </div>

                            <div class="card-body p-4">
                                <div class="tab-content">
                                    
                                    <!-- TAB 1: Personal Profile -->
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
                                                <small class="text-muted">Contact your account manager to change registered email.</small>
                                            </div>

                                            <div class="form-group">
                                                <label class="font-weight-bold">Mobile Phone</label>
                                                <input type="text" name="mobile" class="form-control" value="<?php echo htmlspecialchars($user['mobile'] ?? ''); ?>">
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label class="font-weight-bold">Telegram Handle</label>
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

                                    <!-- TAB 2: Bank & Payout Methods -->
                                    <div class="tab-pane" id="bank">
                                        <form method="post" action="profile.php">
                                            <input type="hidden" name="action" value="bank">

                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label class="font-weight-bold">Bank Name <span class="text-danger">*</span></label>
                                                        <input type="text" name="bank_name" class="form-control" value="<?php echo htmlspecialchars($bank['bank_name'] ?? ''); ?>" required placeholder="e.g. HDFC Bank">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label class="font-weight-bold">Account Holder Name <span class="text-danger">*</span></label>
                                                        <input type="text" name="account_holder" class="form-control" value="<?php echo htmlspecialchars($bank['account_holder'] ?? ''); ?>" required placeholder="Exact name on bank account">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label class="font-weight-bold">Account Number</label>
                                                        <input type="text" name="account_number" class="form-control" value="<?php echo htmlspecialchars($bank['account_number'] ?? ''); ?>" placeholder="9-18 digits">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label class="font-weight-bold">IFSC Code</label>
                                                        <input type="text" name="ifsc_code" class="form-control text-uppercase" value="<?php echo htmlspecialchars($bank['ifsc_code'] ?? ''); ?>" placeholder="e.g. HDFC0001234">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="form-group">
                                                <label class="font-weight-bold">UPI ID (Optional)</label>
                                                <input type="text" name="upi_id" class="form-control" value="<?php echo htmlspecialchars($bank['upi_id'] ?? ''); ?>" placeholder="username@upi">
                                            </div>

                                            <div class="text-right mt-3">
                                                <button type="submit" class="btn btn-success font-weight-bold shadow-sm">
                                                    <i class="fas fa-university mr-1"></i> Update Payment Details
                                                </button>
                                            </div>
                                        </form>
                                    </div>

                                    <!-- TAB 3: Change Password -->
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
        <div class="float-right d-none d-sm-inline"><strong>Affiliate Panel v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
</body>
</html>