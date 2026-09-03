<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

// Fetch Account Managers for dropdown assignment
$managersStmt = $pdo->query("SELECT user_id, name FROM users WHERE role_id = 2 AND status = 'active' ORDER BY name ASC");
$accountManagers = $managersStmt ? $managersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

/* ===============================
   CREATE NEW PUBLISHER / AFFILIATE (ROLE_ID = 3)
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name             = trim($_POST['name'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $password         = $_POST['password'] ?? '';
    $mobile           = trim($_POST['mobile'] ?? '');
    $company          = trim($_POST['company'] ?? '');
    $telegram_id      = trim($_POST['telegram_id'] ?? '');
    $skype_id         = trim($_POST['skype_id'] ?? '');
    $status           = $_POST['status'] ?? 'active';
    $kyc_status       = $_POST['kyc_status'] ?? 'approved';
    $manager_id       = !empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null;
    $payment_method   = trim($_POST['payment_method'] ?? '');
    $payment_details  = trim($_POST['payment_details'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Name, Email, and Initial Password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Check if email exists
        $checkStmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $checkStmt->execute([$email]);
        if ($checkStmt->fetch()) {
            $error = 'An account with this email address already exists.';
        } else {
            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO users 
                        (role_id, name, email, password_hash, mobile, company, telegram_id, status, kyc_status, account_manager_id, payment_method, payment_details, balance, created_at, updated_at)
                    VALUES
                        (3, :name, :email, :password, :mobile, :company, :telegram_id, :status, :kyc_status, :account_manager_id, :payment_method, :payment_details, 0.00, NOW(), NOW())
                ");

                $stmt->execute([
                    'name'                => $name,
                    'email'               => $email,
                    'password'            => $passwordHash,
                    'mobile'              => $mobile,
                    'company'             => $company,
                    'telegram_id'         => $telegram_id,
                    'status'              => $status,
                    'kyc_status'          => $kyc_status,
                    'account_manager_id'  => $manager_id,
                    'payment_method'      => $payment_method,
                    'payment_details'     => $payment_details
                ]);

                $newPubId = $pdo->lastInsertId();

                // Send Welcome Email if service exists
                if (file_exists(__DIR__ . '/../app/services/MailService.php')) {
                    require_once __DIR__ . '/../app/services/MailService.php';
                    if (function_exists('send_welcome_email')) {
                        send_welcome_email($email, $name, 'affiliate');
                    }
                }

                $success = "Publisher / Affiliate Account #$newPubId ($name) created successfully!";
            } catch (PDOException $e) {
                $error = "Error creating publisher account: " . $e->getMessage();
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
    <title>Create New Publisher Account | Admin Console</title>
    
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

        .hero-banner {
            background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
            border-radius: 12px;
            padding: 25px 30px;
            color: #ffffff;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(79, 70, 229, 0.25);
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
            <li class="nav-item d-none d-sm-inline-block"><a href="publishers.php" class="nav-link">Publishers</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="create_publisher.php" class="nav-link active">Add New Publisher</a></li>
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
                        <a href="publishers.php" class="nav-link active">
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
                        <a href="profile.php" class="nav-link">
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
                        <h1 class="m-0 font-weight-bold">Register New Publisher Account</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="publishers.php">Publishers</a></li>
                            <li class="breadcrumb-item active">Create Publisher</li>
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

                <!-- Hero Banner -->
                <div class="hero-banner d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <h3 class="font-weight-bold mb-1"><i class="fas fa-user-plus mr-2"></i>Add New Publisher / Affiliate</h3>
                        <p class="mb-0 text-white-50">Create affiliate accounts, assign account managers, set KYC status, and configure payment payout details.</p>
                    </div>
                    <div>
                        <a href="publishers.php" class="btn btn-light font-weight-bold"><i class="fas fa-arrow-left mr-1"></i> Back to Publishers List</a>
                    </div>
                </div>

                <!-- Registration Form Card -->
                <div class="card card-custom p-4">
                    <form method="post">
                        <h5 class="text-primary font-weight-bold mb-3"><i class="fas fa-user-circle mr-2"></i>Account & Contact Details</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Publisher Full Name <span class="text-danger">*</span></label>
                                    <input type="text" name="name" class="form-control form-control-lg" placeholder="e.g. Rahul Sharma" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Email Address <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control form-control-lg" placeholder="publisher@domain.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Initial Account Password <span class="text-danger">*</span></label>
                                    <input type="password" name="password" class="form-control form-control-lg" placeholder="Assign secure password" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Company / Network Name</label>
                                    <input type="text" name="company" class="form-control form-control-lg" placeholder="e.g. Alpha Traffic Media" value="<?php echo htmlspecialchars($_POST['company'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Mobile Phone</label>
                                    <input type="text" name="mobile" class="form-control" placeholder="+91 9876543210" value="<?php echo htmlspecialchars($_POST['mobile'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold"><i class="fab fa-telegram text-info mr-1"></i> Telegram Username</label>
                                    <input type="text" name="telegram_id" class="form-control" placeholder="@username" value="<?php echo htmlspecialchars($_POST['telegram_id'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold"><i class="fab fa-skype text-primary mr-1"></i> Skype Handle</label>
                                    <input type="text" name="skype_id" class="form-control" placeholder="live:skype_user" value="<?php echo htmlspecialchars($_POST['skype_id'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <hr class="my-4">
                        <h5 class="text-primary font-weight-bold mb-3"><i class="fas fa-shield-alt mr-2"></i>Status & Account Manager</h5>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Account Status</label>
                                    <select name="status" class="form-control">
                                        <option value="active">Active (Immediate Login Enabled)</option>
                                        <option value="pending">Pending Approval</option>
                                        <option value="blocked">Blocked / Suspended</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">KYC Verification Status</label>
                                    <select name="kyc_status" class="form-control">
                                        <option value="approved">Approved</option>
                                        <option value="pending">Pending Documents</option>
                                        <option value="rejected">Rejected</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Assigned Account Manager</label>
                                    <select name="manager_id" class="form-control">
                                        <option value="">-- Select Account Manager --</option>
                                        <?php foreach ($accountManagers as $mgr): ?>
                                            <option value="<?php echo $mgr['user_id']; ?>">
                                                <?php echo htmlspecialchars($mgr['name']); ?> (#<?php echo $mgr['user_id']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <hr class="my-4">
                        <h5 class="text-primary font-weight-bold mb-3"><i class="fas fa-wallet mr-2"></i>Payout Payment Method</h5>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Payment Method</label>
                                    <select name="payment_method" class="form-control">
                                        <option value="Bank Transfer">Bank Wire Transfer</option>
                                        <option value="UPI">UPI / GPay / PhonePe</option>
                                        <option value="USDT">USDT (TRC20)</option>
                                        <option value="PayPal">PayPal</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="form-group mb-4">
                                    <label class="font-weight-bold">Payment Account Details / Address</label>
                                    <input type="text" name="payment_details" class="form-control" placeholder="Account Number, IFSC, UPI ID, or Crypto Wallet Address" value="<?php echo htmlspecialchars($_POST['payment_details'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="text-right">
                            <button type="submit" class="btn btn-indigo btn-lg font-weight-bold px-5 shadow" style="background: #4f46e5; color: white;">
                                <i class="fas fa-check-circle mr-2"></i> Register Publisher Account
                            </button>
                        </div>
                    </form>
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
