<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['admin', 'manager']);

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

/* ===============================
   FETCH ALL OFFERS
================================ */
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? 'all';
$advertiser = $_GET['advertiser'] ?? 'all';
$category = $_GET['category'] ?? 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;

// Build WHERE clause
$where = ['1=1'];
$params = [];

if ($search) {
    $where[] = '(o.offer_name LIKE :search OR o.offer_id LIKE :search LIKE :search)';
    $params['search'] = "%$search%";
}

if ($status !== 'all') {
    $where[] = 'o.status = :status';
    $params['status'] = $status;
}

if ($advertiser !== 'all') {
    $where[] = 'o.advertiser_id = :advertiser';
    $params['advertiser'] = (int)$advertiser;
}

if ($category !== 'all') {
    $where[] = 'o.category = :category';
    $params['category'] = $category;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM offers o $whereSql");
$countStmt->execute($params);
$totalOffers = $countStmt->fetchColumn();
$totalPages = ceil($totalOffers / $perPage);
$offset = ($page - 1) * $perPage;

// Fetch offers with stats
$stmt = $pdo->prepare("
    SELECT 
        o.offer_id,
        o.offer_name,
        o.payout,
        o.offer_description,
        o.payout_type,
        o.conversion_cap,
        o.daily_cap,
        o.category,
        o.country,
        o.device_type,
        o.status,
        o.created_at,
        o.updated_at,
        o.advertiser_id,
        u.email AS advertiser_email,
        u.name AS advertiser_name,
        
        -- Stats
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        AVG(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE NULL END) AS avg_revenue
        
    FROM offers o
    INNER JOIN users u ON u.user_id = o.advertiser_id
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    
    $whereSql
    GROUP BY o.offer_id
    ORDER BY o.created_at DESC
    LIMIT :offset, :per_page
");

$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $perPage, PDO::PARAM_INT);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH ADVERTISERS FOR FILTER
================================ */
$advertisers = $pdo->query("SELECT user_id, email, name FROM users WHERE role_id = 2 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH CATEGORIES
================================ */
$categories = $pdo->query("SELECT DISTINCT category FROM offers WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN, 0);

/* ===============================
   SUMMARY STATISTICS
================================ */
$summaryStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_offers,
        SUM(status = 'active') as active_offers,
        SUM(status = 'pending') as pending_offers,
        SUM(status = 'paused') as paused_offers,
        SUM(status = 'rejected') as rejected_offers,
        AVG(payout) as avg_payout,
        SUM(CASE WHEN conversion_cap IS NOT NULL THEN 1 ELSE 0 END) as capped_offers
    FROM offers
");
$summaryStmt->execute();
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

/* ===============================
   BULK ACTIONS
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selectedOffers = $_POST['selected_offers'] ?? [];
    
    if (empty($selectedOffers)) {
        $error = 'Please select at least one offer';
    } else {
        $placeholders = implode(',', array_fill(0, count($selectedOffers), '?'));
        
        switch ($action) {
            case 'activate':
                $sql = "UPDATE offers SET status = 'active', updated_at = NOW() WHERE offer_id IN ($placeholders)";
                $message = 'selected offers have been activated';
                break;
                
            case 'pause':
                $sql = "UPDATE offers SET status = 'paused', updated_at = NOW() WHERE offer_id IN ($placeholders)";
                $message = 'selected offers have been paused';
                break;
                
            case 'reject':
                $sql = "UPDATE offers SET status = 'rejected', updated_at = NOW() WHERE offer_id IN ($placeholders)";
                $message = 'selected offers have been rejected';
                break;
                
            case 'approve':
                $sql = "UPDATE offers SET status = 'approved', updated_at = NOW() WHERE offer_id IN ($placeholders)";
                $message = 'selected offers have been approved';
                break;
                
            case 'delete':
                $sql = "DELETE FROM offers WHERE offer_id IN ($placeholders)";
                $message = 'selected offers have been deleted';
                break;
                
            default:
                $error = 'Invalid action selected';
                break;
        }
        
        if (!$error) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($selectedOffers);
            $success = count($selectedOffers) . ' ' . $message;
            
            // Refresh data
            header("Location: " . $_SERVER['PHP_SELF'] . "?success=" . urlencode($success));
            exit;
        }
    }
}

/* ===============================
   OFFER ACTIONS
================================ */
if (isset($_GET['offer_action'])) {
    $offerId = (int)$_GET['id'];
    $action = $_GET['offer_action'];
    
    $validActions = ['approve', 'pause', 'activate', 'reject', 'delete'];
    
    if (in_array($action, $validActions)) {
        switch ($action) {
            case 'delete':
                $sql = "DELETE FROM offers WHERE offer_id = ?";
                break;
            default:
                $sql = "UPDATE offers SET status = ?, updated_at = NOW() WHERE offer_id = ?";
                break;
        }
        
        $stmt = $pdo->prepare($sql);
        
        if ($action === 'delete') {
            $stmt->execute([$offerId]);
            $success = 'Offer deleted successfully';
        } else {
            $stmt->execute([$action, $offerId]);
            $success = "Offer status updated to " . ucfirst($action);
        }
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=" . urlencode($success));
        exit;
    }
}

/* ===============================
   EXPORT OFFERS
================================ */
if (isset($_GET['export'])) {
    $exportStmt = $pdo->prepare("
        SELECT 
            o.offer_id,
            o.offer_name,
            o.payout,
            o.payout_type,
            o.category,
            o.country,
            o.device_type,
            o.status,
            o.conversion_cap,
            o.daily_cap,
            o.created_at,
            u.email as advertiser_email,
            u.name as advertiser_name,
            COUNT(DISTINCT cv.conversion_id) as total_conversions,
            SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END) as total_revenue
        FROM offers o
        INNER JOIN users u ON u.user_id = o.advertiser_id
        LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
        GROUP BY o.offer_id
        ORDER BY o.created_at DESC
    ");
    $exportStmt->execute();
    $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="offers-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'ID', 'Offer Name', 'offer_description', 'Payout', 'Payout Type', 'Category',
        'Country', 'Device', 'Status', 'Conversion Cap', 'Daily Cap',
        'Advertiser Email', 'Advertiser Name', 'Total Conversions', 
        'Total Revenue', 'Created At'
    ]);
    
    foreach ($exportData as $row) {
        fputcsv($output, [
            $row['offer_id'],
            $row['offer_name'],
            $row['offer_description'],
            $row['payout'],
            $row['payout_type'],
            $row['category'],
            $row['country'],
            $row['device_type'],
            $row['status'],
            $row['conversion_cap'],
            $row['daily_cap'],
            $row['advertiser_email'],
            $row['advertiser_name'],
            $row['total_conversions'],
            $row['total_revenue'],
            $row['created_at']
        ]);
    }
    
    fclose($output);
    exit;
}

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
    <title>Manage Offers | Admin Panel | GVS Icon Media</title>
    
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
        /* Reuse all the CSS styles from the publishers page */
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
        
        .paused-value {
            color: #6c757d;
            font-weight: 700;
        }
        
        .rejected-value {
            color: #dc3545;
            font-weight: 700;
        }
        
        .avg-value {
            color: #20c997;
            font-weight: 700;
        }
        
        .capped-value {
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
        
        .status-paused {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.2);
        }
        
        .status-rejected {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .status-approved {
            background: rgba(0, 123, 255, 0.15);
            color: #007bff;
            border: 1px solid rgba(0, 123, 255, 0.2);
        }
        
        .category-badge {
            background: rgba(102, 16, 242, 0.1);
            color: #6610f2;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .country-badge {
            background: rgba(32, 201, 151, 0.1);
            color: #20c997;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .device-badge {
            background: rgba(253, 126, 20, 0.1);
            color: #fd7e14;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
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
        
        .cap-badge {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 600;
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
        
        .btn-danger {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .btn-danger:hover {
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
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
        
        .offer-card {
            background: white;
            border: 1px solid #e3e6f0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .offer-card:hover {
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
        
        .stats-badge {
            background: #f8f9fa;
            color: #6c757d;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .truncate-text {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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
                <a href="offers.php" class="nav-link active">Manage Offers</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <?php if ($summary['pending_offers'] > 0): ?>
                    <span class="badge badge-warning navbar-badge">
                        <?php echo $summary['pending_offers'] ?? 0; ?>
                    </span>
                    <?php endif; ?>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header">
                        <?php echo $summary['pending_offers'] ?? 0; ?> Pending Offers
                    </span>
                    <div class="dropdown-divider"></div>
                    <a href="offers.php?status=pending" class="dropdown-item">
                        <i class="fas fa-bullhorn mr-2 text-warning"></i>
                        <?php echo $summary['pending_offers'] ?? 0; ?> Offers Pending Approval
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
                        <a href="campaigns.php" class="nav-link active">
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
                        <h1 class="m-0">Manage Offers</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="offers.php">Offers</a></li>
                            <li class="breadcrumb-item active">Manage Offers</li>
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
                    <h2 class="mb-0">Offer Management</h2>
                    <div class="action-buttons-group">
                        <a href="?export=csv" class="btn btn-outline-primary">
                            <i class="fas fa-download mr-2"></i> Export CSV
                        </a>
                        <a href="create_offer.php" class="btn btn-gradient">
                            <i class="fas fa-plus mr-2"></i> Create New Offer
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
                        <div class="metric-value total-value"><?php echo number_format($summary['total_offers'] ?? 0); ?></div>
                        <div class="metric-label">Total Offers</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value active-value"><?php echo number_format($summary['active_offers'] ?? 0); ?></div>
                        <div class="metric-label">Active</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value pending-value"><?php echo number_format($summary['pending_offers'] ?? 0); ?></div>
                        <div class="metric-label">Pending</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value paused-value"><?php echo number_format($summary['paused_offers'] ?? 0); ?></div>
                        <div class="metric-label">Paused</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value avg-value">$<?php echo number_format($summary['avg_payout'] ?? 0, 2); ?></div>
                        <div class="metric-label">Avg Payout</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value capped-value"><?php echo number_format($summary['capped_offers'] ?? 0); ?></div>
                        <div class="metric-label">Capped Offers</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card-dashboard">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-filter mr-2"></i> Filter Offers
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="get" class="filter-row">
                            <div class="filter-group">
                                <label for="search"><i class="fas fa-search mr-1"></i> Search</label>
                                <input type="text" name="search" id="search" class="filter-control" 
                                       placeholder="Search by offer name, ID..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label for="status"><i class="fas fa-toggle-on mr-1"></i> Status</label>
                                <select name="status" id="status" class="filter-control">
                                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="paused" <?php echo $status === 'paused' ? 'selected' : ''; ?>>Paused</option>
                                    <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="advertiser"><i class="fas fa-briefcase mr-1"></i> Advertiser</label>
                                <select name="advertiser" id="advertiser" class="filter-control">
                                    <option value="all" <?php echo $advertiser === 'all' ? 'selected' : ''; ?>>All Advertisers</option>
                                    <?php foreach ($advertisers as $adv): ?>
                                    <option value="<?php echo $adv['user_id']; ?>" <?php echo $advertiser == $adv['user_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($adv['name'] . ' (' . $adv['email'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="category"><i class="fas fa-tag mr-1"></i> Category</label>
                                <select name="category" id="category" class="filter-control">
                                    <option value="all" <?php echo $category === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat); ?>
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
                                <a href="offers.php" class="btn btn-outline-primary" style="height: 45px; width: 100%; display: flex; align-items: center; justify-content: center;">
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
                            <option value="approve">Approve Selected</option>
                            <option value="activate">Activate Selected</option>
                            <option value="pause">Pause Selected</option>
                            <option value="reject">Reject Selected</option>
                            <option value="delete">Delete Selected</option>
                        </select>
                        
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-play mr-1"></i> Apply
                        </button>
                        
                        <span class="text-muted ml-2">
                            <?php echo $totalOffers; ?> offer<?php echo $totalOffers != 1 ? 's' : ''; ?> found
                        </span>
                    </div>

                    <!-- Offers Table -->
                    <div class="card-dashboard">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-bullhorn mr-2"></i> Offers List
                            </h3>
                            <div class="card-tools">
                                <span class="badge badge-light">
                                    Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($offers)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-bullhorn"></i>
                                    </div>
                                    <h5>No Offers Found</h5>
                                    <p class="text-muted">No offers match your search criteria.</p>
                                    <a href="offers.php" class="btn btn-gradient btn-sm">
                                        <i class="fas fa-redo mr-2"></i> Reset Filters
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-dashboard" id="offersTable">
                                        <thead>
                                            <tr>
                                                <th style="width: 40px;">
                                                    <input type="checkbox" class="form-check-input" id="checkAll">
                                                </th>
                                                <th>Offer Details</th>
                                                <th>Payout</th>
                                                <th>Targeting</th>
                                                <th>Status</th>
                                                <th>Advertiser</th>
                                                <th>Stats</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($offers as $offer): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" 
                                                           name="selected_offers[]" 
                                                           value="<?php echo $offer['offer_id']; ?>" 
                                                           class="form-check-input offer-checkbox">
                                                </td>
                                                <td>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($offer['offer_name']); ?></strong>
                                                        <div class="text-muted small">
                                                            ID: #<?php echo $offer['offer_id']; ?>
                                                            &nbsp;•&nbsp; Created: <?php echo date('M d, Y', strtotime($offer['created_at'])); ?>
                                                        </div>
                                                        <?php if ($offer['offer_description']): ?>
                                                        <div class="text-muted small truncate-text">
                                                            <?php echo htmlspecialchars($offer['offer_description']); ?>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="payout-badge">
                                                        $<?php echo number_format($offer['payout'], 2); ?>
                                                        <small class="text-muted ml-1">
                                                            <?php echo $offer['payout_type'] ?? 'CPI'; ?>
                                                        </small>
                                                    </div>
                                                    <div class="small text-muted mt-1">
                                                        <?php if ($offer['conversion_cap']): ?>
                                                            <span class="cap-badge">
                                                                <i class="fas fa-chart-line mr-1"></i> Cap: <?php echo $offer['conversion_cap']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($offer['daily_cap']): ?>
                                                            <span class="cap-badge ml-1">
                                                                <i class="fas fa-calendar-day mr-1"></i> Daily: <?php echo $offer['daily_cap']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-1 mb-1">
                                                        <?php if ($offer['category']): ?>
                                                        <span class="category-badge">
                                                            <?php echo htmlspecialchars($offer['category']); ?>
                                                        </span>
                                                        <?php endif; ?>
                                                        <?php if ($offer['country']): ?>
                                                        <span class="country-badge">
                                                            <i class="fas fa-globe mr-1"></i>
                                                            <?php echo htmlspecialchars($offer['country']); ?>
                                                        </span>
                                                        <?php endif; ?>
                                                        <?php if ($offer['device_type']): ?>
                                                        <span class="device-badge">
                                                            <i class="fas fa-mobile-alt mr-1"></i>
                                                            <?php echo htmlspecialchars($offer['device_type']); ?>
                                                        </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $offer['status']; ?>">
                                                        <?php echo ucfirst($offer['status']); ?>
                                                    </span>
                                                    <div class="small mt-1">
                                                        <?php if ($offer['status'] !== 'active'): ?>
                                                            <a href="?id=<?php echo $offer['offer_id']; ?>&offer_action=activate" 
                                                               class="text-success mr-2" 
                                                               onclick="return confirm('Activate this offer?')">
                                                                <i class="fas fa-play"></i> Activate
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($offer['status'] === 'active'): ?>
                                                            <a href="?id=<?php echo $offer['offer_id']; ?>&offer_action=pause" 
                                                               class="text-warning" 
                                                               onclick="return confirm('Pause this offer?')">
                                                                <i class="fas fa-pause"></i> Pause
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mb-1">
                                                        <i class="fas fa-user-tie mr-1 text-muted"></i>
                                                        <?php echo htmlspecialchars($offer['advertiser_name']); ?>
                                                    </div>
                                                    <div class="small text-muted">
                                                        <i class="fas fa-envelope mr-1"></i>
                                                        <?php echo htmlspecialchars($offer['advertiser_email']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <div class="mb-1">
                                                            <span class="text-primary">
                                                                <i class="fas fa-mouse-pointer mr-1"></i>
                                                                <?php echo number_format($offer['total_clicks']); ?>
                                                            </span>
                                                            <small class="text-muted">clicks</small>
                                                        </div>
                                                        <div class="mb-1">
                                                            <span class="text-success">
                                                                <i class="fas fa-exchange-alt mr-1"></i>
                                                                <?php echo number_format($offer['total_conversions']); ?>
                                                            </span>
                                                            <small class="text-muted">conv</small>
                                                        </div>
                                                        <div>
                                                            <span class="text-warning">
                                                                <i class="fas fa-percentage mr-1"></i>
                                                                <?php echo $offer['total_clicks'] > 0 ? number_format(($offer['total_conversions'] / $offer['total_clicks']) * 100, 2) : '0'; ?>%
                                                            </span>
                                                            <small class="text-muted">CR</small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <!-- Quick Status Actions -->
                                                        <?php if ($offer['status'] === 'pending'): ?>
                                                        <a href="?id=<?php echo $offer['offer_id']; ?>&offer_action=approve" 
                                                           class="btn-action btn-edit"
                                                           title="Approve Offer"
                                                           onclick="return confirm('Approve this offer?')">
                                                            <i class="fas fa-check"></i>
                                                        </a>
                                                        <a href="?id=<?php echo $offer['offer_id']; ?>&offer_action=reject" 
                                                           class="btn-action btn-danger"
                                                           title="Reject Offer"
                                                           onclick="return confirm('Reject this offer?')">
                                                            <i class="fas fa-times"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($offer['status'] === 'approved' || $offer['status'] === 'active'): ?>
                                                        <a href="?id=<?php echo $offer['offer_id']; ?>&offer_action=pause" 
                                                           class="btn-action btn-toggle"
                                                           title="Pause Offer"
                                                           onclick="return confirm('Pause this offer?')">
                                                            <i class="fas fa-pause"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($offer['status'] === 'paused'): ?>
                                                        <a href="?id=<?php echo $offer['offer_id']; ?>&offer_action=activate" 
                                                           class="btn-action btn-edit"
                                                           title="Activate Offer"
                                                           onclick="return confirm('Activate this offer?')">
                                                            <i class="fas fa-play"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <!-- Edit -->
                                                        <a href="edit_offer.php?id=<?php echo $offer['offer_id']; ?>" 
                                                           class="btn-action btn-edit"
                                                           title="Edit Offer">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        
                                                        <!-- View Details -->
                                                        <a href="offer_details.php?id=<?php echo $offer['offer_id']; ?>" 
                                                           class="btn-action btn-view"
                                                           title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        
                                                        <!-- Delete -->
                                                        <a href="?id=<?php echo $offer['offer_id']; ?>&offer_action=delete" 
                                                           class="btn-action btn-danger"
                                                           title="Delete Offer"
                                                           onclick="return confirm('Are you sure you want to delete this offer?')">
                                                            <i class="fas fa-trash"></i>
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
    $('#offersTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [], // Disable initial sort
        responsive: true,
        searching: false, // We use custom search
        info: false,
        paging: false, // We use custom pagination
        language: {
            emptyTable: "No offers found"
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
        $('.offer-checkbox').prop('checked', $(this).prop('checked'));
    });
    
    // Update "Select All" checkbox when individual checkboxes change
    $('.offer-checkbox').change(function() {
        if ($('.offer-checkbox:checked').length === $('.offer-checkbox').length) {
            $('#selectAll, #checkAll').prop('checked', true);
        } else {
            $('#selectAll, #checkAll').prop('checked', false);
        }
    });
    
    // Confirm bulk action
    function confirmBulkAction() {
        const action = document.querySelector('select[name="bulk_action"]').value;
        const selectedCount = document.querySelectorAll('.offer-checkbox:checked').length;
        
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
                text: 'Please select at least one offer.'
            });
            return false;
        }
        
        let message = '';
        switch(action) {
            case 'approve':
                message = `Are you sure you want to approve ${selectedCount} offer(s)?`;
                break;
            case 'activate':
                message = `Are you sure you want to activate ${selectedCount} offer(s)?`;
                break;
            case 'pause':
                message = `Are you sure you want to pause ${selectedCount} offer(s)?`;
                break;
            case 'reject':
                message = `Are you sure you want to reject ${selectedCount} offer(s)?`;
                break;
            case 'delete':
                message = `Are you sure you want to delete ${selectedCount} offer(s)? This action cannot be undone.`;
                break;
        }
        
        return confirm(message);
    }
    
    // Auto-dismiss alerts after 5 seconds
    $('.alert').delay(5000).fadeOut('slow');
    
    // Search focus
    $('#search').focus();
});
</script>

</body>
</html>