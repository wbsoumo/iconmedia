<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('advertiser');

$advertiserId = auth_user_id();
$advertiserName = $_SESSION['user_name'] ?? 'Advertiser';
$success = $error = null;

/* ===============================
   FETCH ALL OFFERS WITH POSTBACK TOKENS
================================ */
$stmt = $pdo->prepare("
    SELECT 
        offer_id,
        offer_name,
        postback_token,
        status,
        created_at,
        conversion_tracking,
        payout,
        currency
    FROM offers 
    WHERE advertiser_id = :aid
    ORDER BY created_at DESC
");
$stmt->execute(['aid' => $advertiserId]);
$offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   POSTBACK STATISTICS
================================ */
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT o.offer_id) as total_offers,
        SUM(o.status = 'active') as active_offers,
        COUNT(DISTINCT cv.conversion_id) as total_conversions,
        COUNT(DISTINCT CASE WHEN cv.source = 'postback' THEN cv.conversion_id END) as postback_conversions,
        COUNT(DISTINCT CASE WHEN cv.source = 'manual' THEN cv.conversion_id END) as manual_conversions,
        COUNT(DISTINCT CASE WHEN cv.source = 'api' THEN cv.conversion_id END) as api_conversions
    FROM offers o
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE o.advertiser_id = :aid
");
$statsStmt->execute(['aid' => $advertiserId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

/* ===============================
   POSTBACK LOGS (RECENT)
================================ */
$logsStmt = $pdo->prepare("
    SELECT 
        cv.conversion_id,
        cv.transaction_id,
        cv.revenue,
        cv.payout,
        cv.status,
        cv.source,
        cv.created_at,
        o.offer_name,
        o.offer_id,
        u.name as affiliate_name,
        u.email as affiliate_email
    FROM conversions cv
    INNER JOIN offers o ON o.offer_id = cv.offer_id
    LEFT JOIN users u ON u.user_id = cv.affiliate_id
    WHERE o.advertiser_id = :aid AND cv.source = 'postback'
    ORDER BY cv.created_at DESC
    LIMIT 20
");
$logsStmt->execute(['aid' => $advertiserId]);
$postbackLogs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   HANDLE POSTBACK TEST
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_postback'])) {
    $offerId = (int)$_POST['offer_id'];
    $testClickId = 'test_' . uniqid();
    $testPayout = (float)$_POST['test_payout'];
    
    // Find the offer to get its token
    $testOffer = array_filter($offers, function($o) use ($offerId) {
        return $o['offer_id'] == $offerId;
    });
    
    if (!empty($testOffer)) {
        $testOffer = reset($testOffer);
        $testUrl = "https://iconmedianetwork.in/postback.php?click_id={$testClickId}&payout={$testPayout}&token={$testOffer['postback_token']}";
        $success = "Test URL generated successfully!";
        $testUrlGenerated = $testUrl;
    } else {
        $error = "Invalid offer selected";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Postback Manager | Advertiser Panel | GVS Icon Media</title>
    
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
        
        .postback-card {
            background: white;
            border-radius: 15px;
            border: 1px solid #e3e6f0;
            margin-bottom: 20px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .postback-card:hover {
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.15);
            border-color: #667eea;
        }
        
        .postback-header {
            background: linear-gradient(135deg, #f8f9fc 0%, #eaecf4 100%);
            padding: 15px 20px;
            border-bottom: 1px solid #e3e6f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .postback-header h5 {
            margin: 0;
            font-weight: 600;
            color: #2d3748;
        }
        
        .postback-body {
            padding: 20px;
        }
        
        .token-box {
            background: #f8f9fc;
            border: 1px solid #e3e6f0;
            border-radius: 8px;
            padding: 15px;
            font-family: monospace;
            word-break: break-all;
            position: relative;
            margin-bottom: 15px;
            font-size: 14px;
        }
        
        .copy-btn {
            position: absolute;
            right: 10px;
            top: 10px;
            background: white;
            border: 1px solid #e3e6f0;
            border-radius: 6px;
            padding: 5px 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 12px;
        }
        
        .copy-btn:hover {
            background: var(--primary-gradient);
            color: white;
            border-color: transparent;
        }
        
        .url-example {
            background: #e8f0fe;
            border-left: 4px solid #667eea;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        
        .url-example code {
            background: white;
            padding: 8px 12px;
            border-radius: 6px;
            display: block;
            margin-top: 10px;
            word-break: break-all;
            border: 1px dashed #667eea;
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
            color: #4e73df;
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
        }
        
        .status-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }
        
        .status-paused {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
        }
        
        .source-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .source-postback {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        
        .source-manual {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }
        
        .source-api {
            background: rgba(0, 123, 255, 0.15);
            color: #007bff;
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
        
        .advertiser-avatar {
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
        
        .token-variables {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        
        .token-variable {
            background: #e3e6f0;
            color: #4e73df;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .token-variable:hover {
            background: #667eea;
            color: white;
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
        
        .test-url-box {
            background: #f8f9fc;
            border: 2px dashed #667eea;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
            word-break: break-all;
            font-family: monospace;
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
                <a href="postback.php" class="nav-link active">Postback Manager</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <a class="nav-link" data-widget="fullscreen" href="#" role="button">
                    <i class="fas fa-expand-arrows-alt"></i>
                </a>
            </li>
            
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                    <div class="advertiser-avatar mr-2">
                        <?php echo strtoupper(substr($advertiserName, 0, 1)); ?>
                    </div>
                    <span><?php echo htmlspecialchars($advertiserName); ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user mr-2"></i> Profile
                    </a>
                    <a href="settings.php" class="dropdown-item">
                        <i class="fas fa-cog mr-2"></i> Account Settings
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
                        <a href="ip_whitelist.php" class="nav-link">
                            <i class="nav-icon fas fa-tower-broadcast"></i>
                            <p>IP Whitelist</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="postback.php" class="nav-link active">
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
                        <h1 class="m-0">Postback Manager</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="tools.php">Tools</a></li>
                            <li class="breadcrumb-item active">Postback Manager</li>
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
                            <h2>Postback URL Management</h2>
                            <p class="mb-0">Configure and manage postback URLs for tracking conversions automatically.</p>
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
                        <div class="metric-value"><?php echo $stats['total_offers'] ?? 0; ?></div>
                        <div class="metric-label">Total Offers</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value"><?php echo $stats['active_offers'] ?? 0; ?></div>
                        <div class="metric-label">Active Offers</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value"><?php echo $stats['postback_conversions'] ?? 0; ?></div>
                        <div class="metric-label">Postback Conversions</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value"><?php echo $stats['total_conversions'] ?? 0; ?></div>
                        <div class="metric-label">Total Conversions</div>
                    </div>
                </div>

                <!-- Instructions Card -->
                <div class="card-dashboard">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-info-circle mr-2"></i> How Postback URLs Work
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h5>What is a Postback URL?</h5>
                                <p class="text-muted">A postback URL (also called server-to-server tracking) allows your server to notify our system when a conversion occurs. When a user completes a desired action on your website, your server sends a request to our postback URL with the conversion details.</p>
                                
                                <h5 class="mt-4">Available Variables</h5>
                                <div class="token-variables">
                                    <span class="token-variable" onclick="copyToClipboard('{click_id}')">{click_id}</span>
                                    <span class="token-variable" onclick="copyToClipboard('{payout}')">{payout}</span>
                                    <span class="token-variable" onclick="copyToClipboard('{revenue}')">{revenue}</span>
                                    <span class="token-variable" onclick="copyToClipboard('{status}')">{status}</span>
                                    <span class="token-variable" onclick="copyToClipboard('{transaction_id}')">{transaction_id}</span>
                                    <span class="token-variable" onclick="copyToClipboard('{affiliate_id}')">{affiliate_id}</span>
                                    <span class="token-variable" onclick="copyToClipboard('{offer_id}')">{offer_id}</span>
                                    <span class="token-variable" onclick="copyToClipboard('{sub1}')">{sub1}</span>
                                    <span class="token-variable" onclick="copyToClipboard('{sub2}')">{sub2}</span>
                                    <span class="token-variable" onclick="copyToClipboard('{sub3}')">{sub3}</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h5>Postback URL Format</h5>
                                <div class="url-example">
                                    <strong>Base URL:</strong>
                                    <code>https://iconmedianetwork.in/postback.php</code>
                                    
                                    <strong class="d-block mt-3">Parameters:</strong>
                                    <ul class="mt-2">
                                        <li><code>click_id</code> - Unique click identifier (required)</li>
                                        <li><code>token</code> - Your unique postback token (required)</li>
                                        <li><code>payout</code> - Amount paid for this conversion</li>
                                        <li><code>status</code> - Conversion status (approved/pending/rejected)</li>
                                        <li><code>transaction_id</code> - Your internal transaction ID</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Postback URLs List -->
                <div class="card-dashboard">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-code mr-2"></i> Your Postback URLs
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-sm btn-gradient" data-toggle="modal" data-target="#testPostbackModal">
                                <i class="fas fa-vial mr-1"></i> Test Postback
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($offers)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-code"></i>
                            </div>
                            <h5>No Offers Found</h5>
                            <p class="text-muted">You need to create an offer first to get postback URLs.</p>
                            <a href="create_offer.php" class="btn btn-gradient">
                                <i class="fas fa-plus-circle mr-2"></i> Create Your First Offer
                            </a>
                        </div>
                        <?php else: ?>
                            <?php foreach ($offers as $offer): ?>
                            <div class="postback-card">
                                <div class="postback-header">
                                    <div>
                                        <h5><?php echo htmlspecialchars($offer['offer_name']); ?></h5>
                                        <small class="text-muted">ID: #<?php echo $offer['offer_id']; ?> | Created: <?php echo date('M d, Y', strtotime($offer['created_at'])); ?></small>
                                    </div>
                                    <div>
                                        <span class="status-badge status-<?php echo $offer['status']; ?>">
                                            <?php echo ucfirst($offer['status']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="postback-body">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <label class="font-weight-bold">Postback Token:</label>
                                            <div class="token-box">
                                                <?php echo htmlspecialchars($offer['postback_token']); ?>
                                                <span class="copy-btn" onclick="copyToClipboard('<?php echo $offer['postback_token']; ?>')">
                                                    <i class="fas fa-copy mr-1"></i> Copy Token
                                                </span>
                                            </div>
                                            
                                            <label class="font-weight-bold mt-3">Full Postback URL:</label>
                                            <div class="token-box">
                                                https://iconmedianetwork.in/postback.php?click_id={click_id}&payout={payout}&token=<?php echo $offer['postback_token']; ?>
                                                <span class="copy-btn" onclick="copyToClipboard('https://iconmedianetwork.in/postback.php?click_id={click_id}&payout={payout}&token=<?php echo $offer['postback_token']; ?>')">
                                                    <i class="fas fa-copy mr-1"></i> Copy URL
                                                </span>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="url-example">
                                                <strong>Offer Details:</strong>
                                                <ul class="list-unstyled mt-2">
                                                    <li><i class="fas fa-dollar-sign mr-2 text-success"></i> Payout: $<?php echo number_format($offer['payout'], 2); ?> <?php echo $offer['currency']; ?></li>
                                                    <li><i class="fas fa-exchange-alt mr-2 text-info"></i> Tracking: <?php echo ucfirst($offer['conversion_tracking'] ?? 'postback'); ?></li>
                                                    <li><i class="fas fa-tag mr-2 text-warning"></i> Status: <?php echo ucfirst($offer['status']); ?></li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <h6>Example Implementation:</h6>
                                        <pre class="bg-light p-3 rounded"><code>// PHP Example
$click_id = $_GET['click_id']; // Get from your tracking link
$payout = 50.00; // Your conversion amount

$postback_url = "https://iconmedianetwork.in/postback.php?click_id={$click_id}&payout={$payout}&token=<?php echo $offer['postback_token']; ?>";

file_get_contents($postback_url); // Send postback</code></pre>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Postback Logs -->
                <?php if (!empty($postbackLogs)): ?>
                <div class="card-dashboard">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-history mr-2"></i> Recent Postback Conversions
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-dashboard" id="logsTable">
                                <thead>
                                    <tr>
                                        <th>Conversion ID</th>
                                        <th>Offer</th>
                                        <th>Affiliate</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Source</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($postbackLogs as $log): ?>
                                    <tr>
                                        <td>#<?php echo $log['conversion_id']; ?></td>
                                        <td><?php echo htmlspecialchars($log['offer_name']); ?></td>
                                        <td>
                                            <?php if ($log['affiliate_name']): ?>
                                                <?php echo htmlspecialchars($log['affiliate_name']); ?>
                                                <small class="text-muted d-block"><?php echo htmlspecialchars($log['affiliate_email']); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">Unknown</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="text-success">$<?php echo number_format($log['revenue'], 2); ?></span>
                                            <small class="text-muted d-block">Payout: $<?php echo number_format($log['payout'], 2); ?></small>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $log['status']; ?>">
                                                <?php echo ucfirst($log['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="source-badge source-<?php echo $log['source']; ?>">
                                                <?php echo ucfirst($log['source']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, H:i', strtotime($log['created_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
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
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- Test Postback Modal -->
<div class="modal fade" id="testPostbackModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-vial mr-2"></i> Test Postback URL
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="offer_id">Select Offer</label>
                        <select name="offer_id" id="offer_id" class="form-control" required>
                            <option value="">Choose an offer...</option>
                            <?php foreach ($offers as $offer): ?>
                            <option value="<?php echo $offer['offer_id']; ?>">
                                <?php echo htmlspecialchars($offer['offer_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="test_payout">Test Payout Amount ($)</label>
                        <input type="number" step="0.01" name="test_payout" id="test_payout" class="form-control" value="10.00" required>
                    </div>
                    
                    <?php if (isset($testUrlGenerated)): ?>
                    <div class="test-url-box">
                        <strong>Generated Test URL:</strong>
                        <code class="d-block mt-2"><?php echo $testUrlGenerated; ?></code>
                        <button type="button" class="btn btn-sm btn-gradient mt-2" onclick="copyToClipboard('<?php echo $testUrlGenerated; ?>')">
                            <i class="fas fa-copy mr-1"></i> Copy URL
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="test_postback" class="btn btn-gradient">
                        <i class="fas fa-play mr-2"></i> Generate Test URL
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
    // Initialize DataTable for logs
    $('#logsTable').DataTable({
        pageLength: 10,
        lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
        order: [[6, 'desc']],
        responsive: true,
        language: {
            emptyTable: "No postback logs found"
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
    
    // Auto-dismiss alerts after 5 seconds
    $('.alert').delay(5000).fadeOut('slow');
    
    // Initialize SweetAlert2 Toast
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });
});

// Copy to clipboard function
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        Swal.fire({
            title: 'Copied!',
            text: 'Copied to clipboard',
            icon: 'success',
            timer: 2000,
            showConfirmButton: false
        });
    }).catch(err => {
        // Fallback for older browsers
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        
        Swal.fire({
            title: 'Copied!',
            text: 'Copied to clipboard',
            icon: 'success',
            timer: 2000,
            showConfirmButton: false
        });
    });
}
</script>

</body>
</html>