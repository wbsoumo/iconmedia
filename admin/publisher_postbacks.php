<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

/* ===============================
   SAVE / UPDATE AFFILIATE POSTBACK
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_postback'])) {
        $affiliateId = (int)$_POST['affiliate_id'];
        $postbackUrl = trim($_POST['postback_url']);
        $status = $_POST['status'] ?? 'active';
        $postbackType = $_POST['postback_type'] ?? 'global';
        $name = trim($_POST['postback_name'] ?? '');

        if (!$affiliateId || empty($postbackUrl)) {
            $error = 'Publisher and Postback URL are required';
        } elseif (!filter_var($postbackUrl, FILTER_VALIDATE_URL)) {
            $error = 'Please enter a valid HTTP/HTTPS Postback URL';
        } else {
            // Check if postback record already exists for this publisher
            $checkStmt = $pdo->prepare("SELECT id FROM affiliate_postbacks WHERE affiliate_id = ? LIMIT 1");
            $checkStmt->execute([$affiliateId]);
            $existingPostback = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingPostback) {
                // Update existing record
                $updateStmt = $pdo->prepare("
                    UPDATE affiliate_postbacks 
                    SET postback_url = :url,
                        status = :status,
                        postback_type = :type,
                        name = :name,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    'url'    => $postbackUrl,
                    'status' => $status,
                    'type'   => $postbackType,
                    'name'   => $name,
                    'id'     => $existingPostback['id']
                ]);
            } else {
                // Insert new record
                $insertStmt = $pdo->prepare("
                    INSERT INTO affiliate_postbacks 
                        (affiliate_id, postback_url, status, postback_type, name, created_at, updated_at)
                    VALUES 
                        (:aid, :url, :status, :type, :name, NOW(), NOW())
                ");
                $insertStmt->execute([
                    'aid'    => $affiliateId,
                    'url'    => $postbackUrl,
                    'status' => $status,
                    'type'   => $postbackType,
                    'name'   => $name
                ]);
            }

            $success = 'Postback URL configuration saved successfully!';
        }
    }
}

/* ===============================
   FETCH AFFILIATES & POSTBACK CONFIGS
================================ */
$affiliatesQuery = $pdo->query("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.status as affiliate_status,
        ap.id as postback_id,
        ap.postback_url,
        ap.status as postback_status,
        ap.postback_type,
        ap.name as postback_name,
        ap.updated_at as postback_updated
    FROM users u
    LEFT JOIN affiliate_postbacks ap ON ap.affiliate_id = u.user_id
    WHERE u.role_id = 3
    ORDER BY u.name ASC
");
$affiliateData = $affiliatesQuery->fetchAll(PDO::FETCH_ASSOC);

// Totals
$stats = [
    'total_publishers' => count($affiliateData),
    'configured' => 0,
    'active' => 0
];

foreach ($affiliateData as $aff) {
    if (!empty($aff['postback_url'])) $stats['configured']++;
    if (($aff['postback_status'] ?? '') === 'active') $stats['active']++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Publisher Postbacks Configuration | Admin Panel</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <!-- Select2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">

    <style>
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

        .macro-tag {
            display: inline-block;
            background: #eef2ff;
            color: #4f46e5;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin: 2px;
            border: 1px solid #c7d2fe;
            cursor: pointer;
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
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link">Dashboard</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="publishers.php" class="nav-link">Publishers</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="publisher_postbacks.php" class="nav-link active">Publisher Postbacks</a></li>
        </ul>
    </nav>

    <!-- Sidebar -->
        <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.4rem;">
                <i class="fas fa-crown mr-2"></i><strong>Admin Console</strong>
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
                        <a href="create_campaign.php" class="nav-link">
                            <i class="nav-icon fas fa-plus-circle"></i>
                            <p>Create Campaign</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="campaign_access.php" class="nav-link">
                            <i class="nav-icon fas fa-key"></i>
                            <p>Offer Approval Rules</p>
                        </a>
                    </li>

                    <li class="nav-header">USER MANAGEMENT</li>
                    <li class="nav-item">
                        <a href="users.php" class="nav-link">
                            <i class="nav-icon fas fa-users"></i>
                            <p>All System Users</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="publishers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-friends"></i>
                            <p>Publishers / Affiliates</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="advertisers.php" class="nav-link">
                            <i class="nav-icon fas fa-briefcase"></i>
                            <p>Advertisers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="account_managers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-tie"></i>
                            <p>Account Managers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="pending_kyc.php" class="nav-link">
                            <i class="nav-icon fas fa-id-card"></i>
                            <p>Pending KYC Approvals</p>
                        </a>
                    </li>

                    <li class="nav-header">ANALYTICS & REPORTS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Campaign Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <p>Affiliate Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_advertisers.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-pie"></i>
                            <p>Advertiser Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_subid.php" class="nav-link">
                            <i class="nav-icon fas fa-list"></i>
                            <p>SubID Performance</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="fraud_dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-shield-alt"></i>
                            <p>Anti-Fraud Security</p>
                        </a>
                    </li>

                                        <li class="nav-header">TOOLS & TESTING</li>
                    <li class="nav-item">
                        <a href="link_tester.php" class="nav-link">
                            <i class="nav-icon fas fa-vial"></i>
                            <p>Link Tester</p>
                        </a>
                    </li>

                    <li class="nav-header">SYSTEM & POSTBACKS</li>
                    <li class="nav-item">
                        <a href="publisher_postbacks.php" class="nav-link active">
                            <i class="nav-icon fas fa-code"></i>
                            <p>Global Postbacks Log</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="settings.php" class="nav-link">
                            <i class="nav-icon fas fa-cogs"></i>
                            <p>System Settings</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user-cog"></i>
                            <p>My Profile</p>
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
                        <h1 class="m-0 font-weight-bold">Publisher Global Postbacks Setup</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="publishers.php">Publishers</a></li>
                            <li class="breadcrumb-item active">Publisher Postbacks</li>
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

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
                <?php endif; ?>

                <!-- Summary Stat Cards (2x2 Mobile Responsive Grid) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-4">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($stats['total_publishers']); ?></div>
                            <div class="stat-label">Total Publishers</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($stats['configured']); ?></div>
                            <div class="stat-label">Postbacks Configured</div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($stats['active']); ?></div>
                            <div class="stat-label">Active Postback Triggers</div>
                        </div>
                    </div>
                </div>

                <!-- Macro Tokens Cheat Sheet -->
                <div class="card card-custom p-4">
                    <h5 class="font-weight-bold text-dark mb-2"><i class="fas fa-code text-primary mr-2"></i>Supported Publisher Postback URL Tokens:</h5>
                    <div>
                        <span class="macro-tag">{click_id}</span>
                        <span class="macro-tag">{payout}</span>
                        <span class="macro-tag">{status}</span>
                        <span class="macro-tag">{offer_id}</span>
                        <span class="macro-tag">{affiliate_id}</span>
                        <span class="macro-tag">{transaction_id}</span>
                        <span class="macro-tag">{sub1}</span>
                        <span class="macro-tag">{sub2}</span>
                    </div>
                </div>

                <!-- Publisher Postback Directory -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-network-wired mr-2"></i>Publisher Postback Configuration Directory</h4>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="postbacksTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>Publisher</th>
                                    <th>Postback Configuration & Target URL</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($affiliateData as $aff): ?>
                                <tr>
                                    <td style="width: 250px;">
                                        <strong class="text-dark d-block"><?php echo htmlspecialchars($aff['name']); ?></strong>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($aff['email']); ?></small>
                                        <small class="badge badge-light border mt-1">ID: #<?php echo $aff['user_id']; ?></small>
                                    </td>
                                    <td>
                                        <form method="post" class="postback-update-form">
                                            <input type="hidden" name="affiliate_id" value="<?php echo $aff['user_id']; ?>">
                                            <input type="hidden" name="save_postback" value="1">
                                            
                                            <div class="form-row">
                                                <div class="col-md-4 mb-2">
                                                    <input type="text" name="postback_name" class="form-control form-control-sm" placeholder="Label / Name (Optional)" value="<?php echo htmlspecialchars($aff['postback_name'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-8 mb-2">
                                                    <input type="url" name="postback_url" class="form-control form-control-sm" placeholder="https://publisher-tracker.com/postback?cid={click_id}&payout={payout}" required value="<?php echo htmlspecialchars($aff['postback_url'] ?? ''); ?>">
                                                </div>
                                            </div>
                                    </td>
                                    <td style="width: 130px;">
                                            <select name="postback_type" class="form-control form-control-sm">
                                                <option value="global" <?php echo ($aff['postback_type'] ?? 'global') === 'global' ? 'selected' : ''; ?>>Global</option>
                                                <option value="hasoffers" <?php echo ($aff['postback_type'] ?? '') === 'hasoffers' ? 'selected' : ''; ?>>HasOffers</option>
                                                <option value="cake" <?php echo ($aff['postback_type'] ?? '') === 'cake' ? 'selected' : ''; ?>>CAKE</option>
                                                <option value="custom" <?php echo ($aff['postback_type'] ?? '') === 'custom' ? 'selected' : ''; ?>>Custom</option>
                                            </select>
                                    </td>
                                    <td style="width: 120px;">
                                            <select name="status" class="form-control form-control-sm font-weight-bold text-<?php echo ($aff['postback_status'] ?? '') === 'active' ? 'success' : 'secondary'; ?>">
                                                <option value="active" <?php echo ($aff['postback_status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                                <option value="inactive" <?php echo ($aff['postback_status'] ?? 'inactive') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                            </select>
                                    </td>
                                    <td style="width: 100px;">
                                            <button type="submit" class="btn btn-sm btn-primary btn-block font-weight-bold shadow-sm">
                                                <i class="fas fa-save mr-1"></i> Save
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

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
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<script>
$(document).ready(function() {
    $('#postbacksTable').DataTable({
        pageLength: 10,
        responsive: true
    });
});
</script>
</body>
</html>