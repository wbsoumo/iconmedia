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
    header('Location: offers.php?error=Invalid campaign ID');
    exit;
}

/* ===============================
   FETCH OFFER DATA
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
        SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END) AS paid_payout,
        
        -- Performance metrics
        CASE 
            WHEN COUNT(DISTINCT c.click_id) > 0 
            THEN (COUNT(DISTINCT cv.conversion_id) / COUNT(DISTINCT c.click_id)) * 100
            ELSE 0
        END AS conversion_rate,
        
        -- Profit
        (SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END) - 
         SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END)) AS profit
         
    FROM offers o
    INNER JOIN users u ON u.user_id = o.advertiser_id
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE o.offer_id = :offer_id
    GROUP BY o.offer_id
");

$stmt->execute(['offer_id' => $offerId]);
$offer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$offer) {
    header('Location: offers.php?error=Campaign not found');
    exit;
}

// Parse comma-separated fields into arrays
$allowedTraffic = !empty($offer['allowed_traffic']) ? explode(',', $offer['allowed_traffic']) : [];
$browserTargeting = !empty($offer['browser_targeting']) ? explode(',', $offer['browser_targeting']) : [];

/* ===============================
   GET CATEGORIES FOR DROPDOWN
================================ */
$categoriesStmt = $pdo->query("SELECT DISTINCT category FROM offers WHERE category IS NOT NULL AND category != ''");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

/* ===============================
   FETCH ADVERTISERS FOR DROPDOWN
================================ */
$advertisers = $pdo->query("
    SELECT user_id, name, email, company 
    FROM users 
    WHERE role_id = 4 AND status = 'active'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   HANDLE FORM SUBMIT
================================ */
// Handle form submit - UPDATE SECTION (around line 150-220)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $advertiserId        = (int)($_POST['advertiser_id'] ?? $offer['advertiser_id']);
    $title               = trim($_POST['title'] ?? '');
    $description         = trim($_POST['description'] ?? '');
    $objective           = $_POST['objective'] ?? 'conversions';
    $kpi                 = trim($_POST['kpi'] ?? '');
    $allowedTraffic      = isset($_POST['allowed_traffic']) ? implode(',', $_POST['allowed_traffic']) : '';
    $previewUrl          = trim($_POST['preview_url'] ?? '');
    $campaignUrl         = trim($_POST['campaign_url'] ?? '');
    $conversionTracking  = $_POST['conversion_tracking'] ?? 'postback';
    $termsRequired       = isset($_POST['terms_required']) ? 1 : 0;
    $category            = trim($_POST['category'] ?? '');
    $status              = $_POST['status'] ?? $offer['status'];
    $note                = trim($_POST['note'] ?? '');
    $revenue             = (float)($_POST['revenue'] ?? 0);
    $payout              = (float)($_POST['payout'] ?? 0);
    $payoutType          = $_POST['payout_type'] ?? 'cpa';
    $currency            = $_POST['currency'] ?? 'USD';
    $geo                 = trim($_POST['geo'] ?? 'ALL');
    $country             = trim($_POST['country'] ?? '');
    $deviceTargeting     = $_POST['device_targeting'] ?? 'all';
    $browserTargeting    = isset($_POST['browser_targeting']) ? implode(',', $_POST['browser_targeting']) : '';
    $dailyCap            = (int)($_POST['daily_cap'] ?? 0);
    $totalCap            = (int)($_POST['total_cap'] ?? 0);
    $startDate           = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $endDate             = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $visibility          = $_POST['visibility'] ?? 'public';
    $allowedCountries    = trim($_POST['allowed_countries'] ?? '');
    $blockedCountries    = trim($_POST['blocked_countries'] ?? '');
    $regenerateToken     = isset($_POST['regenerate_token']) ? 1 : 0;

    // Regenerate postback token if requested
    $postbackToken = $offer['postback_token'];
    if ($regenerateToken) {
        $postbackToken = bin2hex(random_bytes(16));
    }

    /* BASIC VALIDATION */
    if ($advertiserId === 0) {
        $error = 'Please select an advertiser.';
    } elseif ($title === '' || $campaignUrl === '') {
        $error = 'Campaign Name and Campaign URL are required.';
    } elseif (!filter_var($campaignUrl, FILTER_VALIDATE_URL)) {
        $error = 'Invalid Campaign URL format.';
    } elseif ($previewUrl && !filter_var($previewUrl, FILTER_VALIDATE_URL)) {
        $error = 'Invalid Preview URL format.';
    } elseif ($payout > $revenue) {
        $error = 'Payout cannot be greater than revenue.';
    } else {

        // CORRECTED: Make sure all placeholders match exactly
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

        // CORRECTED: All parameters must match the placeholders exactly
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

        // Debug: Uncomment this to see what's being passed
        // echo "<pre>"; print_r($params); echo "</pre>"; exit;

        $result = $stmt->execute($params);

        if ($result) {
            $success = "Campaign updated successfully!";
            if ($regenerateToken) {
                $success .= " New postback token generated.";
            }
            
            // Refresh offer data
            $refreshStmt = $pdo->prepare("
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
                    SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END) AS paid_payout,
                    
                    -- Performance metrics
                    CASE 
                        WHEN COUNT(DISTINCT c.click_id) > 0 
                        THEN (COUNT(DISTINCT cv.conversion_id) / COUNT(DISTINCT c.click_id)) * 100
                        ELSE 0
                    END AS conversion_rate,
                    
                    -- Profit
                    (SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END) - 
                     SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END)) AS profit
                     
                FROM offers o
                INNER JOIN users u ON u.user_id = o.advertiser_id
                LEFT JOIN clicks c ON c.offer_id = o.offer_id
                LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
                WHERE o.offer_id = :offer_id
                GROUP BY o.offer_id
            ");
            
            $refreshStmt->execute(['offer_id' => $offerId]);
            $offer = $refreshStmt->fetch(PDO::FETCH_ASSOC);
            
            // Re-parse arrays
            $allowedTraffic = !empty($offer['allowed_traffic']) ? explode(',', $offer['allowed_traffic']) : [];
            $browserTargeting = !empty($offer['browser_targeting']) ? explode(',', $offer['browser_targeting']) : [];
        } else {
            $error = "Failed to update campaign. Please try again.";
        }
    }
}

