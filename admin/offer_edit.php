<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

// Get offer ID from URL
$offerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$offerId) {
    header('Location: campaigns.php?error=Invalid campaign ID');
    exit;
}

/* ===============================
   FETCH OFFER DATA & METRICS
================================ */
$stmt = $pdo->prepare("
    SELECT 
        o.*,
        u.name AS advertiser_name,
        u.email AS advertiser_email,
        u.company AS advertiser_company,
        u.mobile AS advertiser_mobile,
        
        -- Stats
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        SUM(CASE WHEN cv.status = 'pending' THEN 1 ELSE 0 END) AS pending_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END) AS earned_revenue,
        SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END) AS paid_payout
         
    FROM offers o
    LEFT JOIN users u ON u.user_id = o.advertiser_id
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE o.offer_id = :offer_id
    GROUP BY o.offer_id
");

$stmt->execute(['offer_id' => $offerId]);
$offer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$offer) {
    header('Location: campaigns.php?error=Campaign not found');
    exit;
}

// Parse comma-separated fields into arrays
$allowedTraffic  = !empty($offer['allowed_traffic']) ? explode(',', $offer['allowed_traffic']) : [];
$browserTargeting = !empty($offer['browser_targeting']) ? explode(',', $offer['browser_targeting']) : [];

/* ===============================
   GET CATEGORIES & ADVERTISERS
================================ */
$categories = $pdo->query("SELECT DISTINCT category FROM offers WHERE category IS NOT NULL AND category != '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

$advertisers = $pdo->query("
    SELECT user_id, name, email, company 
    FROM users 
    WHERE role_id = 4 AND status = 'active'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   HANDLE FORM SUBMIT
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $advertiserId        = (int)($_POST['advertiser_id'] ?? $offer['advertiser_id']);
    $title               = trim($_POST['title'] ?? '');
    $description         = trim($_POST['description'] ?? '');
    $objective           = $_POST['objective'] ?? 'conversions';
    $kpi                 = trim($_POST['kpi'] ?? '');
    $allowedTraffic      = implode(',', $_POST['allowed_traffic'] ?? []);
    $previewUrl          = trim($_POST['preview_url'] ?? '');
    $campaignUrl         = trim($_POST['campaign_url'] ?? '');
    $conversionTracking  = $_POST['conversion_tracking'] ?? 'postback';
    $termsRequired       = isset($_POST['terms_required']) ? 1 : 0;
    
    $category            = trim($_POST['category'] ?? '');
    if ($category === '_custom' && !empty($_POST['custom_category'])) {
        $category = trim($_POST['custom_category']);
    }
    
    $status              = $_POST['status'] ?? $offer['status'];
    $note                = trim($_POST['note'] ?? '');
    $revenue             = (float)($_POST['revenue'] ?? 0);
    $payout              = (float)($_POST['payout'] ?? 0);
    $payoutType          = $_POST['payout_type'] ?? 'cpa';
    $currency            = $_POST['currency'] ?? 'USD';
    $geo                 = trim($_POST['geo'] ?? 'ALL');
    $country             = trim($_POST['country'] ?? 'US');
    $deviceTargeting     = $_POST['device_targeting'] ?? 'all';
    $browserTargeting    = implode(',', $_POST['browser_targeting'] ?? []);
    $dailyCap            = (int)($_POST['daily_cap'] ?? 0);
    $totalCap            = (int)($_POST['total_cap'] ?? 0);
    $startDate           = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $endDate             = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $visibility          = $_POST['visibility'] ?? 'public';
    $allowedCountries    = trim($_POST['allowed_countries'] ?? 'ALL');
    $blockedCountries    = trim($_POST['blocked_countries'] ?? '');
    $regenerateToken     = isset($_POST['regenerate_token']) ? 1 : 0;

    $postbackToken = $offer['postback_token'] ?: bin2hex(random_bytes(16));
    if ($regenerateToken) {
        $postbackToken = bin2hex(random_bytes(16));
    }

    if ($advertiserId === 0) {
        $error = 'Please select an advertiser.';
    } elseif ($title === '' || $campaignUrl === '') {
        $error = 'Campaign Title and Target URL are required.';
    } elseif (!filter_var($campaignUrl, FILTER_VALIDATE_URL)) {
        $error = 'Invalid Campaign Target URL format.';
    } elseif ($previewUrl && !filter_var($previewUrl, FILTER_VALIDATE_URL)) {
        $error = 'Invalid Preview URL format.';
    } elseif ($revenue <= 0 || $payout <= 0) {
        $error = 'Revenue and Payout must be greater than 0.';
    } elseif ($payout > $revenue) {
        $error = 'Publisher payout cannot be greater than revenue.';
    } else {

        $sql = "
            UPDATE offers SET
                advertiser_id = :advertiser_id,
                offer_name = :offer_name,
                offer_description = :offer_description,
                objective = :objective,
                kpi = :kpi,
                allowed_traffic = :allowed_traffic,
                preview_url = :preview_url,
                campaign_url = :campaign_url,
                offer_url = :campaign_url,
                conversion_tracking = :conversion_tracking,
                terms_required = :terms_required,
                category = :category,
                status = :status,
                internal_note = :internal_note,
                revenue = :revenue,
                payout = :payout,
                payout_type = :payout_type,
                currency = :currency,
                geo = :geo,
                country = :country,
                device_type = :device_type,
                browser_targeting = :browser_targeting,
                daily_cap = :daily_cap,
                total_cap = :total_cap,
                start_date = :start_date,
                end_date = :end_date,
                visibility = :visibility,
                allowed_countries = :allowed_countries,
                blocked_countries = :blocked_countries,
                postback_token = :postback_token,
                updated_at = NOW()
            WHERE offer_id = :offer_id
        ";

        $stmt = $pdo->prepare($sql);

        $params = [
            'offer_id'            => $offerId,
            'advertiser_id'       => $advertiserId,
            'offer_name'          => $title,
            'offer_description'   => $description,
            'objective'           => $objective,
            'kpi'                 => $kpi,
            'allowed_traffic'     => $allowedTraffic,
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
        ];

        if ($stmt->execute($params)) {
            $success = "Campaign #{$offerId} updated successfully!";
            if ($regenerateToken) {
                $success .= " New S2S postback token generated.";
            }
            
            // Refresh data
            $refreshStmt = $pdo->prepare("
                SELECT o.*, u.name AS advertiser_name, u.email AS advertiser_email, u.company AS advertiser_company
                FROM offers o
                LEFT JOIN users u ON u.user_id = o.advertiser_id
                WHERE o.offer_id = :offer_id
            ");
            $refreshStmt->execute(['offer_id' => $offerId]);
            $offer = $refreshStmt->fetch(PDO::FETCH_ASSOC);
            
            $allowedTraffic  = !empty($offer['allowed_traffic']) ? explode(',', $offer['allowed_traffic']) : [];
            $browserTargeting = !empty($offer['browser_targeting']) ? explode(',', $offer['browser_targeting']) : [];
        } else {
            $error = "Failed to update campaign. Please check inputs.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Campaign #<?php echo $offerId; ?> | Admin Panel</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- Select2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">
    <!-- Flatpickr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
            --accent-color: #4f46e5;
        }

        .card-custom {
            border-radius: 14px;
            border: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            margin-bottom: 25px;
            background: #ffffff;
        }

        .section-header {
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 12px;
            margin-bottom: 22px;
        }

        .section-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #1e293b;
        }

        /* Select2 bootstrap4 theme fixes */
        .select2-container--bootstrap4 .select2-selection--single {
            height: 46px !important;
            border-radius: 8px !important;
            border: 1px solid #cbd5e1 !important;
            padding: 8px 12px !important;
            display: flex !important;
            align-items: center !important;
        }

        .select2-container--bootstrap4 .select2-selection--multiple {
            min-height: 46px !important;
            border-radius: 8px !important;
            border: 1px solid #cbd5e1 !important;
            padding: 4px 8px !important;
        }

        .token-chip {
            display: inline-block;
            background: #eef2ff;
            color: #4f46e5;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            margin: 3px;
            border: 1px solid #c7d2fe;
            transition: all 0.2s ease;
        }

        .token-chip:hover {
            background: #4f46e5;
            color: #ffffff;
        }

        .margin-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #10b981;
            border-radius: 8px;
            padding: 12px 18px;
        }

        .sticky-save-bar {
            position: sticky;
            bottom: 20px;
            z-index: 100;
            background: #ffffff;
            border-radius: 12px;
            padding: 15px 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            border: 1px solid #e2e8f0;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
            </li>
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link">Dashboard</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="campaigns.php" class="nav-link">Campaigns</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="#" class="nav-link active">Edit Campaign #<?php echo $offerId; ?></a></li>
        </ul>
    </nav>

    <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.4rem;">
                <i class="fas fa-crown mr-2"></i><strong>Admin Console</strong>
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
                    <li class="nav-header">CAMPAIGNS & OFFERS</li>
                    <li class="nav-item">
                        <a href="campaigns.php" class="nav-link active">
                            <i class="nav-icon fas fa-bullhorn"></i>
                            <p>Manage Campaigns</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="create_campaign.php" class="nav-link">
                            <i class="nav-icon fas fa-plus-circle"></i>
                            <p>Create Campaign</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="campaign_access.php" class="nav-link">
                            <i class="nav-icon fas fa-key"></i>
                            <p>Offer Approval Rules</p>
                        </a>
                    </li>

                    <li class="nav-header">USER MANAGEMENT</li>
                    <li class="nav-item">
                        <a href="users.php" class="nav-link">
                            <i class="nav-icon fas fa-users"></i>
                            <p>All System Users</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="publishers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-friends"></i>
                            <p>Publishers / Affiliates</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="advertisers.php" class="nav-link">
                            <i class="nav-icon fas fa-briefcase"></i>
                            <p>Advertisers</p>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    </aside>

    <!-- Content Wrapper -->
    <div class="content-wrapper">
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 font-weight-bold">Edit Campaign #<?php echo $offerId; ?>: <?php echo htmlspecialchars($offer['offer_name']); ?></h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="campaigns.php">Campaigns</a></li>
                            <li class="breadcrumb-item active">Edit Campaign #<?php echo $offerId; ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid mb-5">

                <!-- Alerts -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4" role="alert">
                    <h5><i class="icon fas fa-check-circle"></i> Success!</h5>
                    <p class="mb-0"><?php echo htmlspecialchars($success); ?></p>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4" role="alert">
                    <h5><i class="icon fas fa-exclamation-triangle"></i> Error</h5>
                    <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
                <?php endif; ?>

                <!-- Unified Single-Page Form -->
                <form method="post" id="editCampaignForm">
                    
                    <!-- SECTION 1: ADVERTISER & BASIC INFORMATION -->
                    <div class="card card-custom p-4">
                        <div class="section-header d-flex justify-content-between align-items-center">
                            <span class="section-title"><i class="fas fa-building text-primary mr-2"></i>1. Advertiser Account & Basic Details</span>
                            <span class="badge badge-light border font-weight-bold text-muted">ID: #<?php echo $offerId; ?></span>
                        </div>

                        <div class="form-group mb-4 bg-light p-3 rounded border">
                            <label class="font-weight-bold text-dark">Assigned Advertiser Account <span class="text-danger">*</span></label>
                            <select name="advertiser_id" class="form-control select2" required>
                                <option value="">Select Advertiser Account...</option>
                                <?php foreach ($advertisers as $adv): ?>
                                <option value="<?php echo $adv['user_id']; ?>" <?php echo ($offer['advertiser_id'] == $adv['user_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($adv['name']); ?> 
                                    <?php if ($adv['company']): ?>(<?php echo htmlspecialchars($adv['company']); ?>)<?php endif; ?> 
                                    - <?php echo htmlspecialchars($adv['email']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-4">
                                    <label class="font-weight-bold">Campaign Name / Title <span class="text-danger">*</span></label>
                                    <input type="text" name="title" class="form-control form-control-lg font-weight-bold" required value="<?php echo htmlspecialchars($offer['offer_name']); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group mb-4">
                                    <label class="font-weight-bold">Category</label>
                                    <select name="category" id="campaign_category" class="form-control select2">
                                        <option value="General" <?php echo ($offer['category'] == 'General' || empty($offer['category'])) ? 'selected' : ''; ?>>General</option>
                                        <option value="E-Commerce" <?php echo ($offer['category'] == 'E-Commerce') ? 'selected' : ''; ?>>E-Commerce & Retail</option>
                                        <option value="Finance & Loans" <?php echo ($offer['category'] == 'Finance & Loans') ? 'selected' : ''; ?>>Finance & Loans</option>
                                        <option value="Mobile Apps" <?php echo ($offer['category'] == 'Mobile Apps') ? 'selected' : ''; ?>>Mobile Apps</option>
                                        <option value="Gaming & Casino" <?php echo ($offer['category'] == 'Gaming & Casino') ? 'selected' : ''; ?>>Gaming & Casino</option>
                                        <option value="Crypto & Forex" <?php echo ($offer['category'] == 'Crypto & Forex') ? 'selected' : ''; ?>>Crypto & Forex</option>
                                        <option value="Health & Beauty" <?php echo ($offer['category'] == 'Health & Beauty') ? 'selected' : ''; ?>>Health & Beauty</option>
                                        <option value="_custom">+ Add Custom Category</option>
                                    </select>
                                    <input type="text" name="custom_category" id="custom_category_input" class="form-control mt-2" style="display: none;" placeholder="Enter Custom Category">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group mb-4">
                                    <label class="font-weight-bold">Campaign Objective</label>
                                    <select name="objective" class="form-control select2">
                                        <option value="conversions" <?php echo ($offer['objective'] == 'conversions') ? 'selected' : ''; ?>>Conversions (CPA)</option>
                                        <option value="leads" <?php echo ($offer['objective'] == 'leads') ? 'selected' : ''; ?>>Lead Gen (CPL)</option>
                                        <option value="app_install" <?php echo ($offer['objective'] == 'app_install') ? 'selected' : ''; ?>>App Installs (CPI)</option>
                                        <option value="sale" <?php echo ($offer['objective'] == 'sale') ? 'selected' : ''; ?>>Sales (CPS)</option>
                                        <option value="clicks" <?php echo ($offer['objective'] == 'clicks') ? 'selected' : ''; ?>>Click Traffic (CPC)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="form-group mb-4">
                            <label class="font-weight-bold">Conversion KPI Requirement</label>
                            <input type="text" name="kpi" class="form-control" placeholder="e.g. Valid deposit of min $10, Registration complete" value="<?php echo htmlspecialchars($offer['kpi'] ?? ''); ?>">
                        </div>

                        <div class="form-group mb-0">
                            <label class="font-weight-bold">Campaign Description & Guidelines</label>
                            <textarea name="description" class="form-control" rows="4"><?php echo htmlspecialchars($offer['offer_description'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <!-- SECTION 2: DESTINATION URL & S2S TRACKING -->
                    <div class="card card-custom p-4">
                        <div class="section-header">
                            <span class="section-title"><i class="fas fa-link text-primary mr-2"></i>2. Campaign Target URL & Conversion Postback</span>
                        </div>

                        <div class="form-group mb-4">
                            <label class="font-weight-bold">Campaign Target URL <span class="text-danger">*</span></label>
                            <div class="input-group input-group-lg">
                                <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-globe"></i></span></div>
                                <input type="url" name="campaign_url" id="campaign_url" class="form-control font-weight-bold" required value="<?php echo htmlspecialchars($offer['campaign_url']); ?>">
                            </div>
                            <div class="mt-2">
                                <span class="text-muted small d-block mb-1">Click tokens to insert into URL (Offer18 Standard Tokens):</span>
                                <span class="token-chip" onclick="insertToken('{click_id}')">{click_id}</span>
                                <span class="token-chip" onclick="insertToken('{affiliate_id}')">{affiliate_id}</span>
                                <span class="token-chip" onclick="insertToken('{sub_aff_id}')">{sub_aff_id}</span>
                                <span class="token-chip" onclick="insertToken('{offer_id}')">{offer_id}</span>
                                <span class="token-chip" onclick="insertToken('{sub1}')">{sub1}</span>
                                <span class="token-chip" onclick="insertToken('{sub2}')">{sub2}</span>
                                <span class="token-chip" onclick="insertToken('{sub3}')">{sub3}</span>
                                <span class="token-chip" onclick="insertToken('{sub4}')">{sub4}</span>
                                <span class="token-chip" onclick="insertToken('{sub5}')">{sub5}</span>
                                <span class="token-chip" onclick="insertToken('{country}')">{country}</span>
                                <span class="token-chip" onclick="insertToken('{ip_address}')">{ip_address}</span>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-4">
                                    <label class="font-weight-bold">Preview Landing Page URL</label>
                                    <input type="url" name="preview_url" class="form-control" value="<?php echo htmlspecialchars($offer['preview_url'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-4">
                                    <label class="font-weight-bold">Conversion Tracking Protocol</label>
                                    <select name="conversion_tracking" class="form-control select2">
                                        <option value="postback" <?php echo ($offer['conversion_tracking'] == 'postback') ? 'selected' : ''; ?>>Server-to-Server (S2S Postback URL)</option>
                                        <option value="pixel" <?php echo ($offer['conversion_tracking'] == 'pixel') ? 'selected' : ''; ?>>Client-side Tracking Pixel</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="bg-light p-3 rounded border">
                            <strong class="text-dark d-block mb-2"><i class="fas fa-key text-primary mr-1"></i>S2S Postback Integration Details:</strong>
                            <code class="d-block p-2 bg-white rounded text-break border">
                                https://iconmedianetwork.in/postback?token=<?php echo htmlspecialchars($offer['postback_token']); ?>&click_id={click_id}&payout={payout}
                            </code>
                            <div class="custom-control custom-checkbox mt-2">
                                <input type="checkbox" name="regenerate_token" value="1" class="custom-control-input" id="regenToken">
                                <label class="custom-control-label text-danger font-weight-bold" for="regenToken">Regenerate Postback Token (Invalidates old token)</label>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 3: TARGETING RULES & CONVERSION CAPS -->
                    <div class="card card-custom p-4">
                        <div class="section-header">
                            <span class="section-title"><i class="fas fa-bullseye text-primary mr-2"></i>3. Targeting Restrictions & Daily Caps</span>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-4">
                                    <label class="font-weight-bold">Allowed Countries (ISO Codes)</label>
                                    <input type="text" name="allowed_countries" class="form-control" placeholder="ALL or US,GB,IN,CA" value="<?php echo htmlspecialchars($offer['allowed_countries'] ?? 'ALL'); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-4">
                                    <label class="font-weight-bold">Blocked Countries</label>
                                    <input type="text" name="blocked_countries" class="form-control" placeholder="e.g. RU,CN" value="<?php echo htmlspecialchars($offer['blocked_countries'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-0">
                                    <label class="font-weight-bold">Daily Conversion Cap</label>
                                    <input type="number" name="daily_cap" class="form-control" placeholder="0 = Unlimited" value="<?php echo (int)($offer['daily_cap'] ?? 0); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-0">
                                    <label class="font-weight-bold">Total Conversion Cap</label>
                                    <input type="number" name="total_cap" class="form-control" placeholder="0 = Unlimited" value="<?php echo (int)($offer['total_cap'] ?? 0); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 4: PRICING, VISIBILITY & PUBLICATION STATUS -->
                    <div class="card card-custom p-4">
                        <div class="section-header">
                            <span class="section-title"><i class="fas fa-dollar-sign text-primary mr-2"></i>4. Payout, Revenue & Publication Status</span>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group mb-4">
                                    <label class="font-weight-bold">Advertiser Revenue ($) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" name="revenue" id="rev_input" class="form-control form-control-lg font-weight-bold text-success" required value="<?php echo (float)$offer['revenue']; ?>" oninput="calcMargin()">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group mb-4">
                                    <label class="font-weight-bold">Publisher Payout ($) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" name="payout" id="payout_input" class="form-control form-control-lg font-weight-bold text-indigo" style="color: #4f46e5;" required value="<?php echo (float)$offer['payout']; ?>" oninput="calcMargin()">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group mb-4">
                                    <label class="font-weight-bold">Estimated Network Margin:</label>
                                    <div class="margin-box">
                                        <strong class="text-success h4 mb-0" id="margin_usd">$0.00</strong>
                                        <span class="text-muted ml-1" id="margin_pct">(0%)</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-4">
                                    <label class="font-weight-bold">Publication Status</label>
                                    <select name="status" class="form-control select2">
                                        <option value="active" <?php echo ($offer['status'] == 'active') ? 'selected' : ''; ?>>Active (Live & Ready)</option>
                                        <option value="paused" <?php echo ($offer['status'] == 'paused') ? 'selected' : ''; ?>>Paused</option>
                                        <option value="archived" <?php echo ($offer['status'] == 'archived') ? 'selected' : ''; ?>>Archived / Inactive</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-4">
                                    <label class="font-weight-bold">Visibility Access</label>
                                    <select name="visibility" class="form-control select2">
                                        <option value="public" <?php echo ($offer['visibility'] == 'public') ? 'selected' : ''; ?>>Public (Visible to all network publishers)</option>
                                        <option value="private" <?php echo ($offer['visibility'] == 'private') ? 'selected' : ''; ?>>Private (Requires approval rules)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="form-group mb-0">
                            <label class="font-weight-bold">Internal Admin Notes</label>
                            <textarea name="note" class="form-control" rows="2"><?php echo htmlspecialchars($offer['internal_note'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <!-- STICKY ACTION BAR -->
                    <div class="sticky-save-bar d-flex justify-content-between align-items-center">
                        <div>
                            <a href="campaigns.php" class="btn btn-outline-secondary font-weight-bold px-4"><i class="fas fa-arrow-left mr-2"></i> Back to Campaigns</a>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-success btn-lg font-weight-bold px-5 shadow-sm"><i class="fas fa-save mr-2"></i> Save Campaign Changes</button>
                        </div>
                    </div>

                </form>

            </div>
        </div>
    </div>

    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline"><strong>Admin Panel v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    $('.select2').select2({ theme: 'bootstrap4', width: '100%' });
    calcMargin();

    $('#campaign_category').change(function() {
        if ($(this).val() === '_custom') {
            $('#custom_category_input').show().focus();
        } else {
            $('#custom_category_input').hide();
        }
    });
});

function insertToken(token) {
    const input = document.getElementById('campaign_url');
    const start = input.selectionStart;
    const end = input.selectionEnd;
    const text = input.value;
    input.value = text.substring(0, start) + token + text.substring(end);
    input.focus();
    input.setSelectionRange(start + token.length, start + token.length);
}

function calcMargin() {
    const rev = parseFloat($('#rev_input').val()) || 0;
    const pay = parseFloat($('#payout_input').val()) || 0;
    const net = rev - pay;
    const pct = rev > 0 ? ((net / rev) * 100).toFixed(1) : 0;
    
    $('#margin_usd').text('$' + net.toFixed(2));
    $('#margin_pct').text(pct + '%');
}
</script>
</body>
</html>