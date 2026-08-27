<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

// Check for success/error messages from other actions
if (isset($_GET['approved'])) {
    $success = 'Advertiser approved successfully';
} elseif (isset($_GET['blocked'])) {
    $success = 'Advertiser blocked';
} elseif (isset($_GET['activated'])) {
    $success = 'Advertiser activated';
} elseif (isset($_GET['deleted'])) {
    $success = 'Advertiser deleted successfully';
} elseif (isset($_GET['kyc_verified'])) {
    $success = 'KYC verified successfully';
} elseif (isset($_GET['error'])) {
    $error = $_GET['error'];
}

/* ===============================
   FETCH ALL ADVERTISERS WITH FILTERS (role_id = 4)
================================ */
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? 'all';
$kycFilter = $_GET['kyc'] ?? 'all';
$sortBy = $_GET['sort'] ?? 'recent';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;

// Build WHERE clause
$where = ['u.role_id = 4']; // Advertiser role
$params = [];

if ($search) {
    $where[] = '(u.name LIKE :search OR u.email LIKE :search OR u.company LIKE :search OR u.mobile LIKE :search)';
    $params['search'] = "%$search%";
}

if ($statusFilter !== 'all') {
    $where[] = 'u.status = :status';
    $params['status'] = $statusFilter;
}

if ($kycFilter !== 'all') {
    if ($kycFilter === 'none') {
        $where[] = '(u.kyc_status IS NULL OR u.kyc_status = "pending")';
    } else {
        $where[] = 'u.kyc_status = :kyc';
        $params['kyc'] = $kycFilter;
    }
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Determine sort order
$orderBy = 'u.created_at DESC';
switch ($sortBy) {
    case 'recent':
        $orderBy = 'u.created_at DESC';
        break;
    case 'oldest':
        $orderBy = 'u.created_at ASC';
        break;
    case 'name_asc':
        $orderBy = 'u.name ASC';
        break;
    case 'name_desc':
        $orderBy = 'u.name DESC';
        break;
    case 'balance_high':
        $orderBy = 'u.balance DESC';
        break;
    case 'balance_low':
        $orderBy = 'u.balance ASC';
        break;
}

// Get total count
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM users u
    $whereSql
");
$countStmt->execute($params);
$totalAdvertisers = $countStmt->fetchColumn();
$totalPages = ceil($totalAdvertisers / $perPage);
$offset = ($page - 1) * $perPage;

// Fetch advertisers with stats
$sql = "
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.mobile,
        u.company,
        u.balance,
        u.status,
        u.kyc_status,
        u.payout_enabled,
        u.account_manager_id,
        u.created_at,
        u.last_login_at,
        u.last_login_ip,
        u.notification_email,
        u.notification_sms,
        u.telegram_id,
        u.teams_id,
        
        -- Account manager info
        am.name AS account_manager_name,
        am.email AS account_manager_email,
        
        -- Stats
        COUNT(DISTINCT o.offer_id) AS total_offers,
        COUNT(DISTINCT CASE WHEN o.status = 'active' THEN o.offer_id END) AS active_offers,
        COUNT(DISTINCT CASE WHEN o.status = 'pending' THEN o.offer_id END) AS pending_offers,
        COUNT(DISTINCT CASE WHEN o.status = 'approved' THEN o.offer_id END) AS approved_offers,
        
        -- Financial stats
        SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END) AS total_revenue,
        SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END) AS total_payout,
        SUM(CASE WHEN cv.status = 'approved' THEN (cv.revenue - cv.payout) ELSE 0 END) AS total_profit,
        
        -- Conversion stats
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        COUNT(DISTINCT CASE WHEN cv.status = 'approved' THEN cv.conversion_id END) AS approved_conversions,
        COUNT(DISTINCT CASE WHEN cv.status = 'pending' THEN cv.conversion_id END) AS pending_conversions,
        
        -- Click stats
        COUNT(DISTINCT c.click_id) AS total_clicks
        
    FROM users u
    LEFT JOIN users am ON am.user_id = u.account_manager_id AND am.role_id = 2
    LEFT JOIN offers o ON o.advertiser_id = u.user_id
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    $whereSql
    GROUP BY u.user_id
    ORDER BY $orderBy
    LIMIT :offset, :per_page
";

$stmt = $pdo->prepare($sql);

