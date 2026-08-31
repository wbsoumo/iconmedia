<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('affiliate');

$affiliateId   = auth_user_id();
$affiliateName = $_SESSION['user_name'] ?? 'Affiliate';

/* -------------------------------------------------
   FETCH APPROVED & PUBLIC CAMPAIGN OFFERS
-------------------------------------------------- */
$offersStmt = $pdo->prepare("
    SELECT o.offer_id, o.offer_name, o.category, o.payout, o.currency, o.tracking_url
    FROM offers o
    LEFT JOIN affiliate_offer_approval aoa 
      ON aoa.offer_id = o.offer_id 
     AND aoa.affiliate_id = ?
    WHERE o.status = 'approved'
      AND (LOWER(o.visibility) = 'public' OR aoa.status = 'approved')
    ORDER BY o.offer_name ASC
");
$offersStmt->execute([$affiliateId]);
$offers = $offersStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   GENERATE LINK HANDLER
-------------------------------------------------- */
$generatedUrl = null;
$selectedOfferId = $_GET['offer_id'] ?? ($offers[0]['offer_id'] ?? '');
$sub1 = $_GET['sub1'] ?? '';
$sub2 = $_GET['sub2'] ?? '';
$sub3 = $_GET['sub3'] ?? '';
$sub4 = $_GET['sub4'] ?? '';
$sub5 = $_GET['sub5'] ?? '';

if ($selectedOfferId) {
    $baseUrl = "https://iconmedianetwork.in/click.php";
    $params = [
        'offer_id' => $selectedOfferId,
        'aff_id'   => $affiliateId
    ];
    if ($sub1 !== '') $params['sub1'] = $sub1;
    if ($sub2 !== '') $params['sub2'] = $sub2;
    if ($sub3 !== '') $params['sub3'] = $sub3;
    if ($sub4 !== '') $params['sub4'] = $sub4;
    if ($sub5 !== '') $params['sub5'] = $sub5;

    $generatedUrl = $baseUrl . '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tracking Link Generator | Affiliate Portal</title>
    
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
            <li class="nav-item d-none d-sm-inline-block"><a href="link-builder.php" class="nav-link active">Link Builder</a></li>
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
                        <a href="link-builder.php" class="nav-link active">
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
                    <div class="col-sm-6"><h1 class="m-0 font-weight-bold">Tracking Link Generator</h1></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Link Builder</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">

                <div class="row">
                    <!-- Builder Form -->
                    <div class="col-lg-7">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-link mr-2"></i>Configure Tracking Link</h4>
                            
                            <form method="get">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold small">Select Campaign Offer *</label>
                                    <select name="offer_id" class="form-control form-control-lg" required onchange="this.form.submit()">
                                        <option value="">-- Choose Approved Campaign --</option>
                                        <?php foreach ($offers as $off): ?>
                                        <option value="<?php echo $off['offer_id']; ?>" <?php echo ($selectedOfferId == $off['offer_id']) ? 'selected' : ''; ?>>
                                            #<?php echo $off['offer_id']; ?> - <?php echo htmlspecialchars($off['offer_name']); ?> ($<?php echo number_format($off['payout'], 2); ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 form-group mb-3">
                                        <label class="font-weight-bold small">Sub ID 1 (Traffic Source)</label>
                                        <input type="text" name="sub1" class="form-control" placeholder="e.g. facebook, google" value="<?php echo htmlspecialchars($sub1); ?>">
                                    </div>
                                    <div class="col-md-6 form-group mb-3">
                                        <label class="font-weight-bold small">Sub ID 2 (Campaign / Angle)</label>
                                        <input type="text" name="sub2" class="form-control" placeholder="e.g. summer_sale" value="<?php echo htmlspecialchars($sub2); ?>">
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-4 form-group mb-3">
                                        <label class="font-weight-bold small">Sub ID 3 (Creative/Placement)</label>
                                        <input type="text" name="sub3" class="form-control" placeholder="e.g. banner_300x250" value="<?php echo htmlspecialchars($sub3); ?>">
                                    </div>
                                    <div class="col-md-4 form-group mb-3">
                                        <label class="font-weight-bold small">Sub ID 4 (Keyword / ID)</label>
                                        <input type="text" name="sub4" class="form-control" placeholder="e.g. kw_crypto" value="<?php echo htmlspecialchars($sub4); ?>">
                                    </div>
                                    <div class="col-md-4 form-group mb-3">
                                        <label class="font-weight-bold small">Sub ID 5 (Custom Param)</label>
                                        <input type="text" name="sub5" class="form-control" placeholder="e.g. click_token" value="<?php echo htmlspecialchars($sub5); ?>">
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary btn-block font-weight-bold py-2"><i class="fas fa-magic mr-1"></i> Generate Tracking Link</button>
                            </form>
                        </div>
                    </div>

                    <!-- Generated Output Card -->
                    <div class="col-lg-5">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-success mb-3"><i class="fas fa-copy mr-2"></i>Generated Tracking Link</h4>
                            
                            <?php if ($generatedUrl): ?>
                                <label class="font-weight-bold small text-muted">Your Unique S2S Tracking URL:</label>
                                <div class="url-box mb-3" id="trackingUrlText"><?php echo htmlspecialchars($generatedUrl); ?></div>

                                <button class="btn btn-success btn-block font-weight-bold py-2 mb-2" onclick="copyToClipboard()">
                                    <i class="fas fa-clipboard mr-1"></i> Copy Link to Clipboard
                                </button>
                                <a href="<?php echo htmlspecialchars($generatedUrl); ?>" target="_blank" class="btn btn-outline-primary btn-block font-weight-bold">
                                    <i class="fas fa-external-link-alt mr-1"></i> Test Link Redirect
                                </a>
                            <?php else: ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-link fa-3x mb-3 text-secondary"></i>
                                    <h5>Select a campaign offer on the left to generate your unique tracking URL.</h5>
                                </div>
                            <?php endif; ?>
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
function copyToClipboard() {
    var text = document.getElementById('trackingUrlText').innerText;
    navigator.clipboard.writeText(text).then(function() {
        alert('Tracking URL copied to clipboard!');
    });
}
</script>
</body>
</html>