<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = isset($_GET['approved']) ? 'User approved successfully!' : (isset($_GET['rejected']) ? 'User rejected.' : null);
$error = null;

/* ===============================
   HANDLE INDIVIDUAL & BULK ACTIONS
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Single user quick status change
    if (isset($_POST['action'], $_POST['target_user_id'])) {
        $targetId = (int)$_POST['target_user_id'];
        $act = $_POST['action'];
        
        if (in_array($act, ['active', 'pending', 'rejected', 'blocked'], true)) {
            $uStmt = $pdo->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ?");
            $uStmt->execute([$act, $targetId]);
            $success = "User #$targetId status updated to '" . strtoupper($act) . "'!";
        }
    }
    
    // Bulk Action
    if (isset($_POST['bulk_action']) && !empty($_POST['selected_users'])) {
        $action = $_POST['bulk_action'];
        $selectedUsers = array_map('intval', $_POST['selected_users']);
        
        if (!empty($selectedUsers)) {
            $placeholders = implode(',', array_fill(0, count($selectedUsers), '?'));
            
            if ($action === 'approve') {
                $sql = "UPDATE users SET status = 'active', updated_at = NOW() WHERE user_id IN ($placeholders)";
                $msg = count($selectedUsers) . ' users approved successfully!';
            } elseif ($action === 'reject') {
                $sql = "UPDATE users SET status = 'rejected', updated_at = NOW() WHERE user_id IN ($placeholders)";
                $msg = count($selectedUsers) . ' users rejected!';
            } elseif ($action === 'block') {
                $sql = "UPDATE users SET status = 'blocked', updated_at = NOW() WHERE user_id IN ($placeholders)";
                $msg = count($selectedUsers) . ' users blocked!';
            }
            
            if (isset($sql)) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($selectedUsers);
                $success = $msg;
            }
        }
    }
}

/* ===============================
   SEARCH & FILTERS (ALL USERS)
================================ */
$search     = trim($_GET['search'] ?? '');
$status     = $_GET['status'] ?? 'all';
$roleFilter = $_GET['role'] ?? 'all';
$dateFrom   = $_GET['from'] ?? '';
$dateTo     = $_GET['to'] ?? '';

$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = '(u.name LIKE :search OR u.email LIKE :search OR u.company LIKE :search OR u.mobile LIKE :search)';
    $params['search'] = "%$search%";
}

if ($status !== 'all') {
    $where[] = 'u.status = :status';
    $params['status'] = $status;
}

if ($roleFilter !== 'all') {
    $where[] = 'r.role_name = :role';
    $params['role'] = $roleFilter;
}

if ($dateFrom !== '' && $dateTo !== '') {
    $where[] = 'DATE(u.created_at) BETWEEN :from AND :to';
    $params['from'] = $dateFrom;
    $params['to'] = $dateTo;
}

$whereSql = implode(' AND ', $where);

// Fetch All System Users
$query = "
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.mobile,
        u.status,
        r.role_name,
        u.created_at,
        u.kyc_status,
        u.company,
        u.telegram_id,
        u.balance,
        (SELECT COUNT(*) FROM conversions WHERE affiliate_id = u.user_id) AS conversion_count
    FROM users u
    INNER JOIN roles r ON r.role_id = u.role_id
    WHERE $whereSql
    ORDER BY u.user_id DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH SYSTEM STATS
================================ */
$summary = $pdo->query("
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN u.status = 'active' THEN 1 ELSE 0 END) as active_users,
        SUM(CASE WHEN u.status = 'pending' THEN 1 ELSE 0 END) as pending_users,
        SUM(CASE WHEN u.status = 'blocked' THEN 1 ELSE 0 END) as blocked_users,
        SUM(CASE WHEN r.role_name = 'affiliate' THEN 1 ELSE 0 END) as total_affiliates,
        SUM(CASE WHEN r.role_name = 'advertiser' THEN 1 ELSE 0 END) as total_advertisers,
        SUM(CASE WHEN r.role_name = 'admin' THEN 1 ELSE 0 END) as total_admins
    FROM users u
    INNER JOIN roles r ON r.role_id = u.role_id
