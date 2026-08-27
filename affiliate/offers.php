<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

/**
 * 🔥 CRITICAL FIX
 * Required because :aid is reused multiple times in the query
 */
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

require_role('affiliate');

$affiliateId   = auth_user_id();
$affiliateName = $_SESSION['user_name'] ?? 'Affiliate';

/* -------------------------------------------------
   FILTERS
-------------------------------------------------- */

$where  = [];
$params = [
    'aid' => $affiliateId
];

/* Only approved offers */
$where[] = "o.status = 'approved'";

/* Search */
if (!empty($_GET['search'])) {
    $where[] = "(o.offer_name LIKE :search OR o.offer_description LIKE :search)";
    $params['search'] = '%' . trim($_GET['search']) . '%';
}

/* Category */
if (!empty($_GET['category'])) {
    $where[] = "o.category = :category";
    $params['category'] = $_GET['category'];
}

/* Approval status */
if (isset($_GET['approval_status']) && $_GET['approval_status'] !== '') {
    if ($_GET['approval_status'] === 'not_applied') {
        $where[] = "a.offer_id IS NULL";
    } else {
        $where[] = "a.status = :approval_status";
        $params['approval_status'] = $_GET['approval_status'];
    }
}

/* Payout range */
if (isset($_GET['min_payout']) && is_numeric($_GET['min_payout'])) {
    $where[] = "o.payout >= :min_payout";
    $params['min_payout'] = (float) $_GET['min_payout'];
}

