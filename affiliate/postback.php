<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('affiliate');

$affiliateId   = auth_user_id();
$affiliateName = $_SESSION['user_name'] ?? 'Affiliate';
$success = $error = null;

/* -------------------------------------------------
   FETCH GLOBAL POSTBACK
-------------------------------------------------- */
$globalStmt = $pdo->prepare("SELECT * FROM affiliate_postbacks WHERE affiliate_id = ? LIMIT 1");
$globalStmt->execute([$affiliateId]);
$globalPB = $globalStmt->fetch(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   SAVE POSTBACK HANDLER
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = trim($_POST['postback_url'] ?? '');
    $fireStatus = $_POST['fire_status'] ?? 'approved';

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $error = "Please enter a valid HTTP/HTTPS postback URL.";
    } else {
        if ($globalPB) {
            $up = $pdo->prepare("UPDATE affiliate_postbacks SET postback_url = ?, fire_status = ?, status = 'active' WHERE affiliate_id = ?");
            $up->execute([$url, $fireStatus, $affiliateId]);
        } else {
            $ins = $pdo->prepare("INSERT INTO affiliate_postbacks (affiliate_id, postback_url, fire_status, status) VALUES (?, ?, ?, 'active')");
            $ins->execute([$affiliateId, $url, $fireStatus]);
        }
        $success = "Global S2S Postback configuration saved successfully!";
        
        // Reload fresh data
        $globalStmt->execute([$affiliateId]);
        $globalPB = $globalStmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Global S2S Postback Settings | Affiliate Portal</title>
    
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

        .macro-badge {
            background: #f1f5f9;
            color: #0f172a;
            font-family: monospace;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 13px;
            border: 1px solid #cbd5e1;
            margin-right: 5px;
            margin-bottom: 8px;
            display: inline-block;
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
            <li class="nav-item d-none d-sm-inline-block"><a href="postback.php" class="nav-link active">Postback Settings</a></li>
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
                        <a href="offers.php" class="nav-link">
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
                        <a href="postback.php" class="nav-link active">
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
                    <div class="col-sm-6"><h1 class="m-0 font-weight-bold">Global S2S Postback Configuration</h1></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Postback Settings</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">

                <?php if ($success): ?><div class="alert alert-success font-weight-bold"><i class="fas fa-check-circle mr-2"></i><?php echo $success; ?></div><?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger font-weight-bold"><i class="fas fa-exclamation-triangle mr-2"></i><?php echo $error; ?></div><?php endif; ?>

                <div class="row">
                    <!-- Postback Configuration Form -->
                    <div class="col-lg-7">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-satellite-dish mr-2"></i>Configure Postback URL</h4>
                            
                            <form method="post">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold small">Global S2S Postback URL *</label>
                                    <input type="url" name="postback_url" class="form-control form-control-lg" placeholder="https://yourdomain.com/postback?click_id={click_id}&payout={payout}" required value="<?php echo htmlspecialchars($globalPB['postback_url'] ?? ''); ?>">
                                    <small class="text-muted">Enter your server endpoint to receive real-time conversion callbacks.</small>
                                </div>

                                <div class="form-group mb-4">
                                    <label class="font-weight-bold small">Conversion Trigger Condition</label>
                                    <select name="fire_status" class="form-control">
                                        <option value="approved" <?php echo (($globalPB['fire_status'] ?? '') === 'approved') ? 'selected' : ''; ?>>Fire on Approved Conversions Only (Recommended)</option>
                                        <option value="pending" <?php echo (($globalPB['fire_status'] ?? '') === 'pending') ? 'selected' : ''; ?>>Fire on Pending & Approved Conversions</option>
                                    </select>
                                </div>

                                <button type="submit" class="btn btn-primary btn-block font-weight-bold py-2"><i class="fas fa-save mr-1"></i> Save Postback Configuration</button>
                            </form>
                        </div>
                    </div>

                    <!-- Macro Reference Guide -->
                    <div class="col-lg-5">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-info mb-3"><i class="fas fa-code mr-2"></i>Supported Macro Tokens</h4>
                            <p class="text-muted small">Insert these dynamic placeholder tokens into your postback URL to receive live tracking data:</p>

                            <div class="mb-2"><span class="macro-badge">{click_id}</span> <small class="text-dark font-weight-bold">Unique Click Tracking ID</small></div>
                            <div class="mb-2"><span class="macro-badge">{offer_id}</span> <small class="text-dark font-weight-bold">Campaign Offer ID</small></div>
                            <div class="mb-2"><span class="macro-badge">{payout}</span> <small class="text-dark font-weight-bold">Earned Payout Amount ($)</small></div>
                            <div class="mb-2"><span class="macro-badge">{sub1}</span> <small class="text-dark font-weight-bold">Custom SubID 1 Parameter</small></div>
                            <div class="mb-2"><span class="macro-badge">{sub2}</span> <small class="text-dark font-weight-bold">Custom SubID 2 Parameter</small></div>
                            <div class="mb-2"><span class="macro-badge">{transaction_id}</span> <small class="text-dark font-weight-bold">Advertiser Conversion ID</small></div>

                            <hr class="my-3">
                            <h5 class="font-weight-bold text-dark small"><i class="fas fa-lightbulb text-warning mr-1"></i> Example Postback URL:</h5>
                            <code class="d-block p-2 bg-light rounded text-primary small">
                                https://track.tracker.com/postback?cid={click_id}&pay={payout}&sub={sub1}
                            </code>
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
</body>
</html>