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
   HANDLE LIVE TEST FIRE & SIMULATION
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fire_live_test'])) {
    $offerId = (int)$_POST['test_offer_id'];
    $testClickId = trim($_POST['test_click_id'] ?? ('test_' . uniqid()));
    $testPayout = (float)($_POST['test_payout'] ?? 10.00);
    $testStatus = $_POST['test_status'] ?? 'approved';
    $testTxnId = 'TXN_' . strtoupper(substr(md5(microtime()), 0, 8));

    // Get offer details & token
    $stmt = $pdo->prepare("SELECT offer_id, offer_name, postback_token, revenue FROM offers WHERE offer_id = :oid AND advertiser_id = :aid");
    $stmt->execute(['oid' => $offerId, 'aid' => $advertiserId]);
    $offerData = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($offerData) {
        $token = $offerData['postback_token'];
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'] ?? 'iconmedianetwork.in';
        $postbackUrl = "{$protocol}://{$host}/postback.php?click_id=" . urlencode($testClickId) . "&payout=" . urlencode($testPayout) . "&token=" . urlencode($token) . "&status=" . urlencode($testStatus) . "&transaction_id=" . urlencode($testTxnId);

        // Execute cURL request to simulate incoming postback
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $postbackUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200) {
            $success = "Test Postback successfully fired! Server Response (HTTP 200): " . htmlspecialchars($response);
        } else {
            $error = "Test Postback fired with HTTP status {$httpCode}. Error: " . htmlspecialchars($curlError ?: $response);
        }
    } else {
        $error = "Invalid Offer selected for testing.";
    }
}