if (isset($_GET['max_payout']) && is_numeric($_GET['max_payout'])) {
    $where[] = "o.payout <= :max_payout";
    $params['max_payout'] = (float) $_GET['max_payout'];
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

/* -------------------------------------------------
   MAIN QUERY
-------------------------------------------------- */

$sql = "
SELECT 
    o.offer_id,
    o.offer_name,
    o.offer_description,
    o.payout,
    o.currency,
    o.category,
    o.status AS offer_status,
    o.created_at,
    o.preview_url,

    a.status AS approval_status,

    (
        SELECT COUNT(*)
        FROM clicks c
        WHERE c.offer_id = o.offer_id
          AND c.affiliate_id = :aid
    ) AS total_clicks,

    (
        SELECT COUNT(DISTINCT cv.conversion_id)
        FROM conversions cv
        INNER JOIN clicks c ON c.click_id = cv.click_id
        WHERE c.offer_id = o.offer_id
          AND cv.affiliate_id = :aid
          AND cv.status = 'approved'
    ) AS approved_conversions,

    (
        SELECT IFNULL(SUM(cv.payout), 0)
        FROM conversions cv
        INNER JOIN clicks c ON c.click_id = cv.click_id
        WHERE c.offer_id = o.offer_id
          AND cv.affiliate_id = :aid
          AND cv.status = 'approved'
    ) AS total_earnings

FROM offers o
LEFT JOIN affiliate_offer_approval a
    ON a.offer_id = o.offer_id
   AND a.affiliate_id = :aid

$whereSql
GROUP BY o.offer_id
ORDER BY o.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   STATS
-------------------------------------------------- */

$totalOffers              = count($offers);
$totalEarnings            = 0;
$totalApprovedConversions = 0;
$totalClicks              = 0;

foreach ($offers as $o) {
    $totalEarnings            += (float) $o['total_earnings'];
    $totalApprovedConversions += (int)   $o['approved_conversions'];
    $totalClicks              += (int)   $o['total_clicks'];
}

$avgConversionRate = $totalClicks > 0
    ? ($totalApprovedConversions / $totalClicks) * 100
    : 0;

/* -------------------------------------------------
   CATEGORIES
-------------------------------------------------- */

$categoriesStmt = $pdo->query("
    SELECT DISTINCT category
    FROM offers
    WHERE status = 'approved'
      AND category IS NOT NULL
    ORDER BY category
");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   RECENT APPROVALS
-------------------------------------------------- */

$recentStmt = $pdo->prepare("
    SELECT o.offer_name, o.payout, o.currency, a.approved_at
    FROM affiliate_offer_approval a
    INNER JOIN offers o ON o.offer_id = a.offer_id
    WHERE a.affiliate_id = :aid
      AND a.status = 'approved'
    ORDER BY a.approved_at DESC
    LIMIT 5
");
$recentStmt->execute(['aid' => $affiliateId]);
$recentApprovals = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   TOP PERFORMING OFFERS
-------------------------------------------------- */

$topStmt = $pdo->prepare("
    SELECT
        o.offer_name,
        COUNT(DISTINCT c.click_id) AS clicks,
        COUNT(DISTINCT cv.conversion_id) AS conversions,
        IFNULL(SUM(cv.payout), 0) AS earnings,
        ROUND(
            (COUNT(DISTINCT cv.conversion_id) / NULLIF(COUNT(DISTINCT c.click_id), 0)) * 100,
            2
        ) AS cr
    FROM offers o
    LEFT JOIN clicks c
        ON c.offer_id = o.offer_id
       AND c.affiliate_id = :aid
    LEFT JOIN conversions cv
        ON cv.click_id = c.click_id
       AND cv.status = 'approved'
    WHERE o.status = 'approved'
    GROUP BY o.offer_id
    HAVING clicks > 0
    ORDER BY earnings DESC
    LIMIT 5
");
$topStmt->execute(['aid' => $affiliateId]);
$topOffers = $topStmt->fetchAll(PDO::FETCH_ASSOC);


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Offers | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Ionicons -->
    <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --info-gradient: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            --warning-gradient: linear-gradient(135deg, #f7971e 0%, #ffd200 100%);
            --danger-gradient: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
        }
        
        .small-box {
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
            color: white;
        }
        
        .small-box:hover {
            transform: translateY(-5px);
        }
        
        .small-box .icon {
            font-size: 70px;
            opacity: 0.3;
            transition: all 0.3s ease;
        }
        
        .small-box:hover .icon {
            opacity: 0.5;
            transform: scale(1.1);
        }

        /* 2x2 Grid strictly for 4 main stat boxes on Mobile */
        @media (max-width: 767.98px) {
            .stat-boxes-row > [class*="col-"] {
                flex: 0 0 50% !important;
                max-width: 50% !important;
                padding-left: 6px !important;
                padding-right: 6px !important;
            }

            .stat-boxes-row .small-box {
                margin-bottom: 12px !important;
            }

            .stat-boxes-row .small-box .inner {
                padding: 12px 10px !important;
            }

            .stat-boxes-row .small-box h3 {
                font-size: 18px !important;
                margin-bottom: 2px !important;
            }

            .stat-boxes-row .small-box p {
                font-size: 11px !important;
                margin-bottom: 0 !important;
            }

            .stat-boxes-row .small-box .icon {
                display: none !important;
            }

            .stat-boxes-row .small-box-footer {
                padding: 4px 0 !important;
                font-size: 11px !important;
            }
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
        
        .card-stat {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .card-stat .card-header {
            border-radius: 15px 15px 0 0;
            background: white;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }
        
        .offer-card {
            border-radius: 12px;
            border: 1px solid #e3e6f0;
            transition: all 0.3s ease;
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .offer-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border-color: #4e73df;
        }
        
        .offer-card-header {
            background: linear-gradient(135deg, #f8f9fc 0%, #eaecf4 100%);
            padding: 15px;
            border-bottom: 1px solid #e3e6f0;
        }
        
        .offer-card-body {
            padding: 20px;
        }
        
        .offer-payout {
            font-size: 24px;
            font-weight: 700;
            color: #2e59d9;
        }
        
        .offer-status {
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-approved {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        
        .status-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }
        
        .status-not-applied {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
        }
        
        .category-badge {
            background: #e3e6f0;
            color: #4e73df;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .performance-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px dashed #e3e6f0;
        }
        
        .stat-item {
            text-align: center;
            flex: 1;
        }
        
        .stat-value {
            font-size: 18px;
            font-weight: 700;
            color: #2e59d9;
        }
        
        .stat-label {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .btn-gradient {
            background: var(--primary-gradient);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }
        
        .btn-outline-gradient {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-outline-gradient:hover {
            background: var(--primary-gradient);
            color: white;
            border-color: transparent;
        }
        
        .preview-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255,255,255,0.9);
            border-radius: 6px;
            padding: 5px 10px;
            font-size: 12px;
            color: #667eea;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .preview-btn:hover {
            background: white;
            color: #4e54c8;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state-icon {
            font-size: 60px;
            color: #e3e6f0;
            margin-bottom: 20px;
        }
        
        .filter-card {
            transition: all 0.3s ease;
        }
        
        .filter-card.collapsed .card-body {
            padding: 0;
            max-height: 0;
            overflow: hidden;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <!-- Left navbar links -->
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button">
                    <i class="fas fa-bars"></i>
                </a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
            </li>
        </ul>

        <!-- Right navbar links -->
        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <span class="badge badge-warning navbar-badge"><?php echo count($recentApprovals); ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header"><?php echo count($recentApprovals); ?> New Approvals</span>
                    <div class="dropdown-divider"></div>
                    <?php foreach ($recentApprovals as $approval): ?>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-gift mr-2 text-success"></i> <?php echo htmlspecialchars($approval['offer_name']); ?>
                        <span class="float-right text-muted text-sm">$<?php echo number_format($approval['payout'], 2); ?></span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <?php endforeach; ?>
                    <a href="offers.php" class="dropdown-item">
                        <i class="fas fa-list mr-2"></i> View all offers
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
                    <i class="fas fa-user-circle mr-1"></i> <?php echo htmlspecialchars($affiliateName); ?>
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
                <i class="fas fa-rocket mr-2"></i>
                <strong>GVS Icon Media</strong>
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
                    
                    <li class="nav-header">REPORTS</li>
                    <li class="nav-item">
                        <a href="offers.php" class="nav-link active">
                            <i class="nav-icon fas fa-gift"></i>
                            <p>Offer Performance</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="clicks.php" class="nav-link">
                            <i class="nav-icon fas fa-mouse-pointer"></i>
                            <p>Click Report</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports.php" class="nav-link">
                            <i class="fas fa-exchange-alt nav-icon"></i>
                            <p>Reports</p>
                        </a>
                    </li>
                    
                    <li class="nav-header">TOOLS</li>
                    <li class="nav-item">
                        <a href="link-builder.php" class="nav-link">
                            <i class="nav-icon fas fa-link"></i>
                            <p>Link Builder</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="postback.php" class="nav-link">
                            <i class="nav-icon fas fa-code"></i>
                            <p>Postback</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="insights.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Smart Insights</p>
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
                        <a href="payouts.php" class="nav-link">
                            <i class="nav-icon fas fa-wallet"></i>
                            <p>Payouts</p>
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
                        <h1 class="m-0">Offer Performance</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Offers</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">
                <!-- Filters Card -->
                <div class="card card-stat">
                    <div class="card-header">
                        <h3 class="card-title">Filters</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="get" action="" class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Search Offers</label>
                                    <input type="text" class="form-control" name="search" 
                                           value="<?php echo $_GET['search'] ?? ''; ?>" 
                                           placeholder="Search by name or description">
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Category</label>
                                    <select class="form-control" name="category">
                                        <option value="">All Categories</option>
                                        <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['category']; ?>"
                                            <?php echo (!empty($_GET['category']) && $_GET['category'] == $category['category']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['category']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Payout Range</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" name="min_payout" 
                                               value="<?php echo $_GET['min_payout'] ?? ''; ?>" 
                                               placeholder="Min" step="0.01">
                                        <div class="input-group-append">
                                            <span class="input-group-text">to</span>
                                        </div>
                                        <input type="number" class="form-control" name="max_payout" 
                                               value="<?php echo $_GET['max_payout'] ?? ''; ?>" 
                                               placeholder="Max" step="0.01">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Approval Status</label>
                                    <select class="form-control" name="approval_status">
                                        <option value="">All Status</option>
                                        <option value="approved" <?php echo (!empty($_GET['approval_status']) && $_GET['approval_status'] == 'approved') ? 'selected' : ''; ?>>Approved</option>
                                        <option value="pending" <?php echo (!empty($_GET['approval_status']) && $_GET['approval_status'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                                        <option value="not_applied" <?php echo (!empty($_GET['approval_status']) && $_GET['approval_status'] == 'not_applied') ? 'selected' : ''; ?>>Not Applied</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Sort By</label>
                                    <select class="form-control" name="sort">
                                        <option value="newest" <?php echo (!empty($_GET['sort']) && $_GET['sort'] == 'newest') ? 'selected' : ''; ?>>Newest First</option>
                                        <option value="payout_high" <?php echo (!empty($_GET['sort']) && $_GET['sort'] == 'payout_high') ? 'selected' : ''; ?>>Highest Payout</option>
                                        <option value="payout_low" <?php echo (!empty($_GET['sort']) && $_GET['sort'] == 'payout_low') ? 'selected' : ''; ?>>Lowest Payout</option>
                                        <option value="performance" <?php echo (!empty($_GET['sort']) && $_GET['sort'] == 'performance') ? 'selected' : ''; ?>>Best Performance</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="form-group text-right">
                                    <a href="offers.php" class="btn btn-secondary">Clear Filters</a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter mr-1"></i> Apply Filters
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Stats Summary -->
                <div class="row mb-3 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="small-box bg-gradient-info">
                            <div class="inner">
                                <h3><?php echo number_format($totalOffers); ?></h3>
                                <p>Total Offers</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-gift"></i>
                            </div>
                            <a href="offers.php" class="small-box-footer">View All <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-gradient-success">
                            <div class="inner">
                                <h3>$<?php echo number_format($totalEarnings, 2); ?></h3>
                                <p>Total Earnings</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-dollar-sign"></i>
                            </div>
                            <a href="payouts.php" class="small-box-footer">View Payouts <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-gradient-warning">
                            <div class="inner">
                                <h3><?php echo number_format($totalApprovedConversions); ?></h3>
                                <p>Total Conversions</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                            <a href="conversions.php" class="small-box-footer">View Logs <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-gradient-danger">
                            <div class="inner">
                                <h3><?php echo number_format($avgConversionRate, 2); ?>%</h3>
                                <p>Avg Conversion Rate</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-percentage"></i>
                            </div>
                            <a href="#" class="small-box-footer">View Analytics <i class="fas fa-chart-bar"></i></a>
                        </div>
                    </div>
                </div>

                <!-- Top Performing Offers -->
                <?php if (!empty($topOffers)): ?>
                <div class="card card-stat">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-trophy mr-2 text-warning"></i>Top Performing Offers</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($topOffers as $index => $top): ?>
                            <div class="col-md-4 mb-4">
                                <div class="card border-left-primary shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                    #<?php echo $index + 1; ?> <?php echo htmlspecialchars(substr($top['offer_name'], 0, 30)); ?>...
                                                </div>
                                                <div class="row no-gutters align-items-center">
                                                    <div class="col-auto">
                                                        <div class="h5 mb-0 mr-3 font-weight-bold text-gray-800">
                                                            $<?php echo number_format($top['earnings'], 2); ?>
                                                        </div>
                                                    </div>
                                                    <div class="col">
                                                        <div class="progress progress-sm mr-2">
                                                            <div class="progress-bar bg-primary" role="progressbar" 
                                                                 style="width: <?php echo min(100, $top['cr'] * 2); ?>%" 
                                                                 aria-valuenow="<?php echo $top['cr']; ?>" 
                                                                 aria-valuemin="0" 
                                                                 aria-valuemax="100"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="mt-2">
                                                    <span class="badge badge-success mr-2">
                                                        <i class="fas fa-mouse-pointer"></i> <?php echo $top['clicks']; ?>
                                                    </span>
                                                    <span class="badge badge-info mr-2">
                                                        <i class="fas fa-exchange-alt"></i> <?php echo $top['conversions']; ?>
                                                    </span>
                                                    <span class="badge badge-warning">
                                                        CR: <?php echo $top['cr']; ?>%
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-chart-line fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- All Offers Grid -->
                <div class="card card-stat">
                    <div class="card-header">
                        <h3 class="card-title">All Offers</h3>
                        <div class="card-tools">
                            <span class="badge badge-primary"><?php echo $totalOffers; ?> offers</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($offers)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-gift"></i>
                            </div>
                            <h4>No offers found</h4>
                            <p class="text-muted">Try adjusting your filters or contact your manager for new offers.</p>
                            <a href="offers.php" class="btn btn-primary mt-3">
                                <i class="fas fa-sync-alt mr-2"></i> Clear Filters
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="row">
                            <?php foreach ($offers as $offer): ?>
                            <div class="col-lg-4 col-md-6 mb-4">
                                <div class="offer-card">
                                    <div class="offer-card-header">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h5 class="mb-1"><?php echo htmlspecialchars($offer['offer_name']); ?></h5>
                                                <?php if ($offer['category']): ?>
                                                <span class="category-badge"><?php echo htmlspecialchars($offer['category']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-right">
                                                <span class="offer-payout">
                                                    $<?php echo number_format($offer['payout'], 2); ?>
                                                </span>
                                                <div class="mt-1">
                                                    <span class="offer-status status-<?php 
                                                        echo $offer['approval_status'] === 'approved' ? 'approved' : 
                                                             ($offer['approval_status'] === 'pending' ? 'pending' : 'not-applied');
                                                    ?>">
                                                        <?php
                                                        if ($offer['approval_status'] === 'approved') {
                                                            echo '<i class="fas fa-check-circle mr-1"></i> Approved';
                                                        } elseif ($offer['approval_status'] === 'pending') {
                                                            echo '<i class="fas fa-clock mr-1"></i> Pending';
                                                        } else {
                                                            echo '<i class="fas fa-times-circle mr-1"></i> Not Applied';
                                                        }
                                                        ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="offer-card-body">
                                        <?php if ($offer['offer_description']): ?>
                                        <p class="text-muted mb-3"><?php echo htmlspecialchars(substr($offer['offer_description'], 0, 100)); ?>...</p>
                                        <?php endif; ?>
                                        
                                        <!-- Performance Stats -->
                                        <div class="performance-stats">
                                            <div class="stat-item">
                                                <div class="stat-value"><?php echo number_format($offer['total_clicks']); ?></div>
                                                <div class="stat-label">Clicks</div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-value"><?php echo number_format($offer['approved_conversions']); ?></div>
                                                <div class="stat-label">Conversions</div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-value">$<?php echo number_format($offer['total_earnings'], 2); ?></div>
                                                <div class="stat-label">Earnings</div>
                                            </div>
                                        </div>
                                        
                                        <!-- Actions -->
                                        <div class="mt-4">
                                            <div class="row">
                                                <div class="col-6">
                                                    <?php if ($offer['preview_url']): ?>
                                                    <a href="<?php echo htmlspecialchars($offer['preview_url']); ?>" 
                                                       target="_blank" 
                                                       class="preview-btn">
                                                        <i class="fas fa-eye mr-1"></i> Preview
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-6 text-right">
                                                    <?php if ($offer['approval_status'] === 'approved'): ?>
                                                    <a href="offer_view.php?id=<?php echo $offer['offer_id']; ?>" 
                                                       class="btn btn-sm btn-gradient">
                                                        <i class="fas fa-link mr-1"></i> Get Link
                                                    </a>
                                                    <?php elseif ($offer['approval_status'] === 'pending'): ?>
                                                    <button class="btn btn-sm btn-outline-secondary" disabled>
                                                        <i class="fas fa-clock mr-1"></i> Waiting
                                                    </button>
                                                    <?php else: ?>
                                                    <a href="request_offer.php?id=<?php echo $offer['offer_id']; ?>" 
                                                       class="btn btn-sm btn-outline-gradient">
                                                        <i class="fas fa-paper-plane mr-1"></i> Apply
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="card card-stat">
                    <div class="card-header">
                        <h3 class="card-title">Quick Stats</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 text-center mb-3">
                                <div class="border rounded p-3">
                                    <div class="text-primary mb-2">
                                        <i class="fas fa-gift fa-2x"></i>
                                    </div>
                                    <h4><?php echo $totalOffers; ?></h4>
                                    <p class="text-muted mb-0">Available Offers</p>
                                </div>
                            </div>
                            <div class="col-md-3 text-center mb-3">
                                <div class="border rounded p-3">
                                    <div class="text-success mb-2">
                                        <i class="fas fa-check-circle fa-2x"></i>
                                    </div>
                                    <?php 
                                    $approvedCount = array_filter($offers, function($o) {
                                        return $o['approval_status'] === 'approved';
                                    });
                                    ?>
                                    <h4><?php echo count($approvedCount); ?></h4>
                                    <p class="text-muted mb-0">Approved Offers</p>
                                </div>
                            </div>
                            <div class="col-md-3 text-center mb-3">
                                <div class="border rounded p-3">
                                    <div class="text-warning mb-2">
                                        <i class="fas fa-clock fa-2x"></i>
                                    </div>
                                    <?php 
                                    $pendingCount = array_filter($offers, function($o) {
                                        return $o['approval_status'] === 'pending';
                                    });
                                    ?>
                                    <h4><?php echo count($pendingCount); ?></h4>
                                    <p class="text-muted mb-0">Pending Approval</p>
                                </div>
                            </div>
                            <div class="col-md-3 text-center mb-3">
                                <div class="border rounded p-3">
                                    <div class="text-info mb-2">
                                        <i class="fas fa-chart-line fa-2x"></i>
                                    </div>
                                    <?php 
                                    $activeOffers = array_filter($offers, function($o) {
                                        return $o['total_clicks'] > 0;
                                    });
                                    ?>
                                    <h4><?php echo count($activeOffers); ?></h4>
                                    <p class="text-muted mb-0">Active Campaigns</p>
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
            <strong>GVS Icon Media v3.0</strong>
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

<script>
$(document).ready(function() {
    // Dark mode toggle
    $('#darkModeToggle').click(function(e) {
        e.preventDefault();
        $('body').toggleClass('dark-mode');
        $(this).find('i').toggleClass('fa-moon fa-sun');
        
        // Save preference to localStorage
        localStorage.setItem('darkMode', $('body').hasClass('dark-mode'));
    });
    
    // Check saved dark mode preference
    if (localStorage.getItem('darkMode') === 'true') {
        $('body').addClass('dark-mode');
        $('#darkModeToggle i').removeClass('fa-moon').addClass('fa-sun');
    }
    
    // Collapse/Expand filters
    $('[data-card-widget="collapse"]').click(function() {
        const card = $(this).closest('.card');
        card.toggleClass('collapsed');
        const icon = $(this).find('i');
        if (card.hasClass('collapsed')) {
            icon.removeClass('fa-minus').addClass('fa-plus');
        } else {
            icon.removeClass('fa-plus').addClass('fa-minus');
        }
    });
    
    // Apply button confirmation
    $('a[href*="request_offer.php"]').click(function(e) {
        e.preventDefault();
        const url = $(this).attr('href');
        const offerName = $(this).closest('.offer-card').find('h5').text();
        
        Swal.fire({
            title: 'Apply for Offer',
            html: `Are you sure you want to apply for:<br><strong>${offerName}</strong>?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, apply now!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = url;
            }
        });
    });
    
    // Preview button enhancement
    $('.preview-btn').click(function(e) {
        e.preventDefault();
        const url = $(this).attr('href');
        
        Swal.fire({
            title: 'Offer Preview',
            html: `You are about to view the offer preview.<br><br>
                   <a href="${url}" target="_blank" class="btn btn-primary">
                    <i class="fas fa-external-link-alt mr-2"></i>Open in New Tab
                   </a>`,
            icon: 'info',
            showCancelButton: true,
            confirmButtonText: 'Continue',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.open(url, '_blank');
            }
        });
    });
    
    // Auto-refresh offers every 2 minutes
    function refreshOffers() {
        $.ajax({
            url: 'api/refresh-offers.php',
            method: 'GET',
            success: function(data) {
                if (data.newOffers > 0) {
                    $('.navbar-badge').text(data.newOffers);
                    
                    // Show notification
                    Toast.fire({
                        icon: 'success',
                        title: `${data.newOffers} new offers available!`
                    });
                }
            }
        });
    }
    
    // Refresh every 2 minutes
    setInterval(refreshOffers, 120000);
});
</script>

<!-- SweetAlert2 for better alerts -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Initialize SweetAlert2
const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true,
    didOpen: (toast) => {
        toast.addEventListener('mouseenter', Swal.stopTimer)
        toast.addEventListener('mouseleave', Swal.resumeTimer)
    }
});
</script>

</body>
</html>