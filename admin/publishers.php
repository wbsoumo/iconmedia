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
   INPUTS
================================ */
$search  = trim($_GET['search'] ?? '');
$status  = $_GET['status'] ?? 'all';
$manager = $_GET['manager'] ?? 'all';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

/* ===============================
   BUILD FILTERS
================================ */
$where  = ['u.role_id = 3'];
$params = [];

if ($search !== '') {
    $where[] = '(u.name LIKE :search OR u.email LIKE :search OR u.mobile LIKE :search)';
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
   TOTAL COUNT
================================ */
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u $whereSql");
$countStmt->execute($params);
$totalPublishers = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalPublishers / $perPage));

/* ===============================
   FETCH PUBLISHERS
================================ */
$sql = "
SELECT
    u.user_id,
    u.name,
    u.email,
    u.mobile,
    u.telegram_id,
    u.teams_id,
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
LIMIT :offset, :limit
";

$stmt = $pdo->prepare($sql);

foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);

$stmt->execute();
$publishers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH ACCOUNT MANAGERS (CORRECT)
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
        SUM(kyc_status = 'verified')             AS kyc_verified,
        SUM(kyc_status = 'pending')              AS kyc_pending,
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
            'approve_kyc'     => "UPDATE users SET kyc_status='verified', updated_at=NOW() WHERE role_id=3 AND user_id IN ($in)",
            'reject_kyc'      => "UPDATE users SET kyc_status='rejected', updated_at=NOW() WHERE role_id=3 AND user_id IN ($in)",
        ];

        if (!isset($actions[$_POST['bulk_action']])) {
            $error = 'Invalid bulk action';
        } else {
            $pdo->prepare($actions[$_POST['bulk_action']])->execute($ids);
            $success = count($ids) . ' publishers updated successfully';
        }
    }
}

