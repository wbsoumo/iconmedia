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

// Get categories from database for dropdown
$categoriesStmt = $pdo->query("SELECT DISTINCT category FROM offers WHERE category IS NOT NULL AND category != ''");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

/* ===============================
   HANDLE FORM SUBMIT
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $title              = trim($_POST['title']);
    $description        = trim($_POST['description'] ?? '');
    $objective          = $_POST['objective'] ?? 'conversions';
    $kpi                = trim($_POST['kpi'] ?? '');
    $allowedTraffic     = implode(',', $_POST['allowed_traffic'] ?? []);
    $previewUrl         = trim($_POST['preview_url']);
    $campaignUrl        = trim($_POST['campaign_url']);
    $conversionTracking = $_POST['conversion_tracking'] ?? 'postback';
    $termsRequired      = isset($_POST['terms_required']) ? 1 : 0;
    $category           = trim($_POST['category']);
    $status             = $_POST['status'] ?? 'pending';
    $note               = trim($_POST['note'] ?? '');
    $revenue            = (float)$_POST['revenue'];
    $payout             = (float)$_POST['payout'];
    $payoutType         = $_POST['payout_type'] ?? 'cpa';
    $currency           = $_POST['currency'] ?? 'USD';
    $geo                = trim($_POST['geo'] ?? 'ALL');
    $country            = trim($_POST['country'] ?? '');
    $deviceTargeting    = $_POST['device_targeting'] ?? 'all';
    $browserTargeting   = implode(',', $_POST['browser_targeting'] ?? []);
    $dailyCap           = (int)$_POST['daily_cap'] ?? 0;
    $totalCap           = (int)$_POST['total_cap'] ?? 0;
    $startDate          = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $endDate            = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $visibility         = $_POST['visibility'] ?? 'public';
    $allowedCountries   = trim($_POST['allowed_countries'] ?? '');
    $blockedCountries   = trim($_POST['blocked_countries'] ?? '');
    
    // Generate unique postback token
    $postbackToken = bin2hex(random_bytes(16));

    /* BASIC VALIDATION */
    if ($title === '' || $campaignUrl === '') {
        $error = 'Offer Name and Campaign URL are required.';
    } elseif (!filter_var($campaignUrl, FILTER_VALIDATE_URL)) {
        $error = 'Invalid Campaign URL format.';
    } elseif ($previewUrl && !filter_var($previewUrl, FILTER_VALIDATE_URL)) {
        $error = 'Invalid Preview URL format.';
    } elseif ($payout > $revenue) {
        $error = 'Payout cannot be greater than revenue.';
    } else {

        $stmt = $pdo->prepare("
            INSERT INTO offers (
                advertiser_id,
                offer_name,
                offer_description,
                objective,
                kpi,
                allowed_traffic,
                offer_url,
                preview_url,
                campaign_url,
                conversion_tracking,
                terms_required,
                category,
                status,
                internal_note,
                revenue,
                payout,
                payout_type,
                currency,
                geo,
                country,
                device_type,
                browser_targeting,
                daily_cap,
                total_cap,
                start_date,
                end_date,
                visibility,
                allowed_countries,
                blocked_countries,
                postback_token,
                created_at,
                updated_at
            ) VALUES (
                :advertiser_id,
                :offer_name,
                :offer_description,
                :objective,
                :kpi,
                :allowed_traffic,
                :offer_url,
                :preview_url,
                :campaign_url,
                :conversion_tracking,
                :terms_required,
                :category,
                :status,
                :internal_note,
                :revenue,
                :payout,
                :payout_type,
                :currency,
                :geo,
                :country,
                :device_type,
                :browser_targeting,
                :daily_cap,
                :total_cap,
                :start_date,
                :end_date,
                :visibility,
                :allowed_countries,
                :blocked_countries,
                :postback_token,
                NOW(),
                NOW()
            )
        ");

        $stmt->execute([
            'advertiser_id'       => $advertiserId,
            'offer_name'          => $title,
            'offer_description'   => $description,
            'objective'           => $objective,
            'kpi'                 => $kpi,
            'allowed_traffic'     => $allowedTraffic,
            'offer_url'           => $campaignUrl,
            'preview_url'         => $previewUrl,
            'campaign_url'        => $campaignUrl,
            'conversion_tracking' => $conversionTracking,
            'terms_required'      => $termsRequired,
            'category'            => $category,
            'status'              => $status,
            'internal_note'       => $note,
            'revenue'             => $revenue,
            'payout'              => $payout,
            'payout_type'         => $payoutType,
            'currency'            => $currency,
            'geo'                 => $geo,
            'country'             => $country,
            'device_type'         => $deviceTargeting,
            'browser_targeting'   => $browserTargeting,
            'daily_cap'           => $dailyCap,
            'total_cap'           => $totalCap,
            'start_date'          => $startDate,
            'end_date'            => $endDate,
            'visibility'          => $visibility,
            'allowed_countries'   => $allowedCountries,
            'blocked_countries'   => $blockedCountries,
            'postback_token'      => $postbackToken
        ]);

        $offerId = $pdo->lastInsertId();
        $success = "Offer created successfully! Offer ID: #{$offerId}";
        
        // Store token for display
        $newPostbackToken = $postbackToken;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create New Offer | Advertiser Panel | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- Select2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <!-- Flatpickr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
            padding: 30px;
        }
        
        .form-section {
            background: #f8f9fc;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid #e3e6f0;
        }
        
        .form-section-title {
            color: #4e73df;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e3e6f0;
            display: flex;
            align-items: center;
        }
        
        .form-section-title i {
            margin-right: 10px;
            font-size: 20px;
        }
        
        .form-group-enhanced {
            margin-bottom: 25px;
        }
        
        .form-group-enhanced label {
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 8px;
            display: block;
        }
        
        .form-group-enhanced .form-control, 
        .form-group-enhanced .select2-container--default .select2-selection--single {
            border-radius: 8px;
            border: 1px solid #d1d3e2;
            padding: 10px 15px;
            transition: all 0.3s ease;
            width: 100%;
        }
        
        .form-group-enhanced .form-control:focus, 
        .form-group-enhanced .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .form-control:disabled, .form-control[readonly] {
            background-color: #f8f9fc;
            opacity: 1;
        }
        
        .btn-gradient {
            background: var(--primary-gradient);
            border: none;
            color: white;
            font-weight: 600;
            padding: 12px 30px;
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
        
        .checkbox-group, .radio-group {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 8px;
        }
        
        .checkbox-item, .radio-item {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
        }
        
        .checkbox-item input, .radio-item input {
            margin-right: 8px;
            width: 18px;
            height: 18px;
        }
        
        .checkbox-label, .radio-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            padding: 8px 15px;
            background: white;
            border: 1px solid #e3e6f0;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .checkbox-label:hover, .radio-label:hover {
            border-color: #667eea;
            background: #f0f3ff;
        }
        
        .checkbox-label.selected, .radio-label.selected {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e3e6f0;
        }
        
        .info-box {
            background: linear-gradient(135deg, #f8f9fc 0%, #eaecf4 100%);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #e3e6f0;
        }
        
        .info-box-icon {
            width: 40px;
            height: 40px;
            background: var(--primary-gradient);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            margin-bottom: 10px;
        }
        
        .currency-input {
            position: relative;
        }
        
        .currency-input .input-group-prepend {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            z-index: 3;
        }
        
        .currency-input .form-control {
            padding-left: 45px;
        }
        
        .token-badge {
            display: inline-block;
            background: #e3e6f0;
            color: #4e73df;
            padding: 4px 10px;
            margin: 3px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .token-badge:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }
        
        .required::after {
            content: ' *';
            color: #e74a3b;
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
        
        .postback-info {
            background: #e8f0fe;
            border-left: 4px solid #667eea;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .postback-token {
            font-family: monospace;
            font-size: 16px;
            background: white;
            padding: 10px;
            border-radius: 6px;
            border: 1px dashed #667eea;
            word-break: break-all;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
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
                <a href="offers.php" class="nav-link">My Offers</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="create_offer.php" class="nav-link active">Create Offer</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <span class="badge badge-warning navbar-badge">3</span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header">3 Notifications</span>
                    <div class="dropdown-divider"></div>
                    <a href="offers.php?status=pending" class="dropdown-item">
                        <i class="fas fa-gift mr-2 text-primary"></i> 2 offers pending review
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="reports_campaigns.php" class="dropdown-item">
                        <i class="fas fa-chart-line mr-2 text-success"></i> Daily report ready
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
                    <div class="advertiser-avatar mr-2">
                        <?php echo strtoupper(substr($advertiserName, 0, 1)); ?>
                    </div>
                    <span><?php echo htmlspecialchars($advertiserName); ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user mr-2"></i> My Profile
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
                        <a href="create_offer.php" class="nav-link active">
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
                        <h1 class="m-0">Create New Campaign</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="offers.php">Campaigns</a></li>
                            <li class="breadcrumb-item active">Create Campaign</li>
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
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                    <h5><i class="icon fas fa-check"></i> Success!</h5>
                    <p><?php echo $success; ?></p>
                    
                    <?php if (isset($newPostbackToken)): ?>
                    <div class="postback-info mt-3">
                        <strong><i class="fas fa-link mr-2"></i>Your Postback URL for Tracking:</strong>
                        <div class="postback-token mt-2">
                            <?php echo "https://iconmedianetwork.in/postback?token=" . $newPostbackToken; ?>
                        </div>
                        <small class="text-muted">Use this URL to send conversion data back to our system</small>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mt-3">
                        <a href="offers.php" class="btn btn-sm btn-outline-primary mr-2">
                            <i class="fas fa-eye mr-1"></i> View All Campaigns
                        </a>
                        <button class="btn btn-sm btn-outline-success" onclick="window.location.reload()">
                            <i class="fas fa-plus mr-1"></i> Create Another
                        </button>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                    <h5><i class="icon fas fa-ban"></i> Error!</h5>
                    <p><?php echo $error; ?></p>
                </div>
                <?php endif; ?>

                <!-- Status Info -->
                <div class="info-box mb-4">
                    <div class="row align-items-center">
                        <div class="col-md-1">
                            <div class="info-box-icon">
                                <i class="fas fa-info-circle"></i>
                            </div>
                        </div>
                        <div class="col-md-11">
                            <div class="d-flex align-items-center">
                                <span class="status-badge status-pending mr-3">
                                    <i class="fas fa-clock mr-1"></i> Pending Review
                                </span>
                                <p class="mb-0 text-muted">
                                    New campaigns are submitted for admin review and will be approved within 24-48 hours.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Create Offer Form -->
                <div class="card-dashboard">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-plus-circle mr-2"></i> Campaign Details
                        </h3>
                        <div class="card-tools">
                            <span class="badge badge-light">Advertiser: <?php echo htmlspecialchars($advertiserName); ?></span>
                        </div>
                    </div>
                    
                    <form method="post" id="createOfferForm">
                        <div class="card-body">
                            <!-- Basic Information -->
                            <div class="form-section">
                                <div class="form-section-title">
                                    <i class="fas fa-info-circle"></i> Basic Information
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label class="required">Campaign Name</label>
                                        <input type="text" name="title" class="form-control" required 
                                               placeholder="e.g., Summer Sale - Fashion Apparel"
                                               value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label class="required">Objective</label>
                                        <select name="objective" class="form-control" required>
                                            <option value="conversions" <?php echo ($_POST['objective'] ?? '') == 'conversions' ? 'selected' : ''; ?>>Conversions</option>
                                            <option value="sale" <?php echo ($_POST['objective'] ?? '') == 'sale' ? 'selected' : ''; ?>>Sales</option>
                                            <option value="app_install" <?php echo ($_POST['objective'] ?? '') == 'app_install' ? 'selected' : ''; ?>>App Installs</option>
                                            <option value="leads" <?php echo ($_POST['objective'] ?? '') == 'leads' ? 'selected' : ''; ?>>Leads</option>
                                            <option value="impressions" <?php echo ($_POST['objective'] ?? '') == 'impressions' ? 'selected' : ''; ?>>Impressions</option>
                                            <option value="clicks" <?php echo ($_POST['objective'] ?? '') == 'clicks' ? 'selected' : ''; ?>>Clicks</option>
                                            <option value="registrations" <?php echo ($_POST['objective'] ?? '') == 'registrations' ? 'selected' : ''; ?>>Registrations</option>
                                            <option value="downloads" <?php echo ($_POST['objective'] ?? '') == 'downloads' ? 'selected' : ''; ?>>Downloads</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-group-enhanced">
                                    <label>Description</label>
                                    <textarea name="description" class="form-control" rows="3" 
                                              placeholder="Describe your offer in detail..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label>Category</label>
                                        <select name="category" class="form-control select2">
                                            <option value="">Select Category</option>
                                            <?php foreach($categories as $cat): ?>
                                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($_POST['category'] ?? '') == $cat ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cat); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <option value="_custom">+ Add Custom Category</option>
                                        </select>
                                        <input type="text" name="custom_category" class="form-control mt-2" 
                                               style="display: none;" placeholder="Enter custom category">
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label>KPI (Key Performance Indicator)</label>
                                        <input type="text" name="kpi" class="form-control" 
                                               placeholder="e.g., 5% conversion rate, $50 CPA"
                                               value="<?php echo htmlspecialchars($_POST['kpi'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <!-- URLs & Tracking -->
                            <div class="form-section">
                                <div class="form-section-title">
                                    <i class="fas fa-link"></i> URLs & Tracking
                                </div>
                                
                                <div class="form-group-enhanced">
                                    <label class="required">Campaign URL</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fas fa-external-link-alt"></i></span>
                                        </div>
                                        <input type="url" name="campaign_url" class="form-control" required 
                                               placeholder="https://yourdomain.com/landing?click_id={click_id}"
                                               value="<?php echo htmlspecialchars($_POST['campaign_url'] ?? ''); ?>">
                                    </div>
                                    <div class="mt-2">
                                        <span class="form-help">Available tracking tokens:</span>
                                        <div>
                                            <span class="token-badge" onclick="insertToken('{click_id}')">{click_id}</span>
                                            <span class="token-badge" onclick="insertToken('{sub1}')">{sub1}</span>
                                            <span class="token-badge" onclick="insertToken('{sub2}')">{sub2}</span>
                                            <span class="token-badge" onclick="insertToken('{sub3}')">{sub3}</span>
                                            <span class="token-badge" onclick="insertToken('{sub4}')">{sub4}</span>
                                            <span class="token-badge" onclick="insertToken('{sub5}')">{sub5}</span>
                                            <span class="token-badge" onclick="insertToken('{affiliate_id}')">{affiliate_id}</span>
                                            <span class="token-badge" onclick="insertToken('{country}')">{country}</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-group-enhanced">
                                    <label>Preview URL</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fas fa-eye"></i></span>
                                        </div>
                                        <input type="url" name="preview_url" class="form-control" 
                                               placeholder="https://yourdomain.com/preview"
                                               value="<?php echo htmlspecialchars($_POST['preview_url'] ?? ''); ?>">
                                    </div>
                                    <span class="form-help">URL where affiliates can preview your offer</span>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label>Conversion Tracking</label>
                                        <select name="conversion_tracking" class="form-control">
                                            <option value="postback" <?php echo ($_POST['conversion_tracking'] ?? '') == 'postback' ? 'selected' : ''; ?>>Postback URL</option>
                                            <option value="pixel" <?php echo ($_POST['conversion_tracking'] ?? '') == 'pixel' ? 'selected' : ''; ?>>Tracking Pixel</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label>Visibility</label>
                                        <select name="visibility" class="form-control">
                                            <option value="public" <?php echo ($_POST['visibility'] ?? '') == 'public' ? 'selected' : ''; ?>>Public (All affiliates)</option>
                                            <option value="private" <?php echo ($_POST['visibility'] ?? '') == 'private' ? 'selected' : ''; ?>>Private (Selected affiliates)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Targeting & Restrictions -->
                            <div class="form-section">
                                <div class="form-section-title">
                                    <i class="fas fa-crosshairs"></i> Targeting & Restrictions
                                </div>
                                
                                <div class="form-group-enhanced">
                                    <label>Allowed Traffic Channels</label>
                                    <div class="checkbox-group">
                                        <?php 
                                        $trafficChannels = ['Facebook', 'Google', 'Native', 'Email', 'Push', 'In-App', 
                                                          'Display', 'Social Media', 'Search', 'Direct', 'Referral'];
                                        $selectedTraffic = isset($_POST['allowed_traffic']) ? $_POST['allowed_traffic'] : [];
                                        foreach ($trafficChannels as $ch): 
                                        ?>
                                        <div class="checkbox-item">
                                            <input type="checkbox" name="allowed_traffic[]" value="<?php echo $ch; ?>" 
                                                   id="traffic_<?php echo strtolower($ch); ?>"
                                                   <?php echo in_array($ch, $selectedTraffic) ? 'checked' : ''; ?>>
                                            <label for="traffic_<?php echo strtolower($ch); ?>" class="checkbox-label">
                                                <?php echo $ch; ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label>Device Targeting</label>
                                        <select name="device_targeting" class="form-control">
                                            <option value="all" <?php echo ($_POST['device_targeting'] ?? '') == 'all' ? 'selected' : ''; ?>>All Devices</option>
                                            <option value="desktop" <?php echo ($_POST['device_targeting'] ?? '') == 'desktop' ? 'selected' : ''; ?>>Desktop Only</option>
                                            <option value="mobile" <?php echo ($_POST['device_targeting'] ?? '') == 'mobile' ? 'selected' : ''; ?>>Mobile Only</option>
                                            <option value="tablet" <?php echo ($_POST['device_targeting'] ?? '') == 'tablet' ? 'selected' : ''; ?>>Tablet Only</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label>Browser Targeting</label>
                                        <div class="checkbox-group">
                                            <?php 
                                            $browsers = ['Chrome', 'Firefox', 'Safari', 'Edge', 'Opera'];
                                            $selectedBrowsers = isset($_POST['browser_targeting']) ? $_POST['browser_targeting'] : [];
                                            foreach ($browsers as $browser): 
                                            ?>
                                            <div class="checkbox-item">
                                                <input type="checkbox" name="browser_targeting[]" value="<?php echo $browser; ?>" 
                                                       id="browser_<?php echo strtolower($browser); ?>"
                                                       <?php echo in_array($browser, $selectedBrowsers) ? 'checked' : ''; ?>>
                                                <label for="browser_<?php echo strtolower($browser); ?>" class="checkbox-label">
                                                    <?php echo $browser; ?>
                                                </label>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label>Geo Targeting (Allowed Countries)</label>
                                        <input type="text" name="allowed_countries" class="form-control" 
                                               placeholder="ALL or IN,US,UK,CA"
                                               value="<?php echo htmlspecialchars($_POST['allowed_countries'] ?? ''); ?>">
                                        <span class="form-help">Use comma-separated country codes</span>
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label>Blocked Countries</label>
                                        <input type="text" name="blocked_countries" class="form-control" 
                                               placeholder="RU,CN,PK"
                                               value="<?php echo htmlspecialchars($_POST['blocked_countries'] ?? ''); ?>">
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label>Primary Country</label>
                                        <input type="text" name="country" class="form-control" 
                                               placeholder="e.g., US"
                                               value="<?php echo htmlspecialchars($_POST['country'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label>Geo (Legacy)</label>
                                        <input type="text" name="geo" class="form-control" 
                                               placeholder="ALL"
                                               value="<?php echo htmlspecialchars($_POST['geo'] ?? 'ALL'); ?>">
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label>Daily Cap</label>
                                        <input type="number" name="daily_cap" class="form-control" 
                                               placeholder="0 for unlimited" min="0"
                                               value="<?php echo htmlspecialchars($_POST['daily_cap'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label>Total Cap</label>
                                        <input type="number" name="total_cap" class="form-control" 
                                               placeholder="0 for unlimited" min="0"
                                               value="<?php echo htmlspecialchars($_POST['total_cap'] ?? ''); ?>">
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label>Start Date</label>
                                        <input type="text" name="start_date" class="form-control flatpickr" 
                                               placeholder="Select start date"
                                               value="<?php echo htmlspecialchars($_POST['start_date'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label>End Date</label>
                                        <input type="text" name="end_date" class="form-control flatpickr" 
                                               placeholder="Select end date"
                                               value="<?php echo htmlspecialchars($_POST['end_date'] ?? ''); ?>">
                                    </div>
                                </div>
                                
                                <div class="form-group-enhanced">
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="terms_required" id="terms_required" value="1"
                                               <?php echo isset($_POST['terms_required']) ? 'checked' : ''; ?>>
                                        <label for="terms_required" class="checkbox-label">
                                            Require affiliates to accept terms & conditions
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Pricing -->
                            <div class="form-section">
                                <div class="form-section-title">
                                    <i class="fas fa-dollar-sign"></i> Pricing & Revenue
                                </div>
                                
                                <div class="info-box mb-3">
                                    <div class="row align-items-center">
                                        <div class="col-md-1">
                                            <div class="info-box-icon">
                                                <i class="fas fa-calculator"></i>
                                            </div>
                                        </div>
                                        <div class="col-md-11">
                                            <h6 class="mb-1">Pricing Strategy</h6>
                                            <p class="mb-0 text-muted small">
                                                Set your budget per conversion and affiliate commission. 
                                                Higher payouts attract more affiliates.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced currency-input">
                                        <label class="required">Revenue (You Earn)</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text">$</span>
                                            </div>
                                            <input type="number" step="0.01" name="revenue" class="form-control" required 
                                                   placeholder="50.00" oninput="calculateMargin()"
                                                   value="<?php echo htmlspecialchars($_POST['revenue'] ?? ''); ?>">
                                        </div>
                                        <span class="form-help">Your earnings per conversion</span>
                                    </div>
                                    
                                    <div class="form-group-enhanced currency-input">
                                        <label class="required">Payout (Affiliate Earns)</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text">$</span>
                                            </div>
                                            <input type="number" step="0.01" name="payout" class="form-control" required 
                                                   placeholder="35.00" oninput="calculateMargin()"
                                                   value="<?php echo htmlspecialchars($_POST['payout'] ?? ''); ?>">
                                        </div>
                                        <span class="form-help">Affiliate commission per conversion</span>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label>Payout Type</label>
                                        <select name="payout_type" class="form-control">
                                            <option value="cpa" <?php echo ($_POST['payout_type'] ?? '') == 'cpa' ? 'selected' : ''; ?>>CPA (Cost Per Action)</option>
                                            <option value="cpl" <?php echo ($_POST['payout_type'] ?? '') == 'cpl' ? 'selected' : ''; ?>>CPL (Cost Per Lead)</option>
                                            <option value="cpi" <?php echo ($_POST['payout_type'] ?? '') == 'cpi' ? 'selected' : ''; ?>>CPI (Cost Per Install)</option>
                                            <option value="revshare" <?php echo ($_POST['payout_type'] ?? '') == 'revshare' ? 'selected' : ''; ?>>Revenue Share</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label>Currency</label>
                                        <select name="currency" class="form-control">
                                            <option value="USD" <?php echo ($_POST['currency'] ?? '') == 'USD' ? 'selected' : ''; ?>>USD ($)</option>
                                            <option value="INR" <?php echo ($_POST['currency'] ?? '') == 'INR' ? 'selected' : ''; ?>>INR (₹)</option>
                                            <option value="EUR" <?php echo ($_POST['currency'] ?? '') == 'EUR' ? 'selected' : ''; ?>>EUR (€)</option>
                                            <option value="GBP" <?php echo ($_POST['currency'] ?? '') == 'GBP' ? 'selected' : ''; ?>>GBP (£)</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label>Your Margin</label>
                                        <div class="form-control" id="marginDisplay" readonly style="background: #f8f9fc;">
                                            <span id="marginValue">--</span>
                                            <span id="marginPercent">--</span>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label>Status</label>
                                        <select name="status" class="form-control" disabled>
                                            <option value="pending" selected>Pending Review</option>
                                        </select>
                                        <input type="hidden" name="status" value="pending">
                                        <span class="form-help">Campaigns require admin approval before going live</span>
                                    </div>
                                </div>
                                
                                <div class="form-group-enhanced">
                                    <label>Internal Notes (Optional)</label>
                                    <textarea name="note" class="form-control" rows="2" 
                                              placeholder="Any notes for our team..."><?php echo htmlspecialchars($_POST['note'] ?? ''); ?></textarea>
                                    <span class="form-help">These notes help us understand your campaign better</span>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="form-actions">
                                <a href="offers.php" class="btn btn-outline-primary">
                                    <i class="fas fa-times mr-2"></i> Cancel
                                </a>
                                <button type="submit" class="btn-gradient" id="submitBtn">
                                    <i class="fas fa-check mr-2"></i> Submit Campaign
                                </button>
                            </div>
                            
                            <p class="text-muted text-center mt-3 mb-0 small">
                                <i class="fas fa-info-circle mr-1"></i>
                                By submitting, you agree to our campaign guidelines and terms of service.
                            </p>
                        </div>
                    </form>
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
<!-- Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<!-- Flatpickr -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
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
    
    // Initialize Select2
    $('.select2').select2({
        placeholder: "Select or type...",
        allowClear: true,
        tags: true
    });
    
    // Initialize Flatpickr
    $('.flatpickr').flatpickr({
        dateFormat: "Y-m-d",
        minDate: "today"
    });
    
    // Category selector
    $('select[name="category"]').on('change', function() {
        if ($(this).val() === '_custom') {
            $('input[name="custom_category"]').show().focus();
        } else {
            $('input[name="custom_category"]').hide();
        }
    });
    
    // Custom category handling
    $('input[name="custom_category"]').on('input', function() {
        $('select[name="category"]').val('_custom').trigger('change');
    });
    
    // Checkbox styling
    $('.checkbox-label').click(function() {
        const checkbox = $(this).prev('input[type="checkbox"]');
        checkbox.prop('checked', !checkbox.prop('checked'));
        $(this).toggleClass('selected', checkbox.prop('checked'));
    });
    
    // Initialize checkbox labels
    $('input[type="checkbox"]').each(function() {
        const label = $(this).next('.checkbox-label');
        label.toggleClass('selected', $(this).prop('checked'));
    });
    
    // Form submission
    $('#createOfferForm').submit(function(e) {
        const title = $('input[name="title"]').val().trim();
        const campaignUrl = $('input[name="campaign_url"]').val().trim();
        const revenue = parseFloat($('input[name="revenue"]').val()) || 0;
        const payout = parseFloat($('input[name="payout"]').val()) || 0;
        
        if (!title) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Campaign name is required',
                icon: 'error'
            });
            return;
        }
        
        if (!campaignUrl) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Campaign URL is required',
                icon: 'error'
            });
            return;
        }
        
        if (!isValidUrl(campaignUrl)) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Please enter a valid Campaign URL',
                icon: 'error'
            });
            return;
        }
        
        if (revenue <= 0 || payout <= 0) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Revenue and payout must be greater than 0',
                icon: 'error'
            });
            return;
        }
        
        if (payout > revenue) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Payout cannot be greater than revenue',
                icon: 'error'
            });
            return;
        }
        
        // Show loading
        const submitBtn = $('#submitBtn');
        submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i> Submitting Campaign...');
    });
    
    // Initialize margin calculation
    calculateMargin();
    
    // Auto-dismiss alerts after 5 seconds
    $('.alert').delay(5000).fadeOut('slow');
});

