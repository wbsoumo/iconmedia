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
   IMPERSONATE LOGIN AS PUBLISHER
================================ */
if (isset($_GET['impersonate'])) {
    $targetId = (int)$_GET['impersonate'];
    
    // Generate secure single-use token
    $token = bin2hex(random_bytes(16));
    if (!isset($_SESSION['impersonate_tokens'])) {
        $_SESSION['impersonate_tokens'] = [];
    }
    $_SESSION['impersonate_tokens'][$token] = [
        'user_id'    => $targetId,
        'created_at' => time()
    ];

    header("Location: impersonate.php?token=" . $token);
    exit;
}

/* ===============================
   INPUTS & FILTERS
================================ */
$search  = trim($_GET['search'] ?? '');
$status  = $_GET['status'] ?? 'all';
$manager = $_GET['manager'] ?? 'all';

$where  = ['u.role_id = 3'];
$params = [];

if ($search !== '') {
    $where[] = '(u.name LIKE :search OR u.email LIKE :search OR u.mobile LIKE :search OR u.company LIKE :search)';
    $params['search'] = "%{$search}%";
}

if ($status !== 'all') {
    $where[] = 'u.status = :status';
    $params['status'] = $status;
}

if ($manager === 'unassigned') {
    $where[] = 'u.account_manager_id IS NULL';
} elseif ($manager !== 'all') {
    $where[] = 'u.account_manager_id = :manager';
    $params['manager'] = (int)$manager;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

/* ===============================
   FETCH ALL PUBLISHERS
================================ */
$sql = "
SELECT
    u.user_id,
    u.name,
    u.email,
    u.mobile,
    u.telegram_id,
    u.status,
    u.kyc_status,
    u.payout_enabled,
    u.company,
    u.balance,
    u.last_login_at,
    u.created_at,
    u.account_manager_id,
    am.name  AS manager_name,
    am.email AS manager_email,

    COUNT(DISTINCT c.click_id) AS total_clicks,
    COUNT(DISTINCT cv.conversion_id) AS total_conversions,
    COALESCE(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout END), 0) AS total_earnings,
    COALESCE(SUM(CASE WHEN cv.status = 'pending'  THEN cv.payout END), 0) AS pending_earnings

