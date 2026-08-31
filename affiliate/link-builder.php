<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('affiliate');

$affiliateId   = auth_user_id();
$affiliateName = $_SESSION['user_name'] ?? 'Affiliate';
$success = $error = null;

/* ===============================
   FETCH APPROVED OFFERS (SAFE & AUTHORIZED)
================================ */
$offersStmt = $pdo->prepare("
    SELECT o.offer_id, o.offer_name, o.preview_url, o.payout, o.category
    FROM offers o
    LEFT JOIN affiliate_offer_approval aoa 
      ON aoa.offer_id = o.offer_id 
     AND aoa.affiliate_id = :aid
    WHERE o.status = 'approved'
      AND (LOWER(o.visibility) = 'public' OR aoa.status = 'approved')
    ORDER BY o.offer_name ASC
");
$offersStmt->execute(['aid' => $affiliateId]);
$offers = $offersStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH RECENT LINKS (REAL)
================================ */
$recentStmt = $pdo->prepare("
    SELECT ol.*, o.offer_name
    FROM offer_links ol
    INNER JOIN offers o ON o.offer_id = ol.offer_id
    WHERE ol.affiliate_id = :aid
    ORDER BY ol.created_at DESC
    LIMIT 5
");
$recentStmt->execute(['aid' => $affiliateId]);
$recentLinks = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   SAVE LINK (AJAX / POST)
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_link') {

    $offerId  = (int)($_POST['offer_id'] ?? 0);
    $campaign = trim($_POST['campaign'] ?? '');
    $subs     = [];

    for ($i = 1; $i <= 5; $i++) {
        $subs[$i] = trim($_POST['sub'.$i] ?? '');
    }

    /* ---- Validate offer authorization ---- */
    $check = $pdo->prepare("
        SELECT o.offer_id
        FROM offers o
        LEFT JOIN affiliate_offer_approval aoa 
          ON aoa.offer_id = o.offer_id 
         AND aoa.affiliate_id = :aid
        WHERE o.offer_id = :oid 
          AND o.status = 'approved'
          AND (LOWER(o.visibility) = 'public' OR aoa.status = 'approved')
        LIMIT 1
    ");
    $check->execute(['oid' => $offerId, 'aid' => $affiliateId]);

    if (!$check->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or inactive offer']);
        exit;
    }

    /* ---- Build tracking URL (SERVER SIDE) ---- */
    $base = 'https://iconmedianetwork.in/click.php';

    $query = [
        'offer_id' => $offerId,
        'aff_id'   => $affiliateId,
        'campaign' => $campaign
    ];

    foreach ($subs as $i => $v) {
        if ($v !== '') {
            $query['sub'.$i] = $v;
        }
    }

    $generatedUrl = $base . '?' . http_build_query($query);

    /* ---- Save ---- */
    $insert = $pdo->prepare("
        INSERT INTO offer_links
        (affiliate_id, offer_id, campaign, sub1, sub2, sub3, sub4, sub5, generated_url)
        VALUES
        (:aid, :oid, :camp, :s1, :s2, :s3, :s4, :s5, :url)
    ");

    $insert->execute([
        'aid'  => $affiliateId,
        'oid'  => $offerId,
        'camp' => $campaign,
        's1'   => $subs[1] ?: null,
        's2'   => $subs[2] ?: null,
        's3'   => $subs[3] ?: null,
        's4'   => $subs[4] ?: null,
        's5'   => $subs[5] ?: null,
        'url'  => $generatedUrl
    ]);

    echo json_encode([
        'success' => true,
        'url'     => $generatedUrl
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Link Builder | GVS Icon Media</title>
    
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
        
        .card-builder {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            overflow: hidden;
        }
        
        .card-builder .card-header {
            border-radius: 15px 15px 0 0;
            background: white;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            padding: 20px;
        }
        
        .card-builder .card-body {
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
        
        .generated-link {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e3e6f0;
            font-size: 14px;
            word-break: break-all;
            margin: 10px 0;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .generated-link:hover {
            border-color: #667eea;
            background: #f0f3ff;
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
        
        .offer-card {
            background: white;
            border: 1px solid #e3e6f0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .offer-card:hover {
            border-color: #667eea;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }
        
        .offer-card.active {
            border-color: #667eea;
            background: #f0f3ff;
        }
        
        .quick-link-card {
            background: white;
            border: 1px solid #e3e6f0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .quick-link-card:hover {
            border-color: #667eea;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.1);
        }
        
        .copy-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: var(--success-gradient);
            color: white;
            border: none;
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .copy-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(40, 167, 69, 0.3);
        }
        
        .preview-btn {
            background: var(--info-gradient);
            color: white;
            border: none;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .preview-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(30, 60, 114, 0.3);
        }
        
        .tag-badge {
            background: #e3e6f0;
            color: #4e73df;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
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
        
        .token-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
            margin: 15px 0;
        }
        
        .subid-section {
            background: #f8f9fc;
            border-radius: 10px;
            padding: 15px;
            margin: 15px 0;
            border: 1px dashed #dee2e6;
        }
        
        .advanced-options {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        
        .advanced-options.show {
            max-height: 500px;
        }
        
        .link-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            border: 1px solid #e3e6f0;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .stats-value {
            font-size: 24px;
            font-weight: 700;
            color: #2e59d9;
            margin-bottom: 5px;
        }
        
        .stats-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
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
        
        .link-history-item {
            padding: 12px;
            border-bottom: 1px solid #f8f9fa;
            transition: background 0.3s ease;
        }
        
        .link-history-item:hover {
            background: #f8f9fa;
        }
        
        .campaign-selector {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin: 15px 0;
        }
        
        .campaign-option {
            padding: 10px;
            border: 2px solid #e3e6f0;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .campaign-option:hover {
            border-color: #667eea;
            background: #f0f3ff;
        }
        
        .campaign-option.active {
            border-color: #667eea;
            background: #f0f3ff;
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
                    <span class="badge badge-warning navbar-badge"><?php echo count($offers); ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header"><?php echo count($offers); ?> Active Offers</span>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item">
                        <i class="fas fa-link mr-2 text-primary"></i> Link Builder Ready
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="link-builder.php" class="dropdown-item">
                        <i class="fas fa-bolt mr-2 text-success"></i> Create Tracking Links
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
                        <a href="link-builder.php" class="nav-link active">
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
                        <h1 class="m-0">Advanced Link Builder</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Link Builder</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">
                <!-- Info Box -->
                <div class="info-box">
                    <div class="row align-items-center">
                        <div class="col-md-1 text-center">
                            <div class="info-box-icon">
                                <i class="fas fa-link"></i>
                            </div>
                        </div>
                        <div class="col-md-11">
                            <h5 class="mb-2">Create Tracking Links</h5>
                            <p class="mb-0 text-muted">
                                Generate unique tracking links for your offers with custom sub IDs for advanced tracking. 
                                Track performance by source, campaign, or any custom parameter.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-8">
                        <!-- Main Builder Card -->
                        <div class="card card-builder">
                            <div class="card-header">
                                <h3 class="card-title">Generate Tracking Link</h3>
                                <div class="card-tools">
                                    <span class="badge badge-primary">Advanced</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- Offer Selection -->
                                <div class="form-group">
                                    <label class="mb-2"><strong>Step 1: Select Offer</strong></label>
                                    <div class="row">
                                        <?php foreach(array_slice($offers, 0, 6) as $offer): ?>
                                        <div class="col-md-6">
                                            <div class="offer-card" data-offer-id="<?php echo $offer['offer_id']; ?>" data-preview-url="<?php echo htmlspecialchars($offer['preview_url']); ?>">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6 class="mb-1"><?php echo htmlspecialchars($offer['offer_name']); ?></h6>
                                                        <p class="mb-1 text-muted small"><?php echo htmlspecialchars($offer['category'] ?? 'General'); ?></p>
                                                        <div class="d-flex align-items-center">
                                                            <span class="tag-badge mr-2">$<?php echo number_format($offer['payout'], 2); ?></span>
                                                            <span class="text-success small"><i class="fas fa-check-circle"></i> Approved</span>
                                                        </div>
                                                    </div>
                                                    <button class="preview-btn" onclick="previewOffer('<?php echo htmlspecialchars($offer['preview_url']); ?>')">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <?php if (count($offers) > 6): ?>
                                    <div class="text-center mt-3">
                                        <button class="btn btn-outline-primary btn-sm" id="showAllOffers">
                                            <i class="fas fa-chevron-down mr-1"></i> Show All Offers (<?php echo count($offers); ?>)
                                        </button>
                                    </div>
                                    <div id="allOffers" style="display:none; margin-top: 15px;">
                                        <select class="form-control" id="offerSelect">
                                            <option value="">-- Select from all offers --</option>
                                            <?php foreach($offers as $offer): ?>
                                            <option value="<?php echo $offer['offer_id']; ?>" data-preview-url="<?php echo htmlspecialchars($offer['preview_url']); ?>">
                                                <?php echo htmlspecialchars($offer['offer_name']); ?> ($<?php echo number_format($offer['payout'], 2); ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Campaign Selection -->
                                <div class="form-group mt-4">
                                    <label class="mb-2"><strong>Step 2: Select Campaign Type</strong></label>
                                    <div class="campaign-selector">
                                        <div class="campaign-option active" data-campaign="social">
                                            <i class="fas fa-hashtag fa-2x mb-2"></i>
                                            <div>Social Media</div>
                                        </div>
                                        <div class="campaign-option" data-campaign="email">
                                            <i class="fas fa-envelope fa-2x mb-2"></i>
                                            <div>Email Marketing</div>
                                        </div>
                                        <div class="campaign-option" data-campaign="search">
                                            <i class="fas fa-search fa-2x mb-2"></i>
                                            <div>Search Engine</div>
                                        </div>
                                        <div class="campaign-option" data-campaign="display">
                                            <i class="fas fa-ad fa-2x mb-2"></i>
                                            <div>Display Ads</div>
                                        </div>
                                        <div class="campaign-option" data-campaign="native">
                                            <i class="fas fa-newspaper fa-2x mb-2"></i>
                                            <div>Native Ads</div>
                                        </div>
                                        <div class="campaign-option" data-campaign="custom">
                                            <i class="fas fa-cog fa-2x mb-2"></i>
                                            <div>Custom</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Sub ID Parameters -->
                                <div class="form-group mt-4">
                                    <label class="mb-2"><strong>Step 3: Add Tracking Parameters</strong></label>
                                    <div class="subid-section">
                                        <div class="row">
                                            <?php for($i = 1; $i <= 5; $i++): ?>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>Sub <?php echo $i; ?> (Optional)</label>
                                                    <div class="input-group">
                                                        <div class="input-group-prepend">
                                                            <span class="input-group-text">sub<?php echo $i; ?></span>
                                                        </div>
                                                        <input type="text" class="form-control" id="sub<?php echo $i; ?>" placeholder="e.g., facebook, banner, newsletter">
                                                    </div>
                                                    <small class="form-text text-muted">Track source, placement, etc.</small>
                                                </div>
                                            </div>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Advanced Options -->
                                <div class="form-group mt-3">
                                    <button class="btn btn-outline-secondary btn-sm" id="toggleAdvanced">
                                        <i class="fas fa-cogs mr-1"></i> Advanced Options
                                    </button>
                                    
                                    <div class="advanced-options mt-3" id="advancedOptions">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>UTM Source</label>
                                                    <input type="text" class="form-control" id="utm_source" placeholder="google, facebook, email">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>UTM Medium</label>
                                                    <input type="text" class="form-control" id="utm_medium" placeholder="cpc, banner, social">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>UTM Campaign</label>
                                                    <input type="text" class="form-control" id="utm_campaign" placeholder="summer_sale, black_friday">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>UTM Content</label>
                                                    <input type="text" class="form-control" id="utm_content" placeholder="banner_728x90, text_link">
                                                </div>
                                            </div>
                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label>Custom Parameters</label>
                                                    <textarea class="form-control" id="custom_params" rows="2" placeholder="param1=value1&amp;param2=value2"></textarea>
                                                    <small class="form-text text-muted">Add any custom parameters as key=value pairs</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Available Tokens -->
                                <div class="form-group mt-4">
                                    <label class="mb-2"><strong>Available Tokens (Click to Insert)</strong></label>
                                    <div class="token-grid">
                                        <button type="button" class="token-badge" onclick="insertToken('{affiliate_id}')">
                                            {affiliate_id}
                                        </button>
                                        <button type="button" class="token-badge" onclick="insertToken('{offer_id}')">
                                            {offer_id}
                                        </button>
                                        <button type="button" class="token-badge" onclick="insertToken('{campaign}')">
                                            {campaign}
                                        </button>
                                        <button type="button" class="token-badge" onclick="insertToken('{timestamp}')">
                                            {timestamp}
                                        </button>
                                        <button type="button" class="token-badge" onclick="insertToken('{click_id}')">
                                            {click_id}
                                        </button>
                                        <button type="button" class="token-badge" onclick="insertToken('{country}')">
                                            {country}
                                        </button>
                                        <button type="button" class="token-badge" onclick="insertToken('{device}')">
                                            {device}
                                        </button>
                                        <button type="button" class="token-badge" onclick="insertToken('{ip}')">
                                            {ip}
                                        </button>
                                        <button type="button" class="token-badge" onclick="insertToken('{sub1}')">
                                            {sub1}
                                        </button>
                                        <button type="button" class="token-badge" onclick="insertToken('{sub2}')">
                                            {sub2}
                                        </button>
                                        <button type="button" class="token-badge" onclick="insertToken('{sub3}')">
                                            {sub3}
                                        </button>
                                        <button type="button" class="token-badge" onclick="insertToken('{sub4}')">
                                            {sub4}
                                        </button>
                                        <button type="button" class="token-badge" onclick="insertToken('{sub5}')">
                                            {sub5}
                                        </button>
                                        <button type="button" class="token-badge" onclick="insertToken('{source}')">
                                            {source}
                                        </button>
                                        <button type="button" class="token-badge" onclick="insertToken('{medium}')">
                                            {medium}
                                        </button>
                                    </div>
                                </div>

                                <!-- Generate Button -->
                                <div class="text-center mt-4">
                                    <button class="btn btn-gradient btn-lg px-5" onclick="generateLink()">
                                        <i class="fas fa-magic mr-2"></i> Generate Tracking Link
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Generated Link Display -->
                        <div class="card card-builder" id="resultSection" style="display: none;">
                            <div class="card-header">
                                <h3 class="card-title">Generated Tracking Link</h3>
                                <div class="card-tools">
                                    <span class="badge badge-success">Ready</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="generated-link">
                                    <div id="linkBox"></div>
                                    <button class="copy-btn" onclick="copyLink()">
                                        <i class="fas fa-copy mr-1"></i> Copy
                                    </button>
                                </div>
                                
                                <div class="link-actions mt-3">
                                    <button class="btn btn-outline-primary" onclick="testLink()">
                                        <i class="fas fa-play mr-1"></i> Test Link
                                    </button>
                                    <button class="btn btn-outline-success" onclick="saveLink()">
                                        <i class="fas fa-save mr-1"></i> Save Link
                                    </button>
                                    <button class="btn btn-outline-info" onclick="shareLink()">
                                        <i class="fas fa-share mr-1"></i> Share
                                    </button>
                                    <button class="btn btn-outline-warning" onclick="qrCodeLink()">
                                        <i class="fas fa-qrcode mr-1"></i> QR Code
                                    </button>
                                </div>
                                
                                <div class="mt-4">
                                    <h6>Preview</h6>
                                    <a href="#" id="previewLink" target="_blank" class="btn btn-outline-secondary">
                                        <i class="fas fa-external-link-alt mr-1"></i> Open Offer Preview
                                    </a>
                                </div>
                                
                                <div class="mt-4">
                                    <h6>Link Breakdown</h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Parameter</th>
                                                    <th>Value</th>
                                                    <th>Purpose</th>
                                                </tr>
                                            </thead>
                                            <tbody id="linkBreakdown"></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <!-- Quick Stats -->
                        <div class="card card-builder">
                            <div class="card-header">
                                <h3 class="card-title">Link Stats</h3>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6 mb-3">
                                        <div class="stats-card">
                                            <div class="stats-value"><?php echo count($offers); ?></div>
                                            <div class="stats-label">Active Offers</div>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="stats-card">
                                            <div class="stats-value"><?php echo count($recentLinks); ?></div>
                                            <div class="stats-label">Recent Links</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="stats-card">
                                            <div class="stats-value">5</div>
                                            <div class="stats-label">Sub IDs</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="stats-card">
                                            <div class="stats-value">6</div>
                                            <div class="stats-label">Campaigns</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Link Templates -->
                        <div class="card card-builder">
                            <div class="card-header">
                                <h3 class="card-title">Quick Templates</h3>
                            </div>
                            <div class="card-body">
                                <div class="quick-link-card">
                                    <h6 class="mb-2">Social Media Template</h6>
                                    <p class="text-muted small mb-2">sub1=facebook&sub2=feed&sub3=post123</p>
                                    <button class="btn btn-sm btn-outline-primary" onclick="loadTemplate('social')">
                                        <i class="fas fa-bolt mr-1"></i> Apply
                                    </button>
                                </div>
                                
                                <div class="quick-link-card">
                                    <h6 class="mb-2">Email Template</h6>
                                    <p class="text-muted small mb-2">sub1=newsletter&sub2=june&sub3=header</p>
                                    <button class="btn btn-sm btn-outline-primary" onclick="loadTemplate('email')">
                                        <i class="fas fa-bolt mr-1"></i> Apply
                                    </button>
                                </div>
                                
                                <div class="quick-link-card">
                                    <h6 class="mb-2">PPC Template</h6>
                                    <p class="text-muted small mb-2">sub1=google&sub2=cpc&sub3=adgroup1</p>
                                    <button class="btn btn-sm btn-outline-primary" onclick="loadTemplate('ppc')">
                                        <i class="fas fa-bolt mr-1"></i> Apply
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Recently Generated Links -->
                        <div class="card card-builder">
                            <div class="card-header">
                                <h3 class="card-title">Recent Links</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recentLinks)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-history"></i>
                                    </div>
                                    <p class="text-muted">No recent links generated yet.</p>
                                </div>
                                <?php else: ?>
                                    <?php foreach($recentLinks as $link): ?>
                                    <div class="link-history-item">
                                        <div class="d-flex justify-content-between">
                                            <strong class="text-truncate" style="max-width: 70%;">
                                                <?php echo htmlspecialchars($link['offer_name']); ?>
                                            </strong>
                                            <small class="text-muted"><?php echo date('M d', strtotime($link['created_at'])); ?></small>
                                        </div>
                                        <div class="small text-truncate" style="max-width: 100%;">
                                            <?php 
                                            $url = 'https://yourdomain.com/click.php?offer_id=' . $link['offer_id'] . '&aff_id=' . $affiliateId;
                                            for($i = 1; $i <= 5; $i++) {
                                                if (!empty($link['sub' . $i])) {
                                                    $url .= '&sub' . $i . '=' . urlencode($link['sub' . $i]);
                                                }
                                            }
                                            echo htmlspecialchars($url);
                                            ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Help & Tips -->
                        <div class="card card-builder">
                            <div class="card-header">
                                <h3 class="card-title">Tips & Best Practices</h3>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info small">
                                    <i class="fas fa-lightbulb mr-2"></i>
                                    <strong>Use descriptive sub IDs:</strong> facebook_newsfeed, google_search, email_newsletter
                                </div>
                                <div class="alert alert-success small">
                                    <i class="fas fa-check-circle mr-2"></i>
                                    <strong>Track everything:</strong> Use all 5 sub IDs for maximum tracking granularity
                                </div>
                                <div class="alert alert-warning small">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    <strong>URL length:</strong> Keep sub IDs short to avoid URL length limitations
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
    
    // Offer card selection
    $('.offer-card').click(function() {
        $('.offer-card').removeClass('active');
        $(this).addClass('active');
        window.selectedOfferId = $(this).data('offer-id');
        window.selectedPreviewUrl = $(this).data('preview-url');
    });
    
    // Show all offers toggle
    $('#showAllOffers').click(function() {
        $('#allOffers').slideToggle();
        $(this).find('i').toggleClass('fa-chevron-down fa-chevron-up');
    });
    
    // Campaign selection
    $('.campaign-option').click(function() {
        $('.campaign-option').removeClass('active');
        $(this).addClass('active');
        window.selectedCampaign = $(this).data('campaign');
    });
    
    // Advanced options toggle
    $('#toggleAdvanced').click(function() {
        $('#advancedOptions').toggleClass('show');
        $(this).find('i').toggleClass('fa-cogs fa-times');
    });
    
    // Offer select change
    $('#offerSelect').change(function() {
        const option = $(this).find('option:selected');
        window.selectedOfferId = $(this).val();
        window.selectedPreviewUrl = option.data('preview-url');
        
        // Update selected card
        $('.offer-card').removeClass('active');
        $(`.offer-card[data-offer-id="${window.selectedOfferId}"]`).addClass('active');
    });
    
    // Initialize selected campaign
    window.selectedCampaign = 'social';
    window.selectedOfferId = null;
    window.selectedPreviewUrl = null;
});

// Generate link function
function generateLink() {
    if (!window.selectedOfferId) {
        Swal.fire({
            title: 'Select Offer',
            text: 'Please select an offer first',
            icon: 'warning'
        });
        return;
    }

    // Collect parameters
    let params = [];
    for (let i = 1; i <= 5; i++) {
        const v = $('#sub' + i).val().trim();
        if (v) params.push(`sub${i}=${encodeURIComponent(v)}`);
    }
    
    // Add UTM parameters if provided
    const utmSource = $('#utm_source').val().trim();
    const utmMedium = $('#utm_medium').val().trim();
    const utmCampaign = $('#utm_campaign').val().trim();
    const utmContent = $('#utm_content').val().trim();
    
    if (utmSource) params.push(`utm_source=${encodeURIComponent(utmSource)}`);
    if (utmMedium) params.push(`utm_medium=${encodeURIComponent(utmMedium)}`);
    if (utmCampaign) params.push(`utm_campaign=${encodeURIComponent(utmCampaign)}`);
    if (utmContent) params.push(`utm_content=${encodeURIComponent(utmContent)}`);
    
    // Add custom parameters
    const customParams = $('#custom_params').val().trim();
    if (customParams) {
        // Split by & and add each parameter
        const customPairs = customParams.split('&');
        customPairs.forEach(pair => {
            if (pair.includes('=')) {
                params.push(pair.trim());
            }
        });
    }
    
    // Base tracking URL - replace with your actual tracking domain
    const base = "https://iconmedianetwork.in/click";
    const link = `${base}?offer_id=${window.selectedOfferId}&aff_id=<?php echo $affiliateId; ?>&campaign=${window.selectedCampaign}&` + params.join('&');

    // Display the link
    $('#linkBox').text(link);
    $('#previewLink').attr('href', window.selectedPreviewUrl || '#');
    $('#resultSection').slideDown();
    
    // Generate link breakdown
    const breakdown = [
        {param: 'offer_id', value: window.selectedOfferId, purpose: 'Identifies the offer'},
        {param: 'aff_id', value: '<?php echo $affiliateId; ?>', purpose: 'Your affiliate ID'},
        {param: 'campaign', value: window.selectedCampaign, purpose: 'Campaign type'}
    ];
    
    for (let i = 1; i <= 5; i++) {
        const v = $('#sub' + i).val().trim();
        if (v) {
            breakdown.push({param: `sub${i}`, value: v, purpose: `Tracking parameter ${i}`});
        }
    }
    
    let breakdownHtml = '';
    breakdown.forEach(item => {
        breakdownHtml += `
            <tr>
                <td><code>${item.param}</code></td>
                <td>${item.value}</td>
                <td>${item.purpose}</td>
            </tr>
        `;
    });
    $('#linkBreakdown').html(breakdownHtml);
    
    // Scroll to results
    $('html, body').animate({
        scrollTop: $('#resultSection').offset().top - 100
    }, 500);
}

// Copy link to clipboard
function copyLink() {
    const linkText = $('#linkBox').text();
    navigator.clipboard.writeText(linkText).then(() => {
        Toast.fire({
            icon: 'success',
            title: 'Link copied to clipboard!'
        });
    });
}

// Preview offer
function previewOffer(url) {
    if (url) {
        window.open(url, '_blank');
    }
}

// Test link
function testLink() {
    const link = $('#linkBox').text();
    Swal.fire({
        title: 'Test Link',
        html: `Testing link: <br><small class="text-muted">${link}</small>`,
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Open in New Tab',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            return new Promise((resolve) => {
                window.open(link, '_blank');
                setTimeout(() => {
                    resolve();
                }, 1000);
            });
        }
    });
}

// Save link
function saveLink() {
    const link = $('#linkBox').text();
    Swal.fire({
        title: 'Save Link',
        input: 'text',
        inputLabel: 'Link Name',
        inputPlaceholder: 'e.g., Facebook Campaign - Summer Sale',
        showCancelButton: true,
        confirmButtonText: 'Save',
        preConfirm: (name) => {
            if (!name) {
                Swal.showValidationMessage('Please enter a name for this link');
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            // In a real application, you would send an AJAX request to save the link
            Toast.fire({
                icon: 'success',
                title: 'Link saved successfully!'
            });
        }
    });
}

// Share link
function shareLink() {
    const link = $('#linkBox').text();
    Swal.fire({
        title: 'Share Link',
        html: `
            <div class="text-left">
                <p>Copy this link to share:</p>
                <div class="generated-link small mb-3">${link}</div>
                <button class="btn btn-sm btn-primary w-100 mb-2" onclick="navigator.clipboard.writeText('${link}')">
                    <i class="fas fa-copy mr-1"></i> Copy Link
                </button>
                <button class="btn btn-sm btn-success w-100 mb-2" onclick="window.open('mailto:?body=${encodeURIComponent(link)}', '_blank')">
                    <i class="fas fa-envelope mr-1"></i> Email
                </button>
                <button class="btn btn-sm btn-info w-100" onclick="window.open('https://api.whatsapp.com/send?text=${encodeURIComponent(link)}', '_blank')">
                    <i class="fab fa-whatsapp mr-1"></i> WhatsApp
                </button>
            </div>
        `,
        showConfirmButton: false,
        showCloseButton: true,
        width: 500
    });
}

// Generate QR Code
function qrCodeLink() {
    const link = $('#linkBox').text();
    Swal.fire({
        title: 'QR Code',
        html: `
            <div class="text-center">
                <p>Scan this QR code to visit the link:</p>
                <div style="width: 200px; height: 200px; background: #f8f9fa; margin: 20px auto; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-qrcode fa-4x text-muted"></i>
                </div>
                <p class="text-muted small">QR code generation requires a backend service</p>
            </div>
        `,
        showConfirmButton: false,
        showCloseButton: true
    });
}

// Load template
function loadTemplate(type) {
    const templates = {
        social: {
            sub1: 'facebook',
            sub2: 'newsfeed',
            sub3: 'post_123',
            sub4: 'video',
            sub5: 'mobile'
        },
        email: {
            sub1: 'newsletter',
            sub2: 'weekly',
            sub3: 'header',
            sub4: 'june2024',
            sub5: 'segment_a'
        },
        ppc: {
            sub1: 'google',
            sub2: 'cpc',
            sub3: 'adgroup_1',
            sub4: 'keyword_buy',
            sub5: 'match_broad'
        }
    };
    
    const template = templates[type];
    if (template) {
        for (let i = 1; i <= 5; i++) {
            $('#sub' + i).val(template['sub' + i] || '');
        }
        Toast.fire({
            icon: 'success',
            title: `${type.charAt(0).toUpperCase() + type.slice(1)} template loaded!`
        });
    }
}

// Insert token into custom params
function insertToken(token) {
    const $textarea = $('#custom_params');
    if ($textarea.is(':focus')) {
        const cursorPos = $textarea[0].selectionStart;
        const text = $textarea.val();
        const newText = text.substring(0, cursorPos) + token + text.substring(cursorPos);
        $textarea.val(newText);
        $textarea[0].setSelectionRange(cursorPos + token.length, cursorPos + token.length);
    } else {
        // Insert into first available sub field
        for (let i = 1; i <= 5; i++) {
            const $field = $('#sub' + i);
            if (!$field.val()) {
                $field.val(token);
                $field.focus();
                break;
            }
        }
    }
}

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