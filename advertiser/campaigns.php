<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('advertiser');

$advertiserId   = auth_user_id();
$advertiserName = $_SESSION['user_name'] ?? 'Advertiser';
$success = $error = null;

/* ===============================
   FILTER INPUTS - SIMPLIFIED
================================ */
$statusFilter   = $_GET['status'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$searchQuery    = trim($_GET['search'] ?? '');
$sortBy         = $_GET['sort'] ?? 'created_at';
$sortOrder      = $_GET['order'] ?? 'DESC';
$page           = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage        = 12;

/* ===============================
   GET TOTAL CAMPAIGNS COUNT - SIMPLE QUERY
================================ */
$countQuery = "SELECT COUNT(*) FROM offers WHERE advertiser_id = " . (int)$advertiserId;
$countStmt = $pdo->query($countQuery);
$totalCampaigns = $countStmt->fetchColumn();
$totalPages = ceil($totalCampaigns / $perPage);
$offset = ($page - 1) * $perPage;

/* ===============================
   GET CAMPAIGNS - SIMPLE QUERY WITH FILTERS
================================ */
// Build query parts
$query = "SELECT * FROM offers WHERE advertiser_id = " . (int)$advertiserId;

if (!empty($statusFilter) && $statusFilter !== 'all') {
    $query .= " AND status = '" . $pdo->quote($statusFilter) . "'";
}

if (!empty($categoryFilter) && $categoryFilter !== 'all') {
    $query .= " AND category = '" . $pdo->quote($categoryFilter) . "'";
}

if (!empty($searchQuery)) {
    $query .= " AND (offer_name LIKE '%" . $pdo->quote($searchQuery) . "%' 
                OR offer_description LIKE '%" . $pdo->quote($searchQuery) . "%')";
}

// Add sorting
if ($sortBy === 'name') {
    $query .= " ORDER BY offer_name " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC');
} else {
    $query .= " ORDER BY created_at " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC');
}

// Add pagination
$query .= " LIMIT " . (int)$offset . ", " . (int)$perPage;

// Execute query
$campaigns = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   GET STATS FOR EACH CAMPAIGN - SIMPLE QUERIES
================================ */
foreach ($campaigns as &$campaign) {
    $offerId = $campaign['offer_id'];
    
    // Get clicks
    $clickQuery = "SELECT COUNT(*) as total, 
                          SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today
                   FROM clicks WHERE offer_id = " . (int)$offerId;
    $clickStats = $pdo->query($clickQuery)->fetch(PDO::FETCH_ASSOC);
    $campaign['total_clicks'] = $clickStats['total'] ?? 0;
    $campaign['today_clicks'] = $clickStats['today'] ?? 0;
    
    // Get conversions
    $convQuery = "SELECT COUNT(*) as total,
                         SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                         SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
                         SUM(CASE WHEN status = 'approved' THEN revenue ELSE 0 END) as revenue
                  FROM conversions WHERE offer_id = " . (int)$offerId;
    $convStats = $pdo->query($convQuery)->fetch(PDO::FETCH_ASSOC);
    $campaign['total_conversions'] = $convStats['total'] ?? 0;
    $campaign['approved_conversions'] = $convStats['approved'] ?? 0;
    $campaign['today_conversions'] = $convStats['today'] ?? 0;
    $campaign['total_revenue'] = $convStats['revenue'] ?? 0;
}

/* ===============================
   GET DASHBOARD STATS - SIMPLE QUERIES
================================ */
/* ===============================
   GET DASHBOARD STATS - FIXED WITH TABLE ALIASES
================================ */
// Total campaigns
$totalCampaignsQuery = "SELECT COUNT(*) FROM offers WHERE advertiser_id = " . (int)$advertiserId;
$dashboardStats['total_campaigns'] = $pdo->query($totalCampaignsQuery)->fetchColumn();

// Active campaigns
$activeQuery = "SELECT COUNT(*) FROM offers WHERE advertiser_id = " . (int)$advertiserId . " AND status = 'active'";
$dashboardStats['active_campaigns'] = $pdo->query($activeQuery)->fetchColumn();

// Pending campaigns
$pendingQuery = "SELECT COUNT(*) FROM offers WHERE advertiser_id = " . (int)$advertiserId . " AND status = 'pending'";
$dashboardStats['pending_campaigns'] = $pdo->query($pendingQuery)->fetchColumn();

