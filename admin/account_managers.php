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
   CREATE NEW ACCOUNT MANAGER (ROLE_ID = 2)
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_manager'])) {
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if (!$name || !$email || !$pass) {
        $error = "Name, Email, and Password are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $check = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = "An account with this email address already exists.";
        } else {
            $passHash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (role_id, name, email, password_hash, status, created_at, updated_at) VALUES (2, ?, ?, ?, 'active', NOW(), NOW())");
            $stmt->execute([$name, $email, $passHash]);
            $success = "Account Manager #{$pdo->lastInsertId()} ($name) created successfully!";
        }
    }
}

/* ===============================
   HANDLE STATUS ACTION / ASSIGNMENT
================================ */
if (!empty($_GET['action']) && !empty($_GET['id'])) {
    $mgrId = (int)$_GET['id'];
    $act = $_GET['action'];
    if ($act === 'activate') {
        $pdo->exec("UPDATE users SET status='active', updated_at=NOW() WHERE user_id=$mgrId AND role_id=2");
        $success = "Account Manager activated.";
    } elseif ($act === 'block') {
        $pdo->exec("UPDATE users SET status='blocked', updated_at=NOW() WHERE user_id=$mgrId AND role_id=2");
        $success = "Account Manager blocked.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_publisher'])) {
    $pubId = (int)($_POST['publisher_id'] ?? 0);
    $mgrId = (int)($_POST['manager_id'] ?? 0);
    if ($pubId && $mgrId) {
        $pdo->prepare("UPDATE users SET account_manager_id = ?, updated_at = NOW() WHERE user_id = ? AND role_id = 3")->execute([$mgrId, $pubId]);
        $success = "Publisher assigned to Account Manager successfully!";
    }
}

/* ===============================
   FETCH MANAGERS & ASSIGNMENTS
================================ */
$managers = $pdo->query("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.status,
        u.created_at,
        COUNT(DISTINCT p.user_id) AS total_publishers
    FROM users u
    LEFT JOIN users p ON p.account_manager_id = u.user_id AND p.role_id = 3
    WHERE u.role_id = 2
    GROUP BY u.user_id
    ORDER BY u.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$publishers = $pdo->query("SELECT user_id, name, email, company, account_manager_id FROM users WHERE role_id = 3 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Totals
$stats = [
    'total_managers' => count($managers),
    'active_managers' => 0,
    'assigned_publishers' => 0
];

foreach ($managers as $m) {
    if ($m['status'] === 'active') $stats['active_managers']++;
    $stats['assigned_publishers'] += (int)$m['total_publishers'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account Managers Directory | Admin Panel</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <!-- Select2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">

    <style>
        /* Select2 bootstrap4 theme fixes */
        .select2-container--bootstrap4 .select2-selection--single {
            height: 46px !important;
            border-radius: 8px !important;
            border: 1px solid #cbd5e1 !important;
            padding: 8px 14px !important;
            background-color: #ffffff !important;
            display: flex !important;
            align-items: center !important;
        }

        .select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered {
            color: #1e293b !important;
            font-weight: 600 !important;
            font-size: 14px !important;
            padding-left: 0 !important;
            line-height: normal !important;
        }

        .select2-container--bootstrap4 .select2-selection--single .select2-selection__placeholder {
            color: #64748b !important;
            font-weight: 400 !important;
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
            margin-bottom: 15px;
        }

        .stat-card-custom .stat-number {
            font-size: 24px;
            font-weight: 800;
            color: #1e293b;
        }

        .stat-card-custom .stat-label {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
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
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link">Dashboard</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="account_managers.php" class="nav-link active">Account Managers</a></li>
        </ul>
    </nav>

    <!-- Sidebar -->
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
                        <a href="account_managers.php" class="nav-link active">
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

                                        <li class="nav-header">TOOLS & TESTING</li>
                    <li class="nav-item">
                        <a href="link_tester.php" class="nav-link">
                            <i class="nav-icon fas fa-vial"></i>
                            <p>Link Tester</p>
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
                        <h1 class="m-0 font-weight-bold">Account Managers Directory</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Account Managers</li>
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

                <!-- Summary Stat Cards (2x2 Mobile Responsive Grid) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-4">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($stats['total_managers']); ?></div>
                            <div class="stat-label">Total Account Managers</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($stats['active_managers']); ?></div>
                            <div class="stat-label">Active Managers</div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($stats['assigned_publishers']); ?></div>
                            <div class="stat-label">Assigned Publishers</div>
                        </div>
                    </div>
                </div>

                <!-- Create & Assign Panels -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card card-custom p-4">
                            <h5 class="font-weight-bold text-primary mb-3"><i class="fas fa-user-plus mr-2"></i>Create New Account Manager</h5>
                            <form method="post">
                                <input type="hidden" name="create_manager" value="1">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Manager Full Name <span class="text-danger">*</span></label>
                                    <input type="text" name="name" class="form-control" placeholder="e.g. John Doe" required>
                                </div>
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Manager Email <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control" placeholder="manager@iconmedia.com" required>
                                </div>
                                <div class="form-group mb-4">
                                    <label class="font-weight-bold">Initial Password <span class="text-danger">*</span></label>
                                    <input type="password" name="password" class="form-control" placeholder="Assign secure password" required>
                                </div>
                                <button type="submit" class="btn btn-success btn-block font-weight-bold shadow-sm">
                                    <i class="fas fa-check-circle mr-1"></i> Register Manager Account
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card card-custom p-4">
                            <h5 class="font-weight-bold text-primary mb-3"><i class="fas fa-user-check mr-2"></i>Assign Publisher to Manager</h5>
                            <form method="post">
                                <input type="hidden" name="assign_publisher" value="1">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Select Publisher <span class="text-danger">*</span></label>
                                    <select name="publisher_id" class="form-control select2" required>
                                        <option value="">Choose Publisher...</option>
                                        <?php foreach ($publishers as $pub): ?>
                                        <option value="<?php echo $pub['user_id']; ?>">
                                            <?php echo htmlspecialchars($pub['name']); ?> (<?php echo htmlspecialchars($pub['email']); ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group mb-4">
                                    <label class="font-weight-bold">Select Account Manager <span class="text-danger">*</span></label>
                                    <select name="manager_id" class="form-control select2" required>
                                        <option value="">Choose Account Manager...</option>
                                        <?php foreach ($managers as $mgr): ?>
                                        <option value="<?php echo $mgr['user_id']; ?>">
                                            <?php echo htmlspecialchars($mgr['name']); ?> (<?php echo htmlspecialchars($mgr['email']); ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary btn-block font-weight-bold shadow-sm">
                                    <i class="fas fa-link mr-1"></i> Assign Publisher Now
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Account Managers DataTables Directory -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-user-tie mr-2"></i>Account Managers Roster</h4>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="managersTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Manager Name</th>
                                    <th>Email</th>
                                    <th>Assigned Publishers</th>
                                    <th>Status</th>
                                    <th>Date Added</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($managers as $mgr): ?>
                                <tr>
                                    <td><strong>#<?php echo $mgr['user_id']; ?></strong></td>
                                    <td><strong class="text-dark"><?php echo htmlspecialchars($mgr['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($mgr['email']); ?></td>
                                    <td><span class="badge badge-info p-2"><?php echo number_format($mgr['total_publishers']); ?> Publishers</span></td>
                                    <td>
                                        <span class="badge badge-<?php echo $mgr['status'] === 'active' ? 'success' : 'secondary'; ?> p-2">
                                            <?php echo ucfirst($mgr['status']); ?>
                                        </span>
                                    </td>
                                    <td><small class="text-muted"><?php echo date('Y-m-d', strtotime($mgr['created_at'])); ?></small></td>
                                    <td>
                                        <?php if ($mgr['status'] === 'active'): ?>
                                            <a href="?action=block&id=<?php echo $mgr['user_id']; ?>" class="btn btn-sm btn-outline-danger" title="Block"><i class="fas fa-ban"></i></a>
                                        <?php else: ?>
                                            <a href="?action=activate&id=<?php echo $mgr['user_id']; ?>" class="btn btn-sm btn-outline-success" title="Activate"><i class="fas fa-check"></i></a>
                                        <?php endif; ?>
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

    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline"><strong>Admin Panel v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    $('.select2').select2({ theme: 'bootstrap4', width: '100%' });
    $('#managersTable').DataTable({
        pageLength: 10,
        responsive: true
    });
});
</script>
</body>
</html>