FROM users u
LEFT JOIN account_managers am ON am.id = u.account_manager_id
LEFT JOIN clicks c          ON c.affiliate_id = u.user_id
LEFT JOIN conversions cv    ON cv.affiliate_id = u.user_id
$whereSql
GROUP BY u.user_id
ORDER BY u.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$publishers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH ACCOUNT MANAGERS
================================ */
$managers = $pdo->query("
    SELECT id, name 
    FROM account_managers
    WHERE status = 'active'
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   SUMMARY STATS
================================ */
$summary = $pdo->query("
    SELECT
        COUNT(*)                                  AS total_publishers,
        SUM(status = 'active')                   AS active_publishers,
        SUM(status = 'pending')                  AS pending_publishers,
        SUM(status = 'blocked')                  AS blocked_publishers,
        SUM(kyc_status = 'approved')             AS kyc_verified,
        SUM(payout_enabled = 1)                  AS payout_enabled,
        COALESCE(SUM(balance), 0)                AS total_balance
    FROM users
    WHERE role_id = 3
")->fetch(PDO::FETCH_ASSOC);

/* ===============================
   BULK ACTIONS
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $ids = array_map('intval', $_POST['selected_publishers'] ?? []);
    if (!$ids) {
        $error = 'No publishers selected';
    } else {
        $in = implode(',', array_fill(0, count($ids), '?'));

        $actions = [
            'activate'        => "UPDATE users SET status='active', updated_at=NOW() WHERE role_id=3 AND user_id IN ($in)",
            'deactivate'      => "UPDATE users SET status='pending', updated_at=NOW() WHERE role_id=3 AND user_id IN ($in)",
            'block'           => "UPDATE users SET status='blocked', updated_at=NOW() WHERE role_id=3 AND user_id IN ($in)",
            'enable_payout'   => "UPDATE users SET payout_enabled=1, updated_at=NOW() WHERE role_id=3 AND user_id IN ($in)",
            'disable_payout'  => "UPDATE users SET payout_enabled=0, updated_at=NOW() WHERE role_id=3 AND user_id IN ($in)",
            'approve_kyc'     => "UPDATE users SET kyc_status='approved', updated_at=NOW() WHERE role_id=3 AND user_id IN ($in)",
            'reject_kyc'      => "UPDATE users SET kyc_status='rejected', updated_at=NOW() WHERE role_id=3 AND user_id IN ($in)",
        ];

        if (!isset($actions[$_POST['bulk_action']])) {
            $error = 'Invalid bulk action';
        } else {
            $pdo->prepare($actions[$_POST['bulk_action']])->execute($ids);
            $success = count($ids) . ' publishers updated successfully';
            header("Location: publishers.php");
            exit;
        }
    }
}

/* ===============================
   ASSIGN / REMOVE MANAGER
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_manager'])) {
    $publisherId = (int)$_POST['publisher_id'];
    $managerId   = $_POST['manager_id'] !== '' ? (int)$_POST['manager_id'] : null;

    if ($managerId !== null) {
        $chk = $pdo->prepare("SELECT 1 FROM account_managers WHERE id = ?");
        $chk->execute([$managerId]);
        if (!$chk->fetchColumn()) {
            $error = 'Invalid account manager selected';
        }
    }

    if (!$error) {
        $stmt = $pdo->prepare("
            UPDATE users
            SET account_manager_id = :mid, updated_at = NOW()
            WHERE user_id = :uid AND role_id = 3
        ");
        $stmt->execute(['mid' => $managerId, 'uid' => $publisherId]);
        $success = 'Account manager assigned successfully';
    }
}

/* ===============================
   TOGGLE STATUS
================================ */
if (isset($_GET['toggle_status'])) {
    $pdo->prepare("
        UPDATE users
        SET status = IF(status='active','pending','active'), updated_at=NOW()
        WHERE role_id=3 AND user_id=?
    ")->execute([(int)$_GET['toggle_status']]);
    header("Location: publishers.php");
    exit;
}

/* ===============================
   TOGGLE PAYOUT
================================ */
if (isset($_GET['toggle_payout'])) {
    $pdo->prepare("
        UPDATE users
        SET payout_enabled = NOT payout_enabled, updated_at=NOW()
        WHERE role_id=3 AND user_id=?
    ")->execute([(int)$_GET['toggle_payout']]);
    header("Location: publishers.php");
    exit;
}

/* ===============================
   UPDATE KYC
================================ */
if (isset($_GET['kyc_action'], $_GET['publisher_id'])) {
    $map = ['verify'=>'approved','reject'=>'rejected','pending'=>'pending'];
    if (isset($map[$_GET['kyc_action']])) {
        $pdo->prepare("
            UPDATE users
            SET kyc_status=?, updated_at=NOW()
            WHERE role_id=3 AND user_id=?
        ")->execute([$map[$_GET['kyc_action']], (int)$_GET['publisher_id']]);
        header("Location: publishers.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Publisher Management | Admin Console</title>
    
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
            background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(79, 70, 229, 0.25);
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
            min-width: 180px;
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

        .badge-status {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-active { background: #d1fae5; color: #047857; }
        .status-pending { background: #fef3c7; color: #d97706; }
        .status-blocked { background: #fee2e2; color: #b91c1c; }

        .btn-action-sm {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s ease;
            text-decoration: none !important;
        }

        .btn-login-tab {
            background: rgba(79, 70, 229, 0.1);
            color: #4f46e5;
            border: 1px solid rgba(79, 70, 229, 0.25);
        }
        .btn-login-tab:hover {
            background: #4f46e5;
            color: #ffffff;
        }

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
            <li class="nav-item d-none d-sm-inline-block"><a href="publishers.php" class="nav-link active">Manage Publishers</a></li>
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
                        <h1 class="m-0 font-weight-bold">Publisher Management Console</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Publishers</li>
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
                        <h3 class="font-weight-bold mb-1"><i class="fas fa-user-friends mr-2"></i>Publishers & Affiliates Hub</h3>
                        <p class="mb-0 text-white-50">Manage affiliate traffic sources, assign dedicated account managers, verify KYC, and login directly as publisher.</p>
                    </div>
                    <div class="mt-3 mt-md-0">
                        <a href="create_publisher.php" class="btn btn-light font-weight-bold px-4 shadow-sm">
                            <i class="fas fa-user-plus text-primary mr-1"></i> Add New Publisher
                        </a>
                    </div>
                </div>

                <!-- Summary Metrics -->
                <div class="summary-stats">
                    <div class="metric-card">
                        <div class="metric-value text-primary"><?php echo number_format($summary['total_publishers']); ?></div>
                        <div class="metric-label">Total Publishers</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value text-success"><?php echo number_format($summary['active_publishers']); ?></div>
                        <div class="metric-label">Active</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value text-warning"><?php echo number_format($summary['pending_publishers']); ?></div>
                        <div class="metric-label">Pending Approval</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value text-info"><?php echo number_format($summary['kyc_verified']); ?></div>
                        <div class="metric-label">KYC Approved</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value text-indigo" style="color: #4f46e5;"><?php echo number_format($summary['payout_enabled']); ?></div>
                        <div class="metric-label">Payout Enabled</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value text-success">$<?php echo number_format($summary['total_balance'], 2); ?></div>
                        <div class="metric-label">Total Balance</div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="card-dashboard">
                    <div class="card-header">
                        <h4 class="card-title font-weight-bold text-dark m-0"><i class="fas fa-filter mr-2 text-primary"></i>Filter Publishers</h4>
                    </div>
                    <div class="card-body">
                        <form method="get" class="filter-row">
                            <div class="filter-group">
                                <label><i class="fas fa-search mr-1"></i> Search Keyword</label>
                                <input type="text" name="search" class="filter-control" placeholder="Search name, email, mobile, company..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-toggle-on mr-1"></i> Account Status</label>
                                <select name="status" class="filter-control">
                                    <option value="all">All Statuses</option>
                                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending Approval</option>
                                    <option value="blocked" <?php echo $status === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-user-tie mr-1"></i> Account Manager</label>
                                <select name="manager" class="filter-control">
                                    <option value="all">All Account Managers</option>
                                    <option value="unassigned" <?php echo $manager === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                                    <?php foreach ($managers as $m): ?>
                                        <option value="<?php echo $m['id']; ?>" <?php echo $manager == $m['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($m['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <button type="submit" class="btn btn-primary font-weight-bold px-4" style="height: 42px;">
                                    <i class="fas fa-filter mr-1"></i> Apply Filters
                                </button>
                                <a href="publishers.php" class="btn btn-outline-secondary font-weight-bold px-3 ml-1" style="height: 42px; line-height: 28px;">
                                    Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Publishers Table Card -->
                <div class="card-dashboard">
                    <div class="card-body p-0">
                        <form method="post">
                            <div class="bulk-bar m-3">
                                <input type="checkbox" id="checkAll" class="mr-2">
                                <strong class="text-dark">Select All</strong>
                                <select name="bulk_action" class="form-control form-control-sm d-inline-block" style="width: 200px;">
                                    <option value="">-- Bulk Action --</option>
                                    <option value="activate">Activate Selected</option>
                                    <option value="deactivate">Deactivate Selected</option>
                                    <option value="block">Block Selected</option>
                                    <option value="approve_kyc">Approve KYC</option>
                                    <option value="enable_payout">Enable Payout</option>
                                    <option value="disable_payout">Disable Payout</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-primary font-weight-bold">Apply Bulk Action</button>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-dashboard align-middle mb-0" id="publishersTable">
                                    <thead>
                                        <tr>
                                            <th style="width: 35px;">#</th>
                                            <th>Publisher / Company</th>
                                            <th>Contact Info</th>
                                            <th>Manager</th>
                                            <th>Status</th>
                                            <th>KYC</th>
                                            <th>Payout</th>
                                            <th>Stats</th>
                                            <th class="text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($publishers)): ?>
                                            <tr>
                                                <td colspan="9" class="text-center py-5 text-muted">
                                                    <i class="fas fa-user-friends fa-3x mb-3 d-block text-slate-300"></i>
                                                    No publishers found matching your search criteria.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($publishers as $pub): ?>
                                                <tr>
                                                    <td>
                                                        <input type="checkbox" name="selected_publishers[]" value="<?php echo $pub['user_id']; ?>" class="publisher-checkbox">
                                                    </td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="mr-3" style="width: 38px; height: 38px; background: #4f46e5; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                                                                <?php echo strtoupper(substr($pub['name'], 0, 1)); ?>
                                                            </div>
                                                            <div>
                                                                <strong class="text-dark d-block"><?php echo htmlspecialchars($pub['name']); ?></strong>
                                                                <small class="text-muted">
                                                                    ID: #<?php echo $pub['user_id']; ?>
                                                                    <?php if ($pub['company']): ?> • <?php echo htmlspecialchars($pub['company']); ?><?php endif; ?>
                                                                </small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div><i class="fas fa-envelope mr-1 text-muted"></i><?php echo htmlspecialchars($pub['email']); ?></div>
                                                        <?php if ($pub['mobile']): ?>
                                                            <small class="text-muted"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($pub['mobile']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($pub['manager_name']): ?>
                                                            <span class="badge badge-light border"><i class="fas fa-user-tie text-primary mr-1"></i><?php echo htmlspecialchars($pub['manager_name']); ?></span>
                                                        <?php else: ?>
                                                            <span class="badge badge-secondary">Unassigned</span>
                                                        <?php endif; ?>
                                                        <a href="#" class="ml-1 text-muted" data-toggle="modal" data-target="#assignManagerModal" data-publisher-id="<?php echo $pub['user_id']; ?>" data-publisher-name="<?php echo htmlspecialchars($pub['name']); ?>" data-current-manager="<?php echo $pub['account_manager_id'] ?? ''; ?>">
                                                            <i class="fas fa-pen small"></i>
                                                        </a>
                                                    </td>
                                                    <td>
                                                        <span class="badge-status status-<?php echo strtolower($pub['status']); ?>">
                                                            <?php echo strtoupper($pub['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($pub['kyc_status'] === 'approved'): ?>
                                                            <span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>Verified</span>
                                                        <?php elseif ($pub['kyc_status'] === 'pending'): ?>
                                                            <span class="badge badge-warning"><i class="fas fa-clock mr-1"></i>Pending</span>
                                                            <a href="?publisher_id=<?php echo $pub['user_id']; ?>&kyc_action=verify" class="text-success ml-1"><i class="fas fa-check"></i></a>
                                                        <?php else: ?>
                                                            <span class="badge badge-secondary"><?php echo htmlspecialchars(ucfirst($pub['kyc_status'] ?: 'N/A')); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($pub['payout_enabled']): ?>
                                                            <a href="?toggle_payout=<?php echo $pub['user_id']; ?>" class="badge badge-success text-white" title="Click to Disable"><i class="fas fa-check-circle mr-1"></i>Enabled</a>
                                                        <?php else: ?>
                                                            <a href="?toggle_payout=<?php echo $pub['user_id']; ?>" class="badge badge-danger text-white" title="Click to Enable"><i class="fas fa-times-circle mr-1"></i>Disabled</a>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <small class="d-block text-primary"><i class="fas fa-mouse-pointer mr-1"></i><?php echo number_format($pub['total_clicks']); ?> Clicks</small>
                                                        <small class="d-block text-success"><i class="fas fa-exchange-alt mr-1"></i><?php echo number_format($pub['total_conversions']); ?> Conv</small>
                                                    </td>
                                                    <td class="text-right">
                                                        <div class="btn-group">
                                                            <a href="publishers.php?impersonate=<?php echo $pub['user_id']; ?>" target="_blank" class="btn-action-sm btn-login-tab mr-1" title="Login directly as Publisher in new tab">
                                                                <i class="fas fa-external-link-alt mr-1"></i> Login
                                                            </a>
                                                            <a href="affiliate_details.php?id=<?php echo $pub['user_id']; ?>" class="btn btn-sm btn-outline-primary" title="Publisher Profile"><i class="fas fa-eye"></i></a>
                                                            <a href="?toggle_status=<?php echo $pub['user_id']; ?>" class="btn btn-sm btn-outline-secondary ml-1" title="Toggle Status"><i class="fas fa-power-off"></i></a>
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

    <!-- Assign Manager Modal -->
    <div class="modal fade" id="assignManagerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header">
                        <h5 class="modal-title font-weight-bold"><i class="fas fa-user-tie mr-2 text-primary"></i>Assign Account Manager</h5>
                        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="assign_manager" value="1">
                        <input type="hidden" name="publisher_id" id="modalPublisherId">
                        <p class="text-muted">Assigning manager for <strong id="modalPublisherName"></strong>:</p>
                        <div class="form-group">
                            <label class="font-weight-bold">Select Account Manager</label>
                            <select name="manager_id" id="modalManagerId" class="form-control">
                                <option value="">-- Unassigned (No Manager) --</option>
                                <?php foreach ($managers as $m): ?>
                                    <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary font-weight-bold">Save Manager</button>
                    </div>
                </form>
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
    $('#publishersTable').DataTable({
        "paging": true,
        "lengthChange": true,
        "searching": false,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "pageLength": 15
    });

    $('#checkAll').on('click', function() {
        $('.publisher-checkbox').prop('checked', this.checked);
    });

    $('#assignManagerModal').on('show.bs.modal', function (event) {
        var button = $(event.relatedTarget);
        var pubId = button.data('publisher-id');
        var pubName = button.data('publisher-name');
        var currentMgr = button.data('current-manager');
        
        var modal = $(this);
        modal.find('#modalPublisherId').val(pubId);
        modal.find('#modalPublisherName').text(pubName);
        modal.find('#modalManagerId').val(currentMgr);
    });
});
</script>
</body>
</html>