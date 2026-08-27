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
// Check if API key exists
$stmt = $pdo->prepare("SELECT api_key, api_secret, api_enabled, api_created_at, api_last_used FROM users WHERE user_id = ?");
$stmt->execute([$advertiserId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$apiKey = $user['api_key'] ?? null;
$apiSecret = $user['api_secret'] ?? null;
$apiEnabled = $user['api_enabled'] ?? 0;

/* ===============================
   GENERATE NEW API KEY
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_api'])) {
    
    // Generate new API key and secret
    $newApiKey = 'adv_' . bin2hex(random_bytes(16));
    $newApiSecret = bin2hex(random_bytes(32));
    
    $stmt = $pdo->prepare("
        UPDATE users 
        SET api_key = ?, 
            api_secret = ?, 
            api_enabled = 1,
            api_created_at = NOW(),
            api_updated_at = NOW()
        WHERE user_id = ?
    ");
    
    if ($stmt->execute([$newApiKey, $newApiSecret, $advertiserId])) {
        $success = "API credentials generated successfully! Please save your API Secret - it won't be shown again.";
        $apiKey = $newApiKey;
        $apiSecret = $newApiSecret;
        $apiEnabled = 1;
    } else {
        $error = "Failed to generate API credentials";
    }
}

/* ===============================
   REGENERATE API SECRET
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate_secret'])) {
    
    $newApiSecret = bin2hex(random_bytes(32));
    
    $stmt = $pdo->prepare("
        UPDATE users 
        SET api_secret = ?,
            api_updated_at = NOW()
        WHERE user_id = ?
    ");
    
    if ($stmt->execute([$newApiSecret, $advertiserId])) {
        $success = "API Secret regenerated successfully!";
        $apiSecret = $newApiSecret;
    } else {
        $error = "Failed to regenerate API Secret";
    }
}

/* ===============================
   TOGGLE API STATUS
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_api'])) {
    
    $newStatus = isset($_POST['enable_api']) ? 1 : 0;
    
    $stmt = $pdo->prepare("
        UPDATE users 
        SET api_enabled = ?,
            api_updated_at = NOW()
        WHERE user_id = ?
    ");
    
    if ($stmt->execute([$newStatus, $advertiserId])) {
        $apiEnabled = $newStatus;
        $success = $apiEnabled ? "API enabled successfully" : "API disabled successfully";
    } else {
        $error = "Failed to update API status";
    }
}

/* ===============================
   REVOKE API KEY
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_api'])) {
    
    $stmt = $pdo->prepare("
        UPDATE users 
        SET api_key = NULL,
            api_secret = NULL,
            api_enabled = 0,
            api_revoked_at = NOW()
        WHERE user_id = ?
    ");
    
    if ($stmt->execute([$advertiserId])) {
        $success = "API credentials revoked successfully";
        $apiKey = null;
        $apiSecret = null;
        $apiEnabled = 0;
    } else {
        $error = "Failed to revoke API credentials";
    }
}

/* ===============================
   FETCH API USAGE STATS
================================ */
$usageStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_requests,
        COUNT(DISTINCT DATE(created_at)) as active_days,
        MAX(created_at) as last_request,
        SUM(CASE WHEN response_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) as successful_requests,
        SUM(CASE WHEN response_code >= 400 THEN 1 ELSE 0 END) as failed_requests
    FROM api_logs 
    WHERE advertiser_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$usageStmt->execute([$advertiserId]);
$usageStats = $usageStmt->fetch(PDO::FETCH_ASSOC) ?: [];

/* ===============================
   FETCH RECENT API REQUESTS
================================ */
$logsStmt = $pdo->prepare("
    SELECT 
        endpoint,
        method,
        response_code,
        ip_address,
        created_at,
        execution_time
    FROM api_logs 
    WHERE advertiser_id = ? 
    ORDER BY created_at DESC 
    LIMIT 20
");
$logsStmt->execute([$advertiserId]);
$apiLogs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   API ENDPOINTS DOCUMENTATION
================================ */
$endpoints = [
    [
        'method' => 'GET',
        'endpoint' => '/api/v1/offers',
        'description' => 'Get all offers',
        'parameters' => [
            'status' => 'Filter by status (active, paused, pending)',
            'limit' => 'Number of results (default: 50, max: 100)',
            'page' => 'Page number for pagination'
        ],
        'response' => '{
  "status": "success",
  "data": [
    {
      "offer_id": 1,
      "offer_name": "Summer Sale",
      "status": "active",
      "payout": 50.00,
      "currency": "USD"
    }
  ],
  "pagination": {
    "total": 150,
    "page": 1,
    "per_page": 50,
    "total_pages": 3
  }
}'
    ],
    [
        'method' => 'GET',
        'endpoint' => '/api/v1/offers/{id}',
        'description' => 'Get single offer details',
        'parameters' => [],
        'response' => '{
  "status": "success",
  "data": {
    "offer_id": 1,
    "offer_name": "Summer Sale",
    "description": "Special summer promotion",
    "status": "active",
    "payout": 50.00,
    "revenue": 75.00,
    "currency": "USD",
    "daily_cap": 1000,
    "total_cap": 50000,
    "targeting": {
      "countries": ["US", "CA", "UK"],
      "devices": ["mobile", "desktop"]
    },
    "created_at": "2024-01-15 10:30:00"
  }
}'
    ],
    [
        'method' => 'GET',
        'endpoint' => '/api/v1/conversions',
        'description' => 'Get conversion reports',
        'parameters' => [
            'offer_id' => 'Filter by offer ID',
            'date_from' => 'Start date (YYYY-MM-DD)',
            'date_to' => 'End date (YYYY-MM-DD)',
            'status' => 'Filter by status (approved, pending, rejected)',
            'limit' => 'Number of results (default: 50)',
            'page' => 'Page number'
        ],
        'response' => '{
  "status": "success",
  "data": [
    {
      "conversion_id": 12345,
      "offer_id": 1,
      "offer_name": "Summer Sale",
      "affiliate_id": 678,
      "affiliate_name": "John Doe",
      "revenue": 75.00,
      "payout": 50.00,
      "status": "approved",
      "transaction_id": "TX123456",
      "created_at": "2024-02-20 14:30:00"
    }
  ],
  "summary": {
    "total_revenue": 7500.00,
    "total_payout": 5000.00,
    "total_conversions": 100,
    "approved": 95,
    "pending": 3,
    "rejected": 2
  }
}'
    ],
    [
        'method' => 'GET',
        'endpoint' => '/api/v1/stats',
        'description' => 'Get performance statistics',
        'parameters' => [
            'offer_id' => 'Filter by offer ID',
            'date_from' => 'Start date (YYYY-MM-DD)',
            'date_to' => 'End date (YYYY-MM-DD)',
            'group_by' => 'Group by (day, week, month)'
        ],
        'response' => '{
  "status": "success",
  "data": {
    "summary": {
      "total_clicks": 15000,
      "total_conversions": 750,
      "total_revenue": 56250.00,
      "total_payout": 37500.00,
      "profit": 18750.00,
      "conversion_rate": 5.0
    },
    "daily": [
      {
        "date": "2024-02-20",
        "clicks": 1250,
        "conversions": 62,
        "revenue": 4650.00
      }
    ]
  }
}'
    ],
    [
        'method' => 'POST',
        'endpoint' => '/api/v1/conversions/track',
        'description' => 'Track a conversion (postback alternative)',
        'parameters' => [
            'click_id' => 'Unique click identifier (required)',
            'revenue' => 'Conversion revenue amount',
            'payout' => 'Payout amount',
            'status' => 'Conversion status (approved, pending)',
            'transaction_id' => 'Your internal transaction ID'
        ],
        'response' => '{
  "status": "success",
  "message": "Conversion tracked successfully",
  "data": {
    "conversion_id": 12346,
    "status": "pending"
  }
}'
    ]
];

