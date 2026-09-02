<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

require_role('affiliate');

$affiliateId   = auth_user_id();
$affiliateName = $_SESSION['user_name'] ?? 'Affiliate';

/* -------------------------------------------------
   FILTERS
-------------------------------------------------- */
$where  = [];
$params = ['aid' => $affiliateId];

$where[] = "LOWER(o.status) IN ('approved', 'active', 'live')";

if (!empty($_GET['search'])) {
    $where[] = "(o.offer_name LIKE :search OR o.offer_description LIKE :search)";
    $params['search'] = '%' . trim($_GET['search']) . '%';
}

if (!empty($_GET['category'])) {
    $where[] = "o.category = :category";
    $params['category'] = $_GET['category'];
}

if (isset($_GET['approval_status']) && $_GET['approval_status'] !== '') {
    if ($_GET['approval_status'] === 'not_applied') {
        $where[] = "a.offer_id IS NULL";
    } else {
        $where[] = "a.status = :approval_status";
        $params['approval_status'] = $_GET['approval_status'];
    }
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

/* -------------------------------------------------
   MAIN QUERY
-------------------------------------------------- */
$sql = "
SELECT 
    o.offer_id,
    o.offer_name,
    o.offer_description,
    o.payout,
    o.currency,
    o.category,
    o.status AS offer_status,
    o.created_at,
    o.preview_url,
    a.status AS approval_status,
    (
        SELECT COUNT(*)
        FROM clicks c
        WHERE c.offer_id = o.offer_id AND c.affiliate_id = :aid
    ) AS total_clicks,
    (
        SELECT COUNT(DISTINCT cv.conversion_id)
        FROM conversions cv
        INNER JOIN clicks c ON c.click_id = cv.click_id
        WHERE c.offer_id = o.offer_id AND cv.affiliate_id = :aid AND cv.status = 'approved'
    ) AS approved_conversions,
    (
        SELECT IFNULL(SUM(cv.payout), 0)
        FROM conversions cv
        INNER JOIN clicks c ON c.click_id = cv.click_id
        WHERE c.offer_id = o.offer_id AND cv.affiliate_id = :aid AND cv.status = 'approved'
    ) AS total_earnings
FROM offers o
LEFT JOIN affiliate_offer_approval a ON a.offer_id = o.offer_id AND a.affiliate_id = :aid
$whereSql
GROUP BY o.offer_id
ORDER BY o.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totals
$totalOffers = count($offers);
$totalEarnings = 0;
$totalApprovedConversions = 0;
$totalClicks = 0;

foreach ($offers as $o) {
    $totalEarnings += (float)$o['total_earnings'];
    $totalApprovedConversions += (int)$o['approved_conversions'];
    $totalClicks += (int)$o['total_clicks'];
}

$categoriesStmt = $pdo->query("SELECT DISTINCT category FROM offers WHERE status = 'approved' AND category IS NOT NULL AND category != '' ORDER BY category ASC");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Campaigns & Offers | Affiliate Hub</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
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
                <a href="offers.php" class="nav-link active">My Campaigns</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <a class="nav-link" href="#" id="darkModeToggle"><i class="fas fa-moon"></i></a>
            </li>
        </ul>
    </nav>

    <!-- Sidebar -->
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
                    <div class="col-sm-6">
                        <h1 class="m-0 font-weight-bold">My Campaigns & Offer Catalog</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">My Campaigns</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">

                <!-- Summary Stat Cards Row (2x2 Mobile Responsive Grid) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($totalOffers); ?></div>
                            <div class="stat-label">Available Offers</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($totalClicks); ?></div>
                            <div class="stat-label">Total Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning"><?php echo number_format($totalApprovedConversions); ?></div>
                            <div class="stat-label">Conversions</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success">$<?php echo number_format($totalEarnings, 2); ?></div>
                            <div class="stat-label">Total Earnings</div>
                        </div>
                    </div>
                </div>

                <!-- Offers Catalog Card -->
                <div class="card card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="font-weight-bold text-primary mb-0"><i class="fas fa-bullhorn mr-2"></i>Performance Campaign Offers</h4>
                        <a href="link-builder.php" class="btn btn-primary font-weight-bold shadow-sm">
                            <i class="fas fa-link mr-1"></i> Open Link Builder
                        </a>
                    </div>

                    <?php if (empty($offers)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-gift fa-3x text-muted mb-3"></i>
                        <h5 class="text-dark font-weight-bold">No Active Offers Found</h5>
                        <p class="text-muted">Check back soon for new high-converting advertiser campaigns.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="affiliateOffersTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>ID & Campaign Title</th>
                                    <th>Category</th>
                                    <th>Payout Rate</th>
                                    <th>Status</th>
                                    <th>Clicks</th>
                                    <th>Conversions</th>
                                    <th>My Earnings</th>
                                    <th>Actions & Link</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($offers as $of): 
                                    $clicks = (int)$of['total_clicks'];
                                    $convs = (int)$of['approved_conversions'];
                                    $earnings = (float)$of['total_earnings'];
                                    $isApproved = ($of['approval_status'] === 'approved');
                                    $isPending = ($of['approval_status'] === 'pending');
                                ?>
                                <tr>
                                    <td>
                                        <strong class="d-block text-dark font-weight-bold">#<?php echo $of['offer_id']; ?> - <?php echo htmlspecialchars($of['offer_name']); ?></strong>
                                        <small class="text-muted"><?php echo htmlspecialchars(substr($of['offer_description'] ?? '', 0, 50)); ?>...</small>
                                    </td>
                                    <td>
                                        <span class="badge badge-info p-2"><?php echo htmlspecialchars($of['category'] ?: 'General'); ?></span>
                                    </td>
                                    <td>
                                        <strong class="text-success font-weight-bold" style="font-size: 16px;">$<?php echo number_format($of['payout'], 2); ?></strong>
                                        <small class="d-block text-muted"><?php echo $of['currency'] ?: 'USD'; ?></small>
                                    </td>
                                    <td>
                                        <?php if ($isApproved): ?>
                                            <span class="badge badge-success p-2"><i class="fas fa-check-circle mr-1"></i> Approved</span>
                                        <?php elseif ($isPending): ?>
                                            <span class="badge badge-warning p-2"><i class="fas fa-clock mr-1"></i> Pending</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary p-2">Auto / Open</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo number_format($clicks); ?></strong></td>
                                    <td><strong><?php echo number_format($convs); ?></strong></td>
                                    <td><strong class="text-success font-weight-bold">$<?php echo number_format($earnings, 2); ?></strong></td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="offer_view.php?id=<?php echo $of['offer_id']; ?>" class="btn btn-sm btn-primary font-weight-bold" title="Get Tracking Link">
                                                <i class="fas fa-link mr-1"></i> Get Link
                                            </a>
                                            <?php if ($of['preview_url']): ?>
                                            <a href="<?php echo htmlspecialchars($of['preview_url']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Preview Landing Page">
                                                <i class="fas fa-external-link-alt"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
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

    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline"><strong>Affiliate Panel v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    $('#affiliateOffersTable').DataTable({
        pageLength: 10,
        responsive: true,
        order: [[2, 'desc']]
    });
});
</script>
</body>
</html>