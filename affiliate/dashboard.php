<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('affiliate');

$affiliateId = auth_user_id();
$affiliateName = $_SESSION['user_name'] ?? 'Affiliate';

/* -------------------------------------------------
   FILTERS
-------------------------------------------------- */

$where   = [];
$params  = [];

// Mandatory affiliate filter
$where[]        = 'c.affiliate_id = :aid';
$params['aid']  = $affiliateId;

// Date range
if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {
    $where[] = 'DATE(c.created_at) BETWEEN :start_date AND :end_date';
    $params['start_date'] = $_GET['start_date'];
    $params['end_date']   = $_GET['end_date'];
}

// Offer filter
if (!empty($_GET['offer_id'])) {
    $where[] = 'c.offer_id = :offer_id';
    $params['offer_id'] = (int)$_GET['offer_id'];
}

// SubID filter
if (!empty($_GET['subid'])) {
    $where[] = '(c.sub1 LIKE :subid OR c.sub2 LIKE :subid OR c.sub3 LIKE :subid)';
    $params['subid'] = '%' . $_GET['subid'] . '%';
}

// Country filter
if (!empty($_GET['country'])) {
    $where[] = 'c.country = :country';
    $params['country'] = $_GET['country'];
}

// IP filter
if (!empty($_GET['ip'])) {
    $where[] = 'INET6_NTOA(c.ip_address) LIKE :ip';
    $params['ip'] = $_GET['ip'] . '%';
}

// Final WHERE clause
$whereSql = 'WHERE ' . implode(' AND ', $where);

/* -------------------------------------------------
   TOTAL COUNT
-------------------------------------------------- */

$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM clicks c
    $whereSql
");

$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();

/* -------------------------------------------------
   PAGINATION
-------------------------------------------------- */

$perPage    = 50;
$page       = max(1, (int)($_GET['page'] ?? 1));
$offset     = ($page - 1) * $perPage;
$totalPages = (int)ceil($totalRecords / $perPage);

/* -------------------------------------------------
   FETCH CLICK LOGS
-------------------------------------------------- */

$sql = "
    SELECT 
        c.click_id,
        o.offer_name,
        c.sub1,
        c.sub2,
        c.sub3,
        c.sub4,
        c.sub5,
        INET6_NTOA(c.ip_address) AS full_ip,
        c.country,
        c.device,
        c.referer,
        c.created_at,
        (
            SELECT COUNT(*) 
            FROM conversions cv 
            WHERE cv.click_id = c.click_id
        ) AS conversions
    FROM clicks c
    INNER JOIN offers o ON o.offer_id = c.offer_id
    $whereSql
    ORDER BY c.created_at DESC
    LIMIT :offset, :limit
";

$clickStmt = $pdo->prepare($sql);

// Bind dynamic filters
foreach ($params as $key => $val) {
    $clickStmt->bindValue(':' . $key, $val);
}

// Bind pagination explicitly as INT
$clickStmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$clickStmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);