function isValidUrl(string) {
    try {
        new URL(string);
        return true;
    } catch (_) {
        return false;
    }
}

function calculateMargin() {
    const revenue = parseFloat($('input[name="revenue"]').val()) || 0;
    const payout = parseFloat($('input[name="payout"]').val()) || 0;
    
    if (revenue > 0 && payout > 0) {
        const margin = revenue - payout;
        const marginPercent = (margin / revenue) * 100;
        
        $('#marginValue').text('$' + margin.toFixed(2));
        $('#marginPercent').text(' (' + marginPercent.toFixed(1) + '%)');
        
        if (marginPercent < 10) {
            $('#marginDisplay').css('border-left', '3px solid #dc3545');
        } else if (marginPercent < 25) {
            $('#marginDisplay').css('border-left', '3px solid #ffc107');
        } else {
            $('#marginDisplay').css('border-left', '3px solid #28a745');
        }
    }
}

function insertToken(token) {
    const $input = $('input[name="campaign_url"]');
    const cursorPos = $input[0].selectionStart;
    const currentValue = $input.val();
    const newValue = currentValue.substring(0, cursorPos) + token + currentValue.substring(cursorPos);
    $input.val(newValue).focus();
    $input[0].setSelectionRange(cursorPos + token.length, cursorPos + token.length);
}

// Initialize SweetAlert2 Toast
const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true
});
</script>

</body>
</html>