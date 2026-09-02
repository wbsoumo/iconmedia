<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminId = auth_user_id();
$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

/* ===============================
   FETCH ADMIN PROFILE DATA
================================ */
$stmt = $pdo->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.mobile,
        u.company,
        u.telegram_id,
        u.status,
        u.created_at,
        u.updated_at,
        r.role_name
    FROM users u
    INNER JOIN roles r ON r.role_id = u.role_id
    WHERE u.user_id = :user_id
");

$stmt->execute(['user_id' => $adminId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    die('Admin profile not found');
}

/* ===============================
   HANDLE PROFILE UPDATES
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Update Personal Information
    if (isset($_POST['update_profile'])) {
        $name    = trim($_POST['name'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $mobile  = trim($_POST['mobile'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $telegram_id = trim($_POST['telegram_id'] ?? '');
        
        if (empty($name) || empty($email)) {
            $error = 'Name and Email address are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Check email uniqueness if changed
            if ($email !== $profile['email']) {
                $check = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
                $check->execute([$email, $adminId]);
                if ($check->fetch()) {
                    $error = 'An account with this email address already exists.';
                }
            }
            
            if (!$error) {
                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET name = :name,
                        email = :email,
                        mobile = :mobile,
                        company = :company,
                        telegram_id = :telegram_id,
                        updated_at = NOW()
                    WHERE user_id = :user_id
                ");
                
                $updateStmt->execute([
                    'name'        => $name,
                    'email'       => $email,
                    'mobile'      => $mobile,
                    'company'     => $company,
                    'telegram_id' => $telegram_id,
                    'user_id'     => $adminId
                ]);
                
                $_SESSION['user_name']  = $name;
                $_SESSION['user_email'] = $email;
                
                $success = 'Profile information updated successfully!';
                
                // Refresh profile
                $stmt->execute(['user_id' => $adminId]);
                $profile = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
    }
    
    // Update Account Password
    if (isset($_POST['update_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password     = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (!$current_password || !$new_password || !$confirm_password) {
            $error = 'All password fields are required.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New password and Confirm password do not match.';
        } elseif (strlen($new_password) < 6) {
            $error = 'New password must be at least 6 characters long.';
        } else {
            // Verify current password
            $passStmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $passStmt->execute([$adminId]);
            $userPass = $passStmt->fetchColumn();
            
            if (!password_verify($current_password, $userPass)) {
                $error = 'Current password is incorrect.';
            } else {
                $newHash = password_hash($new_password, PASSWORD_DEFAULT);
                $updPass = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
                $updPass->execute([$newHash, $adminId]);
                
                $success = 'Password updated successfully! Please use your new password next time you log in.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Profile Settings | GVS Icon Media Console</title>
    
    <!-- Google Font: Source Sans Pro -->
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

        .welcome-banner {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.2);
        }

        .profile-avatar {
            width: 90px;
            height: 90px;
            background: #4f46e5;
            color: white;
            font-size: 36px;
            font-weight: 700;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);
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
            <li class="nav-item d-none d-sm-inline-block"><a href="profile.php" class="nav-link active">Admin Profile</a></li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <a class="nav-link text-danger font-weight-bold" href="../logout.php"><i class="fas fa-sign-out-alt mr-1"></i> Logout</a>
            </li>
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
                        <a href="fraud_dashboard.php" class="nav-link">
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
                        <a href="profile.php" class="nav-link active">
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
                        <h1 class="m-0 font-weight-bold">Administrator Profile & Security</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">My Profile</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

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
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
                <?php endif; ?>

                <!-- Executive Hero Banner -->
                <div class="welcome-banner d-flex align-items-center flex-wrap">
                    <div class="profile-avatar mb-3 mb-md-0">
                        <?php echo strtoupper(substr($profile['name'], 0, 1)); ?>
                    </div>
                    <div>
                        <h3 class="font-weight-bold mb-1"><?php echo htmlspecialchars($profile['name']); ?></h3>
                        <p class="mb-1 text-white-50"><i class="fas fa-user-shield text-warning mr-1"></i> System Super Administrator | <i class="fas fa-envelope mr-1"></i><?php echo htmlspecialchars($profile['email']); ?></p>
                        <small class="text-white-50">Account ID: #<?php echo $profile['user_id']; ?> • Account Created: <?php echo date('F d, Y', strtotime($profile['created_at'])); ?></small>
                    </div>
                </div>

                <div class="row">
                    <!-- Profile Information Form -->
                    <div class="col-lg-7">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-user-cog mr-2"></i>Personal & Contact Details</h4>
                            <form method="post">
                                <input type="hidden" name="update_profile" value="1">
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label class="font-weight-bold">Full Name <span class="text-danger">*</span></label>
                                            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($profile['name']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label class="font-weight-bold">Email Address <span class="text-danger">*</span></label>
                                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($profile['email']); ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label class="font-weight-bold">Mobile Phone Number</label>
                                            <input type="text" name="mobile" class="form-control" placeholder="+91 9876543210" value="<?php echo htmlspecialchars($profile['mobile'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label class="font-weight-bold">Company / Organization</label>
                                            <input type="text" name="company" class="form-control" placeholder="GVS Icon Media Network" value="<?php echo htmlspecialchars($profile['company'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group mb-4">
                                    <label class="font-weight-bold"><i class="fab fa-telegram text-info mr-1"></i> Telegram Handle</label>
                                    <input type="text" name="telegram_id" class="form-control" placeholder="@username" value="<?php echo htmlspecialchars($profile['telegram_id'] ?? ''); ?>">
                                </div>

                                <div class="text-right">
                                    <button type="submit" class="btn btn-primary font-weight-bold px-4">
                                        <i class="fas fa-save mr-2"></i> Save Profile Details
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Password Security Form -->
                    <div class="col-lg-5">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-danger mb-3"><i class="fas fa-lock mr-2"></i>Security & Change Password</h4>
                            <form method="post">
                                <input type="hidden" name="update_password" value="1">
                                
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Current Password <span class="text-danger">*</span></label>
                                    <input type="password" name="current_password" class="form-control" placeholder="Enter current password" required>
                                </div>

                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">New Password <span class="text-danger">*</span></label>
                                    <input type="password" name="new_password" class="form-control" placeholder="Minimum 6 characters" required>
                                </div>

                                <div class="form-group mb-4">
                                    <label class="font-weight-bold">Confirm New Password <span class="text-danger">*</span></label>
                                    <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter new password" required>
                                </div>

                                <div class="text-right">
                                    <button type="submit" class="btn btn-danger font-weight-bold px-4">
                                        <i class="fas fa-key mr-2"></i> Update Password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline"><strong>Admin Console v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
</body>
</html>