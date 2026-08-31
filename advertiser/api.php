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
   FETCH OR GENERATE API KEY
================================ */
$stmt = $pdo->prepare("SELECT api_key, api_secret, api_enabled, api_created_at, api_last_used FROM users WHERE user_id = ?");
$stmt->execute([$advertiserId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$apiKey = $user['api_key'] ?? null;
$apiSecret = $user['api_secret'] ?? null;
$apiEnabled = (int)($user['api_enabled'] ?? 0);

/* ===============================
   GENERATE / REGENERATE API KEY
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'generate') {
        $newApiKey = 'adv_' . bin2hex(random_bytes(16));
        $newApiSecret = bin2hex(random_bytes(24));
        
        $up = $pdo->prepare("UPDATE users SET api_key = ?, api_secret = ?, api_enabled = 1, api_created_at = NOW() WHERE user_id = ?");
        if ($up->execute([$newApiKey, $newApiSecret, $advertiserId])) {
            $success = "API Credentials generated successfully!";
            $apiKey = $newApiKey;
            $apiSecret = $newApiSecret;
            $apiEnabled = 1;
        }
    } elseif ($_POST['action'] === 'toggle') {
        $newStatus = $apiEnabled ? 0 : 1;
        $up = $pdo->prepare("UPDATE users SET api_enabled = ? WHERE user_id = ?");
        if ($up->execute([$newStatus, $advertiserId])) {
            $apiEnabled = $newStatus;
            $success = $apiEnabled ? "API Enabled" : "API Disabled";
        }
    } elseif ($_POST['action'] === 'revoke') {
        $up = $pdo->prepare("UPDATE users SET api_key = NULL, api_secret = NULL, api_enabled = 0 WHERE user_id = ?");
        if ($up->execute([$advertiserId])) {
            $apiKey = null;
            $apiSecret = null;
            $apiEnabled = 0;
            $success = "API credentials revoked!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API Integration & Developer Hub | Advertiser Panel</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    
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
                <a href="api.php" class="nav-link active">API Integration</a>
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
                        <a href="reports_campaigns.php" class="nav-link"><i class="fas fa-exchange-alt nav-icon"></i><p>Conversion Reports</p></a>
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
                        <a href="api.php" class="nav-link active"><i class="nav-icon fas fa-plug"></i><p>API Integration</p></a>
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
                        <h1 class="m-0 font-weight-bold">REST API & Developer Integration Hub</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">API Integration</li>
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
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
                <?php endif; ?>

                <!-- Summary Stat Cards (2x2 Mobile Grid) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo $apiEnabled ? 'ACTIVE' : 'INACTIVE'; ?></div>
                            <div class="stat-label">API Status</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info">v1.0</div>
                            <div class="stat-label">REST Version</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success">200 OK</div>
                            <div class="stat-label">System Gateway</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning">100 / min</div>
                            <div class="stat-label">Rate Limit</div>
                        </div>
                    </div>
                </div>

                <!-- API Key Management Card -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-key mr-2"></i>Your API Credentials</h4>
                    
                    <?php if ($apiKey): ?>
                    <div class="row align-items-center mb-3">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <label class="font-weight-bold">X-API-KEY:</label>
                            <div class="input-group">
                                <input type="text" class="form-control font-weight-bold bg-light" id="apiKeyInput" value="<?php echo htmlspecialchars($apiKey); ?>" readonly>
                                <div class="input-group-append">
                                    <button class="btn btn-outline-primary" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($apiKey); ?>'); alert('API Key copied!');">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 text-md-right">
                            <form method="post" class="d-inline-block mr-2">
                                <input type="hidden" name="action" value="toggle">
                                <button type="submit" class="btn btn-<?php echo $apiEnabled ? 'warning' : 'success'; ?> font-weight-bold">
                                    <i class="fas fa-toggle-on mr-1"></i> <?php echo $apiEnabled ? 'Disable API' : 'Enable API'; ?>
                                </button>
                            </form>
                            <form method="post" class="d-inline-block">
                                <input type="hidden" name="action" value="revoke">
                                <button type="submit" class="btn btn-danger font-weight-bold" onclick="return confirm('Revoke existing key?');">
                                    <i class="fas fa-trash-alt mr-1"></i> Revoke
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-plug fa-3x text-muted mb-3"></i>
                        <h5>No API Key Generated Yet</h5>
                        <p class="text-muted">Generate your REST API key to start fetching campaign statistics programmatically.</p>
                        <form method="post">
                            <input type="hidden" name="action" value="generate">
                            <button type="submit" class="btn btn-primary font-weight-bold shadow-sm">
                                <i class="fas fa-key mr-1"></i> Generate API Key
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Live Interactive API Console & Tester -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-terminal mr-2"></i>Live REST API Endpoint Tester</h4>
                    <p class="text-muted small">Execute live requests against network endpoints directly from your browser:</p>

                    <div class="row">
                        <div class="col-md-5">
                            <div class="form-group">
                                <label class="font-weight-bold">Choose Endpoint</label>
                                <select id="apiTestEndpoint" class="form-control">
                                    <option value="/api/v1/offers">GET /api/v1/offers (All Offers)</option>
                                    <option value="/api/v1/offers/1">GET /api/v1/offers/1 (Single Offer)</option>
                                    <option value="/api/v1/conversions">GET /api/v1/conversions (Conversions)</option>
                                </select>
                            </div>
                            <button id="runApiTestBtn" class="btn btn-success font-weight-bold btn-block shadow-sm">
                                <i class="fas fa-paper-plane mr-1"></i> Send Live Request
                            </button>
                        </div>
                        <div class="col-md-7">
                            <label class="font-weight-bold">Live Server JSON Response:</label>
                            <pre id="apiTestResponse" class="p-3 bg-dark text-white rounded" style="max-height: 250px; overflow-y: auto; font-size: 13px;">Click 'Send Live Request' to execute API call...</pre>
                        </div>
                    </div>
                </div>

                <!-- Code Snippets Generator -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-code mr-2"></i>Multi-Language Code Generator</h4>
                    
                    <ul class="nav nav-tabs mb-3" id="snippetTabs">
                        <li class="nav-item"><a class="nav-link active font-weight-bold" href="#curl" data-toggle="tab">cURL CLI</a></li>
                        <li class="nav-item"><a class="nav-link font-weight-bold" href="#php" data-toggle="tab">PHP</a></li>
                        <li class="nav-item"><a class="nav-link font-weight-bold" href="#node" data-toggle="tab">Node.js</a></li>
                        <li class="nav-item"><a class="nav-link font-weight-bold" href="#python" data-toggle="tab">Python</a></li>
                    </ul>

                    <div class="tab-content">
                        <div class="tab-pane active" id="curl">
                            <code class="d-block p-3 bg-dark text-white rounded">curl -X GET "https://iconmedianetwork.in/api/v1/offers" -H "X-API-KEY: <?php echo htmlspecialchars($apiKey ?: 'YOUR_API_KEY'); ?>"</code>
                        </div>
                        <div class="tab-pane" id="php">
                            <pre class="p-3 bg-dark text-white rounded" style="font-size: 13px;">
$ch = curl_init("https://iconmedianetwork.in/api/v1/offers");
curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-API-KEY: <?php echo htmlspecialchars($apiKey ?: 'YOUR_API_KEY'); ?>"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);
$data = json_decode($response, true);</pre>
                        </div>
                        <div class="tab-pane" id="node">
                            <pre class="p-3 bg-dark text-white rounded" style="font-size: 13px;">
const axios = require('axios');
axios.get('https://iconmedianetwork.in/api/v1/offers', {
  headers: { 'X-API-KEY': '<?php echo htmlspecialchars($apiKey ?: 'YOUR_API_KEY'); ?>' }
}).then(res => console.log(res.data));</pre>
                        </div>
                        <div class="tab-pane" id="python">
                            <pre class="p-3 bg-dark text-white rounded" style="font-size: 13px;">
import requests
headers = {'X-API-KEY': '<?php echo htmlspecialchars($apiKey ?: 'YOUR_API_KEY'); ?>'}
res = requests.get('https://iconmedianetwork.in/api/v1/offers', headers=headers)
print(res.json())</pre>
                        </div>
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

<script>
$(document).ready(function() {
    $('#runApiTestBtn').click(function() {
        const endpoint = $('#apiTestEndpoint').val();
        const apiKey = $('#apiKeyInput').val() || '<?php echo htmlspecialchars($apiKey ?: ""); ?>';

        if (!apiKey) {
            alert('Please generate an API Key first!');
            return;
        }

        $('#apiTestResponse').text('Executing HTTP GET request...');

        $.ajax({
            url: endpoint,
            type: 'GET',
            headers: { 'X-API-KEY': apiKey },
            success: function(res) {
                $('#apiTestResponse').text(JSON.stringify(res, null, 2));
            },
            error: function(xhr) {
                $('#apiTestResponse').text(JSON.stringify(xhr.responseJSON || { error: 'Request Failed' }, null, 2));
            }
        });
    });
});
</script>
</body>
</html>