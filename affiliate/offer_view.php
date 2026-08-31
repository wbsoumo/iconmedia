<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);


require_role('affiliate');

$affiliateId = auth_user_id();
$affiliateName = $_SESSION['user_name'] ?? 'Affiliate';
$offerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$offerId) {
    die('Invalid offer');
}

/* -------------------------------------------------
   FETCH OFFER DETAILS WITH APPROVAL CHECK
-------------------------------------------------- */

$stmt = $pdo->prepare("
    SELECT
        o.offer_id,
        o.offer_name,
        o.offer_url,
        o.payout,
        o.currency,
        o.offer_description,
        o.category,
        o.preview_url,
        o.status AS offer_status,
        o.created_at,
        
        -- Performance statistics
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
    INNER JOIN affiliate_offer_approval a
        ON a.offer_id = o.offer_id
    WHERE o.offer_id = :oid
      AND a.affiliate_id = :aid
      AND a.status = 'approved'
      AND o.status = 'approved'
    LIMIT 1
");
$stmt->execute([
    'oid' => $offerId,
    'aid' => $affiliateId
]);

$offer = $stmt->fetch();

if (!$offer) {
    die('Offer not approved for you');
}

/* -------------------------------------------------
   CALCULATE STATISTICS
-------------------------------------------------- */

$conversionRate = $offer['total_clicks'] > 0 
    ? ($offer['approved_conversions'] / $offer['total_clicks']) * 100 
    : 0;
$epc = $offer['total_clicks'] > 0 
    ? $offer['total_earnings'] / $offer['total_clicks'] 
    : 0;

/* -------------------------------------------------
   GENERATE TRACKING LINKS
-------------------------------------------------- */

$baseTrackingLink = "https://iconmedianetwork.in/click.php?offer={$offer['offer_id']}&aff={$affiliateId}";

// Example tracking links with different subids
$exampleLinks = [
    'Basic' => $baseTrackingLink . '&sub1=facebook&sub2=campaign1',
    'With Multiple SubIDs' => $baseTrackingLink . '&sub1=instagram&sub2=story&sub3=male&sub4=18-25',
    'Minimal' => $baseTrackingLink . '&sub1=email'
];

/* -------------------------------------------------
   FETCH RECENT CONVERSIONS FOR THIS OFFER
-------------------------------------------------- */

$recentConversionsStmt = $pdo->prepare("
    SELECT 
        cv.conversion_id,
        cv.status,
        cv.payout,
        cv.created_at,
        cv.transaction_id,
        c.sub1,
        c.sub2
    FROM conversions cv
    INNER JOIN clicks c ON cv.click_id = c.click_id
    WHERE c.offer_id = :oid
      AND cv.affiliate_id = :aid
    ORDER BY cv.created_at DESC
    LIMIT 5
");
$recentConversionsStmt->execute([
    'oid' => $offerId,
    'aid' => $affiliateId
]);
$recentConversions = $recentConversionsStmt->fetchAll();

/* -------------------------------------------------
   FETCH TOP SUBIDS FOR THIS OFFER
-------------------------------------------------- */

$topSubidsStmt = $pdo->prepare("
    SELECT 
        sub1,
        COUNT(*) as clicks,
        COUNT(DISTINCT cv.conversion_id) as conversions,
        IFNULL(SUM(cv.payout), 0) as earnings,
        ROUND((COUNT(DISTINCT cv.conversion_id) / COUNT(*)) * 100, 2) as cr
    FROM clicks c
    LEFT JOIN conversions cv ON c.click_id = cv.click_id AND cv.status = 'approved'
    WHERE c.offer_id = :oid
      AND c.affiliate_id = :aid
      AND sub1 IS NOT NULL
    GROUP BY sub1
    HAVING clicks > 0
    ORDER BY earnings DESC
    LIMIT 5
");
$topSubidsStmt->execute([
    'oid' => $offerId,
    'aid' => $affiliateId
]);
$topSubids = $topSubidsStmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($offer['offer_name']); ?> | GVS Icon Media</title>
    
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
        
        .tracking-link-box {
            background: #f8f9fc;
            border: 1px solid #e3e6f0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .link-input {
            font-family: 'Courier New', monospace;
            font-size: 14px;
            background: white;
            border: 2px solid #e3e6f0;
            border-radius: 8px;
            padding: 12px 15px;
            width: 100%;
            margin-bottom: 10px;
        }
        
        .link-input:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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
        
        .copy-btn {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .copy-btn:hover {
            transform: scale(1.05);
        }
        
        .copy-btn.copied {
            background: #28a745 !important;
            color: white !important;
        }
        
        .subid-badge {
            background: #e3e6f0;
            color: #4e73df;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            margin: 2px;
        }
        
        .example-link {
            background: #f8f9fc;
            border-left: 4px solid #667eea;
            padding: 12px 15px;
            margin-bottom: 10px;
            border-radius: 0 8px 8px 0;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            word-break: break-all;
        }
        
        .offer-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
        }
        
        .status-badge {
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
        
        .status-rejected {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }
        
        .qr-code-container {
            text-align: center;
            padding: 20px;
            background: white;
            border-radius: 10px;
            border: 1px solid #e3e6f0;
        }
        
        .info-box {
            border-left: 4px solid #667eea;
            padding-left: 15px;
            margin-bottom: 15px;
        }
        
        .info-box h6 {
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .subid-performance {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px dashed #e3e6f0;
        }
        
        .subid-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f8f9fc;
        }
        
        .subid-item:last-child {
            border-bottom: none;
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
                    <span class="badge badge-warning navbar-badge"><?php echo count($recentConversions); ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header"><?php echo count($recentConversions); ?> Recent Conversions</span>
                    <div class="dropdown-divider"></div>
                    <?php foreach ($recentConversions as $conv): ?>
                    <a href="reports.php" class="dropdown-item">
                        <i class="fas fa-check-circle mr-2 text-success"></i> Conversion #<?php echo $conv['conversion_id']; ?>
                        <span class="float-right text-muted text-sm">$<?php echo number_format($conv['payout'], 2); ?></span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <?php endforeach; ?>
                    <a href="reports.php" class="dropdown-item">
                        <i class="fas fa-list mr-2"></i> View all conversions
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
                        <a href="dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-tachometer-alt"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>

                    <li class="nav-header">CAMPAIGNS</li>
                    <li class="nav-item">
                        <a href="offers.php" class="nav-link active">
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
                        <h1 class="m-0">Offer Details</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="offers.php">Offers</a></li>
                            <li class="breadcrumb-item active"><?php echo htmlspecialchars($offer['offer_name']); ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">
                <!-- Offer Header -->
                <div class="offer-header">
                    <div class="row">
                        <div class="col-md-8">
                            <h2 class="mb-2"><?php echo htmlspecialchars($offer['offer_name']); ?></h2>
                            <div class="d-flex align-items-center">
                                <span class="badge badge-light mr-3">
                                    <i class="fas fa-tag mr-1"></i> <?php echo htmlspecialchars($offer['category'] ?? 'General'); ?>
                                </span>
                                <span class="badge badge-light mr-3">
                                    <i class="fas fa-calendar mr-1"></i> Added: <?php echo date('M d, Y', strtotime($offer['created_at'])); ?>
                                </span>
                                <span class="badge badge-success">
                                    <i class="fas fa-check-circle mr-1"></i> Approved
                                </span>
                            </div>
                        </div>
                        <div class="col-md-4 text-right">
                            <h1 class="display-4 font-weight-bold">$<?php echo number_format($offer['payout'], 2); ?></h1>
                            <p class="mb-0">Payout per conversion</p>
                        </div>
                    </div>
                </div>

                <!-- Performance Stats -->
                <div class="row mb-4">
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-gradient-info">
                            <div class="inner">
                                <h3><?php echo number_format($offer['total_clicks']); ?></h3>
                                <p>Total Clicks</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-mouse-pointer"></i>
                            </div>
                            <a href="clicks.php?offer_id=<?php echo $offerId; ?>" class="small-box-footer">View Click Logs <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-gradient-success">
                            <div class="inner">
                                <h3><?php echo number_format($offer['approved_conversions']); ?></h3>
                                <p>Approved Conversions</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <a href="reports.php?offer_id=<?php echo $offerId; ?>" class="small-box-footer">View Conversions <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-gradient-warning">
                            <div class="inner">
                                <h3>$<?php echo number_format($offer['total_earnings'], 2); ?></h3>
                                <p>Total Earnings</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-dollar-sign"></i>
                            </div>
                            <a href="profile.php" class="small-box-footer">View Payouts <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-gradient-danger">
                            <div class="inner">
                                <h3><?php echo number_format($conversionRate, 2); ?>%</h3>
                                <p>Conversion Rate</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-percentage"></i>
                            </div>
                            <a href="#" class="small-box-footer">EPC: $<?php echo number_format($epc, 4); ?> <i class="fas fa-chart-line"></i></a>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Left Column: Tracking Links & Info -->
                    <div class="col-lg-8">
                        <!-- Main Tracking Link -->
                        <div class="card card-stat">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-link mr-2"></i>Your Tracking Link</h3>
                                <div class="card-tools">
                                    <button class="btn btn-sm btn-gradient" id="copyMainLink">
                                        <i class="fas fa-copy mr-1"></i> Copy
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="tracking-link-box">
                                    <input type="text" 
                                           class="link-input" 
                                           id="mainTrackingLink" 
                                           value="<?php echo htmlspecialchars($baseTrackingLink); ?>" 
                                           readonly>
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <a href="<?php echo htmlspecialchars($baseTrackingLink); ?>" 
                                               target="_blank" 
                                               class="btn btn-outline-primary btn-sm">
                                                <i class="fas fa-external-link-alt mr-1"></i> Test Link
                                            </a>
                                            <button class="btn btn-outline-success btn-sm ml-2" id="generateQR">
                                                <i class="fas fa-qrcode mr-1"></i> Generate QR
                                            </button>
                                        </div>
                                        <div>
                                            <span class="text-muted">
                                                <i class="fas fa-info-circle"></i> Click to copy
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- QR Code Container (Hidden by default) -->
                                <div class="qr-code-container" id="qrCodeContainer" style="display: none;">
                                    <h5>QR Code for Mobile Sharing</h5>
                                    <div id="qrcode" class="my-3"></div>
                                    <p class="text-muted small">Scan this QR code to open the offer on mobile devices</p>
                                    <button class="btn btn-sm btn-outline-secondary" id="downloadQR">
                                        <i class="fas fa-download mr-1"></i> Download QR
                                    </button>
                                </div>

                                <!-- Link Builder -->
                                <div class="mt-4">
                                    <h5><i class="fas fa-sliders-h mr-2"></i>Customize Your Link</h5>
                                    <div class="row mt-3">
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label class="small">Sub1 (Source)</label>
                                                <input type="text" class="form-control form-control-sm" id="sub1" placeholder="e.g., facebook">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label class="small">Sub2 (Campaign)</label>
                                                <input type="text" class="form-control form-control-sm" id="sub2" placeholder="e.g., summer2024">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label class="small">Sub3 (Ad Group)</label>
                                                <input type="text" class="form-control form-control-sm" id="sub3" placeholder="e.g., mobile">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label class="small">Sub4 (Creative)</label>
                                                <input type="text" class="form-control form-control-sm" id="sub4" placeholder="e.g., banner1">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label class="small">Sub5 (Keyword)</label>
                                                <input type="text" class="form-control form-control-sm" id="sub5" placeholder="e.g., buy_now">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="small">Custom Parameters</label>
                                                <input type="text" class="form-control form-control-sm" id="customParams" placeholder="e.g., &utm_source=email&utm_medium=cpc">
                                            </div>
                                        </div>
                                        <div class="col-md-3 d-flex align-items-end">
                                            <button class="btn btn-gradient btn-sm btn-block" id="generateCustomLink">
                                                <i class="fas fa-magic mr-1"></i> Generate Link
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Custom Link Output -->
                                    <div class="mt-3" id="customLinkContainer" style="display: none;">
                                        <h6>Your Custom Link:</h6>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="customLinkOutput" readonly>
                                            <div class="input-group-append">
                                                <button class="btn btn-outline-success" id="copyCustomLink">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Example Links -->
                                <div class="mt-4">
                                    <h5><i class="fas fa-lightbulb mr-2"></i>Example Tracking Links</h5>
                                    <?php foreach ($exampleLinks as $title => $link): ?>
                                    <div class="example-link">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <small class="text-primary font-weight-bold"><?php echo $title; ?>:</small><br>
                                                <code class="text-dark"><?php echo htmlspecialchars($link); ?></code>
                                            </div>
                                            <button class="btn btn-sm btn-outline-secondary copy-example" data-link="<?php echo htmlspecialchars($link); ?>">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Offer Description & Details -->
                        <div class="card card-stat">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Offer Details</h3>
                            </div>
                            <div class="card-body">
                                <?php if ($offer['offer_description']): ?>
                                <div class="info-box">
                                    <h6>Description</h6>
                                    <p class="text-muted"><?php echo nl2br(htmlspecialchars($offer['offer_description'])); ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="info-box">
                                            <h6>Preview URL</h6>
                                            <a href="<?php echo htmlspecialchars($offer['offer_url']); ?>" 
                                               target="_blank" 
                                               class="text-primary">
                                                <i class="fas fa-external-link-alt mr-1"></i>
                                                <?php echo htmlspecialchars($offer['offer_url']); ?>
                                            </a>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-box">
                                            <h6>Offer Status</h6>
                                            <span class="badge badge-success">
                                                <i class="fas fa-check-circle mr-1"></i> Active & Approved
                                            </span>
                                            <p class="text-muted small mt-1">You are approved to promote this offer</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="info-box">
                                    <h6>Tracking Parameters Available</h6>
                                    <div class="d-flex flex-wrap">
                                        <span class="subid-badge">sub1 (Source)</span>
                                        <span class="subid-badge">sub2 (Campaign)</span>
                                        <span class="subid-badge">sub3 (Ad Group)</span>
                                        <span class="subid-badge">sub4 (Creative)</span>
                                        <span class="subid-badge">sub5 (Keyword)</span>
                                        <span class="subid-badge">aff (Auto-added)</span>
                                        <span class="subid-badge">offer (Auto-added)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Stats & Tools -->
                    <div class="col-lg-4">
                        <!-- Top Performing SubIDs -->
                        <?php if (!empty($topSubids)): ?>
                        <div class="card card-stat">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-chart-line mr-2"></i>Top Performing SubIDs</h3>
                            </div>
                            <div class="card-body">
                                <div class="subid-performance">
                                    <?php foreach ($topSubids as $sub): ?>
                                    <div class="subid-item">
                                        <div>
                                            <strong><code><?php echo htmlspecialchars($sub['sub1']); ?></code></strong>
                                            <div class="small text-muted">
                                                <?php echo $sub['clicks']; ?> clicks • 
                                                <?php echo $sub['conversions']; ?> conv • 
                                                <?php echo $sub['cr']; ?>% CR
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <span class="font-weight-bold text-success">
                                                $<?php echo number_format($sub['earnings'], 2); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="clicks.php?offer_id=<?php echo $offerId; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-search mr-1"></i> Analyze All SubIDs
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Quick Actions -->
                        <div class="card card-stat">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="clicks.php?offer_id=<?php echo $offerId; ?>" class="btn btn-outline-info">
                                        <i class="fas fa-mouse-pointer mr-2"></i> View Click Logs
                                    </a>
                                    <a href="reports.php?offer_id=<?php echo $offerId; ?>" class="btn btn-outline-success">
                                        <i class="fas fa-exchange-alt mr-2"></i> View Conversions
                                    </a>
                                    <a href="link-builder.php?offer_id=<?php echo $offerId; ?>" class="btn btn-outline-primary">
                                        <i class="fas fa-link mr-2"></i> Advanced Link Builder
                                    </a>
                                    <a href="offers.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left mr-2"></i> Back to All Offers
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Tracking Tips -->
                        <div class="card card-stat">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-lightbulb mr-2"></i>Tracking Tips</h3>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled">
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success mr-2"></i>
                                        <small>Use sub1 to track traffic source</small>
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success mr-2"></i>
                                        <small>Use sub2 for campaign names</small>
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success mr-2"></i>
                                        <small>Use sub3 for ad group/placement</small>
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success mr-2"></i>
                                        <small>Test links before sending traffic</small>
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success mr-2"></i>
                                        <small>Monitor conversion rates by subID</small>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- Offer ID Reference -->
                        <div class="card card-stat">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-fingerprint mr-2"></i>Offer Reference</h3>
                            </div>
                            <div class="card-body">
                                <div class="text-center">
                                    <div class="mb-3">
                                        <div class="text-muted small">Offer ID</div>
                                        <h3 class="text-primary">#<?php echo $offer['offer_id']; ?></h3>
                                    </div>
                                    <div class="mb-3">
                                        <div class="text-muted small">Your Affiliate ID</div>
                                        <h4 class="text-success">#<?php echo $affiliateId; ?></h4>
                                    </div>
                                    <div>
                                        <div class="text-muted small">Tracking Format</div>
                                        <code class="small">offer=<?php echo $offer['offer_id']; ?>&aff=<?php echo $affiliateId; ?></code>
                                    </div>
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
<!-- QR Code Generator -->
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>

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
    
    // Copy main tracking link
    $('#copyMainLink').click(function() {
        const link = $('#mainTrackingLink').val();
        copyToClipboard(link, $(this));
    });
    
    // Click to copy from input field
    $('#mainTrackingLink').click(function() {
        $(this).select();
        copyToClipboard($(this).val(), $('#copyMainLink'));
    });
    
    // Copy example links
    $('.copy-example').click(function() {
        const link = $(this).data('link');
        copyToClipboard(link, $(this));
    });
    
    // Generate custom link
    $('#generateCustomLink').click(function() {
        const baseLink = $('#mainTrackingLink').val();
        const sub1 = $('#sub1').val();
        const sub2 = $('#sub2').val();
        const sub3 = $('#sub3').val();
        const sub4 = $('#sub4').val();
        const sub5 = $('#sub5').val();
        const customParams = $('#customParams').val();
        
        let customLink = baseLink;
        
        // Add sub parameters
        if (sub1) customLink += '&sub1=' + encodeURIComponent(sub1);
        if (sub2) customLink += '&sub2=' + encodeURIComponent(sub2);
        if (sub3) customLink += '&sub3=' + encodeURIComponent(sub3);
        if (sub4) customLink += '&sub4=' + encodeURIComponent(sub4);
        if (sub5) customLink += '&sub5=' + encodeURIComponent(sub5);
        
        // Add custom parameters
        if (customParams) {
            // Ensure it starts with &
            if (!customParams.startsWith('&')) {
                customLink += '&' + customParams;
            } else {
                customLink += customParams;
            }
        }
        
        // Show custom link
        $('#customLinkOutput').val(customLink);
        $('#customLinkContainer').show();
    });
    
    // Copy custom link
    $('#copyCustomLink').click(function() {
        const link = $('#customLinkOutput').val();
        copyToClipboard(link, $(this));
    });
    
    // Generate QR Code
    $('#generateQR').click(function() {
        const link = $('#mainTrackingLink').val();
        
        // Clear previous QR code
        $('#qrcode').empty();
        
        // Generate new QR code
        QRCode.toCanvas(document.getElementById('qrcode'), link, {
            width: 200,
            margin: 1,
            color: {
                dark: '#000000',
                light: '#ffffff'
            }
        }, function(error) {
            if (error) {
                console.error(error);
                alert('Error generating QR code');
                return;
            }
            
            // Show QR code container
            $('#qrCodeContainer').slideDown();
        });
    });
    
    // Download QR Code
    $('#downloadQR').click(function() {
        const canvas = document.querySelector('#qrcode canvas');
        const link = document.createElement('a');
        link.download = 'offer-qr-code.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    });
    
    // Utility function to copy to clipboard
    function copyToClipboard(text, button) {
        navigator.clipboard.writeText(text).then(function() {
            const originalHTML = button.html();
            button.addClass('copied');
            button.html('<i class="fas fa-check mr-1"></i> Copied!');
            
            // Show toast notification
            Toast.fire({
                icon: 'success',
                title: 'Link copied to clipboard!'
            });
            
            // Reset button after 2 seconds
            setTimeout(function() {
                button.removeClass('copied');
                button.html(originalHTML);
            }, 2000);
        }).catch(function(err) {
            console.error('Failed to copy: ', err);
            Toast.fire({
                icon: 'error',
                title: 'Failed to copy link'
            });
        });
    }
    
    // Auto-select text on click for all inputs
    $('input[readonly]').click(function() {
        $(this).select();
    });
    
    // Quick subid suggestions
    $('#sub1').on('focus', function() {
        if (!$(this).val()) {
            $(this).attr('placeholder', 'facebook, google, instagram, tiktok, email');
        }
    });
    
    $('#sub2').on('focus', function() {
        if (!$(this).val()) {
            $(this).attr('placeholder', 'summer_sale, black_friday, mobile_app');
        }
    });
    
    // Test link functionality
    $('a[target="_blank"]').click(function(e) {
        e.stopPropagation();
        Toast.fire({
            icon: 'info',
            title: 'Opening in new tab...'
        });
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