")->fetch(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>All System Users | Admin Console | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">

    <style>
        .card-dashboard {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            background: #ffffff;
        }
        
        .card-dashboard .card-header {
            background: white;
            border-bottom: 1px solid rgba(0,0,0,0.08);
            padding: 20px 25px;
        }
        
        .card-dashboard .card-body {
            padding: 25px;
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.2);
        }
        
        .metric-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e2e8f0;
            text-align: center;
            transition: all 0.3s ease;
            flex: 1;
            min-width: 140px;
        }
        
        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.06);
        }
        
        .metric-value {
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .metric-label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .summary-stats {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .filter-group {
            flex: 1;
            min-width: 170px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 6px;
            color: #475569;
            font-size: 13px;
            font-weight: 600;
        }
        
        .filter-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            background: #f8fafc;
            transition: all 0.3s ease;
        }
        
        .filter-control:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
            background: white;
        }
        
        .table-dashboard {
            width: 100%;
        }
        
        .table-dashboard thead th {
            background: #f8fafc;
            color: #1e293b;
            font-weight: 700;
            padding: 14px;
            border-bottom: 2px solid #e2e8f0;
            font-size: 13px;
        }
        
        .table-dashboard tbody td {
            padding: 14px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 14px;
        }
        
        .badge-role {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .role-affiliate { background: #dcfce7; color: #15803d; }
        .role-advertiser { background: #dbeafe; color: #1d4ed8; }
        .role-admin { background: #f3e8ff; color: #7e22ce; }
        .role-manager { background: #fef3c7; color: #b45309; }

        .badge-status {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-active { background: #d1fae5; color: #047857; }
        .status-pending { background: #fef3c7; color: #d97706; }
        .status-blocked { background: #fee2e2; color: #b91c1c; }
        .status-rejected { background: #f3f4f6; color: #4b5563; }
        
        .bulk-bar {
            background: #f8fafc;
            padding: 12px 20px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
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
            <li class="nav-item d-none d-sm-inline-block"><a href="users.php" class="nav-link active">All System Users</a></li>
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
                        <a href="users.php" class="nav-link active">
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
                        <h1 class="m-0 font-weight-bold">System Users Management</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Users</li>
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

                <!-- Hero Banner -->
                <div class="welcome-banner d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <h3 class="font-weight-bold mb-1"><i class="fas fa-users-cog mr-2"></i>Global System Accounts Directory</h3>
                        <p class="mb-0 text-white-50">Filter, manage, approve, or modify accounts across Publishers, Advertisers, Managers, and Admins.</p>
                    </div>
                    <div class="mt-3 mt-md-0">
                        <a href="create_publisher.php" class="btn btn-indigo font-weight-bold mr-2" style="background: #4f46e5; color: white;">
                            <i class="fas fa-user-plus mr-1"></i> Add Publisher
                        </a>
                        <a href="create_advertiser.php" class="btn btn-success font-weight-bold">
                            <i class="fas fa-plus mr-1"></i> Add Advertiser
                        </a>
                    </div>
                </div>

                <!-- Summary Metrics -->
                <div class="summary-stats">
                    <div class="metric-card">
                        <div class="metric-value text-primary"><?php echo number_format($summary['total_users']); ?></div>
                        <div class="metric-label">Total Users</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value text-success"><?php echo number_format($summary['active_users']); ?></div>
                        <div class="metric-label">Active</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value text-warning"><?php echo number_format($summary['pending_users']); ?></div>
                        <div class="metric-label">Pending Approval</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value text-info"><?php echo number_format($summary['total_affiliates']); ?></div>
                        <div class="metric-label">Publishers</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value text-indigo" style="color: #4f46e5;"><?php echo number_format($summary['total_advertisers']); ?></div>
                        <div class="metric-label">Advertisers</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value text-danger"><?php echo number_format($summary['blocked_users']); ?></div>
                        <div class="metric-label">Blocked</div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="card-dashboard">
                    <div class="card-header">
                        <h4 class="card-title font-weight-bold text-dark m-0"><i class="fas fa-filter mr-2 text-primary"></i>Filter System Accounts</h4>
                    </div>
                    <div class="card-body">
                        <form method="get" class="filter-row">
                            <div class="filter-group">
                                <label><i class="fas fa-search mr-1"></i> Search Keyword</label>
                                <input type="text" name="search" class="filter-control" placeholder="Search name, email, company..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-user-shield mr-1"></i> User Role</label>
                                <select name="role" class="filter-control">
                                    <option value="all">All Roles</option>
                                    <option value="affiliate" <?php echo $roleFilter === 'affiliate' ? 'selected' : ''; ?>>Publishers / Affiliates</option>
                                    <option value="advertiser" <?php echo $roleFilter === 'advertiser' ? 'selected' : ''; ?>>Advertisers</option>
                                    <option value="manager" <?php echo $roleFilter === 'manager' ? 'selected' : ''; ?>>Account Managers</option>
                                    <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>System Admins</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-toggle-on mr-1"></i> Account Status</label>
                                <select name="status" class="filter-control">
                                    <option value="all">All Statuses</option>
                                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending Approval</option>
                                    <option value="blocked" <?php echo $status === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                                    <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-calendar-alt mr-1"></i> Joined From</label>
                                <input type="date" name="from" class="filter-control" value="<?php echo htmlspecialchars($dateFrom); ?>">
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-calendar-alt mr-1"></i> Joined To</label>
                                <input type="date" name="to" class="filter-control" value="<?php echo htmlspecialchars($dateTo); ?>">
                            </div>
                            <div>
                                <button type="submit" class="btn btn-primary font-weight-bold px-4" style="height: 42px;">
                                    <i class="fas fa-filter mr-1"></i> Apply
                                </button>
                                <a href="users.php" class="btn btn-outline-secondary font-weight-bold px-3 ml-1" style="height: 42px; line-height: 28px;">
                                    Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Users Directory Table Card -->
                <div class="card-dashboard">
                    <div class="card-body p-0">
                        <form method="post">
                            <div class="bulk-bar m-3">
                                <input type="checkbox" id="checkAll" class="mr-2">
                                <strong class="text-dark">Select All</strong>
                                <select name="bulk_action" class="form-control form-control-sm d-inline-block" style="width: 180px;">
                                    <option value="">-- Bulk Action --</option>
                                    <option value="approve">Approve Selected</option>
                                    <option value="reject">Reject Selected</option>
                                    <option value="block">Block Selected</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-primary font-weight-bold">Apply Bulk Action</button>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-dashboard align-middle mb-0" id="usersTable">
                                    <thead>
                                        <tr>
                                            <th style="width: 40px;">#</th>
                                            <th>User / Account</th>
                                            <th>Role</th>
                                            <th>Contact Info</th>
                                            <th>KYC Status</th>
                                            <th>Status</th>
                                            <th>Registered</th>
                                            <th class="text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($users)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-5 text-muted">
                                                    <i class="fas fa-users-slash fa-3x mb-3 d-block text-slate-300"></i>
                                                    No system users found matching your search criteria.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($users as $u): ?>
                                                <tr>
                                                    <td>
                                                        <input type="checkbox" name="selected_users[]" value="<?php echo $u['user_id']; ?>" class="user-check">
                                                    </td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="avatar-circle mr-3" style="width: 38px; height: 38px; background: #3b82f6; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                                                                <?php echo strtoupper(substr($u['name'], 0, 1)); ?>
                                                            </div>
                                                            <div>
                                                                <strong class="text-dark d-block"><?php echo htmlspecialchars($u['name']); ?></strong>
                                                                <small class="text-muted">ID: #<?php echo $u['user_id']; ?><?php if ($u['company']) echo ' • ' . htmlspecialchars($u['company']); ?></small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                            $r = strtolower($u['role_name']);
                                                            $rClass = 'role-' . $r;
                                                        ?>
                                                        <span class="badge-role <?php echo $rClass; ?>"><?php echo htmlspecialchars($u['role_name']); ?></span>
                                                    </td>
                                                    <td>
                                                        <div><i class="fas fa-envelope mr-1 text-muted"></i><?php echo htmlspecialchars($u['email']); ?></div>
                                                        <?php if ($u['mobile']): ?>
                                                            <small class="text-muted"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($u['mobile']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($u['kyc_status'] === 'approved'): ?>
                                                            <span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>Approved</span>
                                                        <?php elseif ($u['kyc_status'] === 'pending'): ?>
                                                            <span class="badge badge-warning"><i class="fas fa-clock mr-1"></i>Pending</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-secondary"><?php echo htmlspecialchars(ucfirst($u['kyc_status'] ?: 'N/A')); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge-status status-<?php echo strtolower($u['status']); ?>">
                                                            <?php echo strtoupper($u['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <small class="text-muted"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></small>
                                                    </td>
                                                    <td class="text-right">
                                                        <div class="btn-group">
                                                            <?php if ($u['role_name'] === 'affiliate'): ?>
                                                                <a href="affiliate_details.php?id=<?php echo $u['user_id']; ?>" class="btn btn-sm btn-outline-primary" title="View Publisher Details"><i class="fas fa-eye"></i></a>
                                                            <?php elseif ($u['role_name'] === 'advertiser'): ?>
                                                                <a href="advertiser_edit.php?id=<?php echo $u['user_id']; ?>" class="btn btn-sm btn-outline-primary" title="View Advertiser Details"><i class="fas fa-edit"></i></a>
                                                            <?php endif; ?>

                                                            <?php if ($u['status'] !== 'active'): ?>
                                                                <form method="post" class="d-inline ml-1">
                                                                    <input type="hidden" name="target_user_id" value="<?php echo $u['user_id']; ?>">
                                                                    <input type="hidden" name="action" value="active">
                                                                    <button type="submit" class="btn btn-sm btn-success" title="Approve & Activate"><i class="fas fa-check"></i></button>
                                                                </form>
                                                            <?php endif; ?>

                                                            <?php if ($u['status'] !== 'blocked'): ?>
                                                                <form method="post" class="d-inline ml-1" onsubmit="return confirm('Are you sure you want to block this user account?');">
                                                                    <input type="hidden" name="target_user_id" value="<?php echo $u['user_id']; ?>">
                                                                    <input type="hidden" name="action" value="blocked">
                                                                    <button type="submit" class="btn btn-sm btn-danger" title="Block User"><i class="fas fa-ban"></i></button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
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
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<script>
$(document).ready(function() {
    $('#usersTable').DataTable({
        "paging": true,
        "lengthChange": true,
        "searching": false,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "pageLength": 15
    });

    $('#checkAll').on('click', function() {
        $('.user-check').prop('checked', this.checked);
    });
});
</script>
</body>
</html>