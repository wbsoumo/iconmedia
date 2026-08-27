<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('advertiser');

$advertiserId = auth_user_id();
$advertiserName = $_SESSION['user_name'] ?? 'Advertiser';

/* ===============================
   FILTER INPUTS
================================ */
$offerId   = isset($_GET['offer_id']) && $_GET['offer_id'] !== 'all' ? (int)$_GET['offer_id'] : null;
$status    = isset($_GET['status']) && in_array($_GET['status'], ['approved', 'pending', 'rejected']) ? $_GET['status'] : null;
$fromDate  = $_GET['from'] ?? date('Y-m-01');
$toDate    = $_GET['to'] ?? date('Y-m-d');
$export    = isset($_GET['export']);

if (!strtotime($fromDate)) $fromDate = date('Y-m-01');
if (!strtotime($toDate)) $toDate = date('Y-m-d');
if (strtotime($fromDate) > strtotime($toDate)) {
    $fromDate = $toDate;
}

/* ===============================
   FETCH OFFERS FOR FILTER DROPDOWN
================================ */
$offersStmt = $pdo->prepare("SELECT offer_id, offer_name FROM offers WHERE advertiser_id = ? ORDER BY offer_name ASC");
$offersStmt->execute([$advertiserId]);
$offersList = $offersStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   MAIN REPORT QUERY
================================ */
$sql = "
    SELECT
        o.offer_id,
        o.offer_name,
        o.status AS offer_status,
        o.payout,
        
        COUNT(DISTINCT c.click_id) AS clicks,
        COUNT(DISTINCT cv.conversion_id) AS conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        
        COALESCE(SUM(cv.revenue), 0) AS total_revenue,
        COALESCE(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS approved_revenue

    FROM offers o
    LEFT JOIN clicks c ON c.offer_id = o.offer_id AND DATE(c.created_at) BETWEEN ? AND ?
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id AND DATE(cv.created_at) BETWEEN ? AND ?
    WHERE o.advertiser_id = ?
";

$params = [$fromDate, $toDate, $fromDate, $toDate, $advertiserId];

if ($offerId) {
    $sql .= " AND o.offer_id = ?";
    $params[] = $offerId;
}

if ($status) {
    $sql .= " AND cv.status = ?";
    $params[] = $status;
}

$sql .= " GROUP BY o.offer_id, o.offer_name, o.status, o.payout ORDER BY approved_revenue DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   HANDLE CSV EXPORT
================================ */
if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="campaign-reports-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Campaign ID', 'Campaign Name', 'Status', 'Payout Rate', 'Clicks', 'Conversions', 'Approved Conv', 'CR%', 'Total Revenue', 'Approved Revenue']);
    
    foreach ($rows as $row) {
        $cr = ($row['clicks'] ?? 0) > 0 ? (($row['conversions'] ?? 0) / $row['clicks']) * 100 : 0;
        fputcsv($output, [
            $row['offer_id'],
            $row['offer_name'],
            $row['offer_status'],
            '$' . number_format($row['payout'], 2),
            number_format($row['clicks'] ?? 0),
            number_format($row['conversions'] ?? 0),
            number_format($row['approved_conversions'] ?? 0),
            number_format($cr, 2) . '%',
            '$' . number_format($row['total_revenue'] ?? 0, 2),
            '$' . number_format($row['approved_revenue'] ?? 0, 2)
        ]);
    }
    fclose($output);
    exit;
}

/* ===============================
   SUMMARY TOTALS
================================ */
$summary = [
    'clicks' => 0,
    'conversions' => 0,
    'approved_conversions' => 0,
    'revenue' => 0,
    'approved_revenue' => 0
];

foreach ($rows as $r) {
    $summary['clicks'] += (int)$r['clicks'];
    $summary['conversions'] += (int)$r['conversions'];
    $summary['approved_conversions'] += (int)$r['approved_conversions'];
    $summary['revenue'] += (float)$r['total_revenue'];
    $summary['approved_revenue'] += (float)$r['approved_revenue'];
}

