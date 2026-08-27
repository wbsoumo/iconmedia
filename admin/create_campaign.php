<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminId = auth_user_id();
$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

// Get categories from database for dropdown
$categoriesStmt = $pdo->query("SELECT DISTINCT category FROM offers WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch Advertisers for dropdown
$advertisersStmt = $pdo->query("
    SELECT user_id, name, email, company 
    FROM users 
    WHERE role_id = 4 AND status = 'active'
    ORDER BY name ASC
");
$advertisers = $advertisersStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   HANDLE FORM SUBMIT
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $advertiserId       = (int)($_POST['advertiser_id'] ?? 0);
    $title              = trim($_POST['title'] ?? '');
    $description        = trim($_POST['description'] ?? '');
    $objective          = $_POST['objective'] ?? 'conversions';
    $kpi                = trim($_POST['kpi'] ?? '');
    $allowedTraffic     = implode(',', $_POST['allowed_traffic'] ?? []);
    $previewUrl         = trim($_POST['preview_url'] ?? '');
    $campaignUrl        = trim($_POST['campaign_url'] ?? '');
    $conversionTracking = $_POST['conversion_tracking'] ?? 'postback';
    $termsRequired      = isset($_POST['terms_required']) ? 1 : 0;
    
    // Category selection or custom category
    $category           = trim($_POST['category'] ?? '');
    if ($category === '_custom' && !empty($_POST['custom_category'])) {
        $category = trim($_POST['custom_category']);
    }
    
    $status             = $_POST['status'] ?? 'active'; // Admin default is Active
    $note               = trim($_POST['note'] ?? '');
    $revenue            = (float)($_POST['revenue'] ?? 0);
    $payout             = (float)($_POST['payout'] ?? 0);
    $payoutType         = $_POST['payout_type'] ?? 'cpa';
    $currency           = $_POST['currency'] ?? 'USD';
    $geo                = trim($_POST['geo'] ?? 'ALL');
    $country            = trim($_POST['country'] ?? 'US');
    $deviceTargeting    = $_POST['device_targeting'] ?? 'all';
    $browserTargeting   = implode(',', $_POST['browser_targeting'] ?? []);
    $dailyCap           = (int)($_POST['daily_cap'] ?? 0);
    $totalCap           = (int)($_POST['total_cap'] ?? 0);
    $startDate          = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $endDate            = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $visibility         = $_POST['visibility'] ?? 'public';
    $allowedCountries   = trim($_POST['allowed_countries'] ?? 'ALL');
    $blockedCountries   = trim($_POST['blocked_countries'] ?? '');
    
    // Admin Override Controls
    $isFeatured         = isset($_POST['is_featured']) ? 1 : 0;
    $autoApprove        = isset($_POST['auto_approve']) ? 1 : 0;

    // Generate unique postback token
    $postbackToken = bin2hex(random_bytes(16));

    /* BASIC VALIDATION */
    if ($advertiserId === 0) {
        $error = 'Please select an advertiser for this campaign.';
    } elseif ($title === '' || $campaignUrl === '') {
        $error = 'Campaign Title and Target URL are required.';
    } elseif (!filter_var($campaignUrl, FILTER_VALIDATE_URL)) {
        $error = 'Invalid Target URL format. Must start with http:// or https://';
    } elseif ($previewUrl && !filter_var($previewUrl, FILTER_VALIDATE_URL)) {
        $error = 'Invalid Preview URL format.';
    } elseif ($revenue <= 0 || $payout <= 0) {
        $error = 'Revenue and payout amounts must be greater than zero.';
    } elseif ($payout > $revenue) {
        $error = 'Affiliate payout ($' . number_format($payout, 2) . ') cannot exceed total revenue ($' . number_format($revenue, 2) . ').';
    } else {

        try {
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

            $newOfferId = $pdo->lastInsertId();
            $newPostbackToken = $postbackToken;
            $success = "Campaign #" . $newOfferId . " created successfully and published under Status: " . strtoupper($status) . "!";

        } catch (PDOException $e) {
            $error = 'Database error creating campaign: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create New Campaign | Admin Control Panel</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- Select2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <!-- Flatpickr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
            --accent-color: #4f46e5;
        }

        .card-custom {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 18px rgba(0,0,0,0.06);
            margin-bottom: 25px;
            background: #ffffff;
        }

        /* Step Wizard Styling */
        .wizard-progress {
            display: flex;
            justify-content: space-between;
            margin-bottom: 25px;
            position: relative;
        }

        .wizard-progress::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 5%;
            right: 5%;
            height: 3px;
            background: #e2e8f0;
            z-index: 1;
        }

        .wizard-step-item {
            position: relative;
            z-index: 2;
            text-align: center;
            flex: 1;
            cursor: pointer;
        }

        .wizard-step-circle {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #ffffff;
            border: 3px solid #cbd5e1;
            color: #64748b;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 8px;
            transition: all 0.3s ease;
        }

        .wizard-step-item.active .wizard-step-circle {
            background: #4f46e5;
            border-color: #4f46e5;
            color: #ffffff;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.2);
        }

        .wizard-step-item.completed .wizard-step-circle {
            background: #10b981;
            border-color: #10b981;
            color: #ffffff;
        }

        .wizard-step-label {
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
        }

        .wizard-step-item.active .wizard-step-label {
            color: #4f46e5;
            font-weight: 700;
        }

        .tab-pane-step {
            display: none;
        }

        .tab-pane-step.active {
            display: block;
        }

        /* Preset Cards */
        .preset-btn {
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-right: 8px;
            margin-bottom: 8px;
        }

        .preset-btn:hover {
            border-color: #4f46e5;
            background: #eef2ff;
            color: #4f46e5;
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
        }

        .token-chip:hover {
            background: #4f46e5;
            color: #ffffff;
        }

        .admin-override-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
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
            <li class="nav-item d-none d-sm-inline-block">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="campaigns.php" class="nav-link">Campaigns</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="create_campaign.php" class="nav-link active">Create Campaign</a>
            </li>
        </ul>
    </nav>

    <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.5rem;">
                <i class="fas fa-shield-alt mr-2"></i><strong>Admin Panel</strong>
            </span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link"><i class="nav-icon fas fa-tachometer-alt"></i><p>Dashboard</p></a>
                    </li>
                    <li class="nav-header">CAMPAIGNS</li>
                    <li class="nav-item">
                        <a href="campaigns.php" class="nav-link"><i class="nav-icon fas fa-bullhorn"></i><p>Manage Campaigns</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="create_campaign.php" class="nav-link active"><i class="nav-icon fas fa-plus-circle"></i><p>Create Campaign</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="offers.php" class="nav-link"><i class="nav-icon fas fa-gift"></i><p>All Offers</p></a>
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
                        <h1 class="m-0 font-weight-bold">Create New Campaign (Admin Wizard)</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="campaigns.php">Campaigns</a></li>
                            <li class="breadcrumb-item active">Create Campaign</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">

                <!-- Alert Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                    <h5><i class="icon fas fa-check-circle"></i> Campaign Created!</h5>
                    <p class="mb-2"><?php echo htmlspecialchars($success); ?></p>
                    
                    <?php if (isset($newPostbackToken)): ?>
                    <div class="bg-white p-3 rounded text-dark mt-2 border">
                        <strong class="d-block text-primary mb-1"><i class="fas fa-link mr-1"></i>S2S Postback Integration URL:</strong>
                        <code class="d-block p-2 bg-light rounded text-break">https://iconmedianetwork.in/postback?token=<?php echo $newPostbackToken; ?>&click_id={click_id}&payout={payout}</code>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <h5><i class="icon fas fa-exclamation-triangle"></i> Action Required</h5>
                    <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                </div>
                <?php endif; ?>

                <!-- Step Wizard Indicator -->
                <div class="wizard-progress">
                    <div class="wizard-step-item active" onclick="goToStep(1)">
                        <div class="wizard-step-circle">1</div>
                        <div class="wizard-step-label">Advertiser & Basic</div>
                    </div>
                    <div class="wizard-step-item" onclick="goToStep(2)">
                        <div class="wizard-step-circle">2</div>
                        <div class="wizard-step-label">Tracking & Links</div>
                    </div>
                    <div class="wizard-step-item" onclick="goToStep(3)">
                        <div class="wizard-step-circle">3</div>
                        <div class="wizard-step-label">Targeting & Caps</div>
                    </div>
                    <div class="wizard-step-item" onclick="goToStep(4)">
                        <div class="wizard-step-circle">4</div>
                        <div class="wizard-step-label">Admin Controls & Pricing</div>
                    </div>
                </div>

                <!-- Form Wizard Form -->
                <form method="post" id="adminCampaignForm">
                    <div class="card card-custom p-4">

                        <!-- STEP 1: ADVERTISER & BASIC INFORMATION -->
                        <div class="tab-pane-step active" id="step-1">
                            <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-building mr-2"></i>Step 1: Advertiser Assignment & Basic Info</h4>
                            <p class="text-muted mb-4">Assign the campaign to an advertiser account and configure title & category.</p>

                            <div class="form-group mb-4 bg-light p-3 rounded border">
                                <label class="font-weight-bold text-dark">Assign Advertiser Account <span class="text-danger">*</span></label>
                                <select name="advertiser_id" class="form-control form-control-lg select2" required>
                                    <option value="">Select Advertiser Account...</option>
                                    <?php foreach ($advertisers as $adv): ?>
                                    <option value="<?php echo $adv['user_id']; ?>" <?php echo (isset($_POST['advertiser_id']) && $_POST['advertiser_id'] == $adv['user_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($adv['name']); ?> 
                                        <?php if ($adv['company']): ?>(<?php echo htmlspecialchars($adv['company']); ?>)<?php endif; ?> 
                                        - <?php echo htmlspecialchars($adv['email']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Quick Preset Templates -->
                            <div class="mb-4">
                                <label class="font-weight-bold">Quick Campaign Presets (Auto-fill):</label>
                                <div>
                                    <div class="preset-btn" onclick="applyPreset('ecom')"><i class="fas fa-shopping-cart text-primary"></i> E-Commerce Sale</div>
                                    <div class="preset-btn" onclick="applyPreset('lead')"><i class="fas fa-user-check text-success"></i> Lead Gen Form</div>
                                    <div class="preset-btn" onclick="applyPreset('app')"><i class="fas fa-mobile-alt text-info"></i> Mobile App Install</div>
                                    <div class="preset-btn" onclick="applyPreset('finance')"><i class="fas fa-file-invoice-dollar text-warning"></i> Finance & Crypto</div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-8">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Campaign Title <span class="text-danger">*</span></label>
                                        <input type="text" name="title" id="campaign_title" class="form-control form-control-lg" placeholder="e.g. Premium VPN - Global Subscription Promo" required value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Objective <span class="text-danger">*</span></label>
                                        <select name="objective" id="campaign_objective" class="form-control form-control-lg">
                                            <option value="conversions">Conversions / Sales</option>
                                            <option value="leads">Lead Generation (CPL)</option>
                                            <option value="app_install">Mobile App Install (CPI)</option>
                                            <option value="registrations">User Registrations</option>
                                            <option value="downloads">Software Download</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Category</label>
                                        <select name="category" id="campaign_category" class="form-control select2">
                                            <option value="">Select Category</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                            <?php endforeach; ?>
                                            <option value="_custom">+ Create Custom Category</option>
                                        </select>
                                        <input type="text" name="custom_category" id="custom_category_input" class="form-control mt-2" style="display:none;" placeholder="Enter custom category name">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">KPI Criteria</label>
                                        <input type="text" name="kpi" id="campaign_kpi" class="form-control" placeholder="e.g. Minimum $20 deposit required" value="<?php echo htmlspecialchars($_POST['kpi'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group mb-4">
                                <label class="font-weight-bold">Campaign Description & Publisher Terms</label>
                                <textarea name="description" id="campaign_description" class="form-control" rows="4" placeholder="Provide offer rules, restrictions, and conversion instructions for publishers..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                            </div>

                            <div class="text-right mt-4">
                                <button type="button" class="btn btn-primary btn-lg font-weight-bold px-4" onclick="goToStep(2)">Next: Tracking & Links <i class="fas fa-arrow-right ml-2"></i></button>
                            </div>
                        </div>

                        <!-- STEP 2: TRACKING & LINKS -->
                        <div class="tab-pane-step" id="step-2">
                            <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-link mr-2"></i>Step 2: Destination & Conversion Tracking</h4>
                            <p class="text-muted mb-4">Configure landing page target URL and dynamic tracking macros.</p>

                            <div class="form-group mb-4">
                                <label class="font-weight-bold">Campaign Target URL <span class="text-danger">*</span></label>
                                <div class="input-group input-group-lg">
                                    <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-globe"></i></span></div>
                                    <input type="url" name="campaign_url" id="campaign_url" class="form-control" placeholder="https://advertiser.com/landing?click_id={click_id}" required value="<?php echo htmlspecialchars($_POST['campaign_url'] ?? ''); ?>">
                                </div>
                                <div class="mt-2">
                                    <span class="text-muted small d-block mb-1">Click tokens to insert into URL:</span>
                                    <span class="token-chip" onclick="insertToken('{click_id}')">{click_id}</span>
                                    <span class="token-chip" onclick="insertToken('{affiliate_id}')">{affiliate_id}</span>
                                    <span class="token-chip" onclick="insertToken('{sub1}')">{sub1}</span>
                                    <span class="token-chip" onclick="insertToken('{sub2}')">{sub2}</span>
                                    <span class="token-chip" onclick="insertToken('{country}')">{country}</span>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Preview Landing Page URL</label>
                                        <input type="url" name="preview_url" class="form-control" placeholder="https://advertiser.com/preview" value="<?php echo htmlspecialchars($_POST['preview_url'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Conversion Tracking Protocol</label>
                                        <select name="conversion_tracking" class="form-control">
                                            <option value="postback">Server-to-Server (S2S Postback URL)</option>
                                            <option value="pixel">Client-side Tracking Pixel</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Visibility Access</label>
                                        <select name="visibility" class="form-control">
                                            <option value="public">Public (Visible to all network publishers)</option>
                                            <option value="private">Private (Requires manual approval)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-4 mt-4 pt-2">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" name="terms_required" class="custom-control-input" id="terms_required" value="1">
                                            <label class="custom-control-label font-weight-bold" for="terms_required">Require affiliates to accept terms before generating tracking links</label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-outline-secondary font-weight-bold" onclick="goToStep(1)"><i class="fas fa-arrow-left mr-2"></i> Back</button>
                                <button type="button" class="btn btn-primary btn-lg font-weight-bold px-4" onclick="goToStep(3)">Next: Targeting & Caps <i class="fas fa-arrow-right ml-2"></i></button>
                            </div>
                        </div>

                        <!-- STEP 3: TARGETING & CAPS -->
                        <div class="tab-pane-step" id="step-3">
                            <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-crosshairs mr-2"></i>Step 3: Geo Targeting, Devices & Caps</h4>
                            <p class="text-muted mb-4">Restrict traffic locations, device types, and daily lead caps.</p>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Allowed Countries (Comma Separated)</label>
                                        <input type="text" name="allowed_countries" class="form-control" placeholder="ALL or IN, US, UK, CA" value="ALL">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Blocked Countries</label>
                                        <input type="text" name="blocked_countries" class="form-control" placeholder="e.g. RU, CN, PK">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Device Targeting</label>
                                        <select name="device_targeting" class="form-control">
                                            <option value="all">All Devices (Desktop & Mobile)</option>
                                            <option value="mobile">Mobile Only (iOS & Android)</option>
                                            <option value="desktop">Desktop / Laptop Only</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Primary Target Country</label>
                                        <input type="text" name="country" class="form-control" placeholder="e.g. US" value="US">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Daily Conversion Cap (0 = Unlimited)</label>
                                        <input type="number" name="daily_cap" class="form-control" min="0" value="0">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Total Offer Cap (0 = Unlimited)</label>
                                        <input type="number" name="total_cap" class="form-control" min="0" value="0">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Start Date</label>
                                        <input type="text" name="start_date" class="form-control flatpickr" placeholder="Select start date">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Expiration Date</label>
                                        <input type="text" name="end_date" class="form-control flatpickr" placeholder="Select expiration date">
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-outline-secondary font-weight-bold" onclick="goToStep(2)"><i class="fas fa-arrow-left mr-2"></i> Back</button>
                                <button type="button" class="btn btn-primary btn-lg font-weight-bold px-4" onclick="goToStep(4)">Next: Admin Controls & Launch <i class="fas fa-arrow-right ml-2"></i></button>
                            </div>
                        </div>

                        <!-- STEP 4: ADMIN CONTROLS & PRICING -->
                        <div class="tab-pane-step" id="step-4">
                            <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-user-shield mr-2"></i>Step 4: Admin Controls & Financial Margins</h4>
                            <p class="text-muted mb-4">Configure offer status, financial payouts, net network profit, and admin privileges.</p>

                            <!-- Admin Controls Box -->
                            <div class="admin-override-box mb-4">
                                <h5 class="font-weight-bold text-success mb-3"><i class="fas fa-sliders-h mr-2"></i>Admin Override Privileges</h5>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group mb-0">
                                            <label class="font-weight-bold">Initial Campaign Status</label>
                                            <select name="status" class="form-control font-weight-bold text-success">
                                                <option value="active">ACTIVE (Live on Network)</option>
                                                <option value="pending">PENDING (Review Needed)</option>
                                                <option value="paused">PAUSED (Hold Traffic)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="custom-control custom-checkbox mt-4">
                                            <input type="checkbox" name="is_featured" class="custom-control-input" id="is_featured" value="1">
                                            <label class="custom-control-label font-weight-bold text-primary" for="is_featured">Feature on Publisher Catalog Top Banner</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="custom-control custom-checkbox mt-4">
                                            <input type="checkbox" name="auto_approve" class="custom-control-input" id="auto_approve" value="1" checked>
                                            <label class="custom-control-label font-weight-bold text-success" for="auto_approve">Auto-Approve All Publisher Applications</label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Advertiser Revenue (Network Earns) <span class="text-danger">*</span></label>
                                        <div class="input-group input-group-lg">
                                            <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                            <input type="number" step="0.01" name="revenue" id="rev_input" class="form-control" placeholder="50.00" required oninput="calcMargin()">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Affiliate Payout (Publisher Earns) <span class="text-danger">*</span></label>
                                        <div class="input-group input-group-lg">
                                            <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                            <input type="number" step="0.01" name="payout" id="payout_input" class="form-control" placeholder="35.00" required oninput="calcMargin()">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Payout Type</label>
                                        <select name="payout_type" class="form-control">
                                            <option value="cpa">CPA (Cost Per Action)</option>
                                            <option value="cpl">CPL (Cost Per Lead)</option>
                                            <option value="cpi">CPI (Cost Per Install)</option>
                                            <option value="revshare">Revenue Share (%)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-bold">Currency</label>
                                        <select name="currency" class="form-control">
                                            <option value="USD">USD ($)</option>
                                            <option value="INR">INR (₹)</option>
                                            <option value="EUR">EUR (€)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Real-time Margin & Profit Calculator -->
                            <div class="p-3 bg-light rounded border mb-4">
                                <div class="row text-center">
                                    <div class="col-6">
                                        <small class="text-muted text-uppercase d-block font-weight-bold">Network Net Profit / Conversion</small>
                                        <span id="margin_usd" class="h3 font-weight-bold text-success">$0.00</span>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted text-uppercase d-block font-weight-bold">Gross Margin %</small>
                                        <span id="margin_pct" class="h3 font-weight-bold text-primary">0%</span>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group mb-4">
                                <label class="font-weight-bold">Internal Admin Notes</label>
                                <textarea name="note" class="form-control" rows="2" placeholder="Private notes visible only to admins and account managers..."></textarea>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-outline-secondary font-weight-bold" onclick="goToStep(3)"><i class="fas fa-arrow-left mr-2"></i> Back</button>
                                <button type="submit" class="btn btn-success btn-lg font-weight-bold px-5 shadow"><i class="fas fa-rocket mr-2"></i> Publish Campaign Now</button>
                            </div>
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
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
$(document).ready(function() {
    $('.select2').select2({ theme: 'bootstrap4', width: '100%' });
    $('.flatpickr').flatpickr({ dateFormat: 'Y-m-d' });

    $('#campaign_category').change(function() {
        if ($(this).val() === '_custom') {
            $('#custom_category_input').show().focus();
        } else {
            $('#custom_category_input').hide();
        }
    });
});

function goToStep(stepNum) {
    $('.wizard-step-item').removeClass('active');
    for (let i = 1; i <= stepNum; i++) {
        $('.wizard-step-item:nth-child(' + i + ')').addClass('active');
    }
    $('.tab-pane-step').removeClass('active');
    $('#step-' + stepNum).addClass('active');
    window.scrollTo({ top: 150, behavior: 'smooth' });
}

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

function applyPreset(type) {
    if (type === 'ecom') {
        $('#campaign_title').val('E-Commerce Fashion Sale - Summer Clearance');
        $('#campaign_objective').val('conversions');
        $('#campaign_kpi').val('Valid order payment complete');
        $('#campaign_description').val('Promote summer apparel collection. Cash on delivery & online payments accepted.');
    } else if (type === 'lead') {
        $('#campaign_title').val('Personal Loan Instant Approval Form');
        $('#campaign_objective').val('leads');
        $('#campaign_kpi').val('Form submission with valid phone OTP');
        $('#campaign_description').val('User must fill out 4-step loan eligibility form.');
    } else if (type === 'app') {
        $('#campaign_title').val('Mobile Banking App Install & Signup');
        $('#campaign_objective').val('app_install');
        $('#campaign_kpi').val('First open after install & registration');
        $('#campaign_description').val('Android & iOS app install. Only new device installs count.');
    } else if (type === 'finance') {
        $('#campaign_title').val('Crypto Trading Account Deposit');
        $('#campaign_objective').val('conversions');
        $('#campaign_kpi').val('Minimum $50 first time deposit (FTD)');
        $('#campaign_description').val('High converting crypto offer. FTD verified within 24 hours via S2S postback.');
    }
}
</script>
</body>
</html>