// Total clicks
$clicksQuery = "SELECT COUNT(*) FROM clicks c 
                INNER JOIN offers o ON o.offer_id = c.offer_id 
                WHERE o.advertiser_id = " . (int)$advertiserId;
$dashboardStats['total_clicks_all'] = $pdo->query($clicksQuery)->fetchColumn();

// Total conversions
$convTotalQuery = "SELECT COUNT(*) FROM conversions cv 
                   INNER JOIN offers o ON o.offer_id = cv.offer_id 
                   WHERE o.advertiser_id = " . (int)$advertiserId;
$dashboardStats['total_conversions_all'] = $pdo->query($convTotalQuery)->fetchColumn();

// Total revenue - FIXED: Added table alias for revenue
$revenueQuery = "SELECT SUM(cv.revenue) FROM conversions cv 
                  INNER JOIN offers o ON o.offer_id = cv.offer_id 
                  WHERE o.advertiser_id = " . (int)$advertiserId . " AND cv.status = 'approved'";
$dashboardStats['total_revenue_all'] = $pdo->query($revenueQuery)->fetchColumn() ?: 0;

// Today's clicks
$todayClicksQuery = "SELECT COUNT(*) FROM clicks c 
                      INNER JOIN offers o ON o.offer_id = c.offer_id 
                      WHERE o.advertiser_id = " . (int)$advertiserId . " AND DATE(c.created_at) = CURDATE()";
$dashboardStats['today_clicks'] = $pdo->query($todayClicksQuery)->fetchColumn();

// Today's conversions
$todayConvQuery = "SELECT COUNT(*) FROM conversions cv 
                    INNER JOIN offers o ON o.offer_id = cv.offer_id 
                    WHERE o.advertiser_id = " . (int)$advertiserId . " AND DATE(cv.created_at) = CURDATE()";
$dashboardStats['today_conversions'] = $pdo->query($todayConvQuery)->fetchColumn();

// Today's revenue - FIXED: Added table alias for revenue
$todayRevenueQuery = "SELECT SUM(cv.revenue) FROM conversions cv 
                       INNER JOIN offers o ON o.offer_id = cv.offer_id 
                       WHERE o.advertiser_id = " . (int)$advertiserId . " 
                       AND DATE(cv.created_at) = CURDATE() 
                       AND cv.status = 'approved'";
$dashboardStats['today_revenue'] = $pdo->query($todayRevenueQuery)->fetchColumn() ?: 0;

/* ===============================
   GET CATEGORIES FOR FILTER
================================ */
$categoriesQuery = "SELECT DISTINCT category, COUNT(*) as count FROM offers WHERE advertiser_id = " . (int)$advertiserId . " AND category IS NOT NULL AND category != '' GROUP BY category ORDER BY category";
$categories = $pdo->query($categoriesQuery)->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   GET TOP PERFORMING OFFERS - FIXED
================================ */
$topOffersQuery = "
    SELECT 
        o.offer_id,
        o.offer_name,
        COUNT(DISTINCT c.click_id) AS clicks,
        COUNT(DISTINCT cv.conversion_id) AS conversions,
        IFNULL(SUM(cv.revenue), 0) AS revenue  -- FIXED: Use cv.revenue instead of just revenue
    FROM offers o
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE o.advertiser_id = " . (int)$advertiserId . "
    GROUP BY o.offer_id
    HAVING clicks > 0
    ORDER BY revenue DESC
    LIMIT 5
";
$topOffers = $pdo->query($topOffersQuery)->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   GET PERFORMANCE DATA FOR CHART
================================ */
$performanceQuery = "
    SELECT
        DATE(cv.created_at) AS date,
        COUNT(DISTINCT cv.conversion_id) AS conversions,
        IFNULL(SUM(cv.revenue), 0) AS revenue,
        COUNT(DISTINCT c.click_id) AS clicks
    FROM conversions cv
    INNER JOIN offers o ON o.offer_id = cv.offer_id
    LEFT JOIN clicks c ON c.click_id = cv.click_id
    WHERE o.advertiser_id = " . (int)$advertiserId . "
      AND cv.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(cv.created_at)
    ORDER BY date ASC