/* ===============================
   ASSIGN / REMOVE MANAGER (SAFE)
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_manager'])) {

    $publisherId = (int)$_POST['publisher_id'];
    $managerId   = $_POST['manager_id'] !== '' ? (int)$_POST['manager_id'] : null;

    // validate manager exists
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
        $stmt->execute([
            'mid' => $managerId,
            'uid' => $publisherId
        ]);
        $success = 'Account manager updated';
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
    $success = 'Publisher status updated';
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
    $success = 'Payout setting updated';
}

/* ===============================
   UPDATE KYC
================================ */
if (isset($_GET['kyc_action'], $_GET['publisher_id'])) {

    $map = ['verify'=>'verified','reject'=>'rejected','pending'=>'pending'];
    if (isset($map[$_GET['kyc_action']])) {
        $pdo->prepare("
            UPDATE users
            SET kyc_status=?, updated_at=NOW()
            WHERE role_id=3 AND user_id=?
        ")->execute([$map[$_GET['kyc_action']], (int)$_GET['publisher_id']]);
        $success = 'KYC status updated';
    }
}

/* ===============================
   EXPORT CSV
================================ */
if (isset($_GET['export'])) {

    $rows = $pdo->query("
        SELECT
            u.user_id, u.name, u.email, u.mobile, u.status,
            u.kyc_status, u.payout_enabled, u.company, u.balance,
            u.created_at, am.name AS manager,
            COUNT(DISTINCT cv.conversion_id) AS conversions,
            COALESCE(SUM(CASE WHEN cv.status='approved' THEN cv.payout END),0) AS earnings
        FROM users u
        LEFT JOIN account_managers am ON am.id=u.account_manager_id
        LEFT JOIN conversions cv ON cv.affiliate_id=u.user_id
        WHERE u.role_id=3
        GROUP BY u.user_id
        ORDER BY u.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename=publishers-' . date('Y-m-d') . '.csv');

    $out = fopen('php://output','w');
    fputcsv($out, array_keys($rows[0] ?? []));
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Publishers | Admin Panel | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
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
        
        .card-dashboard {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
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
        
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e3e6f0;
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
            margin-bottom: 8px;
            color: #6c757d;
            font-size: 14px;
            font-weight: 600;
        }
        
        .filter-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e3e6f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #f8f9fc;
        }
        
        .filter-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: white;
        }
        
        .btn-gradient {
            background: var(--primary-gradient);
            border: none;
            color: white;
            font-weight: 600;
            padding: 10px 25px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }
        
        .table-dashboard {
            border: none;
            width: 100%;
        }
        
        .table-dashboard thead th {
            border: none;
            background: #f8f9fc;
            color: #4e73df;
            font-weight: 600;
            padding: 15px;
            border-bottom: 2px solid #e3e6f0;
            text-align: left;
        }
        
        .table-dashboard tbody td {
            padding: 15px;
            border-bottom: 1px solid #f8f9fa;
            vertical-align: middle;
        }
        
        .table-dashboard tbody tr:hover {
            background: #f8f9fc;
        }
        
        .metric-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #e3e6f0;
            text-align: center;
            transition: all 0.3s ease;
            flex: 1;
            min-width: 150px;
        }
        
        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .metric-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .metric-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .summary-stats {
            display: flex;
            gap: 15px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        
        .total-value {
            color: #4e73df;
            font-weight: 700;
        }
        
        .active-value {
            color: #28a745;
            font-weight: 700;
        }
        
        .pending-value {
            color: #ffc107;
            font-weight: 700;
        }
        
        .blocked-value {
            color: #dc3545;
            font-weight: 700;
        }
        
        .kyc-verified-value {
            color: #20c997;
            font-weight: 700;
        }
        
        .balance-value {
            color: #6610f2;
            font-weight: 700;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-active {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .status-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .status-blocked {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .kyc-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .kyc-verified {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .kyc-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .kyc-rejected {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .payout-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .payout-enabled {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .payout-disabled {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.2);
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn-action {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        
        .btn-edit {
            background: rgba(32, 201, 151, 0.1);
            color: #20c997;
            border: 1px solid rgba(32, 201, 151, 0.2);
        }
        
        .btn-edit:hover {
            background: rgba(32, 201, 151, 0.2);
            color: #20c997;
        }
        
        .btn-toggle {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.2);
        }
        
        .btn-toggle:hover {
            background: rgba(108, 117, 125, 0.2);
            color: #6c757d;
        }
        
        .btn-view {
            background: rgba(78, 115, 223, 0.1);
            color: #4e73df;
            border: 1px solid rgba(78, 115, 223, 0.2);
        }
        
        .btn-view:hover {
            background: rgba(78, 115, 223, 0.2);
            color: #4e73df;
        }
        
        .bulk-actions {
            background: #f8f9fc;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .select-all-checkbox {
            margin-right: 10px;
        }
        
        .publisher-card {
            background: white;
            border: 1px solid #e3e6f0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .publisher-card:hover {
            border-color: #667eea;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.1);
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
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .action-buttons-group {
            display: flex;
            gap: 10px;
        }
        
        .pagination-container {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 5px;
        }
        
        .page-link {
            padding: 8px 15px;
            border: 1px solid #e3e6f0;
            border-radius: 6px;
            color: #4e73df;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .page-link:hover {
            background: #f8f9fc;
            border-color: #4e73df;
        }
        
        .page-link.active {
            background: #4e73df;
            color: white;
            border-color: #4e73df;
        }
        
        .manager-badge {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .unassigned-badge {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .stats-badge {
            background: #f8f9fa;
            color: #6c757d;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .dataTables_wrapper {
            padding: 0;
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
                <a href="publishers.php" class="nav-link active">Manage Publishers</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <?php if ($summary['pending_publishers'] > 0 || $summary['kyc_pending'] > 0): ?>
                    <span class="badge badge-warning navbar-badge">
                        <?php echo ($summary['pending_publishers'] ?? 0) + ($summary['kyc_pending'] ?? 0); ?>
                    </span>
                    <?php endif; ?>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header">
                        <?php echo ($summary['pending_publishers'] ?? 0) + ($summary['kyc_pending'] ?? 0); ?> Pending Items
                    </span>
                    <div class="dropdown-divider"></div>
                    <?php if ($summary['pending_publishers'] > 0): ?>
                    <a href="publishers.php?status=pending" class="dropdown-item">
                        <i class="fas fa-users mr-2 text-warning"></i>
                        <?php echo $summary['pending_publishers']; ?> Pending Publishers
                    </a>
                    <?php endif; ?>
                    <?php if ($summary['kyc_pending'] > 0): ?>
                    <a href="publishers.php?status=all&kyc=pending" class="dropdown-item">
                        <i class="fas fa-id-card mr-2 text-info"></i>
                        <?php echo $summary['kyc_pending']; ?> Pending KYC
                    </a>
                    <?php endif; ?>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-widget="fullscreen" href="#" role="button">
                    <i class="fas fa-expand-arrows-alt"></i>
                </a>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                    <i class="fas fa-user-circle mr-1"></i> <?php echo htmlspecialchars($adminName); ?>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user mr-2"></i> Admin Profile
                    </a>
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-cog mr-2"></i> System Settings
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
        <!-- Content Header -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0">Manage Publishers</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="publishers.php">Publishers</a></li>
                            <li class="breadcrumb-item active">Manage Publishers</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">
                <!-- Dashboard Header -->
                <div class="dashboard-header">
                    <h2 class="mb-0">Publisher Management</h2>
                    <div class="action-buttons-group">
                        <a href="?export=csv" class="btn btn-outline-primary">
                            <i class="fas fa-download mr-2"></i> Export CSV
                        </a>
                        <a href="create_advertiser.php" class="btn btn-gradient">
                            <i class="fas fa-plus mr-2"></i> Add New Publisher
                        </a>
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

                <!-- Summary Stats -->
                <div class="summary-stats">
                    <div class="metric-card">
                        <div class="metric-value total-value"><?php echo number_format($summary['total_publishers'] ?? 0); ?></div>
                        <div class="metric-label">Total Publishers</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value active-value"><?php echo number_format($summary['active_publishers'] ?? 0); ?></div>
                        <div class="metric-label">Active</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value pending-value"><?php echo number_format($summary['pending_publishers'] ?? 0); ?></div>
                        <div class="metric-label">Pending</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value blocked-value"><?php echo number_format($summary['blocked_publishers'] ?? 0); ?></div>
                        <div class="metric-label">Blocked</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value kyc-verified-value"><?php echo number_format($summary['kyc_verified'] ?? 0); ?></div>
                        <div class="metric-label">KYC Verified</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value balance-value">$<?php echo number_format($summary['total_balance'] ?? 0, 2); ?></div>
                        <div class="metric-label">Total Balance</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card-dashboard">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-filter mr-2"></i> Filter Publishers
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="get" class="filter-row">
                            <div class="filter-group">
                                <label for="search"><i class="fas fa-search mr-1"></i> Search</label>
                                <input type="text" name="search" id="search" class="filter-control" 
                                       placeholder="Search by name, email, mobile..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label for="status"><i class="fas fa-toggle-on mr-1"></i> Status</label>
                                <select name="status" id="status" class="filter-control">
                                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="blocked" <?php echo $status === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="manager"><i class="fas fa-user-tie mr-1"></i> Account Manager</label>
                                <select name="manager" id="manager" class="filter-control">
                                    <option value="all" <?php echo $manager === 'all' ? 'selected' : ''; ?>>All Managers</option>
                                    <option value="unassigned" <?php echo $manager === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                                    <?php foreach ($managers as $mgr): ?>
                                    <option value="<?php echo $mgr['id']; ?>" <?php echo $manager == $mgr['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($mgr['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <button type="submit" class="btn-gradient" style="height: 45px; width: 100%;">
                                    <i class="fas fa-search mr-2"></i> Apply Filters
                                </button>
                            </div>
                            
                            <div class="filter-group">
                                <a href="publishers.php" class="btn btn-outline-primary" style="height: 45px; width: 100%; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-redo mr-2"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Bulk Actions Form -->
                <form method="post" id="bulkForm" onsubmit="return confirmBulkAction()">
                    <!-- Bulk Actions -->
                    <div class="bulk-actions">
                        <div class="form-check select-all-checkbox">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                            <label class="form-check-label" for="selectAll">Select All</label>
                        </div>
                        
                        <select name="bulk_action" class="filter-control" style="width: auto;">
                            <option value="">Bulk Actions</option>
                            <option value="activate">Activate Selected</option>
                            <option value="deactivate">Deactivate Selected</option>
                            <option value="block">Block Selected</option>
                            <option value="enable_payout">Enable Payout</option>
                            <option value="disable_payout">Disable Payout</option>
                            <option value="approve_kyc">Approve KYC</option>
                            <option value="reject_kyc">Reject KYC</option>
                        </select>
                        
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-play mr-1"></i> Apply
                        </button>
                        
                        <span class="text-muted ml-2">
                            <?php echo $totalPublishers; ?> publisher<?php echo $totalPublishers != 1 ? 's' : ''; ?> found
                        </span>
                    </div>

                    <!-- Publishers Table -->
                    <div class="card-dashboard">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-users mr-2"></i> Publishers List
                            </h3>
                            <div class="card-tools">
                                <span class="badge badge-light">
                                    Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($publishers)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <h5>No Publishers Found</h5>
                                    <p class="text-muted">No publishers match your search criteria.</p>
                                    <a href="publishers.php" class="btn btn-gradient btn-sm">
                                        <i class="fas fa-redo mr-2"></i> Reset Filters
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-dashboard" id="publishersTable">
                                        <thead>
                                            <tr>
                                                <th style="width: 40px;">
                                                    <input type="checkbox" class="form-check-input" id="checkAll">
                                                </th>
                                                <th>Publisher</th>
                                                <th>Contact</th>
                                                <th>Status</th>
                                                <th>KYC</th>
                                                <th>Payout</th>
                                                <th>Manager</th>
                                                <th>Stats</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($publishers as $pub): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" 
                                                           name="selected_publishers[]" 
                                                           value="<?php echo $pub['user_id']; ?>" 
                                                           class="form-check-input publisher-checkbox">
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="mr-3">
                                                            <div style="width: 40px; height: 40px; background: #667eea; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600;">
                                                                <?php echo strtoupper(substr($pub['name'], 0, 1)); ?>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <strong><?php echo htmlspecialchars($pub['name']); ?></strong>
                                                            <div class="text-muted small">
                                                                ID: #<?php echo $pub['user_id']; ?>
                                                                <?php if ($pub['company']): ?>
                                                                    &nbsp;•&nbsp; <?php echo htmlspecialchars($pub['company']); ?>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="text-muted small">
                                                                Joined: <?php echo date('M d, Y', strtotime($pub['created_at'])); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mb-1">
                                                        <i class="fas fa-envelope mr-1 text-muted"></i>
                                                        <?php echo htmlspecialchars($pub['email']); ?>
                                                    </div>
                                                    <?php if ($pub['mobile']): ?>
                                                    <div class="mb-1">
                                                        <i class="fas fa-phone mr-1 text-muted"></i>
                                                        <?php echo htmlspecialchars($pub['mobile']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if ($pub['telegram_id']): ?>
                                                    <div>
                                                        <i class="fab fa-telegram mr-1 text-muted"></i>
                                                        <?php echo htmlspecialchars($pub['telegram_id']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $pub['status']; ?>">
                                                        <?php echo ucfirst($pub['status']); ?>
                                                    </span>
                                                    <div class="small text-muted mt-1">
                                                        Balance: $<?php echo number_format($pub['balance'], 2); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="kyc-badge kyc-<?php echo $pub['kyc_status']; ?>">
                                                        <?php echo ucfirst($pub['kyc_status']); ?>
                                                    </span>
                                                    <div class="small mt-1">
                                                        <?php if ($pub['kyc_status'] == 'pending'): ?>
                                                            <a href="?publisher_id=<?php echo $pub['user_id']; ?>&kyc_action=verify" class="text-success mr-2">
                                                                <i class="fas fa-check"></i>
                                                            </a>
                                                            <a href="?publisher_id=<?php echo $pub['user_id']; ?>&kyc_action=reject" class="text-danger">
                                                                <i class="fas fa-times"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($pub['payout_enabled']): ?>
                                                    <span class="payout-badge payout-enabled">
                                                        <i class="fas fa-check-circle mr-1"></i> Enabled
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="payout-badge payout-disabled">
                                                        <i class="fas fa-times-circle mr-1"></i> Disabled
                                                    </span>
                                                    <?php endif; ?>
                                                    <div class="small mt-1">
                                                        <a href="?toggle_payout=<?php echo $pub['user_id']; ?>" class="text-primary">
                                                            <?php echo $pub['payout_enabled'] ? 'Disable' : 'Enable'; ?>
                                                        </a>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($pub['manager_name']): ?>
                                                    <div class="manager-badge">
                                                        <i class="fas fa-user-tie mr-1"></i>
                                                        <?php echo htmlspecialchars($pub['manager_name']); ?>
                                                    </div>
                                                    <div class="small text-muted mt-1">
                                                        <?php echo htmlspecialchars($pub['manager_email'] ?? ''); ?>
                                                    </div>
                                                    <?php else: ?>
                                                    <div class="unassigned-badge">
                                                        <i class="fas fa-user-slash mr-1"></i>
                                                        Unassigned
                                                    </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <div class="mb-1">
                                                            <span class="text-primary">
                                                                <i class="fas fa-mouse-pointer mr-1"></i>
                                                                <?php echo number_format($pub['total_clicks']); ?> clicks
                                                            </span>
                                                        </div>
                                                        <div class="mb-1">
                                                            <span class="text-success">
                                                                <i class="fas fa-exchange-alt mr-1"></i>
                                                                <?php echo number_format($pub['total_conversions']); ?> conv
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <span class="text-warning">
                                                                <i class="fas fa-wallet mr-1"></i>
                                                                $<?php echo number_format($pub['total_earnings'], 2); ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <!-- Toggle Status -->
                                                        <a href="?toggle_status=<?php echo $pub['user_id']; ?>" 
                                                           class="btn-action btn-toggle"
                                                           title="<?php echo $pub['status'] == 'active' ? 'Deactivate' : 'Activate'; ?>">
                                                            <i class="fas fa-power-off"></i>
                                                        </a>
                                                        
                                                        <!-- Assign Manager -->
                                                        <a href="#" 
                                                           class="btn-action btn-edit"
                                                           title="Assign Manager"
                                                           data-toggle="modal" 
                                                           data-target="#assignManagerModal"
                                                           data-publisher-id="<?php echo $pub['user_id']; ?>"
                                                           data-publisher-name="<?php echo htmlspecialchars($pub['name']); ?>"
                                                           data-current-manager="<?php echo $pub['account_manager_id'] ?? ''; ?>">
                                                            <i class="fas fa-user-tie"></i>
                                                        </a>
                                                        
                                                        <!-- Edit -->
                                                        <a href="affiliate_details.php?id=<?php echo $pub['user_id']; ?>" 
                                                           class="btn-action btn-edit"
                                                           title="Edit Publisher">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        
                                                        <!-- View Details -->
                                                        <a href="affiliate_details.php?id=<?php echo $pub['user_id']; ?>" 
                                                           class="btn-action btn-view"
                                                           title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Pagination -->
                                <?php if ($totalPages > 1): ?>
                                <div class="pagination-container">
                                    <?php if ($page > 1): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="page-link">
                                            <i class="fas fa-angle-double-left"></i>
                                        </a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
                                            <i class="fas fa-angle-left"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                           class="page-link <?php echo $i == $page ? 'active' : '' ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>

                                    <?php if ($page < $totalPages): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
                                            <i class="fas fa-angle-right"></i>
                                        </a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>" class="page-link">
                                            <i class="fas fa-angle-double-right"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline">
            <strong>Admin Panel v3.0</strong>
        </div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- Assign Manager Modal -->
<div class="modal fade" id="assignManagerModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Assign Account Manager</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <input type="hidden" name="assign_manager" value="1">
                    <input type="hidden" id="modalPublisherId" name="publisher_id">
                    
                    <div class="form-group">
                        <label>Publisher</label>
                        <input type="text" class="form-control" id="modalPublisherName" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Select Account Manager</label>
                        <select name="manager_id" id="modalManagerId" class="form-control">
                            <option value="">-- No Manager (Unassigned) --</option>
                            <?php foreach ($managers as $mgr): ?>
                            <option value="<?php echo $mgr['id']; ?>">
                                <?php echo htmlspecialchars($mgr['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">
                            Select an account manager to assign to this publisher.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gradient">Assign Manager</button>
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
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#publishersTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [], // Disable initial sort
        responsive: true,
        searching: false, // We use custom search
        info: false,
        paging: false, // We use custom pagination
        language: {
            emptyTable: "No publishers found"
        }
    });
    
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
    
    // Select all functionality
    $('#selectAll, #checkAll').click(function() {
        $('.publisher-checkbox').prop('checked', $(this).prop('checked'));
    });
    
    // Update "Select All" checkbox when individual checkboxes change
    $('.publisher-checkbox').change(function() {
        if ($('.publisher-checkbox:checked').length === $('.publisher-checkbox').length) {
            $('#selectAll, #checkAll').prop('checked', true);
        } else {
            $('#selectAll, #checkAll').prop('checked', false);
        }
    });
    
    // Assign Manager Modal
    $('#assignManagerModal').on('show.bs.modal', function(event) {
        const button = $(event.relatedTarget);
        const publisherId = button.data('publisher-id');
        const publisherName = button.data('publisher-name');
        const currentManager = button.data('current-manager');
        
        const modal = $(this);
        modal.find('#modalPublisherId').val(publisherId);
        modal.find('#modalPublisherName').val(publisherName);
        modal.find('#modalManagerId').val(currentManager);
    });
    
    // Confirm bulk action
    function confirmBulkAction() {
        const action = document.querySelector('select[name="bulk_action"]').value;
        const selectedCount = document.querySelectorAll('.publisher-checkbox:checked').length;
        
        if (!action) {
            Swal.fire({
                icon: 'warning',
                title: 'Action Required',
                text: 'Please select a bulk action.'
            });
            return false;
        }
        
        if (selectedCount === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Selection Required',
                text: 'Please select at least one publisher.'
            });
            return false;
        }
        
        let message = '';
        switch(action) {
            case 'activate':
                message = `Are you sure you want to activate ${selectedCount} publisher(s)?`;
                break;
            case 'deactivate':
                message = `Are you sure you want to deactivate ${selectedCount} publisher(s)?`;
                break;
            case 'block':
                message = `Are you sure you want to block ${selectedCount} publisher(s)?`;
                break;
            case 'enable_payout':
                message = `Are you sure you want to enable payout for ${selectedCount} publisher(s)?`;
                break;
            case 'disable_payout':
                message = `Are you sure you want to disable payout for ${selectedCount} publisher(s)?`;
                break;
            case 'approve_kyc':
                message = `Are you sure you want to approve KYC for ${selectedCount} publisher(s)?`;
                break;
            case 'reject_kyc':
                message = `Are you sure you want to reject KYC for ${selectedCount} publisher(s)?`;
                break;
        }
        
        return confirm(message);
    }
    
    // Auto-dismiss alerts after 5 seconds
    $('.alert').delay(5000).fadeOut('slow');
    
    // Search focus
    $('#search').focus();
    
    // Status toggle confirmation
    $('a[href*="toggle_status"]').click(function(e) {
        return confirm('Are you sure you want to change this publisher\'s status?');
    });
    
    // Payout toggle confirmation
    $('a[href*="toggle_payout"]').click(function(e) {
        return confirm('Are you sure you want to change this publisher\'s payout status?');
    });
    
    // KYC action confirmation
    $('a[href*="kyc_action"]').click(function(e) {
        const action = $(this).attr('href').includes('verify') ? 'approve' : 'reject';
        return confirm(`Are you sure you want to ${action} this publisher's KYC?`);
    });
});
</script>

</body>
</html>