$overallCR = $summary['clicks'] > 0 ? round(($summary['conversions'] / $summary['clicks']) * 100, 2) : 0.00;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Campaign Reports | Advertiser Panel</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <!-- Flatpickr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
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
                <a href="reports_campaigns.php" class="nav-link active">Campaign Reports</a>
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
                        <a href="reports_campaigns.php" class="nav-link active"><i class="nav-icon fas fa-chart-bar"></i><p>Campaign Reports</p></a>
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
                        <h1 class="m-0 font-weight-bold">Campaign Performance Analytics</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Reports</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">

                <!-- Filter Controls Card -->
                <div class="card card-custom p-4">
                    <form method="get" action="" class="row align-items-end">
                        <div class="col-md-3">
                            <div class="form-group mb-0">
                                <label class="font-weight-bold">Select Campaign Offer</label>
                                <select name="offer_id" class="form-control">
                                    <option value="all">All Campaigns</option>
                                    <?php foreach ($offersList as $of): ?>
                                    <option value="<?php echo $of['offer_id']; ?>" <?php echo $offerId == $of['offer_id'] ? 'selected' : ''; ?>>
                                        #<?php echo $of['offer_id']; ?> - <?php echo htmlspecialchars($of['offer_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group mb-0">
                                <label class="font-weight-bold">From Date</label>
                                <input type="text" name="from" class="form-control flatpickr" value="<?php echo htmlspecialchars($fromDate); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group mb-0">
                                <label class="font-weight-bold">To Date</label>
                                <input type="text" name="to" class="form-control flatpickr" value="<?php echo htmlspecialchars($toDate); ?>">
                            </div>
                        </div>
                        <div class="col-md-3 text-right">
                            <button type="submit" class="btn btn-primary font-weight-bold shadow-sm mr-2">
                                <i class="fas fa-filter mr-1"></i> Apply Filter
                            </button>
                            <a href="?export=1&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>&offer_id=<?php echo urlencode($offerId ?: 'all'); ?>" class="btn btn-success font-weight-bold shadow-sm">
                                <i class="fas fa-download mr-1"></i> CSV
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Stat Boxes Row (2x2 Mobile Grid) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($summary['clicks']); ?></div>
                            <div class="stat-label">Total Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($summary['conversions']); ?></div>
                            <div class="stat-label">Conversions (<?php echo $overallCR; ?>% CR)</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($summary['approved_conversions']); ?></div>
                            <div class="stat-label">Approved Conversions</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning">$<?php echo number_format($summary['approved_revenue'], 2); ?></div>
                            <div class="stat-label">Approved Revenue</div>
                        </div>
                    </div>
                </div>

                <!-- Performance Breakdown Table -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-table mr-2"></i>Detailed Campaign Breakdown</h4>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="reportsDataTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>ID & Campaign Name</th>
                                    <th>Status</th>
                                    <th>Payout Rate</th>
                                    <th>Clicks</th>
                                    <th>Total Conv.</th>
                                    <th>Approved Conv.</th>
                                    <th>CR %</th>
                                    <th>Approved Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): 
                                    $c = (int)$r['clicks'];
                                    $cv = (int)$r['conversions'];
                                    $acv = (int)$r['approved_conversions'];
                                    $cr = $c > 0 ? round(($cv / $c) * 100, 2) : 0.00;
                                ?>
                                <tr>
                                    <td>
                                        <strong class="d-block text-dark font-weight-bold">#<?php echo $r['offer_id']; ?> - <?php echo htmlspecialchars($r['offer_name']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $r['offer_status'] === 'active' ? 'success' : 'secondary'; ?> p-2">
                                            <?php echo ucfirst($r['offer_status']); ?>
                                        </span>
                                    </td>
                                    <td><strong class="text-dark">$<?php echo number_format($r['payout'], 2); ?></strong></td>
                                    <td><strong><?php echo number_format($c); ?></strong></td>
                                    <td><?php echo number_format($cv); ?></td>
                                    <td><strong class="text-success"><?php echo number_format($acv); ?></strong></td>
                                    <td><span class="badge badge-info p-2"><?php echo $cr; ?>%</span></td>
                                    <td><strong class="text-success font-weight-bold" style="font-size: 16px;">$<?php echo number_format($r['approved_revenue'], 2); ?></strong></td>
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
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
$(document).ready(function() {
    $('.flatpickr').flatpickr({ dateFormat: "Y-m-d" });
    
    $('#reportsDataTable').DataTable({
        pageLength: 10,
        responsive: true,
        order: [[7, 'desc']]
    });
});
</script>
</body>
</html>