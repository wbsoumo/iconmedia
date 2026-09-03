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
   HANDLE KYC ACTIONS (Approve / Reject)
================================ */
if (!empty($_GET['action']) && !empty($_GET['id'])) {
    $userId = (int)$_GET['id'];
    $act = $_GET['action'];
    if ($act === 'approve' || $act === 'verify') {
        $pdo->exec("UPDATE users SET kyc_status='verified', updated_at=NOW() WHERE user_id=$userId");
        $success = "KYC verified for User #$userId successfully.";
    } elseif ($act === 'reject') {
        $pdo->exec("UPDATE users SET kyc_status='rejected', updated_at=NOW() WHERE user_id=$userId");
        $success = "KYC rejected for User #$userId.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id']) && isset($_POST['action'])) {
    $userId = (int)$_POST['user_id'];
    $action = $_POST['action'];
    if ($action === 'verify') {
        $pdo->exec("UPDATE users SET kyc_status='verified', updated_at=NOW() WHERE user_id=$userId");
        $success = "KYC verified for User #$userId successfully.";
    } elseif ($action === 'reject') {
        $pdo->exec("UPDATE users SET kyc_status='rejected', updated_at=NOW() WHERE user_id=$userId");
        $success = "KYC rejected for User #$userId.";
    }
}

/* ===============================
   FETCH PENDING & ALL KYC REQUESTS
================================ */
$sql = "
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.mobile,
        u.company,
        u.kyc_status,
        u.status as account_status,
        u.created_at,
        r.role_name
    FROM users u
    INNER JOIN roles r ON r.role_id = u.role_id
    WHERE u.role_id IN (3, 4)
    ORDER BY CASE WHEN u.kyc_status = 'pending' THEN 1 ELSE 2 END, u.created_at DESC
";

$kycUsers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Totals
$stats = [
    'pending' => 0,
    'verified' => 0,
    'rejected' => 0,
    'affiliates_pending' => 0
];

foreach ($kycUsers as $user) {
    if ($user['kyc_status'] === 'pending') {
        $stats['pending']++;
        if (strtolower($user['role_name']) === 'affiliate') $stats['affiliates_pending']++;
    } elseif ($user['kyc_status'] === 'verified') {
        $stats['verified']++;
    } elseif ($user['kyc_status'] === 'rejected') {
        $stats['rejected']++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pending KYC Verification | Admin Panel</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">

    <style>
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
            <li class="nav-item d-none d-sm-inline-block"><a href="pending_kyc.php" class="nav-link active">Pending KYC</a></li>
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
                        <a href="account_managers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-tie"></i>
                            <p>Account Managers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="pending_kyc.php" class="nav-link active">
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
                        <h1 class="m-0 font-weight-bold">KYC Verification Hub</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Pending KYC</li>
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
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning"><?php echo number_format($stats['pending']); ?></div>
                            <div class="stat-label">Pending Reviews</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($stats['verified']); ?></div>
                            <div class="stat-label">Verified Accounts</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-danger"><?php echo number_format($stats['rejected']); ?></div>
                            <div class="stat-label">Rejected Reviews</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($stats['affiliates_pending']); ?></div>
                            <div class="stat-label">Pending Publishers</div>
                        </div>
                    </div>
                </div>

                <!-- KYC Verification DataTables Catalog -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-id-card mr-2"></i>KYC Submissions Roster</h4>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="kycTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>User & Company</th>
                                    <th>Account Type</th>
                                    <th>Contact Info</th>
                                    <th>KYC Status</th>
                                    <th>Registration Date</th>
                                    <th>Quick Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($kycUsers as $u): ?>
                                <tr>
                                    <td>
                                        <strong class="text-dark"><?php echo htmlspecialchars($u['name']); ?></strong>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($u['company'] ?: 'Individual'); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo strtolower($u['role_name']) === 'affiliate' ? 'primary' : 'info'; ?> p-2">
                                            <?php echo ucfirst($u['role_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="d-block text-dark"><?php echo htmlspecialchars($u['email']); ?></small>
                                        <small class="text-muted"><?php echo htmlspecialchars($u['mobile'] ?: 'N/A'); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($u['kyc_status'] === 'verified'): ?>
                                            <span class="badge badge-success p-2">Verified</span>
                                        <?php elseif ($u['kyc_status'] === 'rejected'): ?>
                                            <span class="badge badge-danger p-2">Rejected</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning p-2">Pending Review</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small class="text-muted"><?php echo date('Y-m-d', strtotime($u['created_at'])); ?></small></td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="?action=verify&id=<?php echo $u['user_id']; ?>" class="btn btn-sm btn-success font-weight-bold" title="Approve KYC"><i class="fas fa-check mr-1"></i> Verify</a>
                                            <a href="?action=reject&id=<?php echo $u['user_id']; ?>" class="btn btn-sm btn-outline-danger font-weight-bold" title="Reject KYC"><i class="fas fa-times mr-1"></i> Reject</a>
                                        </div>
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

<script>
$(document).ready(function() {
    $('#kycTable').DataTable({
        pageLength: 10,
        responsive: true
    });
});
</script>
</body>
</html>