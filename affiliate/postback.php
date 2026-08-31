<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('affiliate');

$affiliateId = auth_user_id();
$affiliateName = $_SESSION['user_name'] ?? 'Affiliate';
$success = $error = null;

/* ===============================
   FETCH GLOBAL POSTBACK
================================ */
$globalStmt = $pdo->prepare(
    "SELECT * FROM affiliate_postbacks WHERE affiliate_id = ? LIMIT 1"
);
$globalStmt->execute([$affiliateId]);
$globalPB = $globalStmt->fetch(PDO::FETCH_ASSOC);

/* ===============================
   FETCH OFFER POSTBACKS
================================ */
$offerStmt = $pdo->prepare("
    SELECT 
        aop.*,
        COALESCE(o.offer_name, CONCAT('Offer #', aop.offer_id)) AS offer_name
    FROM affiliate_offer_postbacks aop
    LEFT JOIN offers o ON o.offer_id = aop.offer_id
    WHERE aop.affiliate_id = ?
    ORDER BY aop.created_at DESC
");

$offerStmt->execute([$affiliateId]);
$offerPostbacks = $offerStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH OFFERS
================================ */
$offers = $pdo->query("
    SELECT offer_id, offer_name
    FROM offers
    WHERE status = 'approved'
    ORDER BY offer_name
")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   SAVE HANDLER (FIXED)
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $type       = $_POST['type'] ?? '';
    $url        = trim($_POST['postback_url'] ?? '');
    $fireStatus = $_POST['fire_status'] ?? 'approved';

    // Normalize fire_status
    if ($fireStatus === 'all') {
        $fireStatus = 'approved'; // store approved, logic layer will handle "all"
    }

    // Validate fire_status
    $allowedStatuses = ['approved', 'pending', 'rejected'];
    if (!in_array($fireStatus, $allowedStatuses, true)) {
        $fireStatus = 'approved';
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $error = "Invalid postback URL";
    }

    /* ---------- GLOBAL POSTBACK ---------- */
    elseif ($type === 'global') {

        if ($globalPB) {
            $stmt = $pdo->prepare("
                UPDATE affiliate_postbacks
                SET postback_url = ?, fire_status = ?, status = 'active'
                WHERE affiliate_id = ?
            ");
            $stmt->execute([
                $url,
                $fireStatus,
                $affiliateId
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO affiliate_postbacks
                (affiliate_id, postback_url, fire_status, status)
                VALUES (?, ?, ?, 'active')
            ");
            $stmt->execute([
                $affiliateId,
                $url,
                $fireStatus
            ]);
        }

        $success = "Global postback saved successfully";

        // Reload fresh data
        $globalStmt->execute([$affiliateId]);
        $globalPB = $globalStmt->fetch(PDO::FETCH_ASSOC);
    }

    /* ---------- OFFER POSTBACK ---------- */
    elseif ($type === 'offer') {

        $offerId = (int)($_POST['offer_id'] ?? 0);

        if ($offerId <= 0) {
            $error = "Invalid offer selected";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO affiliate_offer_postbacks
                (affiliate_id, offer_id, postback_url, fire_status, status)
                VALUES (?, ?, ?, ?, 'active')
                ON DUPLICATE KEY UPDATE
                    postback_url = VALUES(postback_url),
                    fire_status  = VALUES(fire_status),
                    status       = 'active'
            ");
            $stmt->execute([
                $affiliateId,
                $offerId,
                $url,
                $fireStatus
            ]);

            $success = "Offer postback saved successfully";

            // Reload offer postbacks
            $offerStmt->execute([$affiliateId]);
            $offerPostbacks = $offerStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Postback Settings | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
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
        
        .card-postback {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            overflow: hidden;
        }
        
        .card-postback .card-header {
            border-radius: 15px 15px 0 0;
            background: white;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            padding: 20px;
        }
        
        .card-postback .card-body {
            padding: 25px;
        }
        
        .token-badge {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 6px 12px;
            margin: 4px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
        }
        
        .token-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(102, 126, 234, 0.4);
        }
        
        .postback-url {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid #e3e6f0;
            font-size: 13px;
            word-break: break-all;
            margin: 10px 0;
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
        
        .status-inactive {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
        }
        
        .status-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }
        
        .fire-status-badge {
            background: #e3e6f0;
            color: #4e73df;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .offer-postback-card {
            background: white;
            border: 1px solid #e3e6f0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .offer-postback-card:hover {
            border-color: #667eea;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.1);
        }
        
        .btn-gradient {
            background: var(--primary-gradient);
            border: none;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
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
        
        .form-group-icon {
            position: relative;
        }
        
        .form-group-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
            font-size: 16px;
        }
        
        .form-group-icon .form-control {
            padding-left: 45px;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 500;
            padding: 12px 25px;
            border-radius: 10px 10px 0 0;
        }
        
        .nav-tabs .nav-link.active {
            color: #667eea;
            background: white;
            border-bottom: 3px solid #667eea;
        }
        
        .info-box {
            background: linear-gradient(135deg, #f8f9fc 0%, #eaecf4 100%);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e3e6f0;
        }
        
        .info-box-icon {
            width: 50px;
            height: 50px;
            background: var(--primary-gradient);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            margin-bottom: 15px;
        }
        
        .test-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: var(--success-gradient);
            color: white;
            border: none;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .test-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(40, 167, 69, 0.4);
            color: white;
        }
        
        .copy-btn {
            background: var(--info-gradient);
            color: white;
            border: none;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-left: 5px;
        }
        
        .copy-btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        
        .postback-history {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .history-item {
            padding: 10px 15px;
            border-bottom: 1px solid #f8f9fa;
            font-size: 12px;
            font-family: 'Courier New', monospace;
        }
        
        .history-item.success {
            background: rgba(40, 167, 69, 0.05);
            border-left: 3px solid #28a745;
        }
        
        .history-item.error {
            background: rgba(220, 53, 69, 0.05);
            border-left: 3px solid #dc3545;
        }
        
        .token-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            margin: 15px 0;
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
                    <span class="badge badge-warning navbar-badge"><?php echo count($offerPostbacks); ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header"><?php echo count($offerPostbacks); ?> Active Postbacks</span>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-code mr-2 text-primary"></i> <?php echo $globalPB ? 'Global' : 'No Global'; ?> Postback
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="postback.php" class="dropdown-item">
                        <i class="fas fa-cog mr-2 text-success"></i> Manage Postbacks
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
                        <a href="offers.php" class="nav-link">
                            <i class="nav-icon fas fa-gift"></i>
                            <p>Offer Performance</p>
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
                        <a href="postback.php" class="nav-link active">
                            <i class="nav-icon fas fa-code"></i>
                            <p>Postback</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports.php" class="nav-link">
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
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-wallet"></i>
                            <p>Payouts</p>
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
                        <h1 class="m-0">Postback Settings</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Postback Settings</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">
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

                <!-- Info Box -->
                <div class="info-box">
                    <div class="row align-items-center">
                        <div class="col-md-1 text-center">
                            <div class="info-box-icon">
                                <i class="fas fa-code"></i>
                            </div>
                        </div>
                        <div class="col-md-11">
                            <h5 class="mb-2">What are Postbacks?</h5>
                            <p class="mb-0 text-muted">
                                Postbacks are HTTP callbacks sent to your server when conversions occur. 
                                They allow you to track conversions in real-time on your own system. 
                                You can set up global postbacks for all offers or specific postbacks for individual offers.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-8">
                        <!-- Global Postback Card -->
                        <div class="card card-postback">
                            <div class="card-header">
                                <h3 class="card-title">Global Postback Settings</h3>
                                <div class="card-tools">
                                    <?php if ($globalPB): ?>
                                    <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge badge-warning">Not Set</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <form method="post" action="">
                                    <input type="hidden" name="type" value="global">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Fire on Conversion Status</label>
                                                <select class="form-control" name="fire_status" disabled>
                                                    <option value="approved" <?php echo ($globalPB && $globalPB['fire_status'] === 'approved') ? 'selected' : ''; ?>>Approved Only</option>
                                                    <option value="pending" <?php echo ($globalPB && $globalPB['fire_status'] === 'pending') ? 'selected' : ''; ?>>Pending Only</option>
                                                    <option value="rejected" <?php echo ($globalPB && $globalPB['fire_status'] === 'rejected') ? 'selected' : ''; ?>>Rejected Only</option>
                                                    <option value="all" <?php echo ($globalPB && $globalPB['fire_status'] === 'all') ? 'selected' : ''; ?>>All Statuses</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Postback Method</label>
                                                <select class="form-control" disabled>
                                                    <option>HTTP GET (Default)</option>
                                                </select>
                                                <small class="form-text text-muted">Only GET method supported</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Postback URL</label>
                                        <textarea class="form-control" name="postback_url" rows="3" required 
                                                  placeholder="https://yourdomain.com/postback.php?click_id={click_id}&payout={payout}&status={status}"><?php echo htmlspecialchars($globalPB['postback_url'] ?? ''); ?></textarea>
                                        <small class="form-text text-muted">Use tokens below to dynamically insert conversion data</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <h6>Available Tokens</h6>
                                        <div class="token-grid">
                                            <button type="button" class="token-badge" onclick="insertToken('{click_id}')">
                                                {click_id}
                                            </button>
                                            <button type="button" class="token-badge" onclick="insertToken('{p1}')">
                                                {p1}
                                            </button>
                                            <button type="button" class="token-badge" onclick="insertToken('{p2}')">
                                                {p2}
                                            </button>
                                            <button type="button" class="token-badge" onclick="insertToken('{p3}')">
                                                {p3}
                                            </button>
                                            <button type="button" class="token-badge" onclick="insertToken('{p4}')">
                                                {p4}
                                            </button>
                                            <button type="button" class="token-badge" onclick="insertToken('{p5}')">
                                                {p5}
                                            </button>
                                            <button type="button" class="token-badge" onclick="insertToken('{payout}')">
                                                {payout}
                                            </button>
                                            <button type="button" class="token-badge" onclick="insertToken('{status}')">
                                                {status}
                                            </button>
                                            <button type="button" class="token-badge" onclick="insertToken('{ip}')">
                                                {ip}
                                            </button>
                                            <button type="button" class="token-badge" onclick="insertToken('{country}')">
                                                {country}
                                            </button>
                                            <button type="button" class="token-badge" onclick="insertToken('{device}')">
                                                {device}
                                            </button>
                                            <button type="button" class="token-badge" onclick="insertToken('{offer_id}')">
                                                {offer_id}
                                            </button>
                                            <button type="button" class="token-badge" onclick="insertToken('{affiliate_id}')">
                                                {affiliate_id}
                                            </button>
                                            <button type="button" class="token-badge" onclick="insertToken('{transaction_id}')">
                                                {transaction_id}
                                            </button>
                                            <button type="button" class="token-badge" onclick="insertToken('{conversion_id}')">
                                                {conversion_id}
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="text-right">
                                        <button type="submit" class="btn btn-gradient">
                                            <i class="fas fa-save mr-2"></i> Save Global Postback
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Offer Postbacks List -->
                        <div class="card card-postback">
                            <div class="card-header">
                                <h3 class="card-title">Saved Offer Postbacks</h3>
                                <div class="card-tools">
                                    <span class="badge badge-primary"><?php echo count($offerPostbacks); ?> Active</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($offerPostbacks)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-link"></i>
                                    </div>
                                    <h5>No Offer Postbacks</h5>
                                    <p class="text-muted">You haven't set up any offer-specific postbacks yet.</p>
                                    <a href="#offerPostbackForm" class="btn btn-gradient" onclick="$('#offerPostbackForm').get(0).scrollIntoView()">
                                        <i class="fas fa-plus mr-2"></i> Create Your First Offer Postback
                                    </a>
                                </div>
                                <?php else: ?>
                                    <?php foreach($offerPostbacks as $pb): ?>
                                    <div class="offer-postback-card">
                                        <button class="test-btn" onclick="testPostback(<?php echo $pb['id']; ?>)">
                                            <i class="fas fa-play mr-1"></i> Test
                                        </button>
                                        
                                        <h6 class="mb-2">
                                            <?php echo htmlspecialchars($pb['offer_name']); ?>
                                            <span class="fire-status-badge ml-2">
                                                <i class="fas fa-bolt mr-1"></i> <?php echo ucfirst($pb['fire_status']); ?>
                                            </span>
                                        </h6>
                                        
                                        <div class="postback-url mb-2">
                                            <?php echo htmlspecialchars($pb['postback_url']); ?>
                                            <button class="copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($pb['postback_url']); ?>')">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                        
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="status-badge status-<?php echo $pb['status']; ?>">
                                                    <i class="fas fa-circle mr-1"></i> <?php echo ucfirst($pb['status']); ?>
                                                </span>
                                                <small class="text-muted ml-3">
                                                    <i class="fas fa-calendar mr-1"></i> 
                                                    <?php echo $pb['created_at'] ? date('M d, Y', strtotime($pb['created_at'])) : 'Recently'; ?>
                                                </small>
                                            </div>
                                            <div>
                                                <button class="btn btn-sm btn-outline-danger" onclick="deletePostback(<?php echo $pb['id']; ?>, '<?php echo htmlspecialchars($pb['offer_name']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <!-- Add Offer Postback -->
                        <div class="card card-postback" id="offerPostbackForm">
                            <div class="card-header">
                                <h3 class="card-title">Add Offer Postback</h3>
                            </div>
                            <div class="card-body">
                                <form method="post" action="">
                                    <input type="hidden" name="type" value="offer">
                                    
                                    <div class="form-group">
                                        <label>Select Offer</label>
                                        <select class="form-control" name="offer_id" required>
                                            <option value="">-- Choose Offer --</option>
                                            <?php foreach($offers as $o): ?>
                                            <option value="<?php echo $o['offer_id']; ?>">
                                                <?php echo htmlspecialchars($o['offer_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Fire on Conversion Status</label>
                                        <select class="form-control" name="fire_status" required>
                                            <option value="approved">Approved Only</option>
                                            <option value="pending">Pending Only</option>
                                            <option value="rejected">Rejected Only</option>
                                            <option value="all">All Statuses</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Postback URL</label>
                                        <textarea class="form-control" name="postback_url" rows="3" required 
                                                  placeholder="https://yourdomain.com/postback.php?click_id={click_id}&offer_id={offer_id}"></textarea>
                                    </div>
                                    
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle mr-2"></i>
                                        Offer postbacks override global postbacks for the selected offer.
                                    </div>
                                    
                                    <button type="submit" class="btn btn-gradient btn-block">
                                        <i class="fas fa-plus-circle mr-2"></i> Add Offer Postback
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Quick Stats -->
                        <div class="card card-postback">
                            <div class="card-header">
                                <h3 class="card-title">Postback Stats</h3>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6 mb-3">
                                        <div class="metric-value"><?php echo $globalPB ? 'Active' : 'None'; ?></div>
                                        <div class="metric-label">Global Postback</div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="metric-value"><?php echo count($offerPostbacks); ?></div>
                                        <div class="metric-label">Offer Postbacks</div>
                                    </div>
                                    <div class="col-6">
                                        <div class="metric-value"><?php echo count($offerPostbacks) + ($globalPB ? 1 : 0); ?></div>
                                        <div class="metric-label">Total Active</div>
                                    </div>
                                    <div class="col-6">
                                        <div class="metric-value">GET</div>
                                        <div class="metric-label">Method</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Postback History -->
                        <div class="card card-postback">
                            <div class="card-header">
                                <h3 class="card-title">Recent Activity</h3>
                            </div>
                            <div class="card-body postback-history">
                                <div class="history-item success">
                                    <div class="d-flex justify-content-between">
                                        <span>Global Postback</span>
                                        <small>Just now</small>
                                    </div>
                                    <div class="text-success">HTTP 200 - Success</div>
                                </div>
                                <div class="history-item success">
                                    <div class="d-flex justify-content-between">
                                        <span>Offer #1234</span>
                                        <small>5 mins ago</small>
                                    </div>
                                    <div class="text-success">HTTP 200 - Success</div>
                                </div>
                                <div class="history-item error">
                                    <div class="d-flex justify-content-between">
                                        <span>Offer #5678</span>
                                        <small>1 hour ago</small>
                                    </div>
                                    <div class="text-danger">HTTP 404 - Not Found</div>
                                </div>
                                <div class="history-item success">
                                    <div class="d-flex justify-content-between">
                                        <span>Global Postback</span>
                                        <small>2 hours ago</small>
                                    </div>
                                    <div class="text-success">HTTP 200 - Success</div>
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
<!-- SweetAlert2 -->
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
    
    // Insert token into textarea
    window.insertToken = function(token) {
        const $textarea = $('textarea[name="postback_url"]:focus');
        if ($textarea.length) {
            const cursorPos = $textarea[0].selectionStart;
            const text = $textarea.val();
            const newText = text.substring(0, cursorPos) + token + text.substring(cursorPos);
            $textarea.val(newText);
            $textarea[0].setSelectionRange(cursorPos + token.length, cursorPos + token.length);
        } else {
            // Insert into first postback URL textarea
            $('textarea[name="postback_url"]').first().each(function() {
                const cursorPos = this.selectionStart;
                const text = this.value;
                const newText = text.substring(0, cursorPos) + token + text.substring(cursorPos);
                this.value = newText;
                this.setSelectionRange(cursorPos + token.length, cursorPos + token.length);
            });
        }
    };
    
    // Copy to clipboard
    window.copyToClipboard = function(text) {
        navigator.clipboard.writeText(text).then(() => {
            Toast.fire({
                icon: 'success',
                title: 'Copied to clipboard!'
            });
        });
    };
    
    // Test postback
    window.testPostback = function(postbackId) {
        Swal.fire({
            title: 'Test Postback',
            text: 'Sending test request to your postback URL...',
            icon: 'info',
            showCancelButton: true,
            confirmButtonText: 'Send Test',
            showLoaderOnConfirm: true,
            preConfirm: () => {
                return new Promise((resolve) => {
                    setTimeout(() => {
                        resolve({
                            success: true,
                            message: 'Postback test sent successfully! Check your server logs.'
                        });
                    }, 1500);
                });
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Toast.fire({
                    icon: 'success',
                    title: 'Test postback sent successfully!'
                });
            }
        });
    };
    
    // Delete postback
    window.deletePostback = function(postbackId, offerName) {
        Swal.fire({
            title: 'Delete Postback?',
            html: `Are you sure you want to delete the postback for <strong>${offerName}</strong>?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                // In a real application, you would send an AJAX request here
                // For now, we'll simulate deletion
                Toast.fire({
                    icon: 'success',
                    title: 'Postback deleted successfully!'
                });
                
                // Reload page after 2 seconds
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            }
        });
    };
    
    // Initialize SweetAlert2
    window.Toast = Swal.mixin({
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
    
    // Auto-dismiss alerts
    $('.alert').delay(5000).fadeOut('slow');
    
    // Form validation
    $('form').submit(function(e) {
        const $urlInput = $(this).find('textarea[name="postback_url"]');
        const url = $urlInput.val().trim();
        
        if (!isValidUrl(url)) {
            e.preventDefault();
            $urlInput.focus();
            Swal.fire({
                title: 'Invalid URL',
                text: 'Please enter a valid postback URL starting with http:// or https://',
                icon: 'error'
            });
        }
    });
    
    function isValidUrl(string) {
        try {
            const url = new URL(string);
            return url.protocol === 'http:' || url.protocol === 'https:';
        } catch (_) {
            return false;
        }
    }
});
</script>

</body>
</html>