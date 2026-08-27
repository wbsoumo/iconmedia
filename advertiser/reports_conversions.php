<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('advertiser');

$advertiserId = auth_user_id();
$advertiserName = $_SESSION['user_name'] ?? 'Advertiser';

/* ===============================
   FILTER INPUTS WITH VALIDATION
================================ */
$status   = $_GET['status'] ?? 'all';
$offerId  = $_GET['offer_id'] ?? 'all';
$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate   = $_GET['to'] ?? date('Y-m-d');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 50;

// Validate dates
if (!strtotime($fromDate)) $fromDate = date('Y-m-01');
if (!strtotime($toDate)) $toDate = date('Y-m-d');
if (strtotime($fromDate) > strtotime($toDate)) {
    $fromDate = $toDate;
}

/* ===============================
   BUILD WHERE CLAUSE WITH POSITIONAL PARAMETERS
================================ */
$where  = ["o.advertiser_id = ?"];
$params = [$advertiserId];

if ($status !== 'all') {
    $where[] = "cv.status = ?";
    $params[] = $status;
}

if ($offerId !== 'all') {
    $where[] = "o.offer_id = ?";
    $params[] = (int)$offerId;
}

$where[] = "DATE(cv.created_at) BETWEEN ? AND ?";
$params[] = $fromDate;
$params[] = $toDate;

$whereSql = 'WHERE ' . implode(' AND ', $where);

/* ===============================
   GET TOTAL COUNT FOR PAGINATION
================================ */
$countSql = "
    SELECT COUNT(*)
    FROM conversions cv
    INNER JOIN offers o ON o.offer_id = cv.offer_id
    $whereSql
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($totalRows / $perPage);
$offset = ($page - 1) * $perPage;

/* ===============================
   FETCH CONVERSIONS WITH PAGINATION
================================ */
$sql = "
    SELECT
        cv.conversion_id,
        cv.transaction_id,
        cv.revenue,
        cv.status AS conversion_status,
        cv.created_at,

        o.offer_id,
        o.offer_name,
        o.payout,

        u.name AS affiliate_name,
        u.user_id AS affiliate_id,

        c.country,
        c.device,
        c.ip_address,
        c.user_agent

    FROM conversions cv
    INNER JOIN offers o ON o.offer_id = cv.offer_id
    LEFT JOIN clicks c ON c.click_id = cv.click_id
    LEFT JOIN users u ON u.user_id = c.affiliate_id
    $whereSql
    ORDER BY cv.created_at DESC
    LIMIT ?, ?
";

$limitParams = array_merge($params, [$offset, $perPage]);
$stmt = $pdo->prepare($sql);
$stmt->execute($limitParams);
$conversions = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH OFFERS FOR FILTER DROPDOWN
================================ */
$offersStmt = $pdo->prepare("
    SELECT offer_id, offer_name
    FROM offers
    WHERE advertiser_id = ?
    ORDER BY offer_name
");
$offersStmt->execute([$advertiserId]);
$offers = $offersStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   SUMMARY STATISTICS
================================ */
$summarySql = "
    SELECT
        COUNT(*) AS total_conversions,
        SUM(cv.status = 'approved') AS approved_conversions,
        SUM(cv.status = 'pending') AS pending_conversions,
        SUM(cv.status = 'rejected') AS rejected_conversions,
        COALESCE(SUM(cv.revenue), 0) AS total_revenue,
        COALESCE(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue END), 0) AS approved_revenue,
        COALESCE(AVG(CASE WHEN cv.status = 'approved' THEN cv.revenue END), 0) AS avg_approved_revenue
    FROM conversions cv
    INNER JOIN offers o ON o.offer_id = cv.offer_id
    $whereSql
";
$summaryStmt = $pdo->prepare($summarySql);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

/* ===============================
   STATUS DISTRIBUTION FOR CHART
================================ */
$distSql = "
    SELECT 
        cv.status,
        COUNT(*) as count,
        COALESCE(SUM(cv.revenue), 0) as revenue
    FROM conversions cv
    INNER JOIN offers o ON o.offer_id = cv.offer_id
    $whereSql
    GROUP BY cv.status
    ORDER BY FIELD(cv.status, 'approved', 'pending', 'rejected')
