<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('affiliate');

$affiliateId   = auth_user_id();
$affiliateName = $_SESSION['user_name'] ?? 'Affiliate';
$offerId       = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($offerId <= 0) {
    header("Location: offers.php");
    exit;
}

/* -------------------------------------------------
   FETCH OFFER DETAILS & CHECK ACCESSIBILITY (PUBLIC OR APPROVED)
-------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT 
        o.offer_id,
        o.offer_name,
        o.offer_description,
        o.category,
        o.payout,
        o.currency,
        o.offer_url,
        o.status AS offer_status,
        o.visibility,
        o.daily_cap,
        aoa.status AS approval_status
    FROM offers o
    LEFT JOIN affiliate_offer_approval aoa 
      ON aoa.offer_id = o.offer_id 
     AND aoa.affiliate_id = ?
    WHERE o.offer_id = ?
    LIMIT 1
");
$stmt->execute([$affiliateId, $offerId]);
$offer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$offer) {
    die('<div style="font-family:sans-serif; text-align:center; padding:50px;"><h2>Campaign offer not found.</h2><a href="offers.php">Back to Offers</a></div>');
}

$visibility = strtolower($offer['visibility'] ?? 'public');
$isApproved = ($visibility === 'public' || ($offer['approval_status'] ?? '') === 'approved');

/* -------------------------------------------------
   FETCH PUBLISHER STATS FOR THIS OFFER
-------------------------------------------------- */
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT c.click_id) AS total_clicks,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS total_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS total_earned
    FROM clicks c
    LEFT JOIN conversions cv ON cv.click_id = c.click_id AND cv.affiliate_id = ?
    WHERE c.offer_id = ? AND c.affiliate_id = ?
");
$statsStmt->execute([$affiliateId, $offerId, $affiliateId]);
$pStats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$totalClicks = (int)($pStats['total_clicks'] ?? 0);
$totalConversions = (int)($pStats['total_conversions'] ?? 0);
$totalEarned = (float)($pStats['total_earned'] ?? 0);
$cr = $totalClicks > 0 ? number_format(($totalConversions / $totalClicks) * 100, 2) : '0.00';
$epc = $totalClicks > 0 ? number_format($totalEarned / $totalClicks, 2) : '0.00';

