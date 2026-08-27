<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

/* =====================================================
   CREATE ACCOUNT MANAGER
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_manager'])) {

    $name  = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (!$name || !$email || !$password) {
        $error = "All fields are required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters";
    } else {
        // Check duplicate email
        $check = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $check->execute([$email]);

        if ($check->fetch()) {
            $error = "Email already exists";
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO users 
                (role_id, name, email, password_hash, status, created_at)
                VALUES (2, ?, ?, ?, 'pending', NOW())
            ");

            $stmt->execute([$name, $email, $passwordHash]);

            $success = "Account Manager created successfully (Pending Approval)";
        }
    }
}

/* =====================================================
   APPROVE / BLOCK MANAGER
===================================================== */
if (isset($_GET['action']) && isset($_GET['id'])) {

    $managerId = (int)$_GET['id'];
    $action = $_GET['action'];

    if (in_array($action, ['approve', 'block'])) {

        $status = ($action === 'approve') ? 'active' : 'blocked';

        $stmt = $pdo->prepare("
            UPDATE users 
            SET status = ?, updated_at = NOW()
            WHERE user_id = ? AND role_id = 2
        ");
        $stmt->execute([$status, $managerId]);

        header("Location: account_managers.php?success=" . urlencode("Manager " . ($action === 'approve' ? "approved" : "blocked") . " successfully"));
        exit;
    }
}

/* =====================================================
   ASSIGN PUBLISHER TO MANAGER
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_publisher'])) {

    $publisherId = (int)$_POST['publisher_id'];
    $managerId   = (int)$_POST['manager_id'];

    // Validate manager exists
    $checkManager = $pdo->prepare("
        SELECT user_id FROM users 
        WHERE user_id = ? AND role_id = 2
    ");
    $checkManager->execute([$managerId]);

    if (!$checkManager->fetch()) {
        $error = "Invalid manager selected";
    } else {
        $stmt = $pdo->prepare("
            UPDATE users
            SET account_manager_id = ?, updated_at = NOW()
            WHERE user_id = ? AND role_id = 3
        ");

        $stmt->execute([$managerId, $publisherId]);

        $success = "Publisher assigned successfully";
        
        // Redirect to refresh page
        header("Location: account_managers.php?success=" . urlencode("Publisher assigned successfully"));
        exit;
    }
}

/* =====================================================
   REMOVE PUBLISHER ASSIGNMENT
===================================================== */
if (isset($_GET['remove_assignment'])) {

    $publisherId = (int)$_GET['remove_assignment'];

    $stmt = $pdo->prepare("
        UPDATE users 
        SET account_manager_id = NULL, updated_at = NOW()
        WHERE user_id = ? AND role_id = 3
    ");
    $stmt->execute([$publisherId]);

    header("Location: account_managers.php?success=Assignment removed successfully");
    exit;
}

/* =====================================================
   FETCH ALL MANAGERS
===================================================== */
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? 'all';

$where = ['u.role_id = 2'];
$params = [];

if ($search) {
    $where[] = '(u.name LIKE :search OR u.email LIKE :search)';
    $params['search'] = "%$search%";
}

if ($statusFilter !== 'all') {
    $where[] = 'u.status = :status';
    $params['status'] = $statusFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$managers = $pdo->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.status,
        u.created_at,
        u.updated_at,
        COUNT(DISTINCT p.user_id) AS total_publishers
    FROM users u
    LEFT JOIN users p ON p.account_manager_id = u.user_id AND p.role_id = 3
    $whereSql
    GROUP BY u.user_id
    ORDER BY u.created_at DESC
");

foreach ($params as $key => $value) {
    $managers->bindValue($key, $value);
}

$managers->execute();
$managerData = $managers->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   FETCH ALL PUBLISHERS (AFFILIATES)
===================================================== */
$publisherSearch = $_GET['publisher_search'] ?? '';
$publisherPage = isset($_GET['publisher_page']) ? (int)$_GET['publisher_page'] : 1;
$publishersPerPage = 10;

$publisherWhere = ['u.role_id = 3'];
$publisherParams = [];

if ($publisherSearch) {
    $publisherWhere[] = '(u.name LIKE :search OR u.email LIKE :search OR u.company LIKE :search)';
    $publisherParams['search'] = "%$publisherSearch%";
}

$publisherWhereSql = $publisherWhere ? 'WHERE ' . implode(' AND ', $publisherWhere) : '';

// Get total publishers count
$totalPublishersStmt = $pdo->prepare("
    SELECT COUNT(*) FROM users u
    $publisherWhereSql
");
$totalPublishersStmt->execute($publisherParams);
$totalPublishers = $totalPublishersStmt->fetchColumn();
$totalPublisherPages = ceil($totalPublishers / $publishersPerPage);
$publisherOffset = ($publisherPage - 1) * $publishersPerPage;

// Fetch all publishers with pagination and manager info
$allPublishersStmt = $pdo->prepare("
    SELECT 
        u.user_id, 
        u.name, 
        u.email, 
        u.company, 
        u.balance,
        u.created_at,
        u.status,
        u.account_manager_id,
        m.name as manager_name
    FROM users u
    LEFT JOIN users m ON m.user_id = u.account_manager_id AND m.role_id = 2
    $publisherWhereSql
    ORDER BY u.account_manager_id IS NULL DESC, u.name ASC
    LIMIT :offset, :per_page
");

foreach ($publisherParams as $key => $value) {
    $allPublishersStmt->bindValue($key, $value);
}
$allPublishersStmt->bindValue(':offset', $publisherOffset, PDO::PARAM_INT);
$allPublishersStmt->bindValue(':per_page', $publishersPerPage, PDO::PARAM_INT);
$allPublishersStmt->execute();
$allPublishers = $allPublishersStmt->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   FETCH UNASSIGNED PUBLISHERS (FOR QUICK ASSIGN)
===================================================== */
$unassignedPublishers = $pdo->query("
    SELECT user_id, name, email, created_at
    FROM users
    WHERE role_id = 3 AND account_manager_id IS NULL
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   FETCH PUBLISHERS BY MANAGER
===================================================== */
$publishersByManager = [];
foreach ($managerData as $manager) {
    $stmt = $pdo->prepare("
        SELECT user_id, name, email, created_at, balance
        FROM users
        WHERE role_id = 3 AND account_manager_id = ?
        ORDER BY name
    ");
    $stmt->execute([$manager['user_id']]);
    $publishersByManager[$manager['user_id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =====================================================
   STATISTICS
===================================================== */
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_managers,
        SUM(status = 'active') as active_managers,
        SUM(status = 'pending') as pending_managers,
        SUM(status = 'blocked') as blocked_managers,
        (SELECT COUNT(*) FROM users WHERE role_id = 3) as total_publishers,
        (SELECT COUNT(*) FROM users WHERE role_id = 3 AND account_manager_id IS NOT NULL) as assigned_publishers,
        (SELECT COUNT(*) FROM users WHERE role_id = 3 AND account_manager_id IS NULL) as unassigned_publishers
    FROM users
    WHERE role_id = 2
")->fetch(PDO::FETCH_ASSOC);

// Check for success message
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account Managers | Admin Panel | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <!-- Select2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
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
            --purple-gradient: linear-gradient(135deg, #9f7aea 0%, #667eea 100%);
            --teal-gradient: linear-gradient(135deg, #00b5b8 0%, #38ef7d 100%);
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
        
        .btn-outline-primary {
            border: 2px solid #667eea;
            color: #667eea;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary-gradient);
            border-color: transparent;
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
            font-size: 28px;
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
        
        .assigned-value {
            color: #20c997;
            font-weight: 700;
        }
        
        .unassigned-value {
            color: #fd7e14;
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
        
        .role-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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
        
        .btn-approve {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .btn-approve:hover {
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
        }
        
        .btn-block {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .btn-block:hover {
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
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
        
        .btn-assign {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .btn-assign:hover {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .btn-remove {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.2);
        }
        
        .btn-remove:hover {
            background: rgba(108, 117, 125, 0.2);
            color: #6c757d;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state-icon {
            font-size: 60px;
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
        
        .welcome-banner {
            background: var(--dark-gradient);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner::before {
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
        
        .admin-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            font-weight: 600;
        }
        
        .manager-avatar {
            width: 45px;
            height: 45px;
            background: var(--purple-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
        }
        
        .publisher-avatar {
            width: 40px;
            height: 40px;
            background: var(--teal-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 16px;
        }
        
        .alert {
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            border: none;
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            border-left: 4px solid #28a745;
            color: #155724;
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        
        .refresh-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            border-radius: 20px;
            padding: 8px 20px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .refresh-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }
        
        .modal-header {
            border-bottom: 1px solid #e3e6f0;
            padding: 20px 25px;
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .modal-footer {
            border-top: 1px solid #e3e6f0;
            padding: 20px 25px;
        }
        
        .publisher-tag {
            background: #f8f9fc;
            border: 1px solid #e3e6f0;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .publisher-tag:last-child {
            margin-bottom: 0;
        }
        
        .assigned-badge {
            background: #28a745;
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .unassigned-badge {
            background: #fd7e14;
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .nav-tabs {
            border-bottom: 2px solid #e3e6f0;
            margin-bottom: 25px;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 8px 8px 0 0;
        }
        
        .nav-tabs .nav-link.active {
            color: #4e73df;
            border-bottom: 3px solid #4e73df;
            background: rgba(78, 115, 223, 0.05);
        }
        
        .publisher-search-box {
            background: #f8f9fc;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .search-row {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .publisher-pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }
        
        .publisher-page-link {
            padding: 5px 12px;
            border: 1px solid #e3e6f0;
            border-radius: 6px;
            color: #4e73df;
            text-decoration: none;
            font-size: 13px;
        }
        
        .publisher-page-link.active {
            background: #4e73df;
            color: white;
            border-color: #4e73df;
        }
        
        .select2-container--default .select2-selection--single {
            height: 45px;
            border: 1px solid #e3e6f0;
            border-radius: 8px;
            padding: 8px 12px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 28px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 43px;
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
                <a href="account_managers.php" class="nav-link active">Account Managers</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <span class="badge badge-warning navbar-badge"><?php echo ($stats['pending_managers'] ?? 0) + ($stats['unassigned_publishers'] ?? 0); ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header">Pending Actions</span>
                    <div class="dropdown-divider"></div>
                    <?php if (($stats['pending_managers'] ?? 0) > 0): ?>
                    <a href="account_managers.php?status=pending" class="dropdown-item">
                        <i class="fas fa-user-clock mr-2 text-warning"></i>
                        <?php echo $stats['pending_managers'] ?? 0; ?> Pending Approvals
                    </a>
                    <?php endif; ?>
                    <?php if (($stats['unassigned_publishers'] ?? 0) > 0): ?>
                    <a href="#publishers" class="dropdown-item" onclick="$('#publishers-tab').tab('show')">
                        <i class="fas fa-user-plus mr-2 text-info"></i>
                        <?php echo $stats['unassigned_publishers'] ?? 0; ?> Unassigned Publishers
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
                <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                    <div class="admin-avatar mr-2">
                        <?php echo strtoupper(substr($adminName, 0, 1)); ?>
                    </div>
                    <span><?php echo htmlspecialchars($adminName); ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user mr-2"></i> Admin Profile
                    </a>
                    <a href="settings.php" class="dropdown-item">
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
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.5rem;">
                <i class="fas fa-crown mr-2"></i>
                <strong>Admin</strong>
            </span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>

                    <li class="nav-header">CAMPAIGNS</li>
                    <li class="nav-item">
                        <a href="campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-bullhorn"></i>
                            <p>Manage Campaigns</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="create_campaign.php" class="nav-link">
                            <i class="nav-icon fas fa-plus"></i>
                            <p>Create Campaign</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="campaign_access.php" class="nav-link">
                            <i class="nav-icon fas fa-key"></i>
                            <p>Campaign Access</p>
                        </a>
                    </li>

                    <li class="nav-header">REPORTS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Campaign Report</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link">
                            <i class="nav-icon fas fa-users"></i>
                            <p>Affiliate Report</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_advertisers.php" class="nav-link">
                            <i class="nav-icon fas fa-building"></i>
                            <p>Advertiser Report</p>
                        </a>
                    </li>

                    <li class="nav-header">PUBLISHERS</li>
                    <li class="nav-item">
                        <a href="publishers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-friends"></i>
                            <p>Manage Publishers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="publisher_postbacks.php" class="nav-link">
                            <i class="nav-icon fas fa-link"></i>
                            <p>Publisher Postbacks</p>
                        </a>
                    </li>

                    <li class="nav-header">ADVERTISERS</li>
                    <li class="nav-item">
                        <a href="advertisers.php" class="nav-link">
                            <i class="nav-icon fas fa-briefcase"></i>
                            <p>Manage Advertisers</p>
                        </a>
                    </li>

                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="account_managers.php" class="nav-link active">
                            <i class="nav-icon fas fa-user-tie"></i>
                            <p>Account Managers</p>
                            <?php if ($stats['pending_managers'] > 0): ?>
                            <span class="badge badge-warning right"><?php echo $stats['pending_managers']; ?></span>
                            <?php endif; ?>
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
                        <h1 class="m-0">Account Managers</h1>
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
                <!-- Welcome Banner -->
                <div class="welcome-banner">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h2>Account Manager Management</h2>
                            <p class="mb-0">Create and manage account managers who will handle publisher relationships and performance.</p>
                        </div>
                        <div class="col-md-4 text-right">
                            <button class="refresh-btn" id="refreshPage">
                                <i class="fas fa-sync-alt mr-1"></i> Refresh
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>

                <!-- Summary Stats -->
                <div class="summary-stats">
                    <div class="metric-card">
                        <div class="metric-value total-value"><?php echo number_format($stats['total_managers'] ?? 0); ?></div>
                        <div class="metric-label">Total Managers</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value active-value"><?php echo number_format($stats['active_managers'] ?? 0); ?></div>
                        <div class="metric-label">Active</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value pending-value"><?php echo number_format($stats['pending_managers'] ?? 0); ?></div>
                        <div class="metric-label">Pending</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value assigned-value"><?php echo number_format($stats['assigned_publishers'] ?? 0); ?></div>
                        <div class="metric-label">Assigned</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value unassigned-value"><?php echo number_format($stats['unassigned_publishers'] ?? 0); ?></div>
                        <div class="metric-label">Unassigned</div>
                    </div>
                </div>

                <!-- Tabs -->
                <ul class="nav nav-tabs" id="managerTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="all-tab" data-toggle="tab" href="#all" role="tab">
                            <i class="fas fa-list mr-2"></i> All Managers
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="create-tab" data-toggle="tab" href="#create" role="tab">
                            <i class="fas fa-plus-circle mr-2"></i> Create New Manager
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="publishers-tab" data-toggle="tab" href="#publishers" role="tab">
                            <i class="fas fa-user-friends mr-2"></i> All Publishers
                            <?php if (($stats['unassigned_publishers'] ?? 0) > 0): ?>
                            <span class="badge badge-warning ml-2"><?php echo $stats['unassigned_publishers']; ?> unassigned</span>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>

                <div class="tab-content" id="managerTabsContent">
                    <!-- ALL MANAGERS TAB -->
                    <div class="tab-pane fade show active" id="all" role="tabpanel">
                        <!-- Filters -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-filter mr-2"></i> Filter Managers
                                </h3>
                            </div>
                            <div class="card-body">
                                <form method="get" class="filter-row">
                                    <input type="hidden" name="tab" value="all">
                                    <div class="filter-group">
                                        <label for="search"><i class="fas fa-search mr-1"></i> Search</label>
                                        <input type="text" name="search" id="search" class="filter-control" 
                                               placeholder="Name or email..." 
                                               value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label for="status"><i class="fas fa-toggle-on mr-1"></i> Status</label>
                                        <select name="status" id="status" class="filter-control">
                                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="blocked" <?php echo $statusFilter === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <button type="submit" class="btn-gradient" style="height: 45px; width: 100%;">
                                            <i class="fas fa-search mr-2"></i> Apply Filters
                                        </button>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <a href="account_managers.php" class="btn btn-outline-primary" style="height: 45px; width: 100%; display: flex; align-items: center; justify-content: center;">
                                            <i class="fas fa-redo mr-2"></i> Reset
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Managers Table -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-user-tie mr-2"></i> Account Managers
                                </h3>
                                <div class="card-tools">
                                    <span class="badge badge-light">
                                        <?php echo count($managerData); ?> manager<?php echo count($managerData) != 1 ? 's' : ''; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($managerData)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-user-tie"></i>
                                    </div>
                                    <h5>No Account Managers Found</h5>
                                    <p class="text-muted">Create your first account manager to get started.</p>
                                    <a href="#create" class="btn btn-gradient btn-sm" onclick="$('#create-tab').tab('show')">
                                        <i class="fas fa-plus-circle mr-2"></i> Create Manager
                                    </a>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-dashboard" id="managersTable">
                                        <thead>
                                            <tr>
                                                <th>Manager</th>
                                                <th>Status</th>
                                                <th>Assigned Publishers</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($managerData as $manager): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="manager-avatar mr-3">
                                                            <?php echo strtoupper(substr($manager['name'], 0, 1)); ?>
                                                        </div>
                                                        <div>
                                                            <strong><?php echo htmlspecialchars($manager['name']); ?></strong>
                                                            <div class="text-muted small">
                                                                <?php echo htmlspecialchars($manager['email']); ?>
                                                            </div>
                                                            <div class="text-muted small">
                                                                <i class="far fa-calendar-alt mr-1"></i>
                                                                Joined: <?php echo date('M d, Y', strtotime($manager['created_at'])); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $manager['status']; ?>">
                                                        <?php echo ucfirst($manager['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <span class="badge badge-light mr-2">
                                                            <?php echo $manager['total_publishers']; ?> publishers
                                                        </span>
                                                        <button class="btn btn-sm btn-link" type="button" data-toggle="modal" data-target="#publishersModal<?php echo $manager['user_id']; ?>">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <?php if ($manager['status'] === 'pending'): ?>
                                                        <a href="?action=approve&id=<?php echo $manager['user_id']; ?>" 
                                                           class="btn-action btn-approve"
                                                           title="Approve Manager">
                                                            <i class="fas fa-check"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($manager['status'] === 'active'): ?>
                                                        <a href="?action=block&id=<?php echo $manager['user_id']; ?>" 
                                                           class="btn-action btn-block"
                                                           title="Block Manager"
                                                           onclick="return confirm('Are you sure you want to block this manager?')">
                                                            <i class="fas fa-ban"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($manager['status'] === 'blocked'): ?>
                                                        <a href="?action=approve&id=<?php echo $manager['user_id']; ?>" 
                                                           class="btn-action btn-approve"
                                                           title="Activate Manager">
                                                            <i class="fas fa-play"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <a href="#" 
                                                           class="btn-action btn-assign"
                                                           title="Assign Publisher"
                                                           data-toggle="modal" 
                                                           data-target="#assignModal<?php echo $manager['user_id']; ?>">
                                                            <i class="fas fa-user-plus"></i>
                                                        </a>
                                                        
                                                        <a href="mailto:<?php echo $manager['email']; ?>" 
                                                           class="btn-action btn-view"
                                                           title="Send Email">
                                                            <i class="fas fa-envelope"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- CREATE MANAGER TAB -->
                    <div class="tab-pane fade" id="create" role="tabpanel">
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-user-plus mr-2"></i> Create New Account Manager
                                </h3>
                            </div>
                            <div class="card-body">
                                <form method="post" id="createManagerForm" onsubmit="return validateForm()">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="required" for="name">Full Name</label>
                                                <div class="input-wrapper">
                                                    <i class="fas fa-user input-icon" style="left: 15px; top: 50%; transform: translateY(-50%); position: absolute; color: #6c757d;"></i>
                                                    <input type="text" class="filter-control" id="name" name="name" 
                                                           placeholder="John Smith" style="padding-left: 45px;" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="required" for="email">Email Address</label>
                                                <div class="input-wrapper">
                                                    <i class="fas fa-envelope input-icon" style="left: 15px; top: 50%; transform: translateY(-50%); position: absolute; color: #6c757d;"></i>
                                                    <input type="email" class="filter-control" id="email" name="email" 
                                                           placeholder="manager@example.com" style="padding-left: 45px;" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="required" for="password">Password</label>
                                                <div class="input-wrapper">
                                                    <i class="fas fa-lock input-icon" style="left: 15px; top: 50%; transform: translateY(-50%); position: absolute; color: #6c757d;"></i>
                                                    <input type="password" class="filter-control" id="password" name="password" 
                                                           placeholder="••••••••" style="padding-left: 45px;" required>
                                                </div>
                                                <small class="text-muted">Minimum 6 characters</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="confirm_password">Confirm Password</label>
                                                <div class="input-wrapper">
                                                    <i class="fas fa-lock input-icon" style="left: 15px; top: 50%; transform: translateY(-50%); position: absolute; color: #6c757d;"></i>
                                                    <input type="password" class="filter-control" id="confirm_password" 
                                                           placeholder="••••••••" style="padding-left: 45px;">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4">
                                        <button type="submit" name="create_manager" class="btn-gradient">
                                            <i class="fas fa-save mr-2"></i> Create Account Manager
                                        </button>
                                        <button type="reset" class="btn btn-outline-secondary ml-2">
                                            <i class="fas fa-undo mr-2"></i> Reset
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- ALL PUBLISHERS TAB -->
                    <div class="tab-pane fade" id="publishers" role="tabpanel">
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-user-friends mr-2"></i> All Publishers
                                </h3>
                                <div class="card-tools">
                                    <span class="badge badge-light">
                                        Total: <?php echo $totalPublishers; ?> publishers
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- Publisher Search -->
                                <div class="publisher-search-box">
                                    <form method="get" class="search-row">
                                        <input type="hidden" name="tab" value="publishers">
                                        <div class="filter-group" style="flex: 1;">
                                            <input type="text" name="publisher_search" class="filter-control" 
                                                   placeholder="Search by name, email or company..." 
                                                   value="<?php echo htmlspecialchars($publisherSearch); ?>">
                                        </div>
                                        <div class="filter-group" style="flex: 0 0 auto;">
                                            <button type="submit" class="btn-gradient" style="height: 45px;">
                                                <i class="fas fa-search mr-1"></i> Search
                                            </button>
                                        </div>
                                        <?php if ($publisherSearch): ?>
                                        <div class="filter-group" style="flex: 0 0 auto;">
                                            <a href="account_managers.php?tab=publishers" class="btn btn-outline-secondary" style="height: 45px; display: flex; align-items: center;">
                                                <i class="fas fa-times mr-1"></i> Clear
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </form>
                                </div>

                                <!-- Publishers Table -->
                                <?php if (empty($allPublishers)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-user-friends"></i>
                                    </div>
                                    <h5>No Publishers Found</h5>
                                    <p class="text-muted">No publishers match your search criteria.</p>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-dashboard">
                                        <thead>
                                            <tr>
                                                <th>Publisher</th>
                                                <th>Email</th>
                                                <th>Company</th>
                                                <th>Balance</th>
                                                <th>Status</th>
                                                <th>Assigned To</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($allPublishers as $pub): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="publisher-avatar mr-2">
                                                            <?php echo strtoupper(substr($pub['name'], 0, 1)); ?>
                                                        </div>
                                                        <strong><?php echo htmlspecialchars($pub['name']); ?></strong>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($pub['email']); ?></td>
                                                <td><?php echo htmlspecialchars($pub['company'] ?? '-'); ?></td>
                                                <td>$<?php echo number_format($pub['balance'] ?? 0, 2); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $pub['status']; ?>">
                                                        <?php echo ucfirst($pub['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($pub['manager_name']): ?>
                                                        <?php echo htmlspecialchars($pub['manager_name']); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not assigned</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!$pub['account_manager_id'] && !empty($managerData)): ?>
                                                    <button type="button" 
                                                            class="btn-action btn-assign"
                                                            title="Assign to Manager"
                                                            data-toggle="modal" 
                                                            data-target="#quickAssignModal"
                                                            data-publisher-id="<?php echo $pub['user_id']; ?>"
                                                            data-publisher-name="<?php echo htmlspecialchars($pub['name']); ?>">
                                                        <i class="fas fa-user-plus"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Publisher Pagination -->
                                <?php if ($totalPublisherPages > 1): ?>
                                <div class="publisher-pagination">
                                    <?php if ($publisherPage > 1): ?>
                                    <a href="?tab=publishers&publisher_search=<?php echo urlencode($publisherSearch); ?>&publisher_page=<?php echo $publisherPage - 1; ?>" 
                                       class="publisher-page-link">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    $startPage = max(1, $publisherPage - 2);
                                    $endPage = min($totalPublisherPages, $publisherPage + 2);
                                    for ($i = $startPage; $i <= $endPage; $i++): 
                                    ?>
                                    <a href="?tab=publishers&publisher_search=<?php echo urlencode($publisherSearch); ?>&publisher_page=<?php echo $i; ?>" 
                                       class="publisher-page-link <?php echo $i == $publisherPage ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                    <?php endfor; ?>
                                    
                                    <?php if ($publisherPage < $totalPublisherPages): ?>
                                    <a href="?tab=publishers&publisher_search=<?php echo urlencode($publisherSearch); ?>&publisher_page=<?php echo $publisherPage + 1; ?>" 
                                       class="publisher-page-link">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
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
            <strong>Admin Panel v3.0</strong>
        </div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- Publisher Modals for each manager -->
<?php foreach ($managerData as $manager): ?>
<div class="modal fade" id="publishersModal<?php echo $manager['user_id']; ?>" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-users mr-2"></i> Publishers Assigned to <?php echo htmlspecialchars($manager['name']); ?>
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <?php if (empty($publishersByManager[$manager['user_id']])): ?>
                <p class="text-muted text-center">No publishers assigned yet.</p>
                <?php else: ?>
                    <?php foreach ($publishersByManager[$manager['user_id']] as $pub): ?>
                    <div class="publisher-tag">
                        <div>
                            <strong><?php echo htmlspecialchars($pub['name']); ?></strong>
                            <div class="small text-muted"><?php echo htmlspecialchars($pub['email']); ?></div>
                        </div>
                        <div class="d-flex align-items-center">
                            <span class="badge badge-light mr-2">$<?php echo number_format($pub['balance'], 2); ?></span>
                            <a href="?remove_assignment=<?php echo $pub['user_id']; ?>" 
                               class="btn-action btn-remove"
                               onclick="return confirm('Remove assignment for this publisher?')">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-gradient btn-sm" data-toggle="modal" data-target="#assignModal<?php echo $manager['user_id']; ?>" data-dismiss="modal">
                    <i class="fas fa-user-plus mr-1"></i> Assign More
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Assign Modal -->
<div class="modal fade" id="assignModal<?php echo $manager['user_id']; ?>" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-plus mr-2"></i> Assign Publisher to <?php echo htmlspecialchars($manager['name']); ?>
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="manager_id" value="<?php echo $manager['user_id']; ?>">
                    
                    <div class="form-group">
                        <label for="publisher_id_<?php echo $manager['user_id']; ?>">Select Publisher</label>
                        <?php
                        // Get unassigned affiliates - FIXED QUERY
                        $unassignedForModal = $pdo->prepare("
                            SELECT user_id, name, email 
                            FROM users 
                            WHERE role_id = 3 AND (account_manager_id IS NULL OR account_manager_id = 0)
                            ORDER BY name
                        ");
                        $unassignedForModal->execute();
                        $unassignedList = $unassignedForModal->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        
                        <select class="form-control" id="publisher_id_<?php echo $manager['user_id']; ?>" name="publisher_id" required>
                            <option value="">-- Select a publisher --</option>
                            <?php if (!empty($unassignedList)): ?>
                                <?php foreach ($unassignedList as $pub): ?>
                                <option value="<?php echo $pub['user_id']; ?>">
                                    <?php echo htmlspecialchars($pub['name']); ?> (<?php echo htmlspecialchars($pub['email']); ?>)
                                </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>No unassigned publishers available</option>
                            <?php endif; ?>
                        </select>
                        
                        <?php if (empty($unassignedList)): ?>
                        <p class="text-warning small mt-2">
                            <i class="fas fa-info-circle"></i> 
                            No unassigned publishers found. All publishers may already be assigned.
                        </p>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($unassignedList)): ?>
                    <div class="mt-3">
                        <p class="text-muted small">
                            <i class="fas fa-info-circle"></i> 
                            Showing <?php echo count($unassignedList); ?> unassigned publisher(s)
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="assign_publisher" class="btn btn-gradient" <?php echo empty($unassignedList) ? 'disabled' : ''; ?>>
                        <i class="fas fa-check mr-2"></i> Assign Publisher
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Quick Assign Modal (for unassigned publishers) -->
<div class="modal fade" id="quickAssignModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-plus mr-2"></i> Assign Publisher to Manager
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="publisher_id" id="quickPublisherId">
                    
                    <div class="form-group">
                        <label>Publisher</label>
                        <input type="text" class="form-control" id="quickPublisherName" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="quickManagerId">Select Manager</label>
                        <select name="manager_id" id="quickManagerId" class="filter-control" required>
                            <option value="">Choose a manager...</option>
                            <?php foreach ($managerData as $manager): ?>
                                <option value="<?php echo $manager['user_id']; ?>">
                                    <?php echo htmlspecialchars($manager['name']); ?> (<?php echo htmlspecialchars($manager['email']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="assign_publisher" class="btn btn-gradient">
                        <i class="fas fa-check mr-2"></i> Assign Publisher
                    </button>
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
<!-- Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable for managers table
    $('#managersTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [], // Disable initial sort
        responsive: true,
        searching: false, // We use custom search
        info: true,
        paging: true,
        language: {
            emptyTable: "No account managers found"
        }
    });
    
    // Initialize Select2 for modal dropdowns
    $('.select2-modal').select2({
        placeholder: "Search for a publisher...",
        allowClear: true,
        width: '100%',
        dropdownParent: $('.modal')
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
    
    // Refresh page
    $('#refreshPage').click(function() {
        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Refreshing...');
        
        setTimeout(() => {
            window.location.reload();
        }, 1000);
    });
    
    // Auto-dismiss alerts after 5 seconds
    $('.alert').delay(5000).fadeOut('slow');
    
    // Form validation for create manager
    window.validateForm = function() {
        const name = $('#name').val().trim();
        const email = $('#email').val().trim();
        const password = $('#password').val();
        const confirm = $('#confirm_password').val();
        
        if (!name || !email || !password) {
            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                text: 'All fields are required'
            });
            return false;
        }
        
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                text: 'Please enter a valid email address'
            });
            return false;
        }
        
        if (password.length < 6) {
            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                text: 'Password must be at least 6 characters'
            });
            return false;
        }
        
        if (confirm && password !== confirm) {
            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                text: 'Passwords do not match'
            });
            return false;
        }
        
        return true;
    };
    
    // Tab handling from URL
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab');
    if (tabParam === 'create') {
        $('#create-tab').tab('show');
    } else if (tabParam === 'publishers') {
        $('#publishers-tab').tab('show');
    }
    
    // Quick assign modal data
    $('#quickAssignModal').on('show.bs.modal', function(event) {
        const button = $(event.relatedTarget);
        const publisherId = button.data('publisher-id');
        const publisherName = button.data('publisher-name');
        
        const modal = $(this);
        modal.find('#quickPublisherId').val(publisherId);
        modal.find('#quickPublisherName').val(publisherName);
    });
    
    // Initialize SweetAlert2 Toast
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });
});
</script>

</body>
</html>