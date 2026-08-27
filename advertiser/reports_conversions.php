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
$status   = $_GET['status'] ?? 'all';
$offerId  = isset($_GET['offer_id']) && $_GET['offer_id'] !== 'all' && $_GET['offer_id'] !== '' ? (int)$_GET['offer_id'] : 'all';
$export   = isset($_GET['export']);

// Allow custom dates or default to last 30 days
if (isset($_GET['from']) && !empty($_GET['from']) && strtotime($_GET['from'])) {
    $fromDate = $_GET['from'];
} else {
    $fromDate = date('Y-m-d', strtotime('-30 days'));
}

if (isset($_GET['to']) && !empty($_GET['to']) && strtotime($_GET['to'])) {
    $toDate = $_GET['to'];
} else {
    $toDate = date('Y-m-d');
}

if (strtotime($fromDate) > strtotime($toDate)) {
    $fromDate = $toDate;
}

/* ===============================
   BUILD WHERE CLAUSE
================================ */
$where  = ["o.advertiser_id = ?"];
$params = [$advertiserId];

if ($status !== 'all') {
    $where[] = "cv.status = ?";
    $params[] = $status;
}

if ($offerId !== 'all') {
    $where[] = "o.offer_id = ?";
    $params[] = (int)$offerId;
}

$where[] = "DATE(cv.created_at) BETWEEN ? AND ?";
$params[] = $fromDate;
$params[] = $toDate;

$whereSql = 'WHERE ' . implode(' AND ', $where);

/* ===============================
   FETCH ALL MATCHING CONVERSIONS
================================ */
$sql = "
    SELECT
        cv.conversion_id,
        cv.transaction_id,
        cv.revenue,
        cv.payout,
        cv.status AS conversion_status,
        cv.created_at,
        o.offer_id,
        o.offer_name,
        u.name AS affiliate_name,
        c.country,
        c.device,
        c.ip_address
    FROM conversions cv
    INNER JOIN offers o ON o.offer_id = cv.offer_id
    LEFT JOIN clicks c ON c.click_id = cv.click_id
    LEFT JOIN users u ON u.user_id = cv.affiliate_id
    $whereSql
    ORDER BY cv.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$conversions = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   CSV EXPORT
================================ */
if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="conversions-report-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Conversion ID', 'Transaction ID', 'Campaign Offer', 'Affiliate', 'Revenue', 'Payout', 'Status', 'Country', 'Device', 'IP Address', 'Timestamp']);
    
    foreach ($conversions as $row) {
        fputcsv($output, [
            $row['conversion_id'],
            $row['transaction_id'] ?: 'N/A',
            $row['offer_name'],
            $row['affiliate_name'] ?: 'Direct',
            '$' . number_format($row['revenue'], 2),
            '$' . number_format($row['payout'], 2),
            ucfirst($row['conversion_status']),
            $row['country'] ?: 'ALL',
            $row['device'] ?: 'Desktop',
            $row['ip_address'] ?: 'N/A',
            $row['created_at']
        ]);
    }
    fclose($output);
    exit;
}

/* ===============================
   OFFERS LIST FOR FILTER DROPDOWN
================================ */
$offersStmt = $pdo->prepare("SELECT offer_id, offer_name FROM offers WHERE advertiser_id = ? ORDER BY offer_name ASC");
$offersStmt->execute([$advertiserId]);
$offersList = $offersStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   SUMMARY METRICS
================================ */
$summary = [
    'total_conversions' => count($conversions),
    'approved_conversions' => 0,
    'pending_conversions' => 0,
    'rejected_conversions' => 0,
    'total_revenue' => 0,
    'approved_revenue' => 0
];