/* -------------------------------------------------
   GENERATE PRIMARY TRACKING URL
-------------------------------------------------- */
$trackingUrl = "https://iconmedianetwork.in/click.php?offer_id={$offerId}&aff_id={$affiliateId}";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>#<?php echo $offer['offer_id']; ?> - <?php echo htmlspecialchars($offer['offer_name']); ?> | Affiliate Portal</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">

    <style>
        .card-custom {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 18px rgba(0,0,0,0.06);
            margin-bottom: 25px;
            background: #ffffff;
        }

        .hero-banner {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-radius: 12px;
            padding: 25px 30px;
            color: #ffffff;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .stat-card-custom {
            border-radius: 12px;
            background: #ffffff;
            padding: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            text-align: center;
            margin-bottom: 15px;
        }

        .stat-card-custom .stat-number {
            font-size: 24px;
            font-weight: 800;
            color: #1e293b;
        }

        .stat-card-custom .stat-label {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
        }

        .url-box {
            background: #0f172a;
            color: #38bdf8;
            padding: 15px 20px;
            border-radius: 10px;
            font-family: monospace;
            font-size: 15px;
            word-break: break-all;
            border: 1px solid #334155;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item"><a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link">Dashboard</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="offers.php" class="nav-link">All Campaigns</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="#" class="nav-link active">Offer Details</a></li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <a class="nav-link text-danger font-weight-bold" href="../logout.php"><i class="fas fa-sign-out-alt mr-1"></i> Logout</a>
            </li>
        </ul>
    </nav>

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
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6"><h1 class="m-0 font-weight-bold">Campaign Details</h1></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="offers.php">Offers</a></li>
                            <li class="breadcrumb-item active">#<?php echo $offer['offer_id']; ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">

                <!-- Hero Banner -->
                <div class="hero-banner">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <span class="badge badge-info p-2 mb-2"><?php echo htmlspecialchars($offer['category'] ?: 'General'); ?></span>
                            <h2 class="font-weight-bold mb-1">#<?php echo $offer['offer_id']; ?> - <?php echo htmlspecialchars($offer['offer_name']); ?></h2>
                            <p class="mb-0 text-white-50"><?php echo htmlspecialchars($offer['offer_description'] ?: 'High converting campaign offer with instant postback tracking.'); ?></p>
                        </div>
                        <div class="mt-3 mt-md-0 text-right">
                            <span class="d-block text-white-50 small">Payout Rate</span>
                            <h2 class="font-weight-bold text-success mb-0">$<?php echo number_format($offer['payout'], 2); ?> <?php echo $offer['currency']; ?></h2>
                        </div>
                    </div>
                </div>

                <!-- Performance Stats Bar -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($totalClicks); ?></div>
                            <div class="stat-label">Total Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($totalConversions); ?></div>
                            <div class="stat-label">Conversions</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo $cr; ?>%</div>
                            <div class="stat-label">Conversion Rate</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success">$<?php echo number_format($totalEarned, 2); ?></div>
                            <div class="stat-label">Earned Payout</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Tracking Link Generator -->
                    <div class="col-lg-7">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-link mr-2"></i>Your S2S Tracking Link</h4>
                            
                            <?php if ($isApproved): ?>
                                <label class="font-weight-bold small text-muted">Primary Target Tracking URL:</label>
                                <div class="url-box mb-3" id="trackingUrlText"><?php echo htmlspecialchars($trackingUrl); ?></div>

                                <div class="d-flex mb-3">
                                    <button class="btn btn-success font-weight-bold mr-2 flex-grow-1 py-2" onclick="copyToClipboard()">
                                        <i class="fas fa-clipboard mr-1"></i> Copy Tracking Link
                                    </button>
                                    <a href="<?php echo htmlspecialchars($trackingUrl); ?>" target="_blank" class="btn btn-outline-primary font-weight-bold py-2">
                                        <i class="fas fa-external-link-alt mr-1"></i> Test Link
                                    </a>
                                </div>

                                <hr>
                                <h5 class="font-weight-bold text-dark mb-2"><i class="fas fa-sliders-h text-info mr-1"></i> Add Custom SubIDs</h5>
                                <div class="form-group mb-2">
                                    <input type="text" id="sub1Input" class="form-control" placeholder="Enter sub1 (e.g. facebook)" oninput="updateTrackingUrl()">
                                </div>
                                <div class="form-group mb-2">
                                    <input type="text" id="sub2Input" class="form-control" placeholder="Enter sub2 (e.g. campaign_01)" oninput="updateTrackingUrl()">
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning p-3">
                                    <h5><i class="fas fa-lock mr-2"></i> Approval Required</h5>
                                    <p class="mb-0">This campaign offer requires admin approval before you can access tracking links.</p>
                                    <a href="request_offer.php?id=<?php echo $offerId; ?>" class="btn btn-warning font-weight-bold mt-2"><i class="fas fa-paper-plane mr-1"></i> Request Access</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Offer Specifications -->
                    <div class="col-lg-5">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-dark mb-3"><i class="fas fa-info-circle mr-2"></i>Campaign Specifications</h4>
                            
                            <table class="table table-sm borderless">
                                <tr>
                                    <th class="text-muted font-weight-normal">Offer ID:</th>
                                    <td class="font-weight-bold">#<?php echo $offer['offer_id']; ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted font-weight-normal">Category:</th>
                                    <td><span class="badge badge-info p-2"><?php echo htmlspecialchars($offer['category'] ?: 'General'); ?></span></td>
                                </tr>
                                <tr>
                                    <th class="text-muted font-weight-normal">Daily Cap Limit:</th>
                                    <td class="font-weight-bold text-dark"><?php echo $offer['daily_cap'] ? number_format($offer['daily_cap']) . ' leads/day' : 'Unlimited'; ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted font-weight-normal">Access Visibility:</th>
                                    <td><span class="badge badge-success p-2"><?php echo strtoupper($offer['visibility']); ?></span></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline"><strong>Affiliate Portal v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script>
var baseUrl = "<?php echo $trackingUrl; ?>";

function updateTrackingUrl() {
    var s1 = document.getElementById('sub1Input').value.trim();
    var s2 = document.getElementById('sub2Input').value.trim();
    var finalUrl = baseUrl;
    if (s1 !== '') finalUrl += '&sub1=' + encodeURIComponent(s1);
    if (s2 !== '') finalUrl += '&sub2=' + encodeURIComponent(s2);
    document.getElementById('trackingUrlText').innerText = finalUrl;
}

function copyToClipboard() {
    var text = document.getElementById('trackingUrlText').innerText;
    navigator.clipboard.writeText(text).then(function() {
        alert('Tracking link copied to clipboard!');
    });
}
</script>
</body>
</html>