/* ===============================
   CODE EXAMPLES
================================ */
$codeExamples = [
    'php' => '<?php
// PHP Example using cURL
$api_key = "' . ($apiKey ?? 'YOUR_API_KEY') . '";
$api_secret = "' . ($apiSecret ?? 'YOUR_API_SECRET') . '";

$ch = curl_init("https://iconmedianetwork.in/api/v1/offers");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "X-API-Key: $api_key",
    "X-API-Secret: $api_secret",
    "Content-Type: application/json"
]);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
print_r($data);',
    
    'python' => 'import requests

api_key = "' . ($apiKey ?? 'YOUR_API_KEY') . '"
api_secret = "' . ($apiSecret ?? 'YOUR_API_SECRET') . '"

headers = {
    "X-API-Key": api_key,
    "X-API-Secret": api_secret,
    "Content-Type": "application/json"
}

response = requests.get(
    "https://iconmedianetwork.in/api/v1/offers",
    headers=headers
)

data = response.json()
print(data)',
    
    'javascript' => '// JavaScript Example using fetch
const apiKey = "' . ($apiKey ?? 'YOUR_API_KEY') . '";
const apiSecret = "' . ($apiSecret ?? 'YOUR_API_SECRET') . '";

fetch("https://iconmedianetwork.in/api/v1/offers", {
    headers: {
        "X-API-Key": apiKey,
        "X-API-Secret": apiSecret,
        "Content-Type": "application/json"
    }
})
.then(response => response.json())
.then(data => console.log(data))
.catch(error => console.error("Error:", error));',
    
    'curl' => 'curl -X GET "https://iconmedianetwork.in/api/v1/offers" \\
  -H "X-API-Key: ' . ($apiKey ?? 'YOUR_API_KEY') . '" \\
  -H "X-API-Secret: ' . ($apiSecret ?? 'YOUR_API_SECRET') . '" \\
  -H "Content-Type: application/json"'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API Integration | Advertiser Panel | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- Prism.js for syntax highlighting -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
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
            padding: 25px;
        }
        
        .api-key-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
        }
        
        .api-key-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.1)" d="M0,96L48,112C96,128,192,160,288,186.7C384,213,480,235,576,213.3C672,192,768,128,864,128C960,128,1056,192,1152,213.3C1248,235,1344,213,1392,202.7L1440,192L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>');
            background-size: cover;
            opacity: 0.1;
        }
        
        .api-key-display {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 8px;
            padding: 15px;
            font-family: monospace;
            font-size: 16px;
            word-break: break-all;
            position: relative;
            margin-bottom: 15px;
        }
        
        .api-secret-warning {
            background: rgba(255,255,255,0.1);
            border-left: 4px solid #ffc107;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }
        
        .copy-btn {
            position: absolute;
            right: 10px;
            top: 10px;
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            border-radius: 6px;
            padding: 5px 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 12px;
        }
        
        .copy-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        .metric-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #e3e6f0;
            text-align: center;
            transition: all 0.3s ease;
            flex: 1;
            min-width: 150px;
        }
        
        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .metric-value {
            font-size: 28px;
            font-weight: 700;
            color: #4e73df;
            margin-bottom: 5px;
        }
        
        .metric-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .summary-stats {
            display: flex;
            gap: 15px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        
        .endpoint-card {
            background: #f8f9fc;
            border: 1px solid #e3e6f0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .endpoint-card:hover {
            border-color: #667eea;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.1);
        }
        
        .endpoint-method {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-right: 10px;
        }
        
        .method-get {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        
        .method-post {
            background: rgba(0, 123, 255, 0.15);
            color: #007bff;
        }
        
        .method-put {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }
        
        .method-delete {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }
        
        .endpoint-url {
            font-family: monospace;
            font-size: 14px;
            background: white;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #e3e6f0;
            margin: 10px 0;
        }
        
        .parameter-table {
            font-size: 13px;
        }
        
        .parameter-table td {
            padding: 8px;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-success {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        
        .status-error {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }
        
        .btn-gradient {
            background: var(--primary-gradient);
            border: none;
            color: white;
            font-weight: 600;
            padding: 10px 25px;
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
        }
        
        .btn-danger {
            background: #dc3545;
            border: none;
            color: white;
            font-weight: 600;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state-icon {
            font-size: 60px;
            color: #e3e6f0;
            margin-bottom: 15px;
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .action-buttons-group {
            display: flex;
            gap: 10px;
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
        
        .nav-tabs {
            border-bottom: 2px solid #e3e6f0;
            margin-bottom: 25px;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 600;
            padding: 12px 25px;
            border-radius: 8px 8px 0 0;
        }
        
        .nav-tabs .nav-link.active {
            color: #4e73df;
            border-bottom: 3px solid #4e73df;
            background: rgba(78, 115, 223, 0.05);
        }
        
        .tab-content {
            padding: 20px 0;
        }
        
        pre {
            border-radius: 8px;
            max-height: 300px;
            overflow: auto;
        }
        
        .code-switcher {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .code-btn {
            padding: 5px 15px;
            border: 1px solid #e3e6f0;
            border-radius: 20px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .code-btn.active {
            background: var(--primary-gradient);
            color: white;
            border-color: transparent;
        }
        
        .alert {
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            border: none;
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            border-left: 4px solid #28a745;
            color: #155724;
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        
        .alert-warning {
            background: rgba(255, 193, 7, 0.1);
            border-left: 4px solid #ffc107;
            color: #856404;
        }
        
        .refresh-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            border-radius: 20px;
            padding: 8px 20px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .refresh-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }
        
        .modal-header {
            border-bottom: 1px solid #e3e6f0;
            padding: 20px 25px;
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .modal-footer {
            border-top: 1px solid #e3e6f0;
            padding: 20px 25px;
        }
        
        .api-status-toggle {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .custom-switch {
            padding-left: 2.25rem;
        }
        
        .custom-switch .custom-control-label::before {
            left: -2.25rem;
            width: 1.75rem;
            border-radius: 0.5rem;
        }
        
        .custom-switch .custom-control-label::after {
            top: calc(0.25rem + 2px);
            left: calc(-2.25rem + 2px);
            width: calc(1rem - 4px);
            height: calc(1rem - 4px);
            border-radius: 0.5rem;
        }
        
        .custom-switch .custom-control-input:checked ~ .custom-control-label::after {
            transform: translateX(0.75rem);
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
                <a href="api.php" class="nav-link active">API Integration</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
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
                        <i class="fas fa-user mr-2"></i> Profile
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
                        <a href="create_offer.php" class="nav-link">
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
                        <a href="api.php" class="nav-link active">
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
                        <h1 class="m-0">API Integration</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="tools.php">Tools</a></li>
                            <li class="breadcrumb-item active">API Integration</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">
                <!-- Success/Error Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>

                <!-- API Key Section -->
                <div class="api-key-card">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h3 class="text-white">API Authentication</h3>
                            <p class="text-white-50 mb-4">Use these credentials to authenticate your API requests</p>
                            
                            <?php if ($apiKey): ?>
                                <div class="api-key-display">
                                    <strong>API Key:</strong> <?php echo $apiKey; ?>
                                    <span class="copy-btn" onclick="copyToClipboard('<?php echo $apiKey; ?>')">
                                        <i class="fas fa-copy mr-1"></i> Copy
                                    </span>
                                </div>
                                
                                <div class="api-key-display">
                                    <strong>API Secret:</strong> <?php echo $apiSecret; ?>
                                    <span class="copy-btn" onclick="copyToClipboard('<?php echo $apiSecret; ?>')">
                                        <i class="fas fa-copy mr-1"></i> Copy
                                    </span>
                                </div>
                                
                                <div class="api-secret-warning">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    <strong>Important:</strong> Store your API Secret securely. It won't be shown again after you leave this page.
                                </div>
                            <?php else: ?>
                                <p class="text-white-50">You haven't generated API credentials yet. Click the button to generate your API key and secret.</p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 text-right">
                            <?php if (!$apiKey): ?>
                                <form method="post" style="display: inline;">
                                    <button type="submit" name="generate_api" class="btn btn-light btn-lg">
                                        <i class="fas fa-key mr-2"></i> Generate API Key
                                    </button>
                                </form>
                            <?php else: ?>
                                <div class="api-status-toggle">
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="toggle_api" value="1">
                                        <div class="custom-control custom-switch">
                                            <input type="checkbox" class="custom-control-input" id="apiEnabled" name="enable_api" value="1" <?php echo $apiEnabled ? 'checked' : ''; ?> onchange="this.form.submit()">
                                            <label class="custom-control-label text-white" for="apiEnabled">API Enabled</label>
                                        </div>
                                    </form>
                                    
                                    <button type="button" class="btn btn-warning btn-sm" data-toggle="modal" data-target="#regenerateModal">
                                        <i class="fas fa-sync-alt mr-1"></i> Regenerate Secret
                                    </button>
                                    
                                    <button type="button" class="btn btn-danger btn-sm" data-toggle="modal" data-target="#revokeModal">
                                        <i class="fas fa-trash mr-1"></i> Revoke
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Usage Stats -->
                <?php if ($apiKey): ?>
                <div class="summary-stats">
                    <div class="metric-card">
                        <div class="metric-value"><?php echo number_format($usageStats['total_requests'] ?? 0); ?></div>
                        <div class="metric-label">Total Requests (30d)</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value"><?php echo number_format($usageStats['successful_requests'] ?? 0); ?></div>
                        <div class="metric-label">Successful</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value"><?php echo number_format($usageStats['failed_requests'] ?? 0); ?></div>
                        <div class="metric-label">Failed</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value"><?php echo $usageStats['active_days'] ?? 0; ?></div>
                        <div class="metric-label">Active Days</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value"><?php echo $usageStats['last_request'] ? date('M d', strtotime($usageStats['last_request'])) : 'Never'; ?></div>
                        <div class="metric-label">Last Request</div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tabs -->
                <ul class="nav nav-tabs" id="apiTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="docs-tab" data-toggle="tab" href="#docs" role="tab">
                            <i class="fas fa-book mr-2"></i> Documentation
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="examples-tab" data-toggle="tab" href="#examples" role="tab">
                            <i class="fas fa-code mr-2"></i> Code Examples
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="logs-tab" data-toggle="tab" href="#logs" role="tab">
                            <i class="fas fa-history mr-2"></i> Request Logs
                        </a>
                    </li>
                </ul>

                <div class="tab-content" id="apiTabsContent">
                    <!-- Documentation Tab -->
                    <div class="tab-pane fade show active" id="docs" role="tabpanel">
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-plug mr-2"></i> API Endpoints
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    <strong>Base URL:</strong> <code>https://iconmedianetwork.in/api/v1/</code>
                                </div>
                                
                                <div class="alert alert-warning">
                                    <i class="fas fa-shield-alt mr-2"></i>
                                    <strong>Authentication:</strong> All API requests require the following headers:
                                    <ul class="mt-2 mb-0">
                                        <li><code>X-API-Key: YOUR_API_KEY</code></li>
                                        <li><code>X-API-Secret: YOUR_API_SECRET</code></li>
                                        <li><code>Content-Type: application/json</code></li>
                                    </ul>
                                </div>
                                
                                <?php foreach ($endpoints as $endpoint): ?>
                                <div class="endpoint-card">
                                    <div class="d-flex align-items-center mb-3">
                                        <span class="endpoint-method method-<?php echo strtolower($endpoint['method']); ?>">
                                            <?php echo $endpoint['method']; ?>
                                        </span>
                                        <h5 class="mb-0"><?php echo $endpoint['endpoint']; ?></h5>
                                    </div>
                                    
                                    <p class="text-muted"><?php echo $endpoint['description']; ?></p>
                                    
                                    <?php if (!empty($endpoint['parameters'])): ?>
                                    <h6 class="mt-3">Parameters:</h6>
                                    <table class="table table-sm parameter-table">
                                        <?php foreach ($endpoint['parameters'] as $param => $desc): ?>
                                        <tr>
                                            <td><code><?php echo $param; ?></code></td>
                                            <td><?php echo $desc; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </table>
                                    <?php endif; ?>
                                    
                                    <h6 class="mt-3">Example Response:</h6>
                                    <pre><code class="language-json"><?php echo htmlspecialchars($endpoint['response']); ?></code></pre>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Code Examples Tab -->
                    <div class="tab-pane fade" id="examples" role="tabpanel">
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-code mr-2"></i> Code Examples
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="code-switcher">
                                    <button class="code-btn active" onclick="showCode('php')">PHP</button>
                                    <button class="code-btn" onclick="showCode('python')">Python</button>
                                    <button class="code-btn" onclick="showCode('javascript')">JavaScript</button>
                                    <button class="code-btn" onclick="showCode('curl')">cURL</button>
                                </div>
                                
                                <div id="php-code" class="code-block">
                                    <pre><code class="language-php"><?php echo htmlspecialchars($codeExamples['php']); ?></code></pre>
                                </div>
                                
                                <div id="python-code" class="code-block" style="display: none;">
                                    <pre><code class="language-python"><?php echo htmlspecialchars($codeExamples['python']); ?></code></pre>
                                </div>
                                
                                <div id="javascript-code" class="code-block" style="display: none;">
                                    <pre><code class="language-javascript"><?php echo htmlspecialchars($codeExamples['javascript']); ?></code></pre>
                                </div>
                                
                                <div id="curl-code" class="code-block" style="display: none;">
                                    <pre><code class="language-bash"><?php echo htmlspecialchars($codeExamples['curl']); ?></code></pre>
                                </div>
                                
                                <div class="mt-4">
                                    <h5>Rate Limits</h5>
                                    <p class="text-muted">API requests are limited to 60 requests per minute per API key.</p>
                                    
                                    <h5>Error Handling</h5>
                                    <table class="table table-sm">
                                        <tr>
                                            <td><code>400</code></td>
                                            <td>Bad Request - Invalid parameters</td>
                                        </tr>
                                        <tr>
                                            <td><code>401</code></td>
                                            <td>Unauthorized - Invalid API credentials</td>
                                        </tr>
                                        <tr>
                                            <td><code>403</code></td>
                                            <td>Forbidden - API access disabled</td>
                                        </tr>
                                        <tr>
                                            <td><code>404</code></td>
                                            <td>Not Found - Endpoint not found</td>
                                        </tr>
                                        <tr>
                                            <td><code>429</code></td>
                                            <td>Too Many Requests - Rate limit exceeded</td>
                                        </tr>
                                        <tr>
                                            <td><code>500</code></td>
                                            <td>Internal Server Error</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Logs Tab -->
                    <div class="tab-pane fade" id="logs" role="tabpanel">
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-history mr-2"></i> Recent API Requests
                                </h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($apiLogs)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-history"></i>
                                    </div>
                                    <h5>No API Requests Found</h5>
                                    <p class="text-muted">Your API request logs will appear here.</p>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-dashboard" id="logsTable">
                                        <thead>
                                            <tr>
                                                <th>Endpoint</th>
                                                <th>Method</th>
                                                <th>Response</th>
                                                <th>IP Address</th>
                                                <th>Time</th>
                                                <th>Duration</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($apiLogs as $log): ?>
                                            <tr>
                                                <td><code><?php echo htmlspecialchars($log['endpoint']); ?></code></td>
                                                <td>
                                                    <span class="badge badge-secondary"><?php echo $log['method']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?php echo $log['response_code'] >= 200 && $log['response_code'] < 300 ? 'status-success' : 'status-error'; ?>">
                                                        <?php echo $log['response_code']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $log['ip_address']; ?></td>
                                                <td><?php echo date('M d, H:i', strtotime($log['created_at'])); ?></td>
                                                <td><?php echo number_format($log['execution_time'], 2); ?> ms</td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
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
            <strong>Advertiser Panel v3.0</strong>
        </div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- Regenerate Secret Modal -->
<div class="modal fade" id="regenerateModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-sync-alt mr-2 text-warning"></i> Regenerate API Secret
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <p>Are you sure you want to regenerate your API Secret?</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Warning:</strong> This will invalidate your current API Secret. Any applications using the old secret will stop working until updated.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="regenerate_secret" class="btn btn-warning">
                        <i class="fas fa-sync-alt mr-2"></i> Regenerate Secret
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Revoke API Modal -->
<div class="modal fade" id="revokeModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-trash mr-2 text-danger"></i> Revoke API Credentials
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <p>Are you sure you want to revoke your API credentials?</p>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Warning:</strong> This action cannot be undone. All API access will be immediately terminated.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="revoke_api" class="btn btn-danger">
                        <i class="fas fa-trash mr-2"></i> Revoke API Access
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- REQUIRED SCRIPTS -->
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<!-- Bootstrap 4 -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- AdminLTE App -->
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<!-- Prism.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-python.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-bash.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-json.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable for logs
    $('#logsTable').DataTable({
        pageLength: 10,
        lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
        order: [[4, 'desc']],
        responsive: true,
        language: {
            emptyTable: "No API logs found"
        }
    });
    
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
    
    // Auto-dismiss alerts after 5 seconds
    $('.alert').delay(5000).fadeOut('slow');
    
    // Initialize SweetAlert2 Toast
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });
});

// Copy to clipboard function
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        Swal.fire({
            title: 'Copied!',
            text: 'Copied to clipboard',
            icon: 'success',
            timer: 2000,
            showConfirmButton: false
        });
    }).catch(err => {
        // Fallback for older browsers
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        
        Swal.fire({
            title: 'Copied!',
            text: 'Copied to clipboard',
            icon: 'success',
            timer: 2000,
            showConfirmButton: false
        });
    });
}

// Code example switcher
function showCode(lang) {
    $('.code-btn').removeClass('active');
    $(`.code-btn[onclick*="${lang}"]`).addClass('active');
    
    $('.code-block').hide();
    $(`#${lang}-code`).show();
}
</script>

</body>
</html>