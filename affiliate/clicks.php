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
   DATE RANGE & FILTERS
-------------------------------------------------- */
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date']   ?? date('Y-m-d');

$where   = ["c.affiliate_id = ?", "DATE(c.created_at) BETWEEN ? AND ?"];
$params  = [$affiliateId, $startDate, $endDate];

if (!empty($_GET['offer_id'])) {
    $where[] = "c.offer_id = ?";
    $params[] = (int)$_GET['offer_id'];
}
if (!empty($_GET['sub1'])) {
    $where[] = "c.sub1 LIKE ?";
    $params[] = "%" . $_GET['sub1'] . "%";
}
if (!empty($_GET['country'])) {
    $where[] = "c.country = ?";
    $params[] = $_GET['country'];
}

$whereSql = "WHERE " . implode(' AND ', $where);

/* -------------------------------------------------
   REAL-TIME STATS QUERY
-------------------------------------------------- */
$statsSql = "
    SELECT
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT INET6_NTOA(c.ip_address)) AS unique_clicks,
        COUNT(DISTINCT cv.conversion_id) AS converted_clicks,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS total_earned
    FROM clicks c
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    $whereSql
";
$statsStmt = $pdo->prepare($statsSql);
$statsStmt->execute($params);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$totalClicks = (int)($stats['total_clicks'] ?? 0);
$uniqueClicks = (int)($stats['unique_clicks'] ?? 0);
$convertedClicks = (int)($stats['converted_clicks'] ?? 0);
$totalEarned = (float)($stats['total_earned'] ?? 0);
$cr = $totalClicks > 0 ? number_format(($convertedClicks / $totalClicks) * 100, 2) : '0.00';

/* -------------------------------------------------
   CLICK LOGS DATA (DATATABLES ENABLED)
-------------------------------------------------- */
$clicksSql = "
    SELECT 
        c.click_id,
        o.offer_id,
        o.offer_name,
        c.sub1,
        c.sub2,
        INET6_NTOA(c.ip_address) AS full_ip,
        c.country,
        c.device,
        c.referer,
        c.created_at,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS is_converted,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS click_payout
    FROM clicks c
    INNER JOIN offers o ON o.offer_id = c.offer_id
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    $whereSql
    GROUP BY c.click_id
    ORDER BY c.created_at DESC
    LIMIT 1000
";
$clicksStmt = $pdo->prepare($clicksSql);
$clicksStmt->execute($params);
$clickLogs = $clicksStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   FILTER DROPDOWNS
-------------------------------------------------- */
$offers = $pdo->prepare("SELECT DISTINCT o.offer_id, o.offer_name FROM offers o INNER JOIN clicks c ON c.offer_id = o.offer_id WHERE c.affiliate_id = ? ORDER BY o.offer_name");
$offers->execute([$affiliateId]);
$offerList = $offers->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Real-Time Click Logs | Affiliate Portal</title>
    
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
            <li class="nav-item"><a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link">Dashboard</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="clicks.php" class="nav-link active">Click Logs</a></li>
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
                        <a href="clicks.php" class="nav-link active">
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
                    <div class="col-sm-6"><h1 class="m-0 font-weight-bold">Real-Time Traffic Click Logs</h1></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Click Logs</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">

                <!-- 2x2 Mobile Stat Grid -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($totalClicks); ?></div>
                            <div class="stat-label">Total Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning"><?php echo number_format($uniqueClicks); ?></div>
                            <div class="stat-label">Unique Visitors</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo $cr; ?>%</div>
                            <div class="stat-label">Conversion Rate</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success">$<?php echo number_format($totalEarned, 2); ?></div>
                            <div class="stat-label">Earned Payout</div>
                        </div>
                    </div>
                </div>

                <!-- Filter Controls Card -->
                <div class="card card-custom p-4 mb-4">
                    <form method="get">
                        <div class="row">
                            <div class="col-md-3 mb-2">
                                <label class="font-weight-bold small">Start Date</label>
                                <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($startDate); ?>">
                            </div>
                            <div class="col-md-3 mb-2">
                                <label class="font-weight-bold small">End Date</label>
                                <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($endDate); ?>">
                            </div>
                            <div class="col-md-3 mb-2">
                                <label class="font-weight-bold small">Filter Campaign</label>
                                <select name="offer_id" class="form-control">
                                    <option value="">All Campaigns</option>
                                    <?php foreach ($offerList as $off): ?>
                                    <option value="<?php echo $off['offer_id']; ?>" <?php echo (isset($_GET['offer_id']) && $_GET['offer_id'] == $off['offer_id']) ? 'selected' : ''; ?>>
                                        #<?php echo $off['offer_id']; ?> - <?php echo htmlspecialchars($off['offer_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 mb-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary btn-block font-weight-bold shadow-sm mr-1"><i class="fas fa-filter mr-1"></i> Filter</button>
                                <a href="clicks.php" class="btn btn-outline-secondary font-weight-bold">Clear</a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Click Logs DataTables -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-mouse-pointer mr-2"></i>Click Log Records</h4>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="clicksTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>Click ID</th>
                                    <th>Campaign Offer</th>
                                    <th>Sub ID 1</th>
                                    <th>Sub ID 2</th>
                                    <th>IP Address</th>
                                    <th>Country</th>
                                    <th>Device</th>
                                    <th>Timestamp</th>
                                    <th>Conversion</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clickLogs as $log): ?>
                                <tr>
                                    <td><code>#<?php echo $log['click_id']; ?></code></td>
                                    <td>
                                        <a href="offer_view.php?id=<?php echo $log['offer_id']; ?>" class="text-primary font-weight-bold">
                                            <?php echo htmlspecialchars($log['offer_name']); ?>
                                        </a>
                                    </td>
                                    <td><span class="badge badge-light"><?php echo htmlspecialchars($log['sub1'] ?: '-'); ?></span></td>
                                    <td><span class="badge badge-light"><?php echo htmlspecialchars($log['sub2'] ?: '-'); ?></span></td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($log['full_ip'] ?: 'N/A'); ?></small></td>
                                    <td><span class="badge badge-info p-1"><?php echo htmlspecialchars($log['country'] ?: 'Global'); ?></span></td>
                                    <td><small class="text-dark"><?php echo htmlspecialchars($log['device'] ?: 'Desktop/Mobile'); ?></small></td>
                                    <td><small class="text-muted"><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></small></td>
                                    <td>
                                        <?php if ($log['is_converted'] > 0): ?>
                                            <span class="badge badge-success p-2"><i class="fas fa-check mr-1"></i> Converted ($<?php echo number_format($log['click_payout'], 2); ?>)</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary p-2">Click Only</span>
                                        <?php endif; ?>
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
        <div class="float-right d-none d-sm-inline"><strong>Affiliate Portal v3.0</strong></div>
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
    $('#clicksTable').DataTable({
        pageLength: 25,
        responsive: true,
        order: [[7, 'desc']]
    });
});
</script>
</body>
</html>