<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

// Get advertiser ID from URL
$advertiserId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$advertiserId) {
    header('Location: advertisers.php?error=Invalid advertiser ID');
    exit;
}

/* ===============================
   HANDLE FORM SUBMIT
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $mobile   = trim($_POST['mobile'] ?? '');
    $company  = trim($_POST['company'] ?? '');
    $status   = $_POST['status'] ?? 'active';
    $balance  = (float)($_POST['balance'] ?? 0);

    if ($name === '' || $email === '') {
        $error = 'Name and Email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE users SET
                    name = :name,
                    email = :email,
                    mobile = :mobile,
                    company = :company,
                    status = :status,
                    balance = :balance,
                    updated_at = NOW()
                WHERE user_id = :id AND role_id = 4
            ");
            $stmt->execute([
                'name'    => $name,
                'email'   => $email,
                'mobile'  => $mobile,
                'company' => $company,
                'status'  => $status,
                'balance' => $balance,
                'id'      => $advertiserId
            ]);
            $success = "Advertiser details updated successfully!";
        } catch (PDOException $e) {
            $error = "Error updating advertiser: " . $e->getMessage();
        }
    }
}

/* ===============================
   FETCH ADVERTISER DATA & STATS
================================ */
$stmt = $pdo->prepare("
    SELECT 
        u.*,
        COUNT(DISTINCT o.offer_id) AS total_offers,
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS gross_revenue
    FROM users u
    LEFT JOIN offers o ON o.advertiser_id = u.user_id
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE u.user_id = :user_id AND u.role_id = 4
    GROUP BY u.user_id
");
$stmt->execute(['user_id' => $advertiserId]);
$advertiser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$advertiser) {
    header('Location: advertisers.php?error=Advertiser account not found');
    exit;
}