";
$distStmt = $pdo->prepare($distSql);
$distStmt->execute($params);
$statusDistribution = $distStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   TOP OFFERS BY CONVERSIONS
================================ */
$topOffersSql = "
    SELECT 
        o.offer_id,
        o.offer_name,
        COUNT(cv.conversion_id) as conversions,
        COALESCE(SUM(cv.revenue), 0) as revenue,
        COALESCE(AVG(cv.revenue), 0) as avg_revenue
    FROM offers o
    INNER JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE o.advertiser_id = ?
      AND DATE(cv.created_at) BETWEEN ? AND ?
    GROUP BY o.offer_id, o.offer_name
    ORDER BY conversions DESC
    LIMIT 5
";
$topOffersStmt = $pdo->prepare($topOffersSql);
$topOffersStmt->execute([$advertiserId, $fromDate, $toDate]);
$topOffers = $topOffersStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conversion Reports | Advertiser Panel | GVS Icon Media</title>
    
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
        
        .small-box {
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
            color: white;
            overflow: hidden;
            border: none;
        }
        
        .small-box:hover {
            transform: translateY(-5px);
        }
        
        .small-box .icon {
            font-size: 60px;
            opacity: 0.2;
            transition: all 0.3s ease;
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
        }
        
        .small-box:hover .icon {
            opacity: 0.3;
            transform: translateY(-50%) scale(1.1);
        }
        
        .small-box .inner {
            padding: 20px;
            position: relative;
            z-index: 1;
        }
        
        .small-box h3 {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 5px 0;
        }
        
        .small-box p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .bg-gradient-primary {
            background: var(--primary-gradient) !important;
        }
        
        .bg-gradient-success {
            background: var(--success-gradient) !important;
        }
        
        .bg-gradient-info {
            background: var(--info-gradient) !important;
        }
        
        .bg-gradient-warning {
            background: var(--warning-gradient) !important;
        }
        
        .bg-gradient-danger {
            background: var(--danger-gradient) !important;
        }
        
        .bg-gradient-dark {
            background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%) !important;
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
        
        .revenue-value {
            color: #28a745;
            font-weight: 700;
        }
        
        .clicks-value {
            color: #4e73df;
            font-weight: 700;
        }
        
        .conversions-value {
            color: #6610f2;
            font-weight: 700;
        }
        
        .cr-value {
            color: #20c997;
            font-weight: 700;
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
        
        .date-range-display {
            background: #f8f9fc;
            padding: 10px 15px;
            border-radius: 8px;
            margin: 15px 0;
            font-size: 14px;
            color: #6c757d;
        }
        
        .date-range-display strong {
            color: #4e73df;
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .country-badge {
            background: #e3e6f0;
            color: #4e73df;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .device-badge {
            background: #f8f9fc;
            color: #6c757d;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .payout-badge {
            background: rgba(32, 201, 151, 0.1);
            color: #20c997;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .conversion-details {
            background: #f8f9fc;
            padding: 20px;
            border-radius: 12px;
            margin: 10px 0;
            border-left: 4px solid #4e73df;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 14px;
            padding: 5px 0;
            border-bottom: 1px dashed #e3e6f0;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            color: #6c757d;
            font-weight: 500;
        }
        
        .detail-value {
            color: #343a40;
            font-weight: 600;
        }
        
        .status-chart {
            height: 150px;
            display: flex;
            gap: 15px;
            align-items: flex-end;
            margin-top: 20px;
            padding: 0 10px;
        }
        
        .status-bar {
            flex: 1;
            border-radius: 8px 8px 0 0;
            position: relative;
            transition: height 0.3s ease;
            min-height: 4px;
        }
        
        .status-bar.approved {
            background: linear-gradient(to top, #28a745, #34ce57);
        }
        
        .status-bar.pending {
            background: linear-gradient(to top, #ffc107, #ffd54f);
        }
        
        .status-bar.rejected {
            background: linear-gradient(to top, #dc3545, #e35d6a);
        }
        
        .status-bar:hover {
            opacity: 0.9;
            transform: scale(1.02);
        }
        
        .status-bar::before {
            content: attr(data-count);
            position: absolute;
            top: -25px;
            left: 50%;
            transform: translateX(-50%);
            background: #343a40;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            opacity: 0;
            transition: opacity 0.3s ease;
            white-space: nowrap;
        }
        
        .status-bar:hover::before {
            opacity: 1;
        }
        
        .status-legend {
            display: flex;
            gap: 25px;
            margin-top: 30px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 20px;
            transition: all 0.3s ease;
        }
        
        .legend-item:hover {
            background: #f8f9fc;
        }
        
        .legend-color {
            width: 14px;
            height: 14px;
            border-radius: 4px;
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
        
        .conversion-id {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: #6c757d;
            font-weight: 600;
        }
        
        .affiliate-name {
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .view-details-btn {
            background: transparent;
            border: 1px solid #e3e6f0;
            color: #6c757d;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .view-details-btn:hover {
            background: #f8f9fc;
            color: #4e73df;
            border-color: #4e73df;
        }
        
        .dataTables_wrapper {
            padding: 0;
        }
        
        .dataTables_filter input {
            border: 1px solid #e3e6f0 !important;
            border-radius: 8px !important;
            padding: 8px 15px !important;
        }
        
        .dataTables_length select {
            border: 1px solid #e3e6f0 !important;
            border-radius: 8px !important;
            padding: 8px !important;
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
        
        .quick-stats {
            display: flex;
            justify-content: space-between;
            background: linear-gradient(135deg, #f8f9fc 0%, #eaecf4 100%);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .quick-stat-item {
            text-align: center;
            flex: 1;
        }
        
        .quick-stat-value {
            font-size: 18px;
            font-weight: 700;
            color: #2e59d9;
            margin-bottom: 5px;
        }
        
        .quick-stat-label {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .welcome-banner {
            background: var(--primary-gradient);
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
        
        .badge-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
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
                <a href="reports_conversions.php" class="nav-link active">Conversion Reports</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <span class="badge badge-warning navbar-badge"><?php echo $summary['pending_conversions'] ?? 0; ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header">
                        <?php echo $summary['pending_conversions'] ?? 0; ?> Pending Conversions
                    </span>
                    <div class="dropdown-divider"></div>
                    <a href="reports_conversions.php?status=pending" class="dropdown-item">
                        <i class="fas fa-clock mr-2 text-warning"></i> Review Pending Conversions
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
                        <?php echo strtoupper(substr($advertiserName, 0, 1)); ?>
                    </div>
                    <span><?php echo htmlspecialchars($advertiserName); ?></span>
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
                <i class="fas fa-chart-line mr-2"></i>
                <strong>Advertiser</strong>
            </span>
        </a>

        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Sidebar Menu -->
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-tachometer-alt"></i>
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
                        <a href="offers.php" class="nav-link">
                            <i class="nav-icon fas fa-gift"></i>
                            <p>All Offers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="create_offer.php" class="nav-link">
                            <i class="nav-icon fas fa-plus-circle"></i>
                            <p>Create New Offer</p>
                        </a>
                    </li>
                    
                    <li class="nav-header">REPORTS & ANALYTICS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Campaign Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_conversions.php" class="nav-link active">
                            <i class="fas fa-exchange-alt nav-icon"></i>
                            <p>Conversion Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link">
                            <i class="nav-icon fas fa-users"></i>
                            <p>Affiliate Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="analytics.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-pie"></i>
                            <p>Advanced Analytics</p>
                        </a>
                    </li>
                    
                    <li class="nav-header">TOOLS</li>
                    <li class="nav-item">
                        <a href="ip_whitelist.php" class="nav-link">
                            <i class="nav-icon fas fa-tower-broadcast"></i>
                            <p>IP Whitelist</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="postback.php" class="nav-link">
                            <i class="nav-icon fas fa-code"></i>
                            <p>Postback Manager</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="api.php" class="nav-link">
                            <i class="nav-icon fas fa-plug"></i>
                            <p>API Integration</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="optimization.php" class="nav-link">
                            <i class="nav-icon fas fa-rocket"></i>
                            <p>Optimization Tools</p>
                        </a>
                    </li>
                    
                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user"></i>
                            <p>Profile</p>
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
                        <h1 class="m-0">Conversion Reports</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="reports.php">Reports</a></li>
                            <li class="breadcrumb-item active">Conversion Reports</li>
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
                            <h2>Conversion Tracking & Analytics</h2>
                            <p class="mb-0">Monitor and analyze all conversions across your campaigns.</p>
                        </div>
                        <div class="col-md-4 text-right">
                            <a href="?<?php echo http_build_query($_GET); ?>&export=csv" class="refresh-btn">
                                <i class="fas fa-download mr-1"></i> Export CSV
                            </a>
                        </div>
                    </div>
                    
                    <!-- Quick Stats -->
                    <div class="quick-stats mt-4">
                        <div class="quick-stat-item">
                            <div class="quick-stat-value"><?php echo date('M d, Y', strtotime($fromDate)); ?></div>
                            <div class="quick-stat-label">From Date</div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="quick-stat-value"><?php echo date('M d, Y', strtotime($toDate)); ?></div>
                            <div class="quick-stat-label">To Date</div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="quick-stat-value"><?php echo number_format($totalRows); ?></div>
                            <div class="quick-stat-label">Total Conversions</div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="quick-stat-value">$<?php echo number_format($summary['total_revenue'] ?? 0, 2); ?></div>
                            <div class="quick-stat-label">Total Revenue</div>
                        </div>
                    </div>
                </div>

                <!-- Summary Stats - Small Boxes -->
                <div class="row">
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="small-box bg-gradient-info">
                            <div class="inner">
                                <h3><?php echo number_format($summary['total_conversions'] ?? 0); ?></h3>
                                <p>Total Conversions</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="small-box bg-gradient-success">
                            <div class="inner">
                                <h3><?php echo number_format($summary['approved_conversions'] ?? 0); ?></h3>
                                <p>Approved</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="small-box bg-gradient-warning">
                            <div class="inner">
                                <h3><?php echo number_format($summary['pending_conversions'] ?? 0); ?></h3>
                                <p>Pending</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-clock"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="small-box bg-gradient-danger">
                            <div class="inner">
                                <h3><?php echo number_format($summary['rejected_conversions'] ?? 0); ?></h3>
                                <p>Rejected</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-times-circle"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="small-box bg-gradient-primary">
                            <div class="inner">
                                <h3>$<?php echo number_format($summary['total_revenue'] ?? 0, 2); ?></h3>
                                <p>Total Revenue</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-dollar-sign"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="small-box bg-gradient-dark">
                            <div class="inner">
                                <h3>$<?php echo number_format($summary['avg_approved_revenue'] ?? 0, 2); ?></h3>
                                <p>Avg. Per Conv</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Main Content - Conversions Table -->
                    <div class="col-lg-8">
                        <!-- Filters -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-filter mr-2"></i> Filter Conversions
                                </h3>
                            </div>
                            <div class="card-body">
                                <form method="get" class="filter-row">
                                    <div class="filter-group">
                                        <label for="offer_id"><i class="fas fa-gift mr-1"></i> Select Offer</label>
                                        <select name="offer_id" id="offer_id" class="filter-control">
                                            <option value="all">All Offers</option>
                                            <?php foreach ($offers as $o): ?>
                                                <option value="<?= $o['offer_id'] ?>" 
                                                    <?= $offerId == $o['offer_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($o['offer_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label for="status"><i class="fas fa-tag mr-1"></i> Status</label>
                                        <select name="status" id="status" class="filter-control">
                                            <option value="all">All Status</option>
                                            <option value="approved" <?= $status=='approved'?'selected':'' ?>>Approved</option>
                                            <option value="pending"  <?= $status=='pending'?'selected':'' ?>>Pending</option>
                                            <option value="rejected" <?= $status=='rejected'?'selected':'' ?>>Rejected</option>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label for="from"><i class="fas fa-calendar-day mr-1"></i> From Date</label>
                                        <input type="date" name="from" id="from" value="<?= $fromDate ?>" class="filter-control">
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label for="to"><i class="fas fa-calendar-day mr-1"></i> To Date</label>
                                        <input type="date" name="to" id="to" value="<?= $toDate ?>" class="filter-control">
                                    </div>
                                    
                                    <div class="filter-group">
                                        <button type="submit" class="btn-gradient" style="height: 45px; width: 100%;">
                                            <i class="fas fa-search mr-2"></i> Apply Filters
                                        </button>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <a href="reports_conversions.php" class="btn btn-outline-primary" style="height: 45px; width: 100%; display: flex; align-items: center; justify-content: center;">
                                            <i class="fas fa-redo mr-2"></i> Reset
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Conversions Table -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-exchange-alt mr-2"></i> Conversion Details
                                </h3>
                                <div class="card-tools">
                                    <span class="badge badge-light">
                                        Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($conversions)): ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-exchange-alt"></i>
                                        </div>
                                        <h5>No Conversions Found</h5>
                                        <p class="text-muted">No conversion data available for the selected filters.</p>
                                        <a href="reports_conversions.php" class="btn btn-gradient btn-sm">
                                            <i class="fas fa-redo mr-2"></i> Reset Filters
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-dashboard" id="conversionsTable">
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Offer</th>
                                                    <th>Affiliate</th>
                                                    <th>Location</th>
                                                    <th>Revenue</th>
                                                    <th>Status</th>
                                                    <th>Date</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($conversions as $c): ?>
                                                <tr>
                                                    <td>
                                                        <span class="conversion-id">#<?= $c['conversion_id'] ?></span>
                                                        <?php if (!empty($c['transaction_id'])): ?>
                                                        <br><small class="text-muted"><?= htmlspecialchars($c['transaction_id']) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($c['offer_name']) ?></strong>
                                                        <?php if ($c['payout']): ?>
                                                        <br>
                                                        <span class="payout-badge">
                                                            Payout: $<?= number_format($c['payout'], 2) ?>
                                                        </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($c['affiliate_name'])): ?>
                                                        <strong><?= htmlspecialchars($c['affiliate_name']) ?></strong>
                                                        <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($c['country'])): ?>
                                                        <span class="country-badge">
                                                            <i class="fas fa-globe"></i> <?= htmlspecialchars($c['country']) ?>
                                                        </span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($c['device'])): ?>
                                                        <br>
                                                        <span class="device-badge mt-1">
                                                            <i class="fas fa-<?= strpos(strtolower($c['device']), 'mobile') !== false ? 'mobile-alt' : 'desktop' ?>"></i>
                                                            <?= htmlspecialchars($c['device']) ?>
                                                        </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="revenue-value font-weight-bold">
                                                            $<?= number_format($c['revenue'], 2) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge status-<?= $c['conversion_status'] ?>">
                                                            <?= ucfirst($c['conversion_status']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div><?= date('M d, Y', strtotime($c['created_at'])) ?></div>
                                                        <small class="text-muted"><?= date('h:i A', strtotime($c['created_at'])) ?></small>
                                                    </td>
                                                    <td>
                                                        <button class="view-details-btn" onclick="showConversionDetails(<?= htmlspecialchars(json_encode($c)) ?>)">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
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

                                        <?php 
                                        $start = max(1, $page - 2);
                                        $end = min($totalPages, $page + 2);
                                        for ($i = $start; $i <= $end; $i++): 
                                        ?>
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
                    </div>

                    <!-- Sidebar Widgets -->
                    <div class="col-lg-4">
                        <!-- Status Distribution -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Status Distribution</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($statusDistribution)): ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-chart-pie"></i>
                                        </div>
                                        <p class="text-muted">No data available.</p>
                                    </div>
                                <?php else: 
                                    $maxCount = max(array_column($statusDistribution, 'count'));
                                ?>
                                    <div class="status-chart">
                                        <?php foreach ($statusDistribution as $stat):
                                            $height = $maxCount > 0 ? ($stat['count'] / $maxCount) * 100 : 0;
                                            $color = $stat['status'] == 'approved' ? '#28a745' : 
                                                    ($stat['status'] == 'pending' ? '#ffc107' : '#dc3545');
                                        ?>
                                            <div class="status-bar <?= $stat['status'] ?>" 
                                                 style="height: <?= $height ?>%"
                                                 data-count="<?= $stat['count'] ?> conversions"
                                                 data-revenue="$<?= number_format($stat['revenue'], 2) ?>">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="status-legend">
                                        <?php foreach ($statusDistribution as $stat): ?>
                                            <div class="legend-item" onclick="filterByStatus('<?= $stat['status'] ?>')">
                                                <span class="legend-color" style="background: 
                                                    <?= $stat['status'] == 'approved' ? '#28a745' : 
                                                       ($stat['status'] == 'pending' ? '#ffc107' : '#dc3545') ?>">
                                                </span>
                                                <span><?= ucfirst($stat['status']) ?></span>
                                                <span class="badge badge-light"><?= $stat['count'] ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Top Offers -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Top Performing Offers</h3>
                                <div class="card-tools">
                                    <a href="reports_campaigns.php" class="btn btn-tool">
                                        <i class="fas fa-external-link-alt"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($topOffers)): ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-trophy"></i>
                                        </div>
                                        <p class="text-muted">No performance data available.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach($topOffers as $offer): ?>
                                    <div class="offer-card">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <strong><?= htmlspecialchars($offer['offer_name']) ?></strong>
                                            <span class="badge badge-success"><?= $offer['conversions'] ?> conv</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-primary">
                                                <i class="fas fa-dollar-sign mr-1"></i>
                                                Avg: $<?= number_format($offer['avg_revenue'], 2) ?>
                                            </span>
                                            <span class="text-success">
                                                <strong>$<?= number_format($offer['revenue'], 2) ?></strong>
                                            </span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">Quick Actions</h3>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="create_offer.php" class="btn btn-gradient btn-block">
                                        <i class="fas fa-plus-circle mr-2"></i> Create New Offer
                                    </a>
                                    <a href="reports_campaigns.php" class="btn btn-outline-primary btn-block">
                                        <i class="fas fa-chart-bar mr-2"></i> Campaign Reports
                                    </a>
                                    <a href="reports_affiliates.php" class="btn btn-outline-success btn-block">
                                        <i class="fas fa-users mr-2"></i> Affiliate Reports
                                    </a>
                                </div>
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
            <strong>Advertiser Panel v3.0</strong>
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
    $('#conversionsTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[6, 'desc']],
        responsive: true,
        searching: true,
        info: false,
        paging: false,
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search conversions..."
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
            $('#to').val($('#from').val());
        }
    });
    
    // Small box hover effects
    $('.small-box').hover(
        function() {
            $(this).find('.icon').css('transform', 'translateY(-50%) scale(1.15)');
        },
        function() {
            $(this).find('.icon').css('transform', 'translateY(-50%) scale(1)');
        }
    );
    
    // Initialize SweetAlert2 Toast
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });
});

// Show conversion details modal
function showConversionDetails(conversion) {
    const date = new Date(conversion.created_at);
    const formattedDate = date.toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
    
    Swal.fire({
        title: `Conversion #${conversion.conversion_id}`,
        html: `
            <div class="conversion-details">
                <div class="detail-row">
                    <span class="detail-label">Transaction ID:</span>
                    <span class="detail-value">${conversion.transaction_id || 'N/A'}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Offer:</span>
                    <span class="detail-value">${conversion.offer_name}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Affiliate:</span>
                    <span class="detail-value">${conversion.affiliate_name || 'N/A'}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Revenue:</span>
                    <span class="detail-value revenue-value">$${parseFloat(conversion.revenue).toFixed(2)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Payout:</span>
                    <span class="detail-value">$${parseFloat(conversion.payout || 0).toFixed(2)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">
                        <span class="status-badge status-${conversion.conversion_status}">
                            ${conversion.conversion_status.charAt(0).toUpperCase() + conversion.conversion_status.slice(1)}
                        </span>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Date & Time:</span>
                    <span class="detail-value">${formattedDate}</span>
                </div>
                ${conversion.country ? `
                <div class="detail-row">
                    <span class="detail-label">Country:</span>
                    <span class="detail-value">${conversion.country}</span>
                </div>
                ` : ''}
                ${conversion.device ? `
                <div class="detail-row">
                    <span class="detail-label">Device:</span>
                    <span class="detail-value">${conversion.device}</span>
                </div>
                ` : ''}
                ${conversion.ip_address ? `
                <div class="detail-row">
                    <span class="detail-label">IP Address:</span>
                    <span class="detail-value">${conversion.ip_address}</span>
                </div>
                ` : ''}
            </div>
        `,
        icon: 'info',
        showCloseButton: true,
        showConfirmButton: false,
        width: '600px'
    });
}

// Filter by status from legend click
function filterByStatus(status) {
    window.location.href = 'reports_conversions.php?status=' + status + 
                           '&from=<?= $fromDate ?>&to=<?= $toDate ?>' +
                           '&offer_id=<?= $offerId ?>';
}
</script>

</body>
</html>