$clickStmt->execute();
$clicks = $clickStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($clicks as &$click) {
    if (!empty($click['full_ip']) && filter_var($click['full_ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $click['masked_ip'] = preg_replace('/\.\d+$/', '.xxx', $click['full_ip']);
    } else {
        $click['masked_ip'] = 'xxxx::xxxx';
    }
}
unset($click);

/* -------------------------------------------------
   OFFERS FOR FILTER DROPDOWN
-------------------------------------------------- */

$offersStmt = $pdo->prepare("
    SELECT DISTINCT o.offer_id, o.offer_name
    FROM offers o
    INNER JOIN clicks c ON c.offer_id = o.offer_id
    WHERE c.affiliate_id = :aid
    ORDER BY o.offer_name
");
$offersStmt->execute(['aid' => $affiliateId]);
$offers = $offersStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   COUNTRIES FOR FILTER DROPDOWN
-------------------------------------------------- */

$countriesStmt = $pdo->prepare("
    SELECT DISTINCT country
    FROM clicks
    WHERE affiliate_id = :aid
      AND country IS NOT NULL
    ORDER BY country
");
$countriesStmt->execute(['aid' => $affiliateId]);
$countries = $countriesStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   STATISTICS
-------------------------------------------------- */

// Get conversion rate
$convRateStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT c.click_id) as total_clicks,
        COUNT(DISTINCT cv.conversion_id) as conversions
    FROM clicks c
    LEFT JOIN conversions cv ON c.click_id = cv.click_id
    WHERE c.affiliate_id = :aid
");
$convRateStmt->execute(['aid' => $affiliateId]);
$rateData = $convRateStmt->fetch();
$convRate = $rateData['total_clicks'] > 0 ? 
    ($rateData['conversions'] / $rateData['total_clicks']) * 100 : 0;

// Get unique clicks
$uniqueStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT INET6_NTOA(ip_address))
    FROM clicks
    WHERE affiliate_id = :aid
");
$uniqueStmt->execute(['aid' => $affiliateId]);
$uniqueClicks = (int)$uniqueStmt->fetchColumn();

// Get today's clicks
$todayStmt = $pdo->prepare("
    SELECT COUNT(*) as today_clicks
    FROM clicks 
    WHERE affiliate_id = :aid 
    AND DATE(created_at) = CURDATE()
");
$todayStmt->execute(['aid' => $affiliateId]);
$todayClicks = $todayStmt->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Ionicons -->
    <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap4.min.css">
    
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
        
        .ip-cell {
            cursor: pointer;
            position: relative;
        }
        
        .ip-tooltip {
            position: absolute;
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            z-index: 1000;
            display: none;
            white-space: nowrap;
        }
        
        .subid-badge {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 12px;
            padding: 2px 8px;
            font-size: 11px;
            margin: 2px;
            display: inline-block;
            cursor: help;
        }
        
        .filter-card {
            transition: all 0.3s ease;
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
        
        .table-hover tbody tr:hover {
            background: rgba(0,0,0,0.02);
        }
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-approved {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        
        .status-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }
        
        .status-rejected {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }
        
        .brand-link {
            text-align: center;
        }
        
        .brand-text {
            font-size: 1.5rem;
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
                    <span class="badge badge-warning navbar-badge"><?php echo $todayClicks > 0 ? $todayClicks : '0'; ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header"><?php echo $todayClicks; ?> New Clicks Today</span>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-mouse-pointer mr-2"></i> <?php echo $uniqueClicks; ?> unique clicks
                        <span class="float-right text-muted text-sm">Today</span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-percentage mr-2"></i> CR: <?php echo number_format($convRate, 2); ?>%
                        <span class="float-right text-muted text-sm">Overall</span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="clicks.php" class="dropdown-item">
                        <i class="fas fa-list mr-2"></i> View all clicks
                        <span class="float-right text-muted text-sm"><?php echo number_format($totalRecords); ?> total</span>
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
                    <a href="profile.php" class="dropdown-item">
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
        <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.4rem;">
                <i class="fas fa-rocket mr-2"></i><strong>Icon Media</strong>
            </span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link active">
                            <i class="nav-icon fas fa-tachometer-alt"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>

                    <li class="nav-header">CAMPAIGNS</li>
                    <li class="nav-item">
                        <a href="offers.php" class="nav-link">
                            <i class="nav-icon fas fa-gift"></i>
                            <p>All Campaigns</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="approved_offers.php" class="nav-link">
                            <i class="nav-icon fas fa-check-circle"></i>
                            <p>My Approved Offers</p>
                        </a>
                    </li>

                    <li class="nav-header">ANALYTICS & LOGS</li>
                    <li class="nav-item">
                        <a href="clicks.php" class="nav-link">
                            <i class="nav-icon fas fa-mouse-pointer"></i>
                            <p>Click Logs</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <p>Performance & Conversions</p>
                        </a>
                    </li>

                    <li class="nav-header">TOOLS & POSTBACKS</li>
                    <li class="nav-item">
                        <a href="link-builder.php" class="nav-link">
                            <i class="nav-icon fas fa-link"></i>
                            <p>Link Builder</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="postback.php" class="nav-link">
                            <i class="nav-icon fas fa-code"></i>
                            <p>Postback Settings</p>
                        </a>
                    </li>

                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user-cog"></i>
                            <p>Profile & Payments</p>
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
                        <h1 class="m-0">Dashboard</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Dashboard</li>
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
                                    <label>Date Range</label>
                                    <div class="input-group">
                                        <input type="date" class="form-control" name="start_date" value="<?php echo $_GET['start_date'] ?? ''; ?>">
                                        <div class="input-group-append">
                                            <span class="input-group-text">to</span>
                                        </div>
                                        <input type="date" class="form-control" name="end_date" value="<?php echo $_GET['end_date'] ?? ''; ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Offer</label>
                                    <select class="form-control" name="offer_id">
                                        <option value="">All Offers</option>
                                        <?php foreach ($offers as $offer): ?>
                                        <option value="<?php echo $offer['offer_id']; ?>" 
                                            <?php echo (!empty($_GET['offer_id']) && $_GET['offer_id'] == $offer['offer_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($offer['offer_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>SubID</label>
                                    <input type="text" class="form-control" name="subid" 
                                           value="<?php echo $_GET['subid'] ?? ''; ?>" 
                                           placeholder="Search SubID">
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>IP Address</label>
                                    <input type="text" class="form-control" name="ip" 
                                           value="<?php echo $_GET['ip'] ?? ''; ?>" 
                                           placeholder="192.168.1.x">
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Country</label>
                                    <select class="form-control" name="country">
                                        <option value="">All Countries</option>
                                        <?php foreach ($countries as $country): ?>
                                        <option value="<?php echo $country['country']; ?>"
                                            <?php echo (!empty($_GET['country']) && $_GET['country'] == $country['country']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($country['country']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="form-group text-right">
                                    <a href="clicks.php" class="btn btn-secondary">Clear Filters</a>
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
                                <h3><?php echo number_format($totalRecords); ?></h3>
                                <p>Total Clicks</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-mouse-pointer"></i>
                            </div>
                            <a href="#" class="small-box-footer">Filtered Results <i class="fas fa-chart-bar"></i></a>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-3">
                        <div class="small-box bg-gradient-success">
                            <div class="inner">
                                <h3><?php echo number_format($convRate, 2); ?>%</h3>
                                <p>Conversion Rate</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-percentage"></i>
                            </div>
                            <a href="reports.php" class="small-box-footer">View Conversions <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-3">
                        <div class="small-box bg-gradient-warning">
                            <div class="inner">
                                <h3><?php echo number_format($uniqueClicks); ?></h3>
                                <p>Unique Clicks</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-fingerprint"></i>
                            </div>
                            <a href="#" class="small-box-footer">Unique Visitors <i class="fas fa-users"></i></a>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-3">
                        <div class="small-box bg-gradient-danger">
                            <div class="inner">
                                <h3><?php echo number_format($todayClicks); ?></h3>
                                <p>Today's Clicks</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-calendar-day"></i>
                            </div>
                            <a href="#" class="small-box-footer">Real-time <i class="fas fa-sync-alt"></i></a>
                        </div>
                    </div>
                </div>

                <!-- Click Logs Table -->
                <div class="card card-stat">
                    <div class="card-header">
                        <h3 class="card-title">Report</h3>
                        <div class="card-tools">
                            <div class="btn-group">
                                <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                    <i class="fas fa-minus"></i>
                                </button>
                                <button type="button" class="btn btn-tool" data-card-widget="maximize">
                                    <i class="fas fa-expand"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover" id="clickTable">
                                <thead>
                                    <tr>
                                        <th>Click ID</th>
                                        <th>Offer</th>
                                        <th>Sub IDs</th>
                                        <th>IP</th>
                                        <th>Country</th>
                                        <th>Device</th>
                                        <th>Referrer</th>
                                        <th>Date & Time</th>
                                        <th>Conv.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($clicks)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4">
                                            <i class="fas fa-search fa-2x text-muted mb-3"></i>
                                            <h5>No clicks found</h5>
                                            <p class="text-muted">Try adjusting your filters or check back later.</p>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($clicks as $click): ?>
                                        <tr>
                                            <td><code>#<?php echo $click['click_id']; ?></code></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($click['offer_name']); ?></strong>
                                            </td>
                                            <td>
                                                <?php if ($click['sub1']): ?>
                                                <span class="subid-badge" title="sub1: <?php echo htmlspecialchars($click['sub1']); ?>">
                                                    <?php echo htmlspecialchars(substr($click['sub1'], 0, 10)); ?>
                                                </span>
                                                <?php endif; ?>
                                                <?php if ($click['sub2']): ?>
                                                <span class="subid-badge" title="sub2: <?php echo htmlspecialchars($click['sub2']); ?>">
                                                    <?php echo htmlspecialchars(substr($click['sub2'], 0, 8)); ?>
                                                </span>
                                                <?php endif; ?>
                                                <?php if ($click['sub3']): ?>
                                                <span class="subid-badge" title="sub3: <?php echo htmlspecialchars($click['sub3']); ?>">
                                                    <?php echo htmlspecialchars(substr($click['sub3'], 0, 6)); ?>
                                                </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="ip-cell" data-full-ip="<?php echo htmlspecialchars($click['full_ip']); ?>">
                                                <code><?php echo htmlspecialchars($click['masked_ip']); ?></code>
                                                <div class="ip-tooltip"><?php echo htmlspecialchars($click['full_ip']); ?></div>
                                            </td>
                                            <td>
                                                <?php if ($click['country']): ?>
                                                <span class="badge badge-info">
                                                    <i class="fas fa-globe mr-1"></i><?php echo htmlspecialchars($click['country']); ?>
                                                </span>
                                                <?php else: ?>
                                                <span class="text-muted">Unknown</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <i class="fas fa-<?php echo strpos(strtolower($click['device']), 'mobile') !== false ? 'mobile-alt' : 'desktop'; ?> mr-1"></i>
                                                <?php echo htmlspecialchars($click['device']); ?>
                                            </td>
                                            <td>
                                                <?php if ($click['referer']): ?>
                                                <a href="<?php echo htmlspecialchars($click['referer']); ?>" 
                                                   target="_blank" 
                                                   title="<?php echo htmlspecialchars($click['referer']); ?>"
                                                   class="text-primary">
                                                    <i class="fas fa-external-link-alt"></i> Source
                                                </a>
                                                <?php else: ?>
                                                <span class="text-muted"><i class="fas fa-home"></i> Direct</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-light">
                                                    <?php echo date('M d, H:i', strtotime($click['created_at'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($click['conversions'] > 0): ?>
                                                <span class="badge badge-success">
                                                    <i class="fas fa-check-circle mr-1"></i><?php echo $click['conversions']; ?>
                                                </span>
                                                <?php else: ?>
                                                <span class="text-muted">0</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="card-footer">
                        <div class="row">
                            <div class="col-sm-6">
                                <p>
                                    <i class="fas fa-info-circle text-primary mr-1"></i>
                                    Showing <?php echo min($perPage, count($clicks)); ?> of <?php echo number_format($totalRecords); ?> records
                                    <?php if (!empty($_GET)): ?>
                                    <span class="badge badge-light ml-2">Filtered</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="col-sm-6">
                                <nav class="float-right">
                                    <ul class="pagination">
                                        <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                        
                                        <?php 
                                        $startPage = max(1, $page - 2);
                                        $endPage = min($totalPages, $page + 2);
                                        
                                        for ($i = $startPage; $i <= $endPage; $i++): 
                                        ?>
                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                        <?php endfor; ?>
                                        
                                        <?php if ($page < $totalPages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                                Next <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Export Options -->
                <div class="card card-stat">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-download mr-2"></i>Export Data</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <button type="button" class="btn btn-outline-primary btn-block" id="exportCSV">
                                    <i class="fas fa-file-csv mr-2"></i> Export CSV
                                    <small class="d-block text-muted mt-1">Compatible with Excel, Numbers</small>
                                </button>
                            </div>
                            <div class="col-md-4 mb-3">
                                <button type="button" class="btn btn-outline-success btn-block" id="exportExcel">
                                    <i class="fas fa-file-excel mr-2"></i> Export Excel
                                    <small class="d-block text-muted mt-1">Microsoft Excel format</small>
                                </button>
                            </div>
                            <div class="col-md-4 mb-3">
                                <button type="button" class="btn btn-outline-danger btn-block" id="exportPDF">
                                    <i class="fas fa-file-pdf mr-2"></i> Export PDF
                                    <small class="d-block text-muted mt-1">Print-ready document</small>
                                </button>
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
            <strong>Quantum Affiliate v3.0</strong>
        </div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">Quantum Networks</a>.</strong> All rights reserved.
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
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap4.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    const table = $('#clickTable').DataTable({
        pageLength: <?php echo $perPage; ?>,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[0, 'desc']],
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rt<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search clicks...",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            infoFiltered: "(filtered from _MAX_ total entries)"
        },
        responsive: true
    });
    
    // Show full IP on hover
    $(document).on('mouseenter', '.ip-cell', function() {
        const fullIP = $(this).data('full-ip');
        const tooltip = $(this).find('.ip-tooltip');
        const position = $(this).offset();
        
        tooltip.css({
            top: position.top - tooltip.outerHeight() - 10,
            left: position.left,
            display: 'block'
        });
    }).on('mouseleave', '.ip-cell', function() {
        $(this).find('.ip-tooltip').hide();
    });
    
    // Export functionality
    $('#exportCSV').click(function() {
        const params = new URLSearchParams(window.location.search);
        Swal.fire({
            title: 'Exporting CSV',
            text: 'Preparing your download...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        // Simulate API call
        setTimeout(() => {
            window.location.href = 'api/export-clicks.php?format=csv&' + params.toString();
            Swal.close();
        }, 1000);
    });
    
    $('#exportExcel').click(function() {
        const params = new URLSearchParams(window.location.search);
        Swal.fire({
            title: 'Exporting Excel',
            text: 'Generating Excel file...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        setTimeout(() => {
            window.location.href = 'api/export-clicks.php?format=excel&' + params.toString();
            Swal.close();
        }, 1000);
    });
    
    $('#exportPDF').click(function() {
        const params = new URLSearchParams(window.location.search);
        Swal.fire({
            title: 'Exporting PDF',
            text: 'Creating PDF document...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        setTimeout(() => {
            window.location.href = 'api/export-clicks.php?format=pdf&' + params.toString();
            Swal.close();
        }, 1500);
    });
    
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
    
    // Auto-refresh notification badge every 60 seconds
    function updateClickCount() {
        $.ajax({
            url: 'api/get-today-clicks.php',
            method: 'GET',
            success: function(data) {
                if (data.todayClicks > 0) {
                    $('.navbar-badge').text(data.todayClicks);
                }
            }
        });
    }
    
    // Update every 60 seconds
    setInterval(updateClickCount, 60000);
    
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
    
    // Quick search functionality
    $('#searchInput').on('keyup', function() {
        table.search(this.value).draw();
    });
    
    // Tooltip for subid badges
    $('.subid-badge').hover(function() {
        const title = $(this).attr('title');
        $(this).attr('data-original-title', title);
        $(this).tooltip({
            placement: 'top',
            trigger: 'hover'
        });
        $(this).tooltip('show');
    });
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