/* ===============================
   FETCH ADVERTISER CAMPAIGNS
================================ */
$offersStmt = $pdo->prepare("
    SELECT 
        o.offer_id,
        o.offer_name,
        o.category,
        o.payout,
        o.revenue,
        o.status,
        COUNT(DISTINCT c.click_id) AS clicks,
        COUNT(DISTINCT cv.conversion_id) AS conversions
    FROM offers o
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE o.advertiser_id = ?
    GROUP BY o.offer_id
    ORDER BY o.created_at DESC
");
$offersStmt->execute([$advertiserId]);
$advertiserOffers = $offersStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Advertiser Account | Admin Panel</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">

    <style>
        .card-custom {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 18px rgba(0,0,0,0.06);
            margin-bottom: 25px;
            background: #ffffff;
        }

        .hero-banner {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 12px;
            padding: 30px;
            color: #ffffff;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(16, 185, 129, 0.2);
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
            <li class="nav-item d-none d-sm-inline-block"><a href="advertisers.php" class="nav-link">Advertisers</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="advertiser_edit.php?id=<?php echo $advertiserId; ?>" class="nav-link active">Edit Advertiser</a></li>
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
                        <a href="advertisers.php" class="nav-link active">
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

                    <li class="nav-header">SYSTEM & POSTBACKS</li>
                    <li class="nav-item">
                        <a href="publisher_postbacks.php" class="nav-link">
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
                        <h1 class="m-0 font-weight-bold">Edit Advertiser Account</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="advertisers.php">Advertisers</a></li>
                            <li class="breadcrumb-item active">Advertiser #<?php echo $advertiserId; ?></li>
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

                <!-- Hero Banner -->
                <div class="hero-banner">
                    <div class="d-flex justify-content-between align-items-start flex-wrap">
                        <div class="mb-3">
                            <span class="badge badge-light text-success font-weight-bold p-2 mb-2">
                                <i class="fas fa-briefcase mr-1"></i> Advertiser ID: #<?php echo $advertiser['user_id']; ?>
                            </span>
                            <h1 class="font-weight-bold mb-1"><?php echo htmlspecialchars($advertiser['name']); ?></h1>
                            <p class="mb-2 text-white-50"><i class="fas fa-envelope mr-1"></i> <?php echo htmlspecialchars($advertiser['email']); ?> | <i class="fas fa-building mr-1"></i> <?php echo htmlspecialchars($advertiser['company'] ?: 'Individual Brand'); ?></p>
                            <div>
                                <span class="badge badge-<?php echo $advertiser['status'] === 'active' ? 'light' : 'warning'; ?> p-2 mr-2">Status: <?php echo ucfirst($advertiser['status']); ?></span>
                                <span class="badge badge-light p-2">Joined: <?php echo date('M d, Y', strtotime($advertiser['created_at'])); ?></span>
                            </div>
                        </div>
                        <div>
                            <a href="advertisers.php" class="btn btn-light font-weight-bold"><i class="fas fa-arrow-left mr-1"></i> Back to Advertisers</a>
                        </div>
                    </div>
                </div>

                <!-- Summary Stat Cards (2x2 Mobile Responsive Grid) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($advertiser['total_offers']); ?></div>
                            <div class="stat-label">Total Campaigns</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($advertiser['total_clicks']); ?></div>
                            <div class="stat-label">Total Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning"><?php echo number_format($advertiser['total_conversions']); ?></div>
                            <div class="stat-label">Total Conversions</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success">$<?php echo number_format($advertiser['gross_revenue'], 2); ?></div>
                            <div class="stat-label">Gross Revenue</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Edit Form Left -->
                    <div class="col-md-5">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-edit mr-2"></i>Account Details</h4>
                            <form method="post">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Advertiser Name <span class="text-danger">*</span></label>
                                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($advertiser['name']); ?>" required>
                                </div>
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Email Address <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($advertiser['email']); ?>" required>
                                </div>
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Mobile Phone</label>
                                    <input type="text" name="mobile" class="form-control" value="<?php echo htmlspecialchars($advertiser['mobile'] ?? ''); ?>">
                                </div>
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Company / Brand Name</label>
                                    <input type="text" name="company" class="form-control" value="<?php echo htmlspecialchars($advertiser['company'] ?? ''); ?>">
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label class="font-weight-bold">Account Status</label>
                                            <select name="status" class="form-control">
                                                <option value="active" <?php echo $advertiser['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                                <option value="inactive" <?php echo $advertiser['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                <option value="pending" <?php echo $advertiser['status'] === 'pending' ? 'selected' : ''; ?>>Pending Review</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-4">
                                            <label class="font-weight-bold">Deposit Balance ($)</label>
                                            <input type="number" step="0.01" name="balance" class="form-control" value="<?php echo number_format((float)($advertiser['balance'] ?? 0), 2, '.', ''); ?>">
                                        </div>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-success btn-lg btn-block font-weight-bold shadow-sm">
                                    <i class="fas fa-save mr-2"></i> Save Advertiser Profile
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Advertiser Offers Right -->
                    <div class="col-md-7">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-bullhorn mr-2"></i>Advertiser Campaigns Catalog</h4>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle" id="advertiserOffersTable">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Campaign Offer</th>
                                            <th>Payout</th>
                                            <th>Status</th>
                                            <th>Conversions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($advertiserOffers as $ao): ?>
                                        <tr>
                                            <td>
                                                <a href="offer_details.php?id=<?php echo $ao['offer_id']; ?>" class="text-primary font-weight-bold">
                                                    #<?php echo $ao['offer_id']; ?> - <?php echo htmlspecialchars($ao['offer_name']); ?>
                                                </a>
                                            </td>
                                            <td><strong class="text-success">$<?php echo number_format($ao['payout'], 2); ?></strong></td>
                                            <td><span class="badge badge-<?php echo ($ao['status'] === 'active' || $ao['status'] === 'approved') ? 'success' : 'secondary'; ?> p-2"><?php echo ucfirst($ao['status']); ?></span></td>
                                            <td><strong><?php echo number_format($ao['conversions']); ?></strong></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
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
    $('#advertiserOffersTable').DataTable({
        pageLength: 10,
        responsive: true
    });
});
</script>
</body>
</html>