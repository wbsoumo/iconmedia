<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

// Check for success/error messages
if (isset($_GET['verified'])) {
    $success = 'KYC verified successfully';
} elseif (isset($_GET['rejected'])) {
    $success = 'KYC rejected';
} elseif (isset($_GET['error'])) {
    $error = $_GET['error'];
}

/* ===============================
   FETCH PENDING KYC REQUESTS
================================ */
$search = $_GET['search'] ?? '';
$roleFilter = $_GET['role'] ?? 'all';
$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';

// Build WHERE clause - only users with pending KYC
$where = ["u.kyc_status = 'pending'"];
$params = [];

if ($search) {
    $where[] = '(u.name LIKE :search OR u.email LIKE :search OR u.company LIKE :search)';
    $params['search'] = "%$search%";
}

if ($roleFilter !== 'all') {
    $where[] = 'u.role_id = :role';
    $params['role'] = $roleFilter === 'affiliate' ? 3 : 4;
}

if ($dateFrom && $dateTo) {
    $where[] = 'DATE(u.created_at) BETWEEN :from AND :to';
    $params['from'] = $dateFrom;
    $params['to'] = $dateTo;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Fetch pending KYC users
$stmt = $pdo->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.mobile,
        u.company,
        u.kyc_status,
        u.status as account_status,
        u.created_at,
        u.updated_at,
        r.role_name,
        r.role_id,
        
        -- KYC document info (you'll need to create a kyc_documents table)
        -- For now, we'll use placeholder data
        'document.jpg' as document_file,
        'id_proof' as document_type,
        '123456789' as document_number,
        '2024-01-15' as document_uploaded,
        
        -- Stats
        (SELECT COUNT(*) FROM offers o WHERE o.advertiser_id = u.user_id) as total_offers,
        (SELECT COUNT(*) FROM clicks c WHERE c.affiliate_id = u.user_id) as total_clicks,
        (SELECT COUNT(*) FROM conversions cv WHERE cv.affiliate_id = u.user_id) as total_conversions
        
    FROM users u
    INNER JOIN roles r ON r.role_id = u.role_id
    $whereSql
    ORDER BY u.created_at ASC
");

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$pendingKyc = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH STATISTICS
================================ */
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_pending,
        SUM(CASE WHEN role_id = 3 THEN 1 ELSE 0 END) as pending_affiliates,
        SUM(CASE WHEN role_id = 4 THEN 1 ELSE 0 END) as pending_advertisers,
        
        (SELECT COUNT(*) FROM users WHERE kyc_status = 'verified') as total_verified,
        (SELECT COUNT(*) FROM users WHERE kyc_status = 'rejected') as total_rejected
    FROM users
    WHERE kyc_status = 'pending'
")->fetch(PDO::FETCH_ASSOC);

/* ===============================
   HANDLE KYC ACTIONS
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Single action
    if (isset($_POST['action']) && isset($_POST['user_id'])) {
        $userId = (int)$_POST['user_id'];
        $action = $_POST['action'];
        $remarks = trim($_POST['remarks'] ?? '');
        
        if (in_array($action, ['verify', 'reject'])) {
            $newStatus = ($action === 'verify') ? 'verified' : 'rejected';
            
            // Update user KYC status
            $updateStmt = $pdo->prepare("
                UPDATE users 
                SET kyc_status = :status,
                    updated_at = NOW()
                WHERE user_id = :user_id
            ");
            
            $updateStmt->execute([
                'status' => $newStatus,
                'user_id' => $userId
            ]);
            
            // Here you would also insert into a kyc_log table
            // and possibly send email notification
            
            $success = "KYC " . ($action === 'verify' ? 'verified' : 'rejected') . " successfully!";
            
            // Refresh page
            header("Location: pending_kyc.php?" . $action . "=1");
            exit;
        }
    }
    
    // Bulk actions
    if (isset($_POST['bulk_action'])) {
        $bulkAction = $_POST['bulk_action'];
        $selectedUsers = $_POST['selected_users'] ?? [];
        
        if (empty($selectedUsers)) {
            $error = 'Please select at least one user';
        } else {
            $placeholders = implode(',', array_fill(0, count($selectedUsers), '?'));
            
            if ($bulkAction === 'verify') {
                $sql = "UPDATE users SET kyc_status = 'verified', updated_at = NOW() WHERE user_id IN ($placeholders)";
                $message = 'KYC verified for selected users';
            } elseif ($bulkAction === 'reject') {
                $sql = "UPDATE users SET kyc_status = 'rejected', updated_at = NOW() WHERE user_id IN ($placeholders)";
                $message = 'KYC rejected for selected users';
            } else {
                $error = 'Invalid action';
            }
            
            if (!$error) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($selectedUsers);
                $success = count($selectedUsers) . ' ' . $message;
                
                // Refresh page
                header("Location: pending_kyc.php?success=" . urlencode($success));
                exit;
            }
        }
    }
}

/* ===============================
   FETCH KYC DOCUMENTS (if you have a kyc_documents table)
================================ */
// For now, we'll use placeholder data
$documentTypes = [
    'passport' => 'Passport',
    'driving_license' => 'Driving License',
    'aadhar' => 'Aadhar Card',
    'pan' => 'PAN Card',
    'voter_id' => 'Voter ID'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pending KYC Approvals | Admin Panel | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <!-- Lightbox2 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/css/lightbox.min.css">
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
        
        .btn-outline-success {
            border: 2px solid #28a745;
            color: #28a745;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-outline-success:hover {
            background: #28a745;
            color: white;
        }
        
        .btn-outline-danger {
            border: 2px solid #dc3545;
            color: #dc3545;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-outline-danger:hover {
            background: #dc3545;
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
        }
        
        .affiliates-value {
            color: #38ef7d;
        }
        
        .advertisers-value {
            color: #f7971e;
        }
        
        .verified-value {
            color: #28a745;
        }
        
        .rejected-value {
            color: #dc3545;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .status-verified {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .status-rejected {
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
        
        .role-affiliate {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        
        .role-advertiser {
            background: rgba(0, 123, 255, 0.15);
            color: #007bff;
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
        
        .btn-reject {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .btn-reject:hover {
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
        
        .user-avatar {
            width: 45px;
            height: 45px;
            background: var(--primary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
        }
        
        .document-preview {
            width: 60px;
            height: 60px;
            background: #f8f9fc;
            border: 2px solid #e3e6f0;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #667eea;
            font-size: 24px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .document-preview:hover {
            border-color: #667eea;
            transform: scale(1.05);
        }
        
        .document-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 6px;
        }
        
        .kyc-details {
            background: #f8f9fc;
            border-radius: 8px;
            padding: 12px;
            margin-top: 8px;
            font-size: 13px;
        }
        
        .kyc-details i {
            width: 20px;
            color: #667eea;
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
        
        .document-viewer {
            text-align: center;
            padding: 20px;
            background: #f8f9fc;
            border-radius: 10px;
        }
        
        .document-viewer img {
            max-width: 100%;
            max-height: 400px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
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
                <a href="pending_kyc.php" class="nav-link active">KYC Approvals</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <span class="badge badge-warning navbar-badge"><?php echo $stats['total_pending'] ?? 0; ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header"><?php echo $stats['total_pending'] ?? 0; ?> Pending KYC</span>
                    <div class="dropdown-divider"></div>
                    <a href="pending_kyc.php?role=affiliate" class="dropdown-item">
                        <i class="fas fa-users mr-2 text-success"></i>
                        <?php echo $stats['pending_affiliates'] ?? 0; ?> Affiliates
                    </a>
                    <a href="pending_kyc.php?role=advertiser" class="dropdown-item">
                        <i class="fas fa-briefcase mr-2 text-primary"></i>
                        <?php echo $stats['pending_advertisers'] ?? 0; ?> Advertisers
                    </a>
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
                        <a href="offers.php" class="nav-link">
                            <i class="nav-icon fas fa-bullhorn"></i>
                            <p>Manage Campaigns</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="create_offer.php" class="nav-link">
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

                    <li class="nav-header">KYC</li>
                    <li class="nav-item">
                        <a href="pending_kyc.php" class="nav-link active">
                            <i class="nav-icon fas fa-id-card"></i>
                            <p>KYC Approvals</p>
                            <?php if (($stats['total_pending'] ?? 0) > 0): ?>
                            <span class="badge badge-warning right"><?php echo $stats['total_pending']; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="kyc_history.php" class="nav-link">
                            <i class="nav-icon fas fa-history"></i>
                            <p>KYC History</p>
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
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user-circle"></i>
                            <p>My Profile</p>
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
                        <h1 class="m-0">KYC Verification Requests</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="pending_kyc.php">KYC</a></li>
                            <li class="breadcrumb-item active">Pending Approvals</li>
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
                            <h2>KYC Verification</h2>
                            <p class="mb-0">Review and verify user identification documents. Verify identity documents carefully before approval.</p>
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
                        <div class="metric-value total-value"><?php echo number_format($stats['total_pending'] ?? 0); ?></div>
                        <div class="metric-label">Pending KYC</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value affiliates-value"><?php echo number_format($stats['pending_affiliates'] ?? 0); ?></div>
                        <div class="metric-label">Affiliates</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value advertisers-value"><?php echo number_format($stats['pending_advertisers'] ?? 0); ?></div>
                        <div class="metric-label">Advertisers</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value verified-value"><?php echo number_format($stats['total_verified'] ?? 0); ?></div>
                        <div class="metric-label">Verified</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value rejected-value"><?php echo number_format($stats['total_rejected'] ?? 0); ?></div>
                        <div class="metric-label">Rejected</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-card">
                    <div class="filter-row">
                        <form method="get" class="w-100">
                            <div class="filter-row">
                                <div class="filter-group">
                                    <label for="search"><i class="fas fa-search mr-1"></i> Search</label>
                                    <input type="text" name="search" id="search" class="filter-control" 
                                           placeholder="Name, email, company..." 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label for="role"><i class="fas fa-tag mr-1"></i> User Type</label>
                                    <select name="role" id="role" class="filter-control">
                                        <option value="all" <?php echo $roleFilter === 'all' ? 'selected' : ''; ?>>All Users</option>
                                        <option value="affiliate" <?php echo $roleFilter === 'affiliate' ? 'selected' : ''; ?>>Affiliates</option>
                                        <option value="advertiser" <?php echo $roleFilter === 'advertiser' ? 'selected' : ''; ?>>Advertisers</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label for="from"><i class="fas fa-calendar mr-1"></i> From Date</label>
                                    <input type="date" name="from" id="from" class="filter-control" value="<?php echo $dateFrom; ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label for="to"><i class="fas fa-calendar mr-1"></i> To Date</label>
                                    <input type="date" name="to" id="to" class="filter-control" value="<?php echo $dateTo; ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <button type="submit" class="btn-gradient" style="height: 45px; width: 100%;">
                                        <i class="fas fa-search mr-2"></i> Apply Filters
                                    </button>
                                </div>
                                
                                <div class="filter-group">
                                    <a href="pending_kyc.php" class="btn btn-outline-primary" style="height: 45px; width: 100%; display: flex; align-items: center; justify-content: center;">
                                        <i class="fas fa-redo mr-2"></i> Reset
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Bulk Actions Form -->
                <form method="post" id="bulkForm">
                    <div class="bulk-actions">
                        <div class="form-check select-all-checkbox">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                            <label class="form-check-label" for="selectAll">Select All</label>
                        </div>
                        
                        <select name="bulk_action" class="filter-control" style="width: auto;" required>
                            <option value="">Bulk Actions</option>
                            <option value="verify">Verify Selected</option>
                            <option value="reject">Reject Selected</option>
                        </select>
                        
                        <button type="submit" class="btn btn-outline-primary btn-sm" onclick="return confirmBulkAction()">
                            <i class="fas fa-play mr-1"></i> Apply
                        </button>
                    </div>

                    <!-- KYC Table -->
                    <div class="card-dashboard">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-id-card mr-2"></i> Pending KYC Requests
                            </h3>
                            <div class="card-tools">
                                <span class="badge badge-light">
                                    <?php echo count($pendingKyc); ?> request<?php echo count($pendingKyc) != 1 ? 's' : ''; ?> pending
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($pendingKyc)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fas fa-id-card"></i>
                                </div>
                                <h5>No Pending KYC Requests</h5>
                                <p class="text-muted">All KYC requests have been processed.</p>
                                <a href="dashboard.php" class="btn btn-gradient btn-sm">
                                    <i class="fas fa-arrow-left mr-2"></i> Return to Dashboard
                                </a>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-dashboard" id="kycTable">
                                    <thead>
                                        <tr>
                                            <th style="width: 40px;">
                                                <input type="checkbox" class="form-check-input" id="checkAll">
                                            </th>
                                            <th>User</th>
                                            <th>Type</th>
                                            <th>Documents</th>
                                            <th>Details</th>
                                            <th>Submitted</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pendingKyc as $user): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" 
                                                       name="selected_users[]" 
                                                       value="<?php echo $user['user_id']; ?>" 
                                                       class="form-check-input user-checkbox">
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="user-avatar mr-3">
                                                        <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                                        <?php if ($user['company']): ?>
                                                        <div class="text-muted small">
                                                            <?php echo htmlspecialchars($user['company']); ?>
                                                        </div>
                                                        <?php endif; ?>
                                                        <div class="text-muted small">
                                                            <?php echo htmlspecialchars($user['email']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="role-badge role-<?php echo $user['role_name']; ?>">
                                                    <i class="fas fa-<?php echo $user['role_id'] == 3 ? 'users' : 'briefcase'; ?> mr-1"></i>
                                                    <?php echo ucfirst($user['role_name']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <a href="path/to/documents/<?php echo $user['document_file']; ?>" 
                                                       data-lightbox="kyc-<?php echo $user['user_id']; ?>" 
                                                       data-title="<?php echo htmlspecialchars($user['name']); ?> - <?php echo $user['document_type']; ?>">
                                                        <div class="document-preview">
                                                            <i class="fas fa-file-image"></i>
                                                        </div>
                                                    </a>
                                                    <div class="ml-2">
                                                        <div class="small font-weight-bold"><?php echo $documentTypes[$user['document_type']] ?? 'Document'; ?></div>
                                                        <div class="small text-muted"><?php echo $user['document_number']; ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="kyc-details">
                                                    <div><i class="fas fa-phone mr-2"></i> <?php echo htmlspecialchars($user['mobile'] ?? 'N/A'); ?></div>
                                                    <?php if ($user['role_id'] == 4): ?>
                                                    <div><i class="fas fa-chart-line mr-2"></i> Offers: <?php echo $user['total_offers']; ?></div>
                                                    <?php else: ?>
                                                    <div><i class="fas fa-mouse-pointer mr-2"></i> Clicks: <?php echo $user['total_clicks']; ?></div>
                                                    <div><i class="fas fa-exchange-alt mr-2"></i> Conversions: <?php echo $user['total_conversions']; ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <i class="far fa-calendar-alt mr-2 text-muted"></i>
                                                    <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                                </div>
                                                <div class="small text-muted">
                                                    <?php echo date('h:i A', strtotime($user['created_at'])); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge status-pending">
                                                    <i class="fas fa-clock mr-1"></i> Pending
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <!-- View Details Button triggers modal -->
                                                    <button type="button" 
                                                            class="btn-action btn-view"
                                                            title="View Details"
                                                            data-toggle="modal" 
                                                            data-target="#kycModal<?php echo $user['user_id']; ?>">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    
                                                    <!-- Approve Button -->
                                                    <form method="post" style="display: inline;">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                        <input type="hidden" name="action" value="verify">
                                                        <button type="submit" class="btn-action btn-approve" title="Approve KYC"
                                                                onclick="return confirm('Approve KYC for <?php echo htmlspecialchars($user['name']); ?>?')">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    </form>
                                                    
                                                    <!-- Reject Button triggers modal -->
                                                    <button type="button" 
                                                            class="btn-action btn-reject"
                                                            title="Reject KYC"
                                                            data-toggle="modal" 
                                                            data-target="#rejectModal<?php echo $user['user_id']; ?>">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- KYC Details Modal -->
                                        <div class="modal fade" id="kycModal<?php echo $user['user_id']; ?>" tabindex="-1" role="dialog">
                                            <div class="modal-dialog modal-lg" role="document">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">
                                                            <i class="fas fa-id-card mr-2"></i> KYC Details - <?php echo htmlspecialchars($user['name']); ?>
                                                        </h5>
                                                        <button type="button" class="close" data-dismiss="modal">
                                                            <span>&times;</span>
                                                        </button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="row">
                                                            <div class="col-md-6">
                                                                <h6>Personal Information</h6>
                                                                <table class="table table-sm">
                                                                    <tr>
                                                                        <th>Name:</th>
                                                                        <td><?php echo htmlspecialchars($user['name']); ?></td>
                                                                    </tr>
                                                                    <tr>
                                                                        <th>Email:</th>
                                                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                                                    </tr>
                                                                    <tr>
                                                                        <th>Mobile:</th>
                                                                        <td><?php echo htmlspecialchars($user['mobile'] ?? 'N/A'); ?></td>
                                                                    </tr>
                                                                    <tr>
                                                                        <th>Company:</th>
                                                                        <td><?php echo htmlspecialchars($user['company'] ?? 'N/A'); ?></td>
                                                                    </tr>
                                                                </table>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <h6>Document Information</h6>
                                                                <table class="table table-sm">
                                                                    <tr>
                                                                        <th>Document Type:</th>
                                                                        <td><?php echo $documentTypes[$user['document_type']] ?? 'Document'; ?></td>
                                                                    </tr>
                                                                    <tr>
                                                                        <th>Document Number:</th>
                                                                        <td><?php echo htmlspecialchars($user['document_number']); ?></td>
                                                                    </tr>
                                                                    <tr>
                                                                        <th>Uploaded:</th>
                                                                        <td><?php echo date('M d, Y', strtotime($user['document_uploaded'])); ?></td>
                                                                    </tr>
                                                                </table>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="document-viewer mt-3">
                                                            <h6>Document Preview</h6>
                                                            <img src="path/to/documents/<?php echo $user['document_file']; ?>" 
                                                                 alt="KYC Document" class="img-fluid">
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                                        <form method="post" style="display: inline;">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                            <input type="hidden" name="action" value="reject">
                                                            <button type="submit" class="btn btn-danger" 
                                                                    onclick="return confirm('Reject KYC for <?php echo htmlspecialchars($user['name']); ?>?')">
                                                                <i class="fas fa-times mr-2"></i> Reject
                                                            </button>
                                                        </form>
                                                        <form method="post" style="display: inline;">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                            <input type="hidden" name="action" value="verify">
                                                            <button type="submit" class="btn btn-success">
                                                                <i class="fas fa-check mr-2"></i> Approve KYC
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Reject Modal with Remarks -->
                                        <div class="modal fade" id="rejectModal<?php echo $user['user_id']; ?>" tabindex="-1" role="dialog">
                                            <div class="modal-dialog" role="document">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">
                                                            <i class="fas fa-times-circle mr-2 text-danger"></i> Reject KYC
                                                        </h5>
                                                        <button type="button" class="close" data-dismiss="modal">
                                                            <span>&times;</span>
                                                        </button>
                                                    </div>
                                                    <form method="post">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                            <input type="hidden" name="action" value="reject">
                                                            
                                                            <p>Are you sure you want to reject KYC for <strong><?php echo htmlspecialchars($user['name']); ?></strong>?</p>
                                                            
                                                            <div class="form-group">
                                                                <label for="remarks_<?php echo $user['user_id']; ?>">Rejection Reason (Optional)</label>
                                                                <textarea class="form-control" id="remarks_<?php echo $user['user_id']; ?>" 
                                                                          name="remarks" rows="3" 
                                                                          placeholder="Provide reason for rejection..."></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-danger">
                                                                <i class="fas fa-times mr-2"></i> Confirm Reject
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
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
<!-- Lightbox2 -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/js/lightbox.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#kycTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [], // Disable initial sort
        responsive: true,
        searching: false, // We use custom search
        info: true,
        paging: true,
        language: {
            emptyTable: "No pending KYC requests found"
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
    
    // Refresh page
    $('#refreshPage').click(function() {
        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Refreshing...');
        
        setTimeout(() => {
            window.location.reload();
        }, 1000);
    });
    
    // Select all functionality
    $('#selectAll, #checkAll').click(function() {
        $('.user-checkbox').prop('checked', $(this).prop('checked'));
    });
    
    // Update "Select All" checkbox when individual checkboxes change
    $('.user-checkbox').change(function() {
        if ($('.user-checkbox:checked').length === $('.user-checkbox').length) {
            $('#selectAll, #checkAll').prop('checked', true);
        } else {
            $('#selectAll, #checkAll').prop('checked', false);
        }
    });
    
    // Confirm bulk action
    window.confirmBulkAction = function() {
        const action = document.querySelector('select[name="bulk_action"]').value;
        const selectedCount = document.querySelectorAll('.user-checkbox:checked').length;
        
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
                text: 'Please select at least one user.'
            });
            return false;
        }
        
        let message = action === 'verify' ? 
            `Verify KYC for ${selectedCount} user(s)?` :
            `Reject KYC for ${selectedCount} user(s)?`;
        
        return confirm(message);
    };
    
    // Auto-dismiss alerts after 5 seconds
    $('.alert').delay(5000).fadeOut('slow');
    
    // Search focus
    $('#search').focus();
    
    // Initialize Lightbox
    lightbox.option({
        'resizeDuration': 200,
        'wrapAround': true,
        'albumLabel': 'Document %1 of %2'
    });
    
    // Date validation
    $('#from, #to').change(function() {
        const fromDate = new Date($('#from').val());
        const toDate = new Date($('#to').val());
        
        if (fromDate > toDate) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Date Range',
                text: 'From date cannot be after To date'
            });
            $('#from').val($('#to').val());
        }
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