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

// Get offer ID from URL
$offerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$offerId) {
    header('Location: offers.php?error=Invalid campaign ID');
    exit;
}

/* ===============================
   FETCH OFFER DATA & PERFORMANCE STATS
================================ */
$stmt = $pdo->prepare("
    SELECT 
        o.*,
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END) AS total_revenue,
        SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END) AS total_payout
    FROM offers o
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE o.offer_id = :offer_id AND o.advertiser_id = :advertiser_id
    GROUP BY o.offer_id
");

$stmt->execute([
    'offer_id' => $offerId,
    'advertiser_id' => $advertiserId
]);
$offer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$offer) {
    header('Location: offers.php?error=Campaign not found or access denied');
    exit;
}

$clicks = (int)$offer['total_clicks'];
$conversions = (int)$offer['approved_conversions'];
$cr = $clicks > 0 ? round(($conversions / $clicks) * 100, 2) : 0.00;
$margin = $offer['total_revenue'] - $offer['total_payout'];

/* ===============================
   FETCH RECENT CONVERSIONS FOR THIS OFFER
================================ */
$convStmt = $pdo->prepare("
    SELECT 
        cv.conversion_id,
        cv.transaction_id,
        cv.revenue,
        cv.payout,
        cv.status,
        cv.created_at,
        u.name AS affiliate_name
    FROM conversions cv
    LEFT JOIN users u ON u.user_id = cv.affiliate_id
    WHERE cv.offer_id = :oid
    ORDER BY cv.created_at DESC
    LIMIT 15
");
$convStmt->execute(['oid' => $offerId]);
$recentConversions = $convStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Campaign Details #<?php echo $offerId; ?> | Advertiser Panel</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    
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

        .stat-card-custom {
            border-radius: 12px;
            background: #ffffff;
            padding: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            text-align: center;
        }

        .stat-card-custom .stat-number {
            font-size: 26px;
            font-weight: 800;
            color: #1e293b;
        }

        .stat-card-custom .stat-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
        }

        .detail-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .detail-label {
            width: 200px;
            font-weight: 700;
            color: #475569;
        }

        .detail-value {
            flex: 1;
            color: #0f172a;
        }

        @media (max-width: 767.98px) {
            .stat-boxes-row > [class*="col-"] {
                flex: 0 0 50% !important;
                max-width: 50% !important;
                padding-left: 6px !important;
                padding-right: 6px !important;
            }
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
                <a href="#" class="nav-link active">Campaign #<?php echo $offerId; ?></a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <a class="nav-link" href="#" id="darkModeToggle"><i class="fas fa-moon"></i></a>
            </li>
        </ul>
    </nav>

    <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.5rem;">
                <i class="fas fa-chart-line mr-2"></i><strong>Advertiser</strong>
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
                        <a href="offers.php" class="nav-link active"><i class="nav-icon fas fa-gift"></i><p>All Offers</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="create_offer.php" class="nav-link"><i class="nav-icon fas fa-plus-circle"></i><p>Create New Offer</p></a>
                    </li>
                    <li class="nav-header">REPORTS & ANALYTICS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link"><i class="nav-icon fas fa-chart-bar"></i><p>Campaign Reports</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_conversions.php" class="nav-link"><i class="fas fa-exchange-alt nav-icon"></i><p>Conversion Reports</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link"><i class="nav-icon fas fa-users"></i><p>Affiliate Reports</p></a>
                    </li>
                    <li class="nav-header">TOOLS</li>
                    <li class="nav-item">
                        <a href="ip_whitelist.php" class="nav-link"><i class="nav-icon fas fa-tower-broadcast"></i><p>IP Whitelist</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="postback.php" class="nav-link"><i class="nav-icon fas fa-code"></i><p>Postback Manager</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="api.php" class="nav-link"><i class="nav-icon fas fa-plug"></i><p>API Integration</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="optimization.php" class="nav-link"><i class="nav-icon fas fa-rocket"></i><p>Optimization Tools</p></a>
                    </li>
                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link"><i class="nav-icon fas fa-user"></i><p>Profile</p></a>
                    </li>
                    <li class="nav-item">
                        <a href="billing.php" class="nav-link"><i class="nav-icon fas fa-wallet"></i><p>Billing & Payments</p></a>
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
                        <h1 class="m-0 font-weight-bold">Campaign Details: #<?php echo $offerId; ?></h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="offers.php">Campaigns</a></li>
                            <li class="breadcrumb-item active">#<?php echo $offerId; ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">

                <!-- Header Actions -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <span class="badge badge-<?php echo $offer['status'] === 'active' ? 'success' : 'warning'; ?> p-2 px-3 font-weight-bold" style="font-size: 14px;">
                            <?php echo ucfirst($offer['status']); ?>
                        </span>
                        <span class="badge badge-info p-2 px-3 ml-2 font-weight-bold" style="font-size: 14px;">
                            <?php echo strtoupper($offer['objective'] ?? 'CPA'); ?>
                        </span>
                    </div>
                    <div>
                        <a href="offer_edit.php?id=<?php echo $offerId; ?>" class="btn btn-primary font-weight-bold shadow-sm mr-2">
                            <i class="fas fa-edit mr-1"></i> Edit Campaign
                        </a>
                        <a href="offers.php" class="btn btn-outline-secondary font-weight-bold">
                            <i class="fas fa-arrow-left mr-1"></i> Back to Campaigns
                        </a>
                    </div>
                </div>

                <!-- Stat Boxes Row (2x2 Mobile Grid) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($clicks); ?></div>
                            <div class="stat-label">Total Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($conversions); ?></div>
                            <div class="stat-label">Conversions (<?php echo $cr; ?>% CR)</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info">$<?php echo number_format($offer['total_revenue'], 2); ?></div>
                            <div class="stat-label">Total Revenue</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning">$<?php echo number_format($margin, 2); ?></div>
                            <div class="stat-label">Net Profit Margin</div>
                        </div>
                    </div>
                </div>

                <!-- Campaign Configuration Details -->
                <div class="row">
                    <div class="col-md-7">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-info-circle mr-2"></i>Campaign Specifications</h4>
                            
                            <div class="detail-row">
                                <div class="detail-label">Offer Title</div>
                                <div class="detail-value font-weight-bold"><?php echo htmlspecialchars($offer['offer_name']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Category</div>
                                <div class="detail-value"><?php echo htmlspecialchars($offer['category'] ?: 'General'); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Destination URL</div>
                                <div class="detail-value"><code class="text-break"><?php echo htmlspecialchars($offer['campaign_url']); ?></code></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Preview Landing Page</div>
                                <div class="detail-value">
                                    <?php if ($offer['preview_url']): ?>
                                    <a href="<?php echo htmlspecialchars($offer['preview_url']); ?>" target="_blank" class="text-primary font-weight-bold"><i class="fas fa-external-link-alt mr-1"></i>Visit Preview</a>
                                    <?php else: ?>
                                    <span class="text-muted">None specified</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Payout & Revenue</div>
                                <div class="detail-value">
                                    <span class="text-success font-weight-bold">$<?php echo number_format($offer['payout'], 2); ?> Payout</span> 
                                    <span class="text-muted">/</span> 
                                    <span class="text-primary font-weight-bold">$<?php echo number_format($offer['revenue'], 2); ?> Revenue</span>
                                </div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Allowed Countries</div>
                                <div class="detail-value"><span class="badge badge-light p-2 font-weight-bold border"><?php echo htmlspecialchars($offer['allowed_countries'] ?: 'ALL'); ?></span></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Device Targeting</div>
                                <div class="detail-value"><?php echo ucfirst($offer['device_type'] ?? 'All'); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Postback Token</div>
                                <div class="detail-value"><code class="p-1 px-2 bg-light rounded text-dark font-weight-bold"><?php echo htmlspecialchars($offer['postback_token'] ?? 'N/A'); ?></code></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-5">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-code mr-2"></i>Postback Integration URL</h4>
                            <p class="text-muted small mb-2">Send conversions back to our network using your offer token:</p>
                            <code class="d-block p-3 bg-dark text-white rounded mb-3 text-break" style="font-size: 13px;">https://iconmedianetwork.in/postback.php?click_id={click_id}&token=<?php echo htmlspecialchars($offer['postback_token']); ?>&payout=<?php echo number_format($offer['payout'], 2); ?>&status=approved</code>
                            <button class="btn btn-outline-primary btn-block font-weight-bold" onclick="navigator.clipboard.writeText('https://iconmedianetwork.in/postback.php?click_id={click_id}&token=<?php echo htmlspecialchars($offer['postback_token']); ?>&payout=<?php echo number_format($offer['payout'], 2); ?>&status=approved'); alert('Postback URL copied!');">
                                <i class="fas fa-copy mr-1"></i> Copy Postback URL
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Recent Conversions Table -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-exchange-alt mr-2"></i>Recent Campaign Conversions</h4>
                    
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="thead-light">
                                <tr>
                                    <th>Conversion ID</th>
                                    <th>Affiliate</th>
                                    <th>Revenue</th>
                                    <th>Payout</th>
                                    <th>Status</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentConversions)): ?>
                                <tr><td colspan="6" class="text-center py-4 text-muted">No conversions logged for this campaign yet.</td></tr>
                                <?php else: ?>
                                <?php foreach ($recentConversions as $rc): ?>
                                <tr>
                                    <td><strong>#<?php echo $rc['conversion_id']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($rc['affiliate_name'] ?: 'Direct'); ?></td>
                                    <td><strong class="text-primary">$<?php echo number_format($rc['revenue'], 2); ?></strong></td>
                                    <td><strong class="text-success">$<?php echo number_format($rc['payout'], 2); ?></strong></td>
                                    <td><span class="badge badge-success p-2"><?php echo ucfirst($rc['status']); ?></span></td>
                                    <td><small class="text-muted"><?php echo date('M d, Y H:i:s', strtotime($rc['created_at'])); ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

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
</body>
</html>