foreach ($conversions as $cv) {
    $rev = (float)$cv['revenue'];
    $summary['total_revenue'] += $rev;
    
    if ($cv['conversion_status'] === 'approved') {
        $summary['approved_conversions']++;
        $summary['approved_revenue'] += $rev;
    } elseif ($cv['conversion_status'] === 'pending') {
        $summary['pending_conversions']++;
    } elseif ($cv['conversion_status'] === 'rejected') {
        $summary['rejected_conversions']++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conversion Reports | Advertiser Panel</title>
    
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
                <a href="reports_conversions.php" class="nav-link active">Conversion Reports</a>
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
                        <a href="reports_conversions.php" class="nav-link active"><i class="fas fa-exchange-alt nav-icon"></i><p>Conversion Reports</p></a>
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
                        <h1 class="m-0 font-weight-bold">Conversion Audit & Reports</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Conversion Reports</li>
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
                    <form method="get" action="reports_conversions.php" id="conversionsFilterForm">
                        <div class="row align-items-end">
                            <div class="col-md-3 mb-3 mb-md-0">
                                <div class="form-group mb-0">
                                    <label class="font-weight-bold"><i class="fas fa-bullhorn mr-1"></i>Select Campaign</label>
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
                            <div class="col-md-2 mb-3 mb-md-0">
                                <div class="form-group mb-0">
                                    <label class="font-weight-bold"><i class="fas fa-info-circle mr-1"></i>Status</label>
                                    <select name="status" class="form-control">
                                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                        <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-2 mb-3 mb-md-0">
                                <div class="form-group mb-0">
                                    <label class="font-weight-bold"><i class="fas fa-calendar-alt mr-1"></i>From Date</label>
                                    <input type="text" name="from" id="from_date" class="form-control flatpickr" value="<?php echo htmlspecialchars($fromDate); ?>">
                                </div>
                            </div>
                            <div class="col-md-2 mb-3 mb-md-0">
                                <div class="form-group mb-0">
                                    <label class="font-weight-bold"><i class="fas fa-calendar-alt mr-1"></i>To Date</label>
                                    <input type="text" name="to" id="to_date" class="form-control flatpickr" value="<?php echo htmlspecialchars($toDate); ?>">
                                </div>
                            </div>
                            <div class="col-md-3 text-md-right">
                                <button type="submit" class="btn btn-primary font-weight-bold shadow-sm mr-1">
                                    <i class="fas fa-filter mr-1"></i> Filter
                                </button>
                                <a href="reports_conversions.php" class="btn btn-outline-secondary font-weight-bold mr-1" title="Reset Filters">
                                    <i class="fas fa-redo"></i>
                                </a>
                                <a href="?export=1&from=<?php echo urlencode($fromDate); ?>&to=<?php echo urlencode($toDate); ?>&offer_id=<?php echo urlencode($offerId); ?>&status=<?php echo urlencode($status); ?>" class="btn btn-success font-weight-bold shadow-sm">
                                    <i class="fas fa-download mr-1"></i> CSV
                                </a>
                            </div>
                        </div>

                        <!-- Quick Date Presets -->
                        <div class="mt-3 pt-3 border-top d-flex flex-wrap align-items-center gap-2">
                            <span class="font-weight-bold text-muted mr-2 small"><i class="fas fa-clock mr-1"></i>Quick Ranges:</span>
                            <button type="button" class="btn btn-sm btn-light border font-weight-bold mr-1" onclick="setQuickRange('today')">Today</button>
                            <button type="button" class="btn btn-sm btn-light border font-weight-bold mr-1" onclick="setQuickRange('yesterday')">Yesterday</button>
                            <button type="button" class="btn btn-sm btn-light border font-weight-bold mr-1" onclick="setQuickRange('last7')">Last 7 Days</button>
                            <button type="button" class="btn btn-sm btn-light border font-weight-bold mr-1" onclick="setQuickRange('last30')">Last 30 Days</button>
                            <button type="button" class="btn btn-sm btn-light border font-weight-bold" onclick="setQuickRange('thisMonth')">This Month</button>
                        </div>
                    </form>
                </div>

                <!-- Stat Boxes Row (2x2 Mobile Grid) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($summary['total_conversions']); ?></div>
                            <div class="stat-label">Total Conversions</div>
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
                            <div class="stat-number text-warning"><?php echo number_format($summary['pending_conversions']); ?></div>
                            <div class="stat-label">Pending Review</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success">$<?php echo number_format($summary['approved_revenue'], 2); ?></div>
                            <div class="stat-label">Approved Revenue</div>
                        </div>
                    </div>
                </div>

                <!-- Conversions Table -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-exchange-alt mr-2"></i>Conversion Logs Table</h4>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="conversionsDataTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>ID & Txn ID</th>
                                    <th>Campaign Offer</th>
                                    <th>Affiliate / Publisher</th>
                                    <th>Revenue</th>
                                    <th>Payout</th>
                                    <th>Status</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($conversions as $cv): ?>
                                <tr>
                                    <td>
                                        <strong class="d-block text-dark">#<?php echo $cv['conversion_id']; ?></strong>
                                        <small class="text-muted"><?php echo htmlspecialchars($cv['transaction_id'] ?: 'S2S'); ?></small>
                                    </td>
                                    <td><strong class="text-dark">#<?php echo $cv['offer_id']; ?> - <?php echo htmlspecialchars($cv['offer_name']); ?></strong></td>
                                    <td>
                                        <?php if ($cv['affiliate_name']): ?>
                                            <strong class="text-dark d-block"><?php echo htmlspecialchars($cv['affiliate_name']); ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">Direct / Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong class="text-primary">$<?php echo number_format($cv['revenue'], 2); ?></strong></td>
                                    <td><strong class="text-success">$<?php echo number_format($cv['payout'], 2); ?></strong></td>
                                    <td>
                                        <?php 
                                        $bClass = 'success';
                                        if ($cv['conversion_status'] === 'pending') $bClass = 'warning';
                                        elseif ($cv['conversion_status'] === 'rejected') $bClass = 'danger';
                                        ?>
                                        <span class="badge badge-<?php echo $bClass; ?> p-2"><?php echo ucfirst($cv['conversion_status']); ?></span>
                                    </td>
                                    <td><small class="text-muted"><?php echo date('M d, Y H:i:s', strtotime($cv['created_at'])); ?></small></td>
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
let flatpickrFrom, flatpickrTo;

$(document).ready(function() {
    flatpickrFrom = $('#from_date').flatpickr({ dateFormat: "Y-m-d" });
    flatpickrTo = $('#to_date').flatpickr({ dateFormat: "Y-m-d" });

    $('#conversionsDataTable').DataTable({
        pageLength: 10,
        responsive: true,
        order: [[6, 'desc']]
    });
});

function formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function setQuickRange(type) {
    const now = new Date();
    let from = new Date();
    let to = new Date();

    if (type === 'today') {
        from = now;
        to = now;
    } else if (type === 'yesterday') {
        from.setDate(now.getDate() - 1);
        to.setDate(now.getDate() - 1);
    } else if (type === 'last7') {
        from.setDate(now.getDate() - 6);
        to = now;
    } else if (type === 'last30') {
        from.setDate(now.getDate() - 29);
        to = now;
    } else if (type === 'thisMonth') {
        from = new Date(now.getFullYear(), now.getMonth(), 1);
        to = now;
    }

    const fromStr = formatDate(from);
    const toStr = formatDate(to);

    flatpickrFrom.setDate(fromStr);
    flatpickrTo.setDate(toStr);
    $('#from_date').val(fromStr);
    $('#to_date').val(toStr);

    $('#conversionsFilterForm').submit();
}
</script>
</body>
</html>