";
$performanceData = $pdo->query($performanceQuery)->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   BULK ACTIONS
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $ids = $_POST['selected_campaigns'] ?? [];
    
    if (empty($ids)) {
        $error = 'No campaigns selected.';
    } else {
        $idList = implode(',', array_map('intval', $ids));
        
        switch ($action) {
            case 'activate':
                $updateQuery = "UPDATE offers SET status='active', updated_at=NOW() WHERE offer_id IN ($idList) AND advertiser_id = " . (int)$advertiserId;
                break;
            case 'pause':
                $updateQuery = "UPDATE offers SET status='paused', updated_at=NOW() WHERE offer_id IN ($idList) AND advertiser_id = " . (int)$advertiserId;
                break;
            case 'archive':
                $updateQuery = "UPDATE offers SET status='archived', updated_at=NOW() WHERE offer_id IN ($idList) AND advertiser_id = " . (int)$advertiserId;
                break;
            default:
                $updateQuery = null;
        }
        
        if ($updateQuery) {
            $pdo->exec($updateQuery);
            $success = 'Bulk action applied successfully to ' . count($ids) . ' campaign(s).';
        }
    }
}

/* ===============================
   SINGLE ACTIONS
================================ */
if (!empty($_GET['action']) && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $action = $_GET['action'];
    
    switch ($action) {
        case 'activate':
            $updateQuery = "UPDATE offers SET status='active', updated_at=NOW() WHERE offer_id = $id AND advertiser_id = " . (int)$advertiserId;
            $pdo->exec($updateQuery);
            $success = 'Campaign activated successfully.';
            break;
            
        case 'pause':
            $updateQuery = "UPDATE offers SET status='paused', updated_at=NOW() WHERE offer_id = $id AND advertiser_id = " . (int)$advertiserId;
            $pdo->exec($updateQuery);
            $success = 'Campaign paused successfully.';
            break;
            
        case 'archive':
            $updateQuery = "UPDATE offers SET status='archived', updated_at=NOW() WHERE offer_id = $id AND advertiser_id = " . (int)$advertiserId;
            $pdo->exec($updateQuery);
            $success = 'Campaign archived successfully.';
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Campaign Management | Advertiser Panel | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --info: #4895ef;
            --warning: #f8961e;
            --danger: #f94144;
        }
        
        body {
            background: #f8fafc;
        }
        
        .content-wrapper {
            background: #f8fafc;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.02);
            border: 1px solid #eef2f6;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(67, 97, 238, 0.1);
            border-color: var(--primary);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            background: rgba(67, 97, 238, 0.1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            color: var(--primary);
            font-size: 24px;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #64748b;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-today {
            margin-top: 10px;
            font-size: 12px;
            padding: 4px 12px;
            background: #f1f5f9;
            border-radius: 20px;
            display: inline-block;
            color: var(--primary);
        }
        
        /* Filter Bar */
        .filter-bar {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
            border: 1px solid #eef2f6;
        }
        
        .filter-control {
            padding: 8px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            min-width: 150px;
        }
        
        .filter-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(67, 97, 238, 0.3);
            color: white;
        }
        
        .btn-outline-custom {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
            padding: 6px 18px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-outline-custom:hover {
            background: var(--primary);
            color: white;
        }
        
        /* Campaign Grid */
        .campaign-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
        }
        
        .campaign-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            border: 1px solid #eef2f6;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .campaign-card:hover {
            transform: translateY(-5px);
            border-color: var(--primary);
            box-shadow: 0 20px 40px rgba(67, 97, 238, 0.1);
        }
        
        .campaign-status {
            position: absolute;
            top: 20px;
            right: 20px;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-active { background: rgba(76, 201, 240, 0.1); color: #4cc9f0; }
        .status-paused { background: rgba(248, 150, 30, 0.1); color: #f8961e; }
        .status-pending { background: rgba(100, 116, 139, 0.1); color: #64748b; }
        .status-approved { background: rgba(67, 97, 238, 0.1); color: #4361ee; }
        .status-rejected { background: rgba(249, 65, 68, 0.1); color: #f94144; }
        
        .campaign-title {
            font-size: 18px;
            font-weight: 700;
            margin: 15px 0 10px;
            padding-right: 80px;
        }
        
        .campaign-metrics {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin: 20px 0;
            padding: 15px 0;
            border-top: 1px solid #eef2f6;
            border-bottom: 1px solid #eef2f6;
        }
        
        .metric-item {
            text-align: center;
        }
        
        .metric-value {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
        }
        
        .metric-label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
        }
        
        .campaign-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 15px;
        }
        
        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
            background: white;
            color: #64748b;
            transition: all 0.3s ease;
        }
        
        .btn-icon:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            transform: translateY(-2px);
        }
        
        /* Bulk Actions */
        .bulk-actions {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 20px;
            border: 1px solid #eef2f6;
        }
        
        .select-all {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .campaign-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 30px;
        }
        
        .page-link {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: white;
            color: #64748b;
            text-decoration: none;
        }
        
        .page-link:hover,
        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            color: white;
            padding: 30px;
            border-radius: 20px;
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
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.03)" d="M0,96L48,112C96,128,192,160,288,186.7C384,213,480,235,576,213.3C672,192,768,128,864,128C960,128,1056,192,1152,213.3C1248,235,1344,213,1392,202.7L1440,192L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>');
            background-size: cover;
            opacity: 0.1;
        }
        
        /* Chart Container */
        .chart-container {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid #eef2f6;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 20px;
            border: 2px dashed #e2e8f0;
        }
        
        .admin-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
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
                <a href="campaigns.php" class="nav-link active">Campaigns</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="create_offer.php" class="nav-link">Create Campaign</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <?php if ($dashboardStats['pending_campaigns'] > 0): ?>
                    <span class="badge badge-warning navbar-badge"><?php echo $dashboardStats['pending_campaigns']; ?></span>
                    <?php endif; ?>
                </a>
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
                        <a href="campaigns.php" class="nav-link active">
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
                        <a href="reports_conversions.php" class="nav-link">
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
                    <li class="nav-item">
                        <a href="billing.php" class="nav-link">
                            <i class="nav-icon fas fa-wallet"></i>
                            <p>Billing & Payments</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="support.php" class="nav-link">
                            <i class="nav-icon fas fa-headset"></i>
                            <p>Support</p>
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
                        <h1 class="m-0">Campaign Management</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Campaigns</li>
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
                            <h2>Welcome back, <?php echo htmlspecialchars($advertiserName); ?>!</h2>
                            <p class="mb-0 text-white-50">Manage and optimize your campaigns for maximum performance.</p>
                        </div>
                        <div class="col-md-4 text-right">
                            <a href="create_offer.php" class="btn btn-light">
                                <i class="fas fa-plus-circle mr-2"></i> New Campaign
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert">×</button>
                    <i class="fas fa-check-circle mr-2"></i> <?php echo htmlspecialchars($success); ?>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert">×</button>
                    <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-bullhorn"></i></div>
                        <div class="stat-value"><?php echo number_format($dashboardStats['total_campaigns']); ?></div>
                        <div class="stat-label">Total Campaigns</div>
                        <div class="stat-today"><?php echo $dashboardStats['active_campaigns']; ?> active</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-mouse-pointer"></i></div>
                        <div class="stat-value"><?php echo number_format($dashboardStats['total_clicks_all']); ?></div>
                        <div class="stat-label">Total Clicks</div>
                        <div class="stat-today"><?php echo $dashboardStats['today_clicks']; ?> today</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-exchange-alt"></i></div>
                        <div class="stat-value"><?php echo number_format($dashboardStats['total_conversions_all']); ?></div>
                        <div class="stat-label">Conversions</div>
                        <div class="stat-today"><?php echo $dashboardStats['today_conversions']; ?> today</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
                        <div class="stat-value">$<?php echo number_format($dashboardStats['total_revenue_all'], 2); ?></div>
                        <div class="stat-label">Total Revenue</div>
                        <div class="stat-today">$<?php echo number_format($dashboardStats['today_revenue'], 2); ?> today</div>
                    </div>
                </div>

               

                <!-- Filter Bar -->
                <div class="filter-bar">
                    <input type="text" class="filter-control" placeholder="Search campaigns..." 
                           id="searchInput" value="<?php echo htmlspecialchars($searchQuery); ?>" style="flex: 1;">
                    
                    <select class="filter-control" id="statusFilter">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="paused" <?php echo $statusFilter === 'paused' ? 'selected' : ''; ?>>Paused</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                    
                    <select class="filter-control" id="categoryFilter">
                        <option value="">All Categories</option>
                        <?php foreach($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat['category']); ?>" 
                                <?php echo $categoryFilter === $cat['category'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['category']); ?> (<?php echo $cat['count']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select class="filter-control" id="sortFilter">
                        <option value="created_at_DESC" <?php echo $sortBy === 'created_at' && $sortOrder === 'DESC' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="created_at_ASC" <?php echo $sortBy === 'created_at' && $sortOrder === 'ASC' ? 'selected' : ''; ?>>Oldest First</option>
                        <option value="name_ASC" <?php echo $sortBy === 'name' && $sortOrder === 'ASC' ? 'selected' : ''; ?>>Name A-Z</option>
                        <option value="name_DESC" <?php echo $sortBy === 'name' && $sortOrder === 'DESC' ? 'selected' : ''; ?>>Name Z-A</option>
                    </select>
                    
                    <button class="btn-primary-custom" onclick="applyFilters()">
                        <i class="fas fa-filter mr-1"></i> Apply
                    </button>
                    
                    <button class="btn-outline-custom" onclick="resetFilters()">
                        <i class="fas fa-redo mr-1"></i> Reset
                    </button>
                </div>

                <!-- Bulk Actions -->
                <form method="post" id="bulkForm">
                    <div class="bulk-actions">
                        <div class="select-all">
                            <input type="checkbox" id="selectAll" class="campaign-checkbox">
                            <label for="selectAll">Select All</label>
                        </div>
                        
                        <select name="bulk_action" class="filter-control" style="width: 200px;">
                            <option value="">Bulk Actions</option>
                            <option value="activate">Activate Selected</option>
                            <option value="pause">Pause Selected</option>
                            <option value="archive">Archive Selected</option>
                        </select>
                        
                        <button type="submit" class="btn-primary-custom btn-sm" onclick="return confirmBulkAction()">
                            <i class="fas fa-play mr-1"></i> Apply
                        </button>
                        
                        <span class="text-muted ml-auto">
                            Showing <?php echo count($campaigns); ?> of <?php echo $totalCampaigns; ?> campaigns
                        </span>
                    </div>

                    <!-- Campaigns Grid -->
                    <div class="campaign-grid">
                        <?php if (empty($campaigns)): ?>
                        <div class="empty-state" style="grid-column: 1/-1;">
                            <i class="fas fa-bullhorn fa-4x text-muted mb-3"></i>
                            <h4>No Campaigns Found</h4>
                            <p class="text-muted">Get started by creating your first campaign.</p>
                            <a href="create_offer.php" class="btn-primary-custom mt-3">
                                <i class="fas fa-plus mr-2"></i> Create Campaign
                            </a>
                        </div>
                        <?php else: ?>
                            <?php foreach($campaigns as $campaign): 
                                $cr = $campaign['total_clicks'] > 0 ? round(($campaign['total_conversions'] / $campaign['total_clicks']) * 100, 1) : 0;
                                $epc = $campaign['total_clicks'] > 0 ? round($campaign['total_revenue'] / $campaign['total_clicks'], 2) : 0;
                            ?>
                            <div class="campaign-card">
                                <input type="checkbox" name="selected_campaigns[]" value="<?php echo $campaign['offer_id']; ?>" 
                                       class="campaign-checkbox campaign-select" style="position: absolute; top: 20px; left: 20px;">
                                
                                <span class="campaign-status status-<?php echo $campaign['status'] ?? 'pending'; ?>">
                                    <?php echo ucfirst($campaign['status'] ?? 'pending'); ?>
                                </span>
                                
                                <h3 class="campaign-title"><?php echo htmlspecialchars($campaign['offer_name']); ?></h3>
                                
                                <?php if (!empty($campaign['category'])): ?>
                                <span class="badge badge-secondary"><?php echo htmlspecialchars($campaign['category']); ?></span>
                                <?php endif; ?>
                                
                                <div class="campaign-metrics">
                                    <div class="metric-item">
                                        <div class="metric-value"><?php echo number_format($campaign['total_clicks']); ?></div>
                                        <div class="metric-label">Clicks</div>
                                        <?php if ($campaign['today_clicks'] > 0): ?>
                                        <small class="text-success">+<?php echo $campaign['today_clicks']; ?> today</small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="metric-item">
                                        <div class="metric-value"><?php echo number_format($campaign['total_conversions']); ?></div>
                                        <div class="metric-label">Conv.</div>
                                        <?php if ($campaign['today_conversions'] > 0): ?>
                                        <small class="text-success">+<?php echo $campaign['today_conversions']; ?> today</small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="metric-item">
                                        <div class="metric-value"><?php echo $cr; ?>%</div>
                                        <div class="metric-label">CR</div>
                                    </div>
                                    <div class="metric-item">
                                        <div class="metric-value">$<?php echo $epc; ?></div>
                                        <div class="metric-label">EPC</div>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-primary">$<?php echo number_format($campaign['payout'] ?? 0, 2); ?> payout</span>
                                    <span class="text-success">$<?php echo number_format($campaign['total_revenue'], 2); ?> revenue</span>
                                </div>
                                
                                <div class="campaign-actions">
                                    <a href="edit_offer.php?id=<?php echo $campaign['offer_id']; ?>" class="btn-icon" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="reports_campaigns.php?offer_id=<?php echo $campaign['offer_id']; ?>" class="btn-icon" title="Reports">
                                        <i class="fas fa-chart-bar"></i>
                                    </a>
                                    <div class="dropdown">
                                        <button class="btn-icon" type="button" data-toggle="dropdown">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <div class="dropdown-menu">
                                            <?php if ($campaign['status'] === 'active'): ?>
                                            <a class="dropdown-item" href="?action=pause&id=<?php echo $campaign['offer_id']; ?>">
                                                <i class="fas fa-pause mr-2"></i> Pause
                                            </a>
                                            <?php elseif ($campaign['status'] === 'paused'): ?>
                                            <a class="dropdown-item" href="?action=activate&id=<?php echo $campaign['offer_id']; ?>">
                                                <i class="fas fa-play mr-2"></i> Activate
                                            </a>
                                            <?php endif; ?>
                                            <a class="dropdown-item" href="?action=archive&id=<?php echo $campaign['offer_id']; ?>" 
                                               onclick="return confirm('Archive this campaign?')">
                                                <i class="fas fa-archive mr-2"></i> Archive
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page-1; ?><?php echo !empty($statusFilter) ? '&status='.$statusFilter : ''; ?>" class="page-link">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <?php endif; ?>
                    
                    <?php for($i=1; $i<=$totalPages; $i++): ?>
                    <a href="?page=<?php echo $i; ?><?php echo !empty($statusFilter) ? '&status='.$statusFilter : ''; ?>" 
                       class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page+1; ?><?php echo !empty($statusFilter) ? '&status='.$statusFilter : ''; ?>" class="page-link">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline">
            <strong>Advertiser Panel v3.0</strong>
        </div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> GVS Icon Media.</strong> All rights reserved.
    </footer>
</div>

<!-- REQUIRED SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
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
    
    // Initialize Chart
    const ctx = document.getElementById('performanceChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_column($performanceData, 'date') ?: []); ?>,
            datasets: [{
                label: 'Revenue',
                data: <?php echo json_encode(array_column($performanceData, 'revenue') ?: []); ?>,
                borderColor: '#4361ee',
                tension: 0.4
            }, {
                label: 'Conversions',
                data: <?php echo json_encode(array_column($performanceData, 'conversions') ?: []); ?>,
                borderColor: '#4cc9f0',
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false
        }
    });
    
    // Select all functionality
    $('#selectAll').change(function() {
        $('.campaign-select').prop('checked', $(this).prop('checked'));
    });
    
    // Auto-dismiss alerts
    $('.alert').delay(5000).fadeOut('slow');
});

// Apply filters
function applyFilters() {
    let params = new URLSearchParams();
    if ($('#searchInput').val()) params.set('search', $('#searchInput').val());
    if ($('#statusFilter').val()) params.set('status', $('#statusFilter').val());
    if ($('#categoryFilter').val()) params.set('category', $('#categoryFilter').val());
    
    let sort = $('#sortFilter').val().split('_');
    params.set('sort', sort[0]);
    params.set('order', sort[1]);
    
    window.location.href = 'campaigns.php?' + params.toString();
}

// Reset filters
function resetFilters() {
    window.location.href = 'campaigns.php';
}

// Confirm bulk action
function confirmBulkAction() {
    const action = $('select[name="bulk_action"]').val();
    const selectedCount = $('.campaign-select:checked').length;
    
    if (!action) {
        Swal.fire('No Action Selected', 'Please select a bulk action.', 'warning');
        return false;
    }
    
    if (selectedCount === 0) {
        Swal.fire('No Campaigns Selected', 'Please select at least one campaign.', 'warning');
        return false;
    }
    
    return confirm(`Apply ${action} to ${selectedCount} campaign(s)?`);
}
</script>

</body>
</html>