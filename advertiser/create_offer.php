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
$categoriesStmt = $pdo->query("SELECT DISTINCT category FROM offers WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

/* ===============================
   HANDLE FORM SUBMIT
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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
    
    $status             = 'pending'; // Always pending admin approval for safety
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
    
    // Generate unique postback token
    $postbackToken = bin2hex(random_bytes(16));

    /* BASIC VALIDATION */
    if ($title === '' || $campaignUrl === '') {
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
                'preview_url'         => $previewUrl ?: $campaignUrl,
                'campaign_url'        => $campaignUrl,
                'conversion_tracking' => $conversionTracking,
                'terms_required'      => $termsRequired,
                'category'            => $category ?: 'General',
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
            $success = "Campaign #{$offerId} submitted successfully! Our compliance team will review and approve it shortly.";
            $newPostbackToken = $postbackToken;
        } catch (Exception $e) {
            $error = "Failed to create campaign: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Advanced Campaign Creation Wizard | Advertiser Panel</title>
    
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
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #4f46e5 0%, #2563eb 100%);
            --success-gradient: linear-gradient(135deg, #059669 0%, #10b981 100%);
            --accent-color: #4f46e5;
        }

        .wizard-progress {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin-bottom: 30px;
            background: #ffffff;
            padding: 20px 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .wizard-step-item {
            flex: 1;
            text-align: center;
            position: relative;
            z-index: 2;
            cursor: pointer;
        }

        .wizard-step-circle {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            margin-bottom: 8px;
            transition: all 0.3s ease;
        }

        .wizard-step-item.active .wizard-step-circle {
            background: var(--accent-color);
            color: #ffffff;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.2);
        }

        .wizard-step-item.completed .wizard-step-circle {
            background: #10b981;
            color: #ffffff;
        }

        .wizard-step-label {
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
        }

        .wizard-step-item.active .wizard-step-label {
            color: var(--accent-color);
        }

        .tab-pane-step {
            display: none;
        }

        .tab-pane-step.active {
            display: block;
            animation: fadeIn 0.4s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .card-custom {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.06);
            background: #ffffff;
            overflow: hidden;
        }

        .form-label-enhanced {
            font-weight: 700;
            color: #1e293b;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .form-help-text {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
        }

        .token-chip {
            display: inline-block;
            background: #e0e7ff;
            color: #3730a3;
            padding: 4px 10px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            margin: 3px;
            transition: all 0.2s;
        }

        .token-chip:hover {
            background: #c7d2fe;
            transform: scale(1.05);
        }

        .preset-btn {
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            border-radius: 8px;
            padding: 10px 16px;
            font-weight: 600;
            color: #334155;
            transition: all 0.2s;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-right: 8px;
            margin-bottom: 8px;
        }

        .preset-btn.active, .preset-btn:hover {
            border-color: var(--accent-color);
            background: #e0e7ff;
            color: var(--accent-color);
        }

        .margin-calculator-box {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #cbd5e1;
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
                <a href="offers.php" class="nav-link">Campaigns</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="create_offer.php" class="nav-link active">Create Campaign</a>
            </li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <a class="nav-link" href="#" id="darkModeToggle"><i class="fas fa-moon"></i></a>
            </li>
        </ul>
    </nav>

    <!-- Sidebar -->
        <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.4rem;">
                <i class="fas fa-bullhorn mr-2"></i><strong>Advertiser Portal</strong>
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
                        <a href="campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-bullhorn"></i>
                            <p>Manage Campaigns</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="create_offer.php" class="nav-link active">
                            <i class="nav-icon fas fa-plus-circle"></i>
                            <p>Create New Offer</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="optimization.php" class="nav-link">
                            <i class="nav-icon fas fa-rocket"></i>
                            <p>Campaign Optimization</p>
                        </a>
                    </li>

                    <li class="nav-header">ANALYTICS & REPORTS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Campaign Performance</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_conversions.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <p>Conversion Logs</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link">
                            <i class="nav-icon fas fa-users"></i>
                            <p>Publisher Breakdown</p>
                        </a>
                    </li>

                    <li class="nav-header">BILLING & INTEGRATION</li>
                    <li class="nav-item">
                        <a href="billing.php" class="nav-link">
                            <i class="nav-icon fas fa-wallet"></i>
                            <p>Billing & Deposit</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="postback.php" class="nav-link">
                            <i class="nav-icon fas fa-code"></i>
                            <p>S2S Postback Integration</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="ip_whitelist.php" class="nav-link">
                            <i class="nav-icon fas fa-shield-alt"></i>
                            <p>IP Whitelist</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="api.php" class="nav-link">
                            <i class="nav-icon fas fa-key"></i>
                            <p>API Access Keys</p>
                        </a>
                    </li>

                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user-cog"></i>
                            <p>Account Profile</p>
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
                        <h1 class="m-0 font-weight-bold">Create New Campaign</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="offers.php">Campaigns</a></li>
                            <li class="breadcrumb-item active">New Campaign</li>
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
                        <div class="wizard-step-label">Basic Info</div>
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
                        <div class="wizard-step-label">Pricing & Launch</div>
                    </div>
                </div>

                <!-- Form Wizard Form -->
                <form method="post" id="advancedCampaignForm">
                    <div class="card card-custom p-4">

                        <!-- STEP 1: BASIC INFORMATION -->
                        <div class="tab-pane-step active" id="step-1">
                            <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-info-circle mr-2"></i>Step 1: Basic Campaign Details</h4>
                            <p class="text-muted mb-4">Set up the title, objective, and description for your affiliate campaign.</p>

                            <!-- Quick Preset Templates -->
                            <div class="mb-4">
                                <label class="form-label-enhanced">Quick Campaign Presets (Auto-fill):</label>
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
                                        <label class="form-label-enhanced">Campaign Title <span class="text-danger">*</span></label>
                                        <input type="text" name="title" id="campaign_title" class="form-control form-control-lg" placeholder="e.g. Premium VPN - Global Subscription Promo" required value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
                                        <div class="form-help-text">Clear titles attract higher-converting affiliates.</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group mb-4">
                                        <label class="form-label-enhanced">Objective <span class="text-danger">*</span></label>
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
                                        <label class="form-label-enhanced">Category</label>
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
                                        <label class="form-label-enhanced">KPI Criteria</label>
                                        <input type="text" name="kpi" id="campaign_kpi" class="form-control" placeholder="e.g. Minimum $20 deposit required" value="<?php echo htmlspecialchars($_POST['kpi'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group mb-4">
                                <label class="form-label-enhanced">Campaign Description & Guidelines</label>
                                <textarea name="description" id="campaign_description" class="form-control" rows="4" placeholder="Provide offer rules, restrictions, and conversion instructions for publishers..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                            </div>

                            <div class="text-right mt-4">
                                <button type="button" class="btn btn-primary btn-lg font-weight-bold px-4" onclick="goToStep(2)">Next: Tracking & Links <i class="fas fa-arrow-right ml-2"></i></button>
                            </div>
                        </div>

                        <!-- STEP 2: TRACKING & LINKS -->
                        <div class="tab-pane-step" id="step-2">
                            <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-link mr-2"></i>Step 2: Destination & Conversion Tracking</h4>
                            <p class="text-muted mb-4">Configure your landing page target URL and dynamic tracking macros.</p>

                            <div class="form-group mb-4">
                                <label class="form-label-enhanced">Campaign Target URL <span class="text-danger">*</span></label>
                                <div class="input-group input-group-lg">
                                    <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-globe"></i></span></div>
                                    <input type="url" name="campaign_url" id="campaign_url" class="form-control" placeholder="https://yourdomain.com/landing?click_id={click_id}" required value="<?php echo htmlspecialchars($_POST['campaign_url'] ?? ''); ?>">
                                </div>
                                <div class="mt-2">
                                    <span class="form-help-text d-block mb-1">Click tokens to insert into URL:</span>
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
                                        <label class="form-label-enhanced">Preview Landing Page URL</label>
                                        <input type="url" name="preview_url" class="form-control" placeholder="https://yourdomain.com/landing" value="<?php echo htmlspecialchars($_POST['preview_url'] ?? ''); ?>">
                                        <div class="form-help-text">Direct URL where publishers can preview the landing page without tracking.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="form-label-enhanced">Conversion Tracking Type</label>
                                        <select name="conversion_tracking" class="form-control">
                                            <option value="postback">S2S Server-to-Server Postback URL (Recommended)</option>
                                            <option value="pixel">HTML Image / JS Pixel</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="form-label-enhanced">Campaign Visibility</label>
                                        <select name="visibility" class="form-control">
                                            <option value="public">Public (Visible & available to all network affiliates)</option>
                                            <option value="private">Private (Requires advertiser manual approval)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-outline-secondary btn-lg" onclick="goToStep(1)"><i class="fas fa-arrow-left mr-2"></i>Back</button>
                                <button type="button" class="btn btn-primary btn-lg font-weight-bold px-4" onclick="goToStep(3)">Next: Targeting & Caps <i class="fas fa-arrow-right ml-2"></i></button>
                            </div>
                        </div>

                        <!-- STEP 3: TARGETING & CAPS -->
                        <div class="tab-pane-step" id="step-3">
                            <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-crosshairs mr-2"></i>Step 3: Geographic & Device Restrictions</h4>
                            <p class="text-muted mb-4">Specify allowed traffic sources, locations, and daily conversion limits.</p>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="form-label-enhanced">Allowed Country Codes</label>
                                        <input type="text" name="allowed_countries" class="form-control" placeholder="ALL or US, CA, UK, IN" value="<?php echo htmlspecialchars($_POST['allowed_countries'] ?? 'ALL'); ?>">
                                        <div class="form-help-text">Use comma-separated ISO country codes (or 'ALL' for worldwide).</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="form-label-enhanced">Blocked Country Codes</label>
                                        <input type="text" name="blocked_countries" class="form-control" placeholder="e.g. RU, CN, PK" value="<?php echo htmlspecialchars($_POST['blocked_countries'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="form-label-enhanced">Device Targeting</label>
                                        <select name="device_targeting" class="form-control">
                                            <option value="all">All Devices (Desktop + Mobile + Tablet)</option>
                                            <option value="mobile">Mobile Devices Only</option>
                                            <option value="desktop">Desktop Only</option>
                                            <option value="tablet">Tablet Only</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group mb-4">
                                        <label class="form-label-enhanced">Daily Conversion Cap</label>
                                        <input type="number" name="daily_cap" class="form-control" placeholder="0 for Unlimited" min="0" value="<?php echo htmlspecialchars($_POST['daily_cap'] ?? '0'); ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group mb-4">
                                        <label class="form-label-enhanced">Total Conversion Cap</label>
                                        <input type="number" name="total_cap" class="form-control" placeholder="0 for Unlimited" min="0" value="<?php echo htmlspecialchars($_POST['total_cap'] ?? '0'); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group mb-4">
                                <label class="form-label-enhanced mb-2">Allowed Traffic Types:</label>
                                <div class="d-flex flex-wrap gap-3">
                                    <?php 
                                    $channels = ['Facebook', 'Google Ads', 'Native', 'Email', 'Push', 'In-App', 'Display', 'Social Media', 'Search'];
                                    foreach ($channels as $ch): 
                                    ?>
                                    <div class="custom-control custom-checkbox mr-3 mb-2">
                                        <input type="checkbox" name="allowed_traffic[]" value="<?php echo $ch; ?>" class="custom-control-input" id="tf_<?php echo strtolower(str_replace(' ', '_', $ch)); ?>" checked>
                                        <label class="custom-control-label" for="tf_<?php echo strtolower(str_replace(' ', '_', $ch)); ?>"><?php echo $ch; ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-outline-secondary btn-lg" onclick="goToStep(2)"><i class="fas fa-arrow-left mr-2"></i>Back</button>
                                <button type="button" class="btn btn-primary btn-lg font-weight-bold px-4" onclick="goToStep(4)">Next: Pricing & Submit <i class="fas fa-arrow-right ml-2"></i></button>
                            </div>
                        </div>

                        <!-- STEP 4: PRICING & SUBMIT -->
                        <div class="tab-pane-step" id="step-4">
                            <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-dollar-sign mr-2"></i>Step 4: Payout & Revenue Calculation</h4>
                            <p class="text-muted mb-4">Define your budget per lead/sale and calculate your net profit margin.</p>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="form-label-enhanced">Revenue Per Conversion (Advertiser Receives) <span class="text-danger">*</span></label>
                                        <div class="input-group input-group-lg">
                                            <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                            <input type="number" step="0.01" name="revenue" id="input_revenue" class="form-control" placeholder="50.00" required oninput="calcMargin()" value="<?php echo htmlspecialchars($_POST['revenue'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="form-label-enhanced">Payout Per Conversion (Affiliate Earns) <span class="text-danger">*</span></label>
                                        <div class="input-group input-group-lg">
                                            <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                            <input type="number" step="0.01" name="payout" id="input_payout" class="form-control" placeholder="35.00" required oninput="calcMargin()" value="<?php echo htmlspecialchars($_POST['payout'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-4">
                                        <label class="form-label-enhanced">Payout Model</label>
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
                                        <label class="form-label-enhanced">Currency</label>
                                        <select name="currency" class="form-control">
                                            <option value="USD">USD ($)</option>
                                            <option value="INR">INR (₹)</option>
                                            <option value="EUR">EUR (€)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Real-time Margin Calculator -->
                            <div class="margin-calculator-box mb-4">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <span class="text-uppercase text-muted font-weight-bold small">Calculated Profit Margin</span>
                                        <h3 id="netMarginDisplay" class="font-weight-bold text-success mb-0">$0.00 (0%)</h3>
                                    </div>
                                    <div class="col-md-6 text-md-right">
                                        <span class="badge badge-info p-2"><i class="fas fa-shield-alt mr-1"></i>Network Fee: 0% Direct Payout</span>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-outline-secondary btn-lg" onclick="goToStep(3)"><i class="fas fa-arrow-left mr-2"></i>Back</button>
                                <button type="submit" class="btn btn-success btn-lg font-weight-bold px-5 shadow"><i class="fas fa-rocket mr-2"></i>Launch Campaign Now</button>
                            </div>
                        </div>

                    </div>
                </form>

            </div>
        </div>
    </div>

    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline"><strong>Advertiser Panel v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
let currentStep = 1;

function goToStep(step) {
    if (step > currentStep && !validateCurrentStep()) return;

    $('.wizard-step-item').removeClass('active completed');
    for (let i = 1; i <= 4; i++) {
        if (i < step) {
            $('.wizard-step-item:nth-child(' + i + ')').addClass('completed');
        } else if (i === step) {
            $('.wizard-step-item:nth-child(' + i + ')').addClass('active');
        }
    }

    $('.tab-pane-step').removeClass('active');
    $('#step-' + step).addClass('active');
    currentStep = step;
    window.scrollTo({ top: 150, behavior: 'smooth' });
}

function validateCurrentStep() {
    if (currentStep === 1) {
        const title = $('#campaign_title').val().trim();
        if (!title) {
            Swal.fire('Required Field', 'Please enter a Campaign Title to proceed.', 'warning');
            return false;
        }
    } else if (currentStep === 2) {
        const url = $('#campaign_url').val().trim();
        if (!url) {
            Swal.fire('Required Field', 'Please enter your Campaign Target URL to proceed.', 'warning');
            return false;
        }
    }
    return true;
}

function applyPreset(type) {
    if (type === 'ecom') {
        $('#campaign_title').val('Summer Fashion Sale - 20% Off Promo');
        $('#campaign_objective').val('conversions');
        $('#campaign_kpi').val('Completed checkout purchase');
        $('#campaign_description').val('Promote our summer fashion catalog. All traffic allowed except incentivized.');
    } else if (type === 'lead') {
        $('#campaign_title').val('Solar Consultation - Free Quote Lead');
        $('#campaign_objective').val('leads');
        $('#campaign_kpi').val('Verified phone & email lead submission');
        $('#campaign_description').val('User must fill out home address and request a free solar installation quote.');
    } else if (type === 'app') {
        $('#campaign_title').val('Gaming App - iOS/Android Install Campaign');
        $('#campaign_objective').val('app_install');
        $('#campaign_kpi').val('First open after install');
        $('#campaign_description').val('CPI campaign for mobile devices. First open after installation counts as conversion.');
    } else if (type === 'finance') {
        $('#campaign_title').val('Crypto Wallet - First Deposit Offer');
        $('#campaign_objective').val('registrations');
        $('#campaign_kpi').val('KYC verification + $50 min deposit');
        $('#campaign_description').val('Target crypto traders. User must complete account verification.');
    }
}

function insertToken(token) {
    const $input = $('#campaign_url');
    const val = $input.val();
    $input.val(val + token).focus();
}

function calcMargin() {
    const rev = parseFloat($('#input_revenue').val()) || 0;
    const pay = parseFloat($('#input_payout').val()) || 0;

    if (rev > 0 && pay > 0) {
        const margin = rev - pay;
        const pct = (margin / rev) * 100;
        $('#netMarginDisplay').html('$' + margin.toFixed(2) + ' (' + pct.toFixed(1) + '%)');
    }
}

$(document).ready(function() {
    $('.select2').select2({ placeholder: "Select Category", allowClear: true });
    
    $('#campaign_category').on('change', function() {
        if ($(this).val() === '_custom') {
            $('#custom_category_input').show().focus();
        } else {
            $('#custom_category_input').hide();
        }
    });
});
</script>
</body>
</html>