/* ===============================
   FETCH RECENT CONVERSIONS FOR THIS OFFER
================================ */
$recentConversions = $pdo->prepare("
    SELECT 
        cv.conversion_id,
        cv.transaction_id,
        cv.revenue,
        cv.payout,
        cv.status,
        cv.created_at,
        u.name AS affiliate_name,
        u.email AS affiliate_email
    FROM conversions cv
    LEFT JOIN users u ON u.user_id = cv.affiliate_id
    WHERE cv.offer_id = ?
    ORDER BY cv.created_at DESC
    LIMIT 10
");
$recentConversions->execute([$offerId]);
$conversions = $recentConversions->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Campaign | Admin Panel | GVS Icon Media</title>
    
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
            --purple-gradient: linear-gradient(135deg, #9f7aea 0%, #667eea 100%);
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
            justify-content: space-between;
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
        
        .btn-warning {
            background: #ffc107;
            border: none;
            color: #212529;
            font-weight: 600;
        }
        
        .btn-warning:hover {
            background: #e0a800;
            color: #212529;
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
            justify-content: space-between;
            align-items: center;
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
        
        .admin-avatar {
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
        
        .status-approved {
            background: rgba(0, 123, 255, 0.15);
            color: #007bff;
        }
        
        .status-active {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        
        .status-paused {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
        }
        
        .status-rejected {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }
        
        .performance-metrics {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            border: 1px solid #e3e6f0;
        }
        
        .metric-item {
            text-align: center;
            padding: 10px;
            background: #f8f9fc;
            border-radius: 8px;
        }
        
        .metric-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .metric-value {
            font-size: 24px;
            font-weight: 700;
            color: #4e73df;
        }
        
        .metric-sub {
            font-size: 14px;
            color: #6c757d;
            margin-top: 5px;
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
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .postback-token:hover {
            background: #f0f3ff;
        }
        
        .advertiser-card {
            background: white;
            border: 1px solid #e3e6f0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .profit-positive {
            color: #28a745;
            font-weight: 600;
        }
        
        .profit-negative {
            color: #dc3545;
            font-weight: 600;
        }
        
        .conversion-table {
            font-size: 14px;
        }
        
        .conversion-table td {
            padding: 10px;
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
                <a href="offers.php" class="nav-link">Campaigns</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="offer_edit.php?id=<?php echo $offerId; ?>" class="nav-link active">Edit Campaign</a>
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
                        <i class="fas fa-clock mr-2 text-warning"></i> Pending approvals
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
                    <div class="admin-avatar mr-2">
                        <?php echo strtoupper(substr($adminName, 0, 1)); ?>
                    </div>
                    <span><?php echo htmlspecialchars($adminName); ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user mr-2"></i> Admin Profile
                    </a>
                    <a href="settings.php" class="dropdown-item">
                        <i class="fas fa-cog mr-2"></i> System Settings
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
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.5rem;">
                <i class="fas fa-crown mr-2"></i>
                <strong>Admin</strong>
            </span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>

                    <li class="nav-header">CAMPAIGNS</li>
                    <li class="nav-item">
                        <a href="offers.php" class="nav-link">
                            <i class="nav-icon fas fa-bullhorn"></i>
                            <p>Manage Campaigns</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="create_offer.php" class="nav-link">
                            <i class="nav-icon fas fa-plus"></i>
                            <p>Create Campaign</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="offer_edit.php?id=<?php echo $offerId; ?>" class="nav-link active">
                            <i class="nav-icon fas fa-edit"></i>
                            <p>Edit Campaign</p>
                        </a>
                    </li>

                    <li class="nav-header">REPORTS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Campaign Report</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link">
                            <i class="nav-icon fas fa-users"></i>
                            <p>Affiliate Report</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_advertisers.php" class="nav-link">
                            <i class="nav-icon fas fa-building"></i>
                            <p>Advertiser Report</p>
                        </a>
                    </li>

                    <li class="nav-header">PUBLISHERS</li>
                    <li class="nav-item">
                        <a href="publishers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-friends"></i>
                            <p>Manage Publishers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="publisher_postbacks.php" class="nav-link">
                            <i class="nav-icon fas fa-link"></i>
                            <p>Publisher Postbacks</p>
                        </a>
                    </li>

                    <li class="nav-header">ADVERTISERS</li>
                    <li class="nav-item">
                        <a href="advertisers.php" class="nav-link">
                            <i class="nav-icon fas fa-briefcase"></i>
                            <p>Manage Advertisers</p>
                        </a>
                    </li>

                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="account_managers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-tie"></i>
                            <p>Account Managers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user-circle"></i>
                            <p>My Profile</p>
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
                        <h1 class="m-0">Edit Campaign</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="offers.php">Campaigns</a></li>
                            <li class="breadcrumb-item active">Edit Campaign #<?php echo $offerId; ?></li>
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
                    <p><?php echo htmlspecialchars($success); ?></p>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                    <h5><i class="icon fas fa-ban"></i> Error!</h5>
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
                <?php endif; ?>

                <!-- Campaign Status Banner -->
                <div class="info-box mb-4">
                    <div class="row align-items-center">
                        <div class="col-md-1">
                            <div class="info-box-icon">
                                <i class="fas fa-info-circle"></i>
                            </div>
                        </div>
                        <div class="col-md-11">
                            <div class="d-flex align-items-center">
                                <span class="status-badge status-<?php echo $offer['status']; ?> mr-3" style="font-size: 14px; padding: 8px 16px;">
                                    <i class="fas fa-<?php 
                                        echo $offer['status'] === 'active' ? 'check-circle' : 
                                            ($offer['status'] === 'pending' ? 'clock' : 
                                            ($offer['status'] === 'approved' ? 'thumbs-up' : 
                                            ($offer['status'] === 'paused' ? 'pause-circle' : 
                                            ($offer['status'] === 'rejected' ? 'times-circle' : 'circle')))); 
                                    ?> mr-1"></i>
                                    Status: <?php echo ucfirst($offer['status']); ?>
                                </span>
                                <p class="mb-0 text-muted">
                                    <?php if ($offer['status'] === 'pending'): ?>
                                        This campaign is pending review. Review details and approve or reject.
                                    <?php elseif ($offer['status'] === 'approved'): ?>
                                        Campaign approved. Activate to make it visible to affiliates.
                                    <?php elseif ($offer['status'] === 'active'): ?>
                                        Campaign is live and visible to affiliates based on visibility settings.
                                    <?php elseif ($offer['status'] === 'paused'): ?>
                                        Campaign paused. Not visible to affiliates.
                                    <?php elseif ($offer['status'] === 'rejected'): ?>
                                        Campaign rejected. Add notes in internal notes explaining why.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Performance Metrics -->
                <div class="performance-metrics">
                    <div class="metric-item">
                        <div class="metric-label">Total Clicks</div>
                        <div class="metric-value"><?php echo number_format($offer['total_clicks'] ?? 0); ?></div>
                    </div>
                    <div class="metric-item">
                        <div class="metric-label">Conversions</div>
                        <div class="metric-value"><?php echo number_format($offer['total_conversions'] ?? 0); ?></div>
                        <div class="metric-sub">
                            Approved: <?php echo number_format($offer['approved_conversions'] ?? 0); ?>
                        </div>
                    </div>
                    <div class="metric-item">
                        <div class="metric-label">Conversion Rate</div>
                        <div class="metric-value"><?php echo number_format($offer['conversion_rate'] ?? 0, 2); ?>%</div>
                    </div>
                    <div class="metric-item">
                        <div class="metric-label">Revenue</div>
                        <div class="metric-value">$<?php echo number_format($offer['earned_revenue'] ?? 0, 2); ?></div>
                    </div>
                    <div class="metric-item">
                        <div class="metric-label">Payout</div>
                        <div class="metric-value">$<?php echo number_format($offer['paid_payout'] ?? 0, 2); ?></div>
                    </div>
                    <div class="metric-item">
                        <div class="metric-label">Profit</div>
                        <div class="metric-value <?php echo ($offer['profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                            $<?php echo number_format($offer['profit'] ?? 0, 2); ?>
                        </div>
                    </div>
                </div>

                <!-- Edit Form -->
                <div class="card-dashboard">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-edit mr-2"></i> Edit Campaign Details
                        </h3>
                        <div class="card-tools">
                            <span class="badge badge-light">Campaign ID: #<?php echo $offer['offer_id']; ?></span>
                            <span class="badge badge-light ml-2">Created: <?php echo date('M d, Y', strtotime($offer['created_at'])); ?></span>
                        </div>
                    </div>
                    
                    <form method="post" id="editOfferForm">
                        <div class="card-body">
                            <!-- Advertiser Selection -->
                            <div class="form-section">
                                <div class="form-section-title">
                                    <div>
                                        <i class="fas fa-building"></i> Advertiser Information
                                    </div>
                                    <a href="advertiser_edit.php?id=<?php echo $offer['advertiser_id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-external-link-alt mr-1"></i> View Advertiser
                                    </a>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label class="required">Select Advertiser</label>
                                        <select name="advertiser_id" class="form-control select2" required>
                                            <?php foreach ($advertisers as $adv): ?>
                                            <option value="<?php echo $adv['user_id']; ?>" <?php echo $offer['advertiser_id'] == $adv['user_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($adv['name']); ?> 
                                                <?php if ($adv['company']): ?>(<?php echo htmlspecialchars($adv['company']); ?>)<?php endif; ?> 
                                                - <?php echo htmlspecialchars($adv['email']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="advertiser-card">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong><i class="fas fa-user mr-2"></i> <?php echo htmlspecialchars($offer['advertiser_name']); ?></strong>
                                            <?php if ($offer['advertiser_company']): ?>
                                            <div class="text-muted small"><?php echo htmlspecialchars($offer['advertiser_company']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-6">
                                            <div><i class="fas fa-envelope mr-2"></i> <?php echo htmlspecialchars($offer['advertiser_email']); ?></div>
                                            <?php if ($offer['advertiser_mobile']): ?>
                                            <div><i class="fas fa-phone mr-2"></i> <?php echo htmlspecialchars($offer['advertiser_mobile']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

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
                                               value="<?php echo htmlspecialchars($offer['offer_name']); ?>">
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label class="required">Objective</label>
                                        <select name="objective" class="form-control" required>
                                            <option value="conversions" <?php echo $offer['objective'] == 'conversions' ? 'selected' : ''; ?>>Conversions</option>
                                            <option value="sale" <?php echo $offer['objective'] == 'sale' ? 'selected' : ''; ?>>Sales</option>
                                            <option value="app_install" <?php echo $offer['objective'] == 'app_install' ? 'selected' : ''; ?>>App Installs</option>
                                            <option value="leads" <?php echo $offer['objective'] == 'leads' ? 'selected' : ''; ?>>Leads</option>
                                            <option value="impressions" <?php echo $offer['objective'] == 'impressions' ? 'selected' : ''; ?>>Impressions</option>
                                            <option value="clicks" <?php echo $offer['objective'] == 'clicks' ? 'selected' : ''; ?>>Clicks</option>
                                            <option value="registrations" <?php echo $offer['objective'] == 'registrations' ? 'selected' : ''; ?>>Registrations</option>
                                            <option value="downloads" <?php echo $offer['objective'] == 'downloads' ? 'selected' : ''; ?>>Downloads</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-group-enhanced">
                                    <label>Description</label>
                                    <textarea name="description" class="form-control" rows="3" 
                                              placeholder="Describe your offer in detail..."><?php echo htmlspecialchars($offer['offer_description'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label>Category</label>
                                        <select name="category" class="form-control select2">
                                            <option value="">Select Category</option>
                                            <?php foreach($categories as $cat): ?>
                                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($offer['category'] ?? '') == $cat ? 'selected' : ''; ?>>
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
                                               value="<?php echo htmlspecialchars($offer['kpi'] ?? ''); ?>">
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
                                               placeholder="https://advertiser.com/landing?click_id={click_id}"
                                               value="<?php echo htmlspecialchars($offer['campaign_url']); ?>">
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
                                               placeholder="https://advertiser.com/preview"
                                               value="<?php echo htmlspecialchars($offer['preview_url'] ?? ''); ?>">
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label>Conversion Tracking</label>
                                        <select name="conversion_tracking" class="form-control">
                                            <option value="postback" <?php echo ($offer['conversion_tracking'] ?? '') == 'postback' ? 'selected' : ''; ?>>Postback URL</option>
                                            <option value="pixel" <?php echo ($offer['conversion_tracking'] ?? '') == 'pixel' ? 'selected' : ''; ?>>Tracking Pixel</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label>Visibility</label>
                                        <select name="visibility" class="form-control">
                                            <option value="public" <?php echo ($offer['visibility'] ?? '') == 'public' ? 'selected' : ''; ?>>Public (All affiliates)</option>
                                            <option value="private" <?php echo ($offer['visibility'] ?? '') == 'private' ? 'selected' : ''; ?>>Private (Selected affiliates)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Postback Token -->
                            <div class="postback-info">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><i class="fas fa-key mr-2"></i>Postback Token:</strong>
                                        <p class="mb-0 small text-muted">Used in postback URL for conversion tracking</p>
                                    </div>
                                    <div>
                                        <label class="mr-2">
                                            <input type="checkbox" name="regenerate_token" value="1"> Regenerate token
                                        </label>
                                    </div>
                                </div>
                                <div class="postback-token mt-2" id="postbackToken" onclick="copyToken()">
                                    <span><?php echo htmlspecialchars($offer['postback_token']); ?></span>
                                    <i class="fas fa-copy ml-2" style="cursor: pointer;"></i>
                                </div>
                                <div class="mt-2 small">
                                    <strong>Full Postback URL:</strong><br>
                                    <code>https://iconmedianetwork.in/postback?token=<?php echo $offer['postback_token']; ?>&click_id={click_id}&payout={payout}</code>
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
                                        foreach ($trafficChannels as $ch): 
                                        ?>
                                        <div class="checkbox-item">
                                            <input type="checkbox" name="allowed_traffic[]" value="<?php echo $ch; ?>" 
                                                   id="traffic_<?php echo strtolower($ch); ?>"
                                                   <?php echo in_array($ch, $allowedTraffic) ? 'checked' : ''; ?>>
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
                                            <option value="all" <?php echo ($offer['device_type'] ?? '') == 'all' ? 'selected' : ''; ?>>All Devices</option>
                                            <option value="desktop" <?php echo ($offer['device_type'] ?? '') == 'desktop' ? 'selected' : ''; ?>>Desktop Only</option>
                                            <option value="mobile" <?php echo ($offer['device_type'] ?? '') == 'mobile' ? 'selected' : ''; ?>>Mobile Only</option>
                                            <option value="tablet" <?php echo ($offer['device_type'] ?? '') == 'tablet' ? 'selected' : ''; ?>>Tablet Only</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label>Browser Targeting</label>
                                        <div class="checkbox-group">
                                            <?php 
                                            $browsers = ['Chrome', 'Firefox', 'Safari', 'Edge', 'Opera'];
                                            foreach ($browsers as $browser): 
                                            ?>
                                            <div class="checkbox-item">
                                                <input type="checkbox" name="browser_targeting[]" value="<?php echo $browser; ?>" 
                                                       id="browser_<?php echo strtolower($browser); ?>"
                                                       <?php echo in_array($browser, $browserTargeting) ? 'checked' : ''; ?>>
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
                                               value="<?php echo htmlspecialchars($offer['allowed_countries'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label>Blocked Countries</label>
                                        <input type="text" name="blocked_countries" class="form-control" 
                                               placeholder="RU,CN,PK"
                                               value="<?php echo htmlspecialchars($offer['blocked_countries'] ?? ''); ?>">
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label>Primary Country</label>
                                        <input type="text" name="country" class="form-control" 
                                               placeholder="e.g., US"
                                               value="<?php echo htmlspecialchars($offer['country'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label>Geo (Legacy)</label>
                                        <input type="text" name="geo" class="form-control" 
                                               placeholder="ALL"
                                               value="<?php echo htmlspecialchars($offer['geo'] ?? 'ALL'); ?>">
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label>Daily Cap</label>
                                        <input type="number" name="daily_cap" class="form-control" 
                                               placeholder="0 for unlimited" min="0"
                                               value="<?php echo htmlspecialchars($offer['daily_cap'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label>Total Cap</label>
                                        <input type="number" name="total_cap" class="form-control" 
                                               placeholder="0 for unlimited" min="0"
                                               value="<?php echo htmlspecialchars($offer['total_cap'] ?? ''); ?>">
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label>Start Date</label>
                                        <input type="text" name="start_date" class="form-control flatpickr" 
                                               placeholder="Select start date"
                                               value="<?php echo htmlspecialchars($offer['start_date'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label>End Date</label>
                                        <input type="text" name="end_date" class="form-control flatpickr" 
                                               placeholder="Select end date"
                                               value="<?php echo htmlspecialchars($offer['end_date'] ?? ''); ?>">
                                    </div>
                                </div>
                                
                                <div class="form-group-enhanced">
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="terms_required" id="terms_required" value="1"
                                               <?php echo $offer['terms_required'] ? 'checked' : ''; ?>>
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
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced currency-input">
                                        <label class="required">Revenue (You Earn)</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text">$</span>
                                            </div>
                                            <input type="number" step="0.01" name="revenue" class="form-control" required 
                                                   placeholder="50.00" oninput="calculateMargin()"
                                                   value="<?php echo htmlspecialchars($offer['revenue']); ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group-enhanced currency-input">
                                        <label class="required">Payout (Affiliate Earns)</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text">$</span>
                                            </div>
                                            <input type="number" step="0.01" name="payout" class="form-control" required 
                                                   placeholder="35.00" oninput="calculateMargin()"
                                                   value="<?php echo htmlspecialchars($offer['payout']); ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label>Payout Type</label>
                                        <select name="payout_type" class="form-control">
                                            <option value="cpa" <?php echo ($offer['payout_type'] ?? '') == 'cpa' ? 'selected' : ''; ?>>CPA (Cost Per Action)</option>
                                            <option value="cpl" <?php echo ($offer['payout_type'] ?? '') == 'cpl' ? 'selected' : ''; ?>>CPL (Cost Per Lead)</option>
                                            <option value="cpi" <?php echo ($offer['payout_type'] ?? '') == 'cpi' ? 'selected' : ''; ?>>CPI (Cost Per Install)</option>
                                            <option value="revshare" <?php echo ($offer['payout_type'] ?? '') == 'revshare' ? 'selected' : ''; ?>>Revenue Share</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label>Currency</label>
                                        <select name="currency" class="form-control">
                                            <option value="USD" <?php echo ($offer['currency'] ?? '') == 'USD' ? 'selected' : ''; ?>>USD ($)</option>
                                            <option value="INR" <?php echo ($offer['currency'] ?? '') == 'INR' ? 'selected' : ''; ?>>INR (₹)</option>
                                            <option value="EUR" <?php echo ($offer['currency'] ?? '') == 'EUR' ? 'selected' : ''; ?>>EUR (€)</option>
                                            <option value="GBP" <?php echo ($offer['currency'] ?? '') == 'GBP' ? 'selected' : ''; ?>>GBP (£)</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group-enhanced">
                                        <label>Your Margin</label>
                                        <div class="form-control" id="marginDisplay" readonly style="background: #f8f9fc;">
                                            <span id="marginValue">$<?php echo number_format($offer['revenue'] - $offer['payout'], 2); ?></span>
                                            <span id="marginPercent">
                                                (<?php echo $offer['revenue'] > 0 ? number_format((($offer['revenue'] - $offer['payout']) / $offer['revenue']) * 100, 1) : 0; ?>%)
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group-enhanced">
                                        <label>Status</label>
                                        <select name="status" class="form-control">
                                            <option value="pending" <?php echo $offer['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="approved" <?php echo $offer['status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                            <option value="active" <?php echo $offer['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="paused" <?php echo $offer['status'] == 'paused' ? 'selected' : ''; ?>>Paused</option>
                                            <option value="rejected" <?php echo $offer['status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-group-enhanced">
                                    <label>Internal Notes (Admin Only)</label>
                                    <textarea name="note" class="form-control" rows="2" 
                                              placeholder="Internal notes about this campaign..."><?php echo htmlspecialchars($offer['internal_note'] ?? ''); ?></textarea>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="form-actions">
                                <div>
                                    <a href="offers.php" class="btn btn-outline-primary">
                                        <i class="fas fa-arrow-left mr-2"></i> Back to Campaigns
                                    </a>
                                </div>
                                <div>
                                    <button type="reset" class="btn btn-outline-secondary mr-2">
                                        <i class="fas fa-undo mr-2"></i> Reset
                                    </button>
                                    <button type="submit" class="btn-gradient" id="submitBtn">
                                        <i class="fas fa-save mr-2"></i> Save Changes
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Recent Conversions -->
                <?php if (!empty($conversions)): ?>
                <div class="card-dashboard">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-exchange-alt mr-2"></i> Recent Conversions
                        </h3>
                        <div class="card-tools">
                            <a href="reports_conversions.php?offer_id=<?php echo $offerId; ?>" class="btn btn-sm btn-outline-primary">
                                View All <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-dashboard conversion-table">
                                <thead>
                                    <tr>
                                        <th>Transaction ID</th>
                                        <th>Affiliate</th>
                                        <th>Revenue</th>
                                        <th>Payout</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($conversions as $conv): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($conv['transaction_id'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($conv['affiliate_name'] ?? 'Unknown'); ?></td>
                                        <td class="text-success">$<?php echo number_format($conv['revenue'], 2); ?></td>
                                        <td class="text-warning">$<?php echo number_format($conv['payout'], 2); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $conv['status']; ?>" style="padding: 3px 8px;">
                                                <?php echo ucfirst($conv['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, H:i', strtotime($conv['created_at'])); ?></td>
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
            <strong>Admin Panel v3.0</strong>
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
    $('#editOfferForm').submit(function(e) {
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
        submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i> Saving Changes...');
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

function copyToken() {
    const token = document.getElementById('postbackToken').innerText.trim();
    navigator.clipboard.writeText(token).then(() => {
        Swal.fire({
            title: 'Copied!',
            text: 'Postback token copied to clipboard',
            icon: 'success',
            timer: 2000,
            showConfirmButton: false
        });
    });
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