/* ===============================
   FETCH ALL ADVERTISER OFFERS
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
        revenue,
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
    WHERE o.advertiser_id = :aid
    ORDER BY cv.created_at DESC
    LIMIT 30
");
$logsStmt->execute(['aid' => $advertiserId]);
$postbackLogs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Postback Manager & S2S Tester | Advertiser Panel</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <!-- Prism Syntax Highlighting -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
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
            font-size: 28px;
            font-weight: 800;
            color: #1e293b;
        }

        .stat-card-custom .stat-label {
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
        }

        .token-chip {
            display: inline-block;
            background: #e0e7ff;
            color: #3730a3;
            padding: 4px 10px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            margin: 3px;
            transition: all 0.2s;
        }

        .token-chip:hover {
            background: #c7d2fe;
            transform: scale(1.05);
        }

        .code-box-wrapper {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            background: #1e1e1e;
        }

        .code-copy-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255,255,255,0.15);
            color: #ffffff;
            border: none;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            z-index: 5;
            transition: all 0.2s;
        }

        .code-copy-btn:hover {
            background: rgba(255,255,255,0.3);
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
                <a href="postback.php" class="nav-link active">Postback Manager</a>
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
                        <a href="offers.php" class="nav-link"><i class="nav-icon fas fa-gift"></i><p>All Offers</p></a>
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
                        <a href="postback.php" class="nav-link active"><i class="nav-icon fas fa-code"></i><p>Postback Manager</p></a>
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
                        <h1 class="m-0 font-weight-bold">Postback Manager & S2S Tester</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Postback Manager</li>
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
                    <h5><i class="icon fas fa-check-circle"></i> Success!</h5>
                    <p class="mb-0"><?php echo $success; ?></p>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <h5><i class="icon fas fa-exclamation-triangle"></i> Error</h5>
                    <p class="mb-0"><?php echo $error; ?></p>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
                <?php endif; ?>

                <!-- Stat Boxes Row (2x2 Mobile Responsive Grid) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($stats['total_offers'] ?? 0); ?></div>
                            <div class="stat-label">Total Offers</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($stats['active_offers'] ?? 0); ?></div>
                            <div class="stat-label">Active Offers</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($stats['postback_conversions'] ?? 0); ?></div>
                            <div class="stat-label">S2S Conversions</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning"><?php echo number_format($stats['total_conversions'] ?? 0); ?></div>
                            <div class="stat-label">Total Conversions</div>
                        </div>
                    </div>
                </div>

                <!-- Live Postback Simulator Card -->
                <div class="card card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="font-weight-bold text-primary mb-0"><i class="fas fa-paper-plane mr-2"></i>Live Postback Endpoint Simulator & S2S Tester</h4>
                        <button class="btn btn-primary font-weight-bold" data-toggle="modal" data-target="#liveTestModal">
                            <i class="fas fa-play-circle mr-1"></i> Fire Live Test Request
                        </button>
                    </div>
                    <p class="text-muted mb-3">Test your server-to-server (S2S) postback integration directly from your browser in real-time.</p>

                    <div class="bg-light p-3 rounded border mb-3">
                        <strong class="d-block text-dark mb-1"><i class="fas fa-globe mr-1"></i>Global S2S Server-to-Server Postback URL Structure:</strong>
                        <code class="d-block p-2 bg-dark text-white rounded">https://iconmedianetwork.in/postback.php?click_id=<span class="text-warning">{click_id}</span>&token=<span class="text-info">{YOUR_POSTBACK_TOKEN}</span>&payout=<span class="text-success">{payout}</span>&status=<span class="text-danger">approved</span></code>
                    </div>

                    <div>
                        <span class="font-weight-bold text-dark mr-2">Supported Dynamic Macros:</span>
                        <span class="token-chip" onclick="copyText('{click_id}')">{click_id}</span>
                        <span class="token-chip" onclick="copyText('{token}')">{token}</span>
                        <span class="token-chip" onclick="copyText('{payout}')">{payout}</span>
                        <span class="token-chip" onclick="copyText('{revenue}')">{revenue}</span>
                        <span class="token-chip" onclick="copyText('{status}')">{status}</span>
                        <span class="token-chip" onclick="copyText('{transaction_id}')">{transaction_id}</span>
                    </div>
                </div>

                <!-- Tabbed Integration Snippets (PHP, Node.js, Python, Curl) -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-laptop-code mr-2"></i>Developer Server Integration Snippets</h4>
                    
                    <ul class="nav nav-pills mb-3" id="snippetTabs" role="tablist">
                        <li class="nav-item"><a class="nav-link active font-weight-bold" id="php-tab" data-toggle="pill" href="#tab-php">PHP (cURL)</a></li>
                        <li class="nav-item"><a class="nav-link font-weight-bold" id="node-tab" data-toggle="pill" href="#tab-node">Node.js (Axios)</a></li>
                        <li class="nav-item"><a class="nav-link font-weight-bold" id="python-tab" data-toggle="pill" href="#tab-python">Python (Requests)</a></li>
                        <li class="nav-item"><a class="nav-link font-weight-bold" id="curl-tab" data-toggle="pill" href="#tab-curl">cURL CLI</a></li>
                    </ul>

                    <div class="tab-content" id="snippetTabsContent">
                        <!-- PHP -->
                        <div class="tab-pane fade show active" id="tab-php">
                            <div class="code-box-wrapper">
                                <button class="code-copy-btn" onclick="copyCode('code-php')"><i class="fas fa-copy mr-1"></i>Copy</button>
                                <pre><code class="language-php" id="code-php">&lt;?php
$clickId = $_GET['click_id']; // Passed from Icon Media tracking link
$token = "YOUR_OFFER_POSTBACK_TOKEN"; 
$payout = 35.00;

$url = "https://iconmedianetwork.in/postback.php?" . http_build_query([
    'click_id' => $clickId,
    'token'    => $token,
    'payout'   => $payout,
    'status'   => 'approved'
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);
?&gt;</code></pre>
                            </div>
                        </div>

                        <!-- NODE.JS -->
                        <div class="tab-pane fade" id="tab-node">
                            <div class="code-box-wrapper">
                                <button class="code-copy-btn" onclick="copyCode('code-node')"><i class="fas fa-copy mr-1"></i>Copy</button>
                                <pre><code class="language-javascript" id="code-node">const axios = require('axios');

async function sendPostback(clickId, payout, token) {
    try {
        const response = await axios.get('https://iconmedianetwork.in/postback.php', {
            params: {
                click_id: clickId,
                token: token,
                payout: payout,
                status: 'approved'
            }
        });
        console.log('Postback Sent Successfully:', response.data);
    } catch (error) {
        console.error('Postback Failed:', error.message);
    }
}</code></pre>
                            </div>
                        </div>

                        <!-- PYTHON -->
                        <div class="tab-pane fade" id="tab-python">
                            <div class="code-box-wrapper">
                                <button class="code-copy-btn" onclick="copyCode('code-python')"><i class="fas fa-copy mr-1"></i>Copy</button>
                                <pre><code class="language-python" id="code-python">import requests

def fire_postback(click_id, payout, token):
    params = {
        'click_id': click_id,
        'token': token,
        'payout': payout,
        'status': 'approved'
    }
    response = requests.get('https://iconmedianetwork.in/postback.php', params=params)
    print("Response Status:", response.status_code)
    print("Response Text:", response.text)</code></pre>
                            </div>
                        </div>

                        <!-- CURL -->
                        <div class="tab-pane fade" id="tab-curl">
                            <div class="code-box-wrapper">
                                <button class="code-copy-btn" onclick="copyCode('code-curl')"><i class="fas fa-copy mr-1"></i>Copy</button>
                                <pre><code class="language-bash" id="code-curl">curl -X GET "https://iconmedianetwork.in/postback.php?click_id=CLICK_ID_12345&token=YOUR_OFFER_POSTBACK_TOKEN&payout=35.00&status=approved"</code></pre>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Offers & Tokens Accordion / Cards List -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-key mr-2"></i>Active Campaign Postback Tokens & Links</h4>

                    <?php if (empty($offers)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-code fa-3x text-muted mb-3"></i>
                        <h5 class="text-dark font-weight-bold">No Active Campaigns Found</h5>
                        <p class="text-muted mb-3">Create your first campaign to generate unique postback security tokens.</p>
                        <a href="create_offer.php" class="btn btn-primary font-weight-bold"><i class="fas fa-plus mr-1"></i>Create Offer Now</a>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="thead-light">
                                <tr>
                                    <th>Offer ID & Title</th>
                                    <th>Status</th>
                                    <th>Payout</th>
                                    <th>Postback Security Token</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($offers as $offer): ?>
                                <tr>
                                    <td>
                                        <strong class="d-block text-dark">#<?php echo $offer['offer_id']; ?> - <?php echo htmlspecialchars($offer['offer_name']); ?></strong>
                                        <small class="text-muted">Created: <?php echo date('M d, Y', strtotime($offer['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $offer['status'] === 'active' ? 'success' : 'warning'; ?> p-2">
                                            <?php echo ucfirst($offer['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong class="text-success">$<?php echo number_format($offer['payout'], 2); ?></strong>
                                    </td>
                                    <td>
                                        <code class="p-2 bg-light rounded text-primary font-weight-bold"><?php echo htmlspecialchars($offer['postback_token']); ?></code>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary font-weight-bold mr-1" onclick="copyText('https://iconmedianetwork.in/postback.php?click_id={click_id}&token=<?php echo $offer['postback_token']; ?>')">
                                            <i class="fas fa-copy mr-1"></i>Copy URL
                                        </button>
                                        <button class="btn btn-sm btn-success font-weight-bold" onclick="openTestModal('<?php echo $offer['offer_id']; ?>', '<?php echo number_format($offer['payout'], 2); ?>')">
                                            <i class="fas fa-vial mr-1"></i>Test
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Postback Conversion Logs Table -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-history mr-2"></i>Real-time S2S Postback Conversion Audit Logs</h4>
                    
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="postbackLogsTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>Conversion ID</th>
                                    <th>Offer Name</th>
                                    <th>Affiliate</th>
                                    <th>Revenue / Payout</th>
                                    <th>Source</th>
                                    <th>Status</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($postbackLogs as $log): ?>
                                <tr>
                                    <td><strong>#<?php echo $log['conversion_id']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($log['offer_name']); ?></td>
                                    <td>
                                        <?php if ($log['affiliate_name']): ?>
                                            <span class="text-dark font-weight-bold"><?php echo htmlspecialchars($log['affiliate_name']); ?></span>
                                            <small class="d-block text-muted"><?php echo htmlspecialchars($log['affiliate_email']); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">Unassigned / Direct</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="text-success font-weight-bold">$<?php echo number_format($log['revenue'], 2); ?></span>
                                        <small class="d-block text-muted">Payout: $<?php echo number_format($log['payout'], 2); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge-info p-2"><?php echo strtoupper($log['source']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $log['status'] === 'approved' ? 'success' : 'warning'; ?> p-2">
                                            <?php echo ucfirst($log['status']); ?>
                                        </span>
                                    </td>
                                    <td><small class="text-muted"><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Live Postback Tester Modal -->
    <div class="modal fade" id="liveTestModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
                <div class="modal-header bg-primary text-white" style="border-radius: 15px 15px 0 0;">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-vial mr-2"></i>Fire Live S2S Postback Request</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <form method="post">
                    <div class="modal-body p-4">
                        <div class="form-group mb-3">
                            <label class="font-weight-bold">Select Campaign Offer <span class="text-danger">*</span></label>
                            <select name="test_offer_id" id="modal_offer_id" class="form-control" required>
                                <option value="">Choose offer to test...</option>
                                <?php foreach ($offers as $of): ?>
                                <option value="<?php echo $of['offer_id']; ?>"><?php echo htmlspecialchars($of['offer_name']); ?> (#<?php echo $of['offer_id']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group mb-3">
                            <label class="font-weight-bold">Test Click ID</label>
                            <input type="text" name="test_click_id" class="form-control" value="TEST_CLICK_<?php echo rand(10000,99999); ?>">
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Test Payout ($)</label>
                                    <input type="number" step="0.01" name="test_payout" id="modal_test_payout" class="form-control" value="10.00" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Status</label>
                                    <select name="test_status" class="form-control">
                                        <option value="approved">Approved</option>
                                        <option value="pending">Pending</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light" style="border-radius: 0 0 15px 15px;">
                        <button type="button" class="btn btn-outline-secondary font-weight-bold" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="fire_live_test" class="btn btn-success font-weight-bold"><i class="fas fa-paper-plane mr-2"></i>Fire Postback Request</button>
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
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-python.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-bash.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    $('#postbackLogsTable').DataTable({
        pageLength: 10,
        order: [[6, 'desc']],
        responsive: true
    });
});

function copyText(text) {
    navigator.clipboard.writeText(text).then(() => {
        Swal.fire({ title: 'Copied!', text: text, icon: 'success', timer: 1500, showConfirmButton: false });
    });
}

function copyCode(elementId) {
    const code = document.getElementById(elementId).innerText;
    copyText(code);
}

function openTestModal(offerId, payout) {
    $('#modal_offer_id').val(offerId);
    if(payout) $('#modal_test_payout').val(payout);
    $('#liveTestModal').modal('show');
}
</script>
</body>
</html>