// Bind parameters
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $perPage, PDO::PARAM_INT);

$stmt->execute();
$advertisers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH ACCOUNT MANAGERS FOR DROPDOWN
================================ */
$accountManagers = $pdo->query("
    SELECT user_id, name, email 
    FROM users 
    WHERE role_id = 2 AND status = 'active'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   SUMMARY STATISTICS
================================ */
$summaryStmt = $pdo->query("
    SELECT 
        COUNT(*) as total_advertisers,
        SUM(status = 'active') as active_advertisers,
        SUM(status = 'pending') as pending_advertisers,
        SUM(status = 'blocked') as blocked_advertisers,
        
        SUM(kyc_status = 'verified') as kyc_verified,
        SUM(kyc_status = 'pending') as kyc_pending,
        SUM(kyc_status = 'rejected') as kyc_rejected,
        
        SUM(balance) as total_balance,
        
        (SELECT COUNT(*) FROM offers) as total_offers,
        (SELECT SUM(revenue) FROM conversions WHERE status = 'approved') as total_revenue,
        (SELECT SUM(payout) FROM conversions WHERE status = 'approved') as total_payout
    FROM users
    WHERE role_id = 4
");
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

/* ===============================
   BULK ACTIONS
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selectedAdvertisers = $_POST['selected_advertisers'] ?? [];
    
    if (empty($selectedAdvertisers)) {
        $error = 'Please select at least one advertiser';
    } else {
        $placeholders = implode(',', array_fill(0, count($selectedAdvertisers), '?'));
        
        switch ($action) {
            case 'activate':
                $sql = "UPDATE users SET status = 'active', updated_at = NOW() WHERE user_id IN ($placeholders) AND role_id = 4";
                $message = 'selected advertisers have been activated';
                break;
            case 'block':
                $sql = "UPDATE users SET status = 'blocked', updated_at = NOW() WHERE user_id IN ($placeholders) AND role_id = 4";
                $message = 'selected advertisers have been blocked';
                break;
            case 'verify_kyc':
                $sql = "UPDATE users SET kyc_status = 'verified' WHERE user_id IN ($placeholders) AND role_id = 4";
                $message = 'KYC verified for selected advertisers';
                break;
            case 'enable_payout':
                $sql = "UPDATE users SET payout_enabled = 1 WHERE user_id IN ($placeholders) AND role_id = 4";
                $message = 'Payout enabled for selected advertisers';
                break;
            case 'disable_payout':
                $sql = "UPDATE users SET payout_enabled = 0 WHERE user_id IN ($placeholders) AND role_id = 4";
                $message = 'Payout disabled for selected advertisers';
                break;
            default:
                $error = 'Invalid action selected';
                break;
        }
        
        if (!$error) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($selectedAdvertisers);
            $success = count($selectedAdvertisers) . ' ' . $message;
            
            // Refresh page
            header("Location: advertisers.php?success=" . urlencode($success));
            exit;
        }
    }
}

/* ===============================
   SINGLE ADVERTISER ACTIONS
================================ */
if (isset($_GET['action']) && isset($_GET['id'])) {
    $advertiserId = (int)$_GET['id'];
    $action = $_GET['action'];
    
    $validActions = ['activate', 'block', 'verify_kyc', 'enable_payout', 'disable_payout', 'delete'];
    
    if (in_array($action, $validActions)) {
        if ($action === 'delete') {
            $sql = "DELETE FROM users WHERE user_id = ? AND role_id = 4";
        } elseif ($action === 'verify_kyc') {
            $sql = "UPDATE users SET kyc_status = 'verified' WHERE user_id = ? AND role_id = 4";
        } elseif ($action === 'enable_payout') {
            $sql = "UPDATE users SET payout_enabled = 1 WHERE user_id = ? AND role_id = 4";
        } elseif ($action === 'disable_payout') {
            $sql = "UPDATE users SET payout_enabled = 0 WHERE user_id = ? AND role_id = 4";
        } else {
            $status = ($action === 'activate') ? 'active' : 'blocked';
            $sql = "UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ? AND role_id = 4";
        }
        
        $stmt = $pdo->prepare($sql);
        
        if ($action === 'delete') {
            $stmt->execute([$advertiserId]);
            header("Location: advertisers.php?deleted=1");
        } elseif ($action === 'verify_kyc') {
            $stmt->execute([$advertiserId]);
            header("Location: advertisers.php?kyc_verified=1");
        } elseif (in_array($action, ['enable_payout', 'disable_payout'])) {
            $stmt->execute([$advertiserId]);
            header("Location: advertisers.php?updated=1");
        } else {
            $stmt->execute([$status, $advertiserId]);
            header("Location: advertisers.php?" . $action . "=1");
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Advertisers | Admin Panel | GVS Icon Media</title>
    
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
        }
        
        .active-value {
            color: #28a745;
        }
        
        .pending-value {
            color: #ffc107;
        }
        
        .blocked-value {
            color: #dc3545;
        }
        
        .kyc-value {
            color: #20c997;
        }
        
        .balance-value {
            color: #6610f2;
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
        }
        
        .kyc-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }
        
        .kyc-rejected {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }
        
        .kyc-none {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
        }
        
        .payout-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .payout-enabled {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }
        
        .payout-disabled {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
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
        
        .btn-activate {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .btn-activate:hover {
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
        
        .btn-verify {
            background: rgba(32, 201, 151, 0.1);
            color: #20c997;
            border: 1px solid rgba(32, 201, 151, 0.2);
        }
        
        .btn-verify:hover {
            background: rgba(32, 201, 151, 0.2);
            color: #20c997;
        }
        
        .btn-payout {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .btn-payout:hover {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .btn-edit {
            background: rgba(78, 115, 223, 0.1);
            color: #4e73df;
            border: 1px solid rgba(78, 115, 223, 0.2);
        }
        
        .btn-edit:hover {
            background: rgba(78, 115, 223, 0.2);
            color: #4e73df;
        }
        
        .btn-view {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            border: 1px solid rgba(102, 126, 234, 0.2);
        }
        
        .btn-view:hover {
            background: rgba(102, 126, 234, 0.2);
            color: #667eea;
        }
        
        .btn-delete {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .btn-delete:hover {
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
        
        .advertiser-avatar {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            font-weight: 600;
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
        
        .profit-positive {
            color: #28a745;
            font-weight: 600;
        }
        
        .profit-negative {
            color: #dc3545;
            font-weight: 600;
        }
        
        .manager-badge {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .sort-indicator {
            display: inline-block;
            width: 0;
            height: 0;
            margin-left: 5px;
            vertical-align: middle;
            border-right: 4px solid transparent;
            border-left: 4px solid transparent;
        }
        
        .sort-asc {
            border-bottom: 4px solid #4e73df;
            border-top: none;
        }
        
        .sort-desc {
            border-top: 4px solid #4e73df;
            border-bottom: none;
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
                <a href="advertisers.php" class="nav-link active">Advertisers</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <span class="badge badge-warning navbar-badge">
                        <?php echo ($summary['pending_advertisers'] ?? 0) + ($summary['kyc_pending'] ?? 0); ?>
                    </span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header">
                        <?php echo ($summary['pending_advertisers'] ?? 0) + ($summary['kyc_pending'] ?? 0); ?> Pending Items
                    </span>
                    <div class="dropdown-divider"></div>
                    <?php if (($summary['pending_advertisers'] ?? 0) > 0): ?>
                    <a href="advertisers.php?status=pending" class="dropdown-item">
                        <i class="fas fa-user-clock mr-2 text-warning"></i>
                        <?php echo $summary['pending_advertisers']; ?> Pending Approvals
                    </a>
                    <?php endif; ?>
                    <?php if (($summary['kyc_pending'] ?? 0) > 0): ?>
                    <a href="advertisers.php?kyc=pending" class="dropdown-item">
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
                        <a href="advertisers.php" class="nav-link active">
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
                        <h1 class="m-0">Advertiser Management</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Advertisers</li>
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
                            <h2>Advertiser Management</h2>
                            <p class="mb-0">Manage all advertisers, their KYC status, campaigns, and account balances.</p>
                        </div>
                        <div class="col-md-4 text-right">
                            <a href="create_advertiser.php" class="btn btn-light">
                                <i class="fas fa-plus-circle mr-2"></i> Add Advertiser
                            </a>
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
                        <div class="metric-value total-value"><?php echo number_format($summary['total_advertisers'] ?? 0); ?></div>
                        <div class="metric-label">Total Advertisers</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value active-value"><?php echo number_format($summary['active_advertisers'] ?? 0); ?></div>
                        <div class="metric-label">Active</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value pending-value"><?php echo number_format($summary['pending_advertisers'] ?? 0); ?></div>
                        <div class="metric-label">Pending</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value blocked-value"><?php echo number_format($summary['blocked_advertisers'] ?? 0); ?></div>
                        <div class="metric-label">Blocked</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value kyc-value"><?php echo number_format($summary['kyc_verified'] ?? 0); ?></div>
                        <div class="metric-label">KYC Verified</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value balance-value">$<?php echo number_format($summary['total_balance'] ?? 0, 2); ?></div>
                        <div class="metric-label">Total Balance</div>
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
                                           placeholder="Name, email, company, phone..." 
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
                                    <label for="kyc"><i class="fas fa-id-card mr-1"></i> KYC Status</label>
                                    <select name="kyc" id="kyc" class="filter-control">
                                        <option value="all" <?php echo $kycFilter === 'all' ? 'selected' : ''; ?>>All KYC</option>
                                        <option value="verified" <?php echo $kycFilter === 'verified' ? 'selected' : ''; ?>>Verified</option>
                                        <option value="pending" <?php echo $kycFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="rejected" <?php echo $kycFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                        <option value="none" <?php echo $kycFilter === 'none' ? 'selected' : ''; ?>>Not Submitted</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label for="sort"><i class="fas fa-sort mr-1"></i> Sort By</label>
                                    <select name="sort" id="sort" class="filter-control">
                                        <option value="recent" <?php echo $sortBy === 'recent' ? 'selected' : ''; ?>>Most Recent</option>
                                        <option value="oldest" <?php echo $sortBy === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                        <option value="name_asc" <?php echo $sortBy === 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                                        <option value="name_desc" <?php echo $sortBy === 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
                                        <option value="balance_high" <?php echo $sortBy === 'balance_high' ? 'selected' : ''; ?>>Balance (High to Low)</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <button type="submit" class="btn-gradient" style="height: 45px; width: 100%;">
                                        <i class="fas fa-search mr-2"></i> Apply Filters
                                    </button>
                                </div>
                                
                                <div class="filter-group">
                                    <a href="advertisers.php" class="btn btn-outline-primary" style="height: 45px; width: 100%; display: flex; align-items: center; justify-content: center;">
                                        <i class="fas fa-redo mr-2"></i> Reset
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Bulk Actions -->
                <form method="post" id="bulkForm">
                    <div class="bulk-actions">
                        <div class="form-check select-all-checkbox">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                            <label class="form-check-label" for="selectAll">Select All</label>
                        </div>
                        
                        <select name="bulk_action" class="filter-control" style="width: auto;" required>
                            <option value="">Bulk Actions</option>
                            <option value="activate">Activate Selected</option>
                            <option value="block">Block Selected</option>
                            <option value="verify_kyc">Verify KYC</option>
                            <option value="enable_payout">Enable Payout</option>
                            <option value="disable_payout">Disable Payout</option>
                        </select>
                        
                        <button type="submit" class="btn btn-outline-primary btn-sm" onclick="return confirmBulkAction()">
                            <i class="fas fa-play mr-1"></i> Apply
                        </button>
                        
                        <span class="text-muted ml-2">
                            <?php echo $totalAdvertisers; ?> advertiser<?php echo $totalAdvertisers != 1 ? 's' : ''; ?> found
                        </span>
                    </div>

                    <!-- Advertisers Table -->
                    <div class="card-dashboard">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-briefcase mr-2"></i> All Advertisers
                            </h3>
                            <div class="card-tools">
                                <span class="badge badge-light">
                                    Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($advertisers)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-briefcase"></i>
                                    </div>
                                    <h5>No Advertisers Found</h5>
                                    <p class="text-muted">No advertisers match your search criteria.</p>
                                    <a href="advertisers.php" class="btn btn-gradient btn-sm">
                                        <i class="fas fa-redo mr-2"></i> Reset Filters
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-dashboard" id="advertisersTable">
                                        <thead>
                                            <tr>
                                                <th style="width: 40px;">
                                                    <input type="checkbox" class="form-check-input" id="checkAll">
                                                </th>
                                                <th>Advertiser</th>
                                                <th>Contact</th>
                                                <th>Status</th>
                                                <th>KYC</th>
                                                <th>Payout</th>
                                                <th>Account Manager</th>
                                                <th>Campaigns</th>
                                                <th>Financial</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($advertisers as $adv): 
                                                $statusClass = 'status-' . $adv['status'];
                                                $kycClass = 'kyc-' . ($adv['kyc_status'] ?? 'none');
                                                $kycLabel = $adv['kyc_status'] ? ucfirst($adv['kyc_status']) : 'Not Submitted';
                                                $payoutClass = $adv['payout_enabled'] ? 'payout-enabled' : 'payout-disabled';
                                                $payoutLabel = $adv['payout_enabled'] ? 'Enabled' : 'Disabled';
                                            ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" 
                                                           name="selected_advertisers[]" 
                                                           value="<?php echo $adv['user_id']; ?>" 
                                                           class="form-check-input advertiser-checkbox">
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="advertiser-avatar mr-3">
                                                            <?php echo strtoupper(substr($adv['name'], 0, 1)); ?>
                                                        </div>
                                                        <div>
                                                            <strong><?php echo htmlspecialchars($adv['name']); ?></strong>
                                                            <?php if ($adv['company']): ?>
                                                            <div class="text-muted small">
                                                                <?php echo htmlspecialchars($adv['company']); ?>
                                                            </div>
                                                            <?php endif; ?>
                                                            <div class="text-muted small">
                                                                ID: #<?php echo $adv['user_id']; ?> | 
                                                                Joined: <?php echo date('M d, Y', strtotime($adv['created_at'])); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div><i class="fas fa-envelope mr-1 text-muted"></i> <?php echo htmlspecialchars($adv['email']); ?></div>
                                                    <?php if ($adv['mobile']): ?>
                                                    <div><i class="fas fa-phone mr-1 text-muted"></i> <?php echo htmlspecialchars($adv['mobile']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($adv['telegram_id']): ?>
                                                    <div><i class="fab fa-telegram mr-1 text-muted"></i> <?php echo htmlspecialchars($adv['telegram_id']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?php echo $statusClass; ?>">
                                                        <?php echo ucfirst($adv['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="kyc-badge <?php echo $kycClass; ?>">
                                                        <?php echo $kycLabel; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="payout-badge <?php echo $payoutClass; ?>">
                                                        <i class="fas fa-<?php echo $adv['payout_enabled'] ? 'check-circle' : 'times-circle'; ?> mr-1"></i>
                                                        <?php echo $payoutLabel; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($adv['account_manager_name']): ?>
                                                    <span class="manager-badge">
                                                        <i class="fas fa-user-tie mr-1"></i>
                                                        <?php echo htmlspecialchars($adv['account_manager_name']); ?>
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="text-muted small">Unassigned</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <span class="text-primary">
                                                            <i class="fas fa-bullhorn mr-1"></i>
                                                            Total: <?php echo $adv['total_offers'] ?? 0; ?>
                                                        </span>
                                                        <span class="text-success small">
                                                            Active: <?php echo $adv['active_offers'] ?? 0; ?>
                                                        </span>
                                                        <?php if (($adv['pending_offers'] ?? 0) > 0): ?>
                                                        <span class="text-warning small">
                                                            Pending: <?php echo $adv['pending_offers']; ?>
                                                        </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <span class="text-success">
                                                            <i class="fas fa-arrow-up mr-1"></i>
                                                            $<?php echo number_format($adv['total_revenue'] ?? 0, 2); ?>
                                                        </span>
                                                        <span class="text-warning small">
                                                            <i class="fas fa-arrow-down mr-1"></i>
                                                            $<?php echo number_format($adv['total_payout'] ?? 0, 2); ?>
                                                        </span>
                                                        <span class="<?php echo ($adv['total_profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?> small">
                                                            <i class="fas fa-chart-line mr-1"></i>
                                                            $<?php echo number_format($adv['total_profit'] ?? 0, 2); ?>
                                                        </span>
                                                        <span class="text-info small">
                                                            <i class="fas fa-wallet mr-1"></i>
                                                            $<?php echo number_format($adv['balance'] ?? 0, 2); ?>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <?php if ($adv['status'] === 'pending'): ?>
                                                        <a href="?action=activate&id=<?php echo $adv['user_id']; ?>" 
                                                           class="btn-action btn-activate"
                                                           title="Activate"
                                                           onclick="return confirm('Activate this advertiser?')">
                                                            <i class="fas fa-check"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($adv['status'] === 'active'): ?>
                                                        <a href="?action=block&id=<?php echo $adv['user_id']; ?>" 
                                                           class="btn-action btn-block"
                                                           title="Block"
                                                           onclick="return confirm('Block this advertiser?')">
                                                            <i class="fas fa-ban"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($adv['status'] === 'blocked'): ?>
                                                        <a href="?action=activate&id=<?php echo $adv['user_id']; ?>" 
                                                           class="btn-action btn-activate"
                                                           title="Activate"
                                                           onclick="return confirm('Activate this advertiser?')">
                                                            <i class="fas fa-play"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($adv['kyc_status'] === 'pending'): ?>
                                                        <a href="?action=verify_kyc&id=<?php echo $adv['user_id']; ?>" 
                                                           class="btn-action btn-verify"
                                                           title="Verify KYC"
                                                           onclick="return confirm('Verify KYC for this advertiser?')">
                                                            <i class="fas fa-id-card"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($adv['payout_enabled']): ?>
                                                        <a href="?action=disable_payout&id=<?php echo $adv['user_id']; ?>" 
                                                           class="btn-action btn-payout"
                                                           title="Disable Payout"
                                                           onclick="return confirm('Disable payout for this advertiser?')">
                                                            <i class="fas fa-money-bill-wave-slash"></i>
                                                        </a>
                                                        <?php else: ?>
                                                        <a href="?action=enable_payout&id=<?php echo $adv['user_id']; ?>" 
                                                           class="btn-action btn-payout"
                                                           title="Enable Payout"
                                                           onclick="return confirm('Enable payout for this advertiser?')">
                                                            <i class="fas fa-money-bill-wave"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <a href="advertiser_edit.php?id=<?php echo $adv['user_id']; ?>" 
                                                           class="btn-action btn-edit"
                                                           title="Edit Advertiser">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        
                                                        <a href="advertiser_view.php?id=<?php echo $adv['user_id']; ?>" 
                                                           class="btn-action btn-view"
                                                           title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        
                                                        <a href="?action=delete&id=<?php echo $adv['user_id']; ?>" 
                                                           class="btn-action btn-delete"
                                                           title="Delete"
                                                           onclick="return confirm('Are you sure you want to delete this advertiser? This action cannot be undone.')">
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
    $('#advertisersTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [], // Disable initial sort
        responsive: true,
        searching: false, // We use custom search
        info: false,
        paging: false, // We use custom pagination
        language: {
            emptyTable: "No advertisers found"
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
        $('.advertiser-checkbox').prop('checked', $(this).prop('checked'));
    });
    
    // Update "Select All" checkbox when individual checkboxes change
    $('.advertiser-checkbox').change(function() {
        if ($('.advertiser-checkbox:checked').length === $('.advertiser-checkbox').length) {
            $('#selectAll, #checkAll').prop('checked', true);
        } else {
            $('#selectAll, #checkAll').prop('checked', false);
        }
    });
    
    // Confirm bulk action
    window.confirmBulkAction = function() {
        const action = document.querySelector('select[name="bulk_action"]').value;
        const selectedCount = document.querySelectorAll('.advertiser-checkbox:checked').length;
        
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
                text: 'Please select at least one advertiser.'
            });
            return false;
        }
        
        let message = '';
        switch(action) {
            case 'activate':
                message = `Activate ${selectedCount} advertiser(s)?`;
                break;
            case 'block':
                message = `Block ${selectedCount} advertiser(s)?`;
                break;
            case 'verify_kyc':
                message = `Verify KYC for ${selectedCount} advertiser(s)?`;
                break;
            case 'enable_payout':
                message = `Enable payout for ${selectedCount} advertiser(s)?`;
                break;
            case 'disable_payout':
                message = `Disable payout for ${selectedCount} advertiser(s)?`;
                break;
        }
        
        return confirm(message);
    };
    
    // Auto-dismiss alerts after 5 seconds
    $('.alert').delay(5000).fadeOut('slow');
    
    // Search focus
    $('#search').focus();
    
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