<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

/* ===============================
   SAFE ADMIN SESSION HANDLING
================================ */
$adminId = $_SESSION['user_name'] ?? 'Admin';
$adminName = $_SESSION['user_name'] ?? 'Admin';

if (!$adminId) {
    die('Invalid admin session. Please re-login.');
}

$success = $error = null;

/* ===============================
   ASSIGN OFFER TO AFFILIATE
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['assign_offer'])) {
        $affiliateId = (int)($_POST['affiliate_id'] ?? 0);
        $offerId = (int)($_POST['offer_id'] ?? 0);
        $payoutType = $_POST['payout_type'] ?? 'default';
        $customPayout = isset($_POST['custom_payout']) ? (float)$_POST['custom_payout'] : null;
        $notes = trim($_POST['notes'] ?? '');

        if (!$affiliateId || !$offerId) {
            $error = 'Publisher and Offer are required';
        } else {
            try {
                // Check if assignment already exists
                $checkStmt = $pdo->prepare("
                    SELECT id FROM affiliate_offer_approval 
                    WHERE affiliate_id = ? AND offer_id = ?
                ");
                $checkStmt->execute([$affiliateId, $offerId]);
                
                if ($checkStmt->fetch()) {
                    $error = 'This offer is already assigned to this publisher';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO affiliate_offer_approval
                            (affiliate_id, offer_id, status, payout_type, custom_payout, notes, created_by)
                        VALUES
                            (:affiliate_id, :offer_id, 'pending', :payout_type, :custom_payout, :notes, :created_by)
                    ");

                    $stmt->execute([
                        'affiliate_id' => $affiliateId,
                        'offer_id' => $offerId,
                        'payout_type' => $payoutType,
                        'custom_payout' => $customPayout,
                        'notes' => $notes,
                        'created_by' => $adminId
                    ]);

                    $success = 'Offer assigned successfully (pending approval)';
                }
            } catch (PDOException $e) {
                $error = 'Database error while assigning offer: ' . $e->getMessage();
            }
        }
    }
    
    // Bulk status update
    if (isset($_POST['bulk_status'])) {
        $selectedAssignments = $_POST['selected_assignments'] ?? [];
        $bulkStatus = $_POST['bulk_status'];
        
        if (empty($selectedAssignments)) {
            $error = 'Please select at least one assignment';
        } else {
            $placeholders = implode(',', array_fill(0, count($selectedAssignments), '?'));
            $stmt = $pdo->prepare("
                UPDATE affiliate_offer_approval 
                SET status = ?, 
                    approved_by = ?, 
                    approved_at = NOW() 
                WHERE id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$bulkStatus, $adminId], $selectedAssignments));
            
            $success = count($selectedAssignments) . ' assignment(s) updated to ' . ucfirst($bulkStatus);
        }
    }

    /* ===============================
       UPDATE APPROVAL STATUS
    ================================ */
    if (isset($_POST['update_status'])) {
        $approvalId = (int)($_POST['approval_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';
        $notes = trim($_POST['notes'] ?? '');

        if (!$approvalId || !in_array($newStatus, ['approved', 'rejected'], true)) {
            $error = 'Invalid approval request';
        } else {
            $stmt = $pdo->prepare("
                UPDATE affiliate_offer_approval
                SET status = :status,
                    approved_by = :approved_by,
                    approved_at = NOW(),
                    notes = CONCAT(COALESCE(notes, ''), '\n--- Admin Note: ', :notes, ' (', NOW(), ')')
                WHERE id = :id
            ");

            $stmt->execute([
                'status' => $newStatus,
                'approved_by' => $adminId,
                'notes' => $notes,
                'id' => $approvalId
            ]);

            $success = 'Campaign access status updated';
        }
    }
}

/* ===============================
   SEARCH AND FILTER PARAMETERS
================================ */
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? 'all';
$affiliateFilter = $_GET['affiliate'] ?? 'all';
$offerFilter = $_GET['offer'] ?? 'all';
$sortBy = $_GET['sort'] ?? 'recent';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;

/* ===============================
   FETCH AFFILIATES
================================ */
$affiliates = $pdo->query("
    SELECT 
        user_id,
        name,
        email
    FROM users
    WHERE role_id = 3 AND status = 'active'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH OFFERS
================================ */
$offers = $pdo->query("
    SELECT 
        offer_id,
        offer_name,
        payout,
        status
    FROM offers
    WHERE status IN ('approved', 'active')
    ORDER BY offer_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH EXISTING ACCESS WITH FILTERS
================================ */
$where = [];
$params = [];

if ($search) {
    $where[] = '(u.name LIKE :search OR u.email LIKE :search OR o.offer_name LIKE :search OR aoa.notes LIKE :search)';
    $params['search'] = "%$search%";
}

if ($statusFilter !== 'all') {
    $where[] = 'aoa.status = :status';
    $params['status'] = $statusFilter;
}

if ($affiliateFilter !== 'all') {
    $where[] = 'aoa.affiliate_id = :affiliate';
    $params['affiliate'] = (int)$affiliateFilter;
}

if ($offerFilter !== 'all') {
    $where[] = 'aoa.offer_id = :offer';
    $params['offer'] = (int)$offerFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Get sort order
$orderBy = 'aoa.id DESC';
switch ($sortBy) {
    case 'recent':
        $orderBy = 'aoa.created_at DESC';
        break;
    case 'oldest':
        $orderBy = 'aoa.created_at ASC';
        break;
    case 'affiliate':
        $orderBy = 'u.name ASC';
        break;
    case 'offer':
        $orderBy = 'o.offer_name ASC';
        break;
    case 'status':
        $orderBy = 'aoa.status ASC, aoa.created_at DESC';
        break;
}

// Get total count
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM affiliate_offer_approval aoa
    INNER JOIN users u ON u.user_id = aoa.affiliate_id
    INNER JOIN offers o ON o.offer_id = aoa.offer_id
    $whereSql
");
$countStmt->execute($params);
$totalAssignments = $countStmt->fetchColumn();
$totalPages = ceil($totalAssignments / $perPage);
$offset = ($page - 1) * $perPage;

// Fetch assignments with pagination
$assignments = $pdo->prepare("
    SELECT 
        aoa.id,
        aoa.status,
        aoa.payout_type,
        aoa.custom_payout,
        aoa.notes,
        aoa.created_at,
        aoa.approved_at,
        aoa.created_by,
        aoa.approved_by,
        
        u.user_id as affiliate_id,
        u.name  AS affiliate_name,
        u.email AS affiliate_email,
        
        o.offer_id,
        o.offer_name,
        o.payout as original_payout,
        o.status as offer_status,
        
        admin.name as approved_by_name
        
    FROM affiliate_offer_approval aoa
    INNER JOIN users u ON u.user_id = aoa.affiliate_id
    INNER JOIN offers o ON o.offer_id = aoa.offer_id
    LEFT JOIN users admin ON admin.user_id = aoa.approved_by
    
    $whereSql
    ORDER BY $orderBy
    LIMIT :offset, :per_page
");

$assignments->bindValue(':offset', $offset, PDO::PARAM_INT);
$assignments->bindValue(':per_page', $perPage, PDO::PARAM_INT);

foreach ($params as $key => $value) {
    $assignments->bindValue($key, $value);
}

$assignments->execute();
$assignmentData = $assignments->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   STATISTICS
================================ */
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_assignments,
        SUM(status = 'approved') as approved_assignments,
        SUM(status = 'pending') as pending_assignments,
        SUM(status = 'rejected') as rejected_assignments,
        
        COUNT(DISTINCT affiliate_id) as unique_publishers,
        COUNT(DISTINCT offer_id) as unique_offers,
        
        AVG(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) * 100 as approval_rate
    FROM affiliate_offer_approval
")->fetch(PDO::FETCH_ASSOC);

/* ===============================
   GET ADMIN USER LIST
================================ */
$admins = $pdo->query("
    SELECT user_id, name FROM users WHERE role_id = 1 ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// Check for success message from redirect
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Campaign Access | Admin Panel | GVS Icon Media</title>
    
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
        
        .approved-value {
            color: #28a745;
            font-weight: 700;
        }
        
        .pending-value {
            color: #ffc107;
            font-weight: 700;
        }
        
        .rejected-value {
            color: #dc3545;
            font-weight: 700;
        }
        
        .publishers-value {
            color: #20c997;
            font-weight: 700;
        }
        
        .offers-value {
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
        
        .status-approved {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .status-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .status-rejected {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .payout-badge {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .offer-status-badge {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
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
        
        .btn-approve {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .btn-approve:hover {
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
        }
        
        .btn-reject {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .btn-reject:hover {
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
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
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .note-text {
            font-size: 12px;
            color: #6c757d;
            background: #f8f9fa;
            padding: 8px;
            border-radius: 6px;
            border-left: 3px solid #6c757d;
        }
        
        .admin-badge {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
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
                <a href="campaign_access.php" class="nav-link active">Campaign Access</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <?php if (($stats['pending_assignments'] ?? 0) > 0): ?>
                    <span class="badge badge-warning navbar-badge">
                        <?php echo $stats['pending_assignments'] ?? 0; ?>
                    </span>
                    <?php endif; ?>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header">
                        <?php echo $stats['pending_assignments'] ?? 0; ?> Pending Approvals
                    </span>
                    <div class="dropdown-divider"></div>
                    <a href="campaign_access.php?status=pending" class="dropdown-item">
                        <i class="fas fa-user-check mr-2 text-warning"></i>
                        <?php echo $stats['pending_assignments'] ?? 0; ?> Campaigns Pending Approval
                    </a>
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
                        <i class="fas fa-user mr-2"></i> Profile
                    </a>
                    <a href="settings.php" class="dropdown-item">
                        <i class="fas fa-cog mr-2"></i> Settings
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
        <!-- Brand Logo -->
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.5rem;">
                <i class="fas fa-crown mr-2"></i>
                <strong>Admin</strong>
            </span>
        </a>

        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Sidebar Menu -->
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
                        <a href="campaign_access.php" class="nav-link active">
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
                        <a href="account_managers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-tie"></i>
                            <p>Account Managers</p>
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
                        <h1 class="m-0">Campaign Access Management</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="offers.php">Campaigns</a></li>
                            <li class="breadcrumb-item active">Campaign Access</li>
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
                    <h2 class="mb-0">Campaign Access Control</h2>
                    <div class="action-buttons-group">
                        <a href="?export=csv" class="btn btn-outline-primary">
                            <i class="fas fa-download mr-2"></i> Export Access List
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
                        <div class="metric-value total-value"><?php echo number_format($stats['total_assignments'] ?? 0); ?></div>
                        <div class="metric-label">Total Assignments</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value approved-value"><?php echo number_format($stats['approved_assignments'] ?? 0); ?></div>
                        <div class="metric-label">Approved</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value pending-value"><?php echo number_format($stats['pending_assignments'] ?? 0); ?></div>
                        <div class="metric-label">Pending</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value rejected-value"><?php echo number_format($stats['rejected_assignments'] ?? 0); ?></div>
                        <div class="metric-label">Rejected</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value publishers-value"><?php echo number_format($stats['unique_publishers'] ?? 0); ?></div>
                        <div class="metric-label">Unique Publishers</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value offers-value"><?php echo number_format($stats['unique_offers'] ?? 0); ?></div>
                        <div class="metric-label">Unique Offers</div>
                    </div>
                </div>

                <!-- Assign New Campaign Access -->
                <div class="card-dashboard">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-plus-circle mr-2"></i> Assign Campaign to Publisher
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="post" id="assignForm">
                            <div class="form-grid">
                                <div class="filter-group">
                                    <label for="affiliate_id"><i class="fas fa-user-friends mr-1"></i> Select Publisher</label>
                                    <select name="affiliate_id" id="affiliate_id" class="filter-control" required>
                                        <option value="">Choose Publisher...</option>
                                        <?php foreach ($affiliates as $a): ?>
                                        <option value="<?php echo $a['user_id']; ?>">
                                            <?php echo htmlspecialchars($a['name']); ?> (<?php echo htmlspecialchars($a['email']); ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label for="offer_id"><i class="fas fa-bullhorn mr-1"></i> Select Campaign</label>
                                    <select name="offer_id" id="offer_id" class="filter-control" required>
                                        <option value="">Choose Campaign...</option>
                                        <?php foreach ($offers as $o): ?>
                                        <option value="<?php echo $o['offer_id']; ?>" data-payout="<?php echo $o['payout']; ?>">
                                            <?php echo htmlspecialchars($o['offer_name']); ?> 
                                            <span class="text-muted">($<?php echo number_format($o['payout'], 2); ?>)</span>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label for="payout_type"><i class="fas fa-money-bill-wave mr-1"></i> Payout Type</label>
                                    <select name="payout_type" id="payout_type" class="filter-control">
                                        <option value="default">Default (Campaign Payout)</option>
                                        <option value="custom">Custom Payout</option>
                                        <option value="revshare">Revenue Share (%)</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group" id="customPayoutGroup" style="display: none;">
                                    <label for="custom_payout"><i class="fas fa-dollar-sign mr-1"></i> Custom Payout</label>
                                    <input type="number" 
                                           name="custom_payout" 
                                           id="custom_payout" 
                                           class="filter-control" 
                                           step="0.01"
                                           placeholder="0.00">
                                </div>
                            </div>
                            
                            <div class="filter-group">
                                <label for="notes"><i class="fas fa-sticky-note mr-1"></i> Notes (Optional)</label>
                                <textarea name="notes" 
                                          id="notes" 
                                          class="filter-control" 
                                          rows="2"
                                          placeholder="Add any notes about this assignment..."></textarea>
                            </div>
                            
                            <div class="mt-3">
                                <button type="submit" name="assign_offer" class="btn-gradient">
                                    <i class="fas fa-key mr-2"></i> Assign Campaign Access
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Bulk Actions Form -->
                <form method="post" id="bulkForm">
                    <!-- Bulk Actions -->
                    <div class="bulk-actions">
                        <div class="form-check select-all-checkbox">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                            <label class="form-check-label" for="selectAll">Select All</label>
                        </div>
                        
                        <select name="bulk_status" class="filter-control" style="width: auto;">
                            <option value="">Bulk Status Update</option>
                            <option value="approved">Approve Selected</option>
                            <option value="rejected">Reject Selected</option>
                        </select>
                        
                        <button type="submit" class="btn btn-outline-primary btn-sm" onclick="return confirmBulkAction()">
                            <i class="fas fa-play mr-1"></i> Apply
                        </button>
                        
                        <span class="text-muted ml-2">
                            <?php echo $totalAssignments; ?> assignment<?php echo $totalAssignments != 1 ? 's' : ''; ?> found
                        </span>
                    </div>

                    <!-- Filters -->
                    <div class="card-dashboard">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-filter mr-2"></i> Filter Assignments
                            </h3>
                        </div>
                        <div class="card-body">
                            <form method="get" class="filter-row">
                                <div class="filter-group">
                                    <label for="search"><i class="fas fa-search mr-1"></i> Search</label>
                                    <input type="text" name="search" id="search" class="filter-control" 
                                           placeholder="Search by publisher, campaign, notes..." 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label for="status"><i class="fas fa-toggle-on mr-1"></i> Status</label>
                                    <select name="status" id="status" class="filter-control">
                                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                        <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label for="affiliate"><i class="fas fa-user-friends mr-1"></i> Publisher</label>
                                    <select name="affiliate" id="affiliate" class="filter-control">
                                        <option value="all" <?php echo $affiliateFilter === 'all' ? 'selected' : ''; ?>>All Publishers</option>
                                        <?php foreach ($affiliates as $a): ?>
                                        <option value="<?php echo $a['user_id']; ?>" <?php echo $affiliateFilter == $a['user_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($a['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label for="offer"><i class="fas fa-bullhorn mr-1"></i> Campaign</label>
                                    <select name="offer" id="offer" class="filter-control">
                                        <option value="all" <?php echo $offerFilter === 'all' ? 'selected' : ''; ?>>All Campaigns</option>
                                        <?php foreach ($offers as $o): ?>
                                        <option value="<?php echo $o['offer_id']; ?>" <?php echo $offerFilter == $o['offer_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($o['offer_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label for="sort"><i class="fas fa-sort mr-1"></i> Sort By</label>
                                    <select name="sort" id="sort" class="filter-control">
                                        <option value="recent" <?php echo $sortBy === 'recent' ? 'selected' : ''; ?>>Most Recent</option>
                                        <option value="oldest" <?php echo $sortBy === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                        <option value="affiliate" <?php echo $sortBy === 'affiliate' ? 'selected' : ''; ?>>Publisher A-Z</option>
                                        <option value="offer" <?php echo $sortBy === 'offer' ? 'selected' : ''; ?>>Campaign A-Z</option>
                                        <option value="status" <?php echo $sortBy === 'status' ? 'selected' : ''; ?>>Status</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <button type="submit" class="btn-gradient" style="height: 45px; width: 100%;">
                                        <i class="fas fa-search mr-2"></i> Apply Filters
                                    </button>
                                </div>
                                
                                <div class="filter-group">
                                    <a href="campaign_access.php" class="btn btn-outline-primary" style="height: 45px; width: 100%; display: flex; align-items: center; justify-content: center;">
                                        <i class="fas fa-redo mr-2"></i> Reset
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Assignments Table -->
                    <div class="card-dashboard">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-list-alt mr-2"></i> Campaign Access Assignments
                            </h3>
                            <div class="card-tools">
                                <span class="badge badge-light">
                                    Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($assignmentData)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-key"></i>
                                    </div>
                                    <h5>No Campaign Access Assignments Found</h5>
                                    <p class="text-muted">No assignments match your search criteria.</p>
                                    <a href="campaign_access.php" class="btn btn-gradient btn-sm">
                                        <i class="fas fa-redo mr-2"></i> Reset Filters
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-dashboard" id="assignmentsTable">
                                        <thead>
                                            <tr>
                                                <th style="width: 40px;">
                                                    <input type="checkbox" class="form-check-input" id="checkAll">
                                                </th>
                                                <th>Publisher</th>
                                                <th>Campaign</th>
                                                <th>Payout</th>
                                                <th>Status</th>
                                                <th>Dates</th>
                                                <th>Notes</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($assignmentData as $row): ?>
                                            <tr>
                                                <td>
                                                    <?php if ($row['status'] === 'pending'): ?>
                                                    <input type="checkbox" 
                                                           name="selected_assignments[]" 
                                                           value="<?php echo $row['id']; ?>" 
                                                           class="form-check-input assignment-checkbox">
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="mr-3">
                                                            <div style="width: 40px; height: 40px; background: #667eea; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600;">
                                                                <?php echo strtoupper(substr($row['affiliate_name'], 0, 1)); ?>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <strong><?php echo htmlspecialchars($row['affiliate_name']); ?></strong>
                                                            <div class="text-muted small">
                                                                <?php echo htmlspecialchars($row['affiliate_email']); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($row['offer_name']); ?></strong>
                                                        <div class="text-muted small">
                                                            ID: #<?php echo $row['offer_id']; ?>
                                                            &nbsp;•&nbsp; 
                                                            <span class="offer-status-badge">
                                                                <?php echo ucfirst($row['offer_status']); ?>
                                                            </span>
                                                        </div>
                                                        <div class="text-muted small">
                                                            Default: $<?php echo number_format($row['original_payout'], 2); ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($row['payout_type'] === 'custom' && $row['custom_payout']): ?>
                                                    <div class="payout-badge">
                                                        <i class="fas fa-star mr-1"></i>
                                                        $<?php echo number_format($row['custom_payout'], 2); ?>
                                                    </div>
                                                    <div class="small text-muted">
                                                        Custom
                                                    </div>
                                                    <?php else: ?>
                                                    <div class="payout-badge">
                                                        <i class="fas fa-dollar-sign mr-1"></i>
                                                        $<?php echo number_format($row['original_payout'], 2); ?>
                                                    </div>
                                                    <div class="small text-muted">
                                                        Default
                                                    </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $row['status']; ?>">
                                                        <?php echo ucfirst($row['status']); ?>
                                                    </span>
                                                    <?php if ($row['approved_by_name']): ?>
                                                    <div class="small text-muted mt-1">
                                                        By: <?php echo htmlspecialchars($row['approved_by_name']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        <div class="mb-1">
                                                            <i class="far fa-calendar-plus mr-1 text-muted"></i>
                                                            <?php echo date('M d, Y', strtotime($row['created_at'])); ?>
                                                        </div>
                                                        <?php if ($row['approved_at']): ?>
                                                        <div>
                                                            <i class="far fa-calendar-check mr-1 text-muted"></i>
                                                            <?php echo date('M d, Y', strtotime($row['approved_at'])); ?>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($row['notes']): ?>
                                                    <div class="note-text">
                                                        <?php echo nl2br(htmlspecialchars(substr($row['notes'], 0, 100))); ?>
                                                        <?php if (strlen($row['notes']) > 100): ?>...<?php endif; ?>
                                                    </div>
                                                    <?php else: ?>
                                                    <span class="text-muted small">No notes</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <?php if ($row['status'] === 'pending'): ?>
                                                        <!-- Approve/Reject Buttons -->
                                                        <a href="#" 
                                                           class="btn-action btn-approve"
                                                           title="Approve"
                                                           data-toggle="modal" 
                                                           data-target="#approveModal"
                                                           data-id="<?php echo $row['id']; ?>"
                                                           data-affiliate="<?php echo htmlspecialchars($row['affiliate_name']); ?>"
                                                           data-offer="<?php echo htmlspecialchars($row['offer_name']); ?>">
                                                            <i class="fas fa-check"></i>
                                                        </a>
                                                        <a href="#" 
                                                           class="btn-action btn-reject"
                                                           title="Reject"
                                                           data-toggle="modal" 
                                                           data-target="#rejectModal"
                                                           data-id="<?php echo $row['id']; ?>"
                                                           data-affiliate="<?php echo htmlspecialchars($row['affiliate_name']); ?>"
                                                           data-offer="<?php echo htmlspecialchars($row['offer_name']); ?>">
                                                            <i class="fas fa-times"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <!-- Edit Button -->
                                                        <a href="edit_offer.php?id=<?php echo $row['id']; ?>" 
                                                           class="btn-action btn-edit"
                                                           title="Edit Assignment">
                                                            <i class="fas fa-edit"></i>
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

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Approve Campaign Access</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <input type="hidden" name="update_status" value="1">
                    <input type="hidden" name="approval_id" id="approveId">
                    <input type="hidden" name="status" value="approved">
                    
                    <div class="form-group">
                        <label>Publisher</label>
                        <input type="text" class="form-control" id="approveAffiliate" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Campaign</label>
                        <input type="text" class="form-control" id="approveOffer" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Approval Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Add notes about this approval..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check mr-2"></i> Approve Access
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reject Campaign Access</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <input type="hidden" name="update_status" value="1">
                    <input type="hidden" name="approval_id" id="rejectId">
                    <input type="hidden" name="status" value="rejected">
                    
                    <div class="form-group">
                        <label>Publisher</label>
                        <input type="text" class="form-control" id="rejectAffiliate" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Campaign</label>
                        <input type="text" class="form-control" id="rejectOffer" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Rejection Reason (Optional)</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Add reason for rejection..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times mr-2"></i> Reject Access
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
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#assignmentsTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [], // Disable initial sort
        responsive: true,
        searching: false, // We use custom search
        info: false,
        paging: false, // We use custom pagination
        language: {
            emptyTable: "No assignments found"
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
        $('.assignment-checkbox').prop('checked', $(this).prop('checked'));
    });
    
    // Update "Select All" checkbox when individual checkboxes change
    $('.assignment-checkbox').change(function() {
        if ($('.assignment-checkbox:checked').length === $('.assignment-checkbox').length) {
            $('#selectAll, #checkAll').prop('checked', true);
        } else {
            $('#selectAll, #checkAll').prop('checked', false);
        }
    });
    
    // Payout type toggle
    $('#payout_type').change(function() {
        if ($(this).val() === 'custom') {
            $('#customPayoutGroup').slideDown();
            $('#custom_payout').focus();
        } else {
            $('#customPayoutGroup').slideUp();
        }
    });
    
    // Show default payout when campaign is selected
    $('#offer_id').change(function() {
        const selectedOption = $(this).find('option:selected');
        const defaultPayout = selectedOption.data('payout');
        
        if (defaultPayout) {
            // Update custom payout placeholder with default value
            $('#custom_payout').attr('placeholder', defaultPayout);
        }
    });
    
    // Modal handlers
    $('#approveModal').on('show.bs.modal', function(event) {
        const button = $(event.relatedTarget);
        const modal = $(this);
        modal.find('#approveId').val(button.data('id'));
        modal.find('#approveAffiliate').val(button.data('affiliate'));
        modal.find('#approveOffer').val(button.data('offer'));
    });
    
    $('#rejectModal').on('show.bs.modal', function(event) {
        const button = $(event.relatedTarget);
        const modal = $(this);
        modal.find('#rejectId').val(button.data('id'));
        modal.find('#rejectAffiliate').val(button.data('affiliate'));
        modal.find('#rejectOffer').val(button.data('offer'));
    });
    
    // Confirm bulk action
    function confirmBulkAction() {
        const action = document.querySelector('select[name="bulk_status"]').value;
        const selectedCount = document.querySelectorAll('.assignment-checkbox:checked').length;
        
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
                text: 'Please select at least one assignment.'
            });
            return false;
        }
        
        let message = '';
        switch(action) {
            case 'approved':
                message = `Are you sure you want to approve ${selectedCount} assignment(s)?`;
                break;
            case 'rejected':
                message = `Are you sure you want to reject ${selectedCount} assignment(s)?`;
                break;
        }
        
        return confirm(message);
    }
    
    // Auto-dismiss alerts after 5 seconds
    $('.alert').delay(5000).fadeOut('slow');
    
    // Search focus
    $('#search').focus();
    
    // Form validation
    $('#assignForm').submit(function(e) {
        const affiliateId = $('#affiliate_id').val();
        const offerId = $('#offer_id').val();
        const payoutType = $('#payout_type').val();
        const customPayout = $('#custom_payout').val();
        
        if (!affiliateId || !offerId) {
            Swal.fire({
                icon: 'error',
                title: 'Missing Information',
                text: 'Please select both Publisher and Campaign.'
            });
            return false;
        }
        
        if (payoutType === 'custom' && (!customPayout || parseFloat(customPayout) <= 0)) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Payout',
                text: 'Please enter a valid custom payout amount.'
            });
            return false;
        }
        
        return true;
    });
});
</script>

</body>
</html>