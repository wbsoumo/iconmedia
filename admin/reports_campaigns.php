<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['admin', 'manager']);

$adminName = $_SESSION['user_name'] ?? 'Admin';

/* ===============================
   FILTER PARAMETERS
================================ */
$search = $_GET['search'] ?? '';
$offerFilter = $_GET['offer_id'] ?? 'all';
$advertiserFilter = $_GET['advertiser_id'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
$sortBy = $_GET['sort'] ?? 'date';
$sortOrder = $_GET['order'] ?? 'desc';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 50;

// Validate dates
if (!strtotime($dateFrom)) $dateFrom = date('Y-m-01');
if (!strtotime($dateTo)) $dateTo = date('Y-m-d');

/* ===============================
   EXPORT FUNCTION
================================ */
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    exportToExcel($pdo, $search, $offerFilter, $advertiserFilter, $statusFilter, $dateFrom, $dateTo, $sortBy, $sortOrder);
    exit;
}

function exportToExcel($pdo, $search, $offerFilter, $advertiserFilter, $statusFilter, $dateFrom, $dateTo, $sortBy, $sortOrder) {
    // Build WHERE clause
    $where = ['1=1'];
    $params = [];

    if ($search) {
        $where[] = '(offer_name LIKE ? OR offer_description LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($offerFilter !== 'all') {
        $where[] = 'offer_id = ?';
        $params[] = (int)$offerFilter;
    }

    if ($advertiserFilter !== 'all') {
        $where[] = 'advertiser_id = ?';
        $params[] = (int)$advertiserFilter;
    }

    if ($statusFilter !== 'all') {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Get all offers
    $sql = "SELECT offer_id, offer_name, status, advertiser_id FROM offers $whereSql ORDER BY offer_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Set headers for Excel
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="campaign_report_' . date('Y-m-d') . '.xls"');

    echo '<html><head><meta charset="utf-8">';
    echo '<style>td,th{border:1px solid #000;padding:5px} th{background:#f0f0f0}</style>';
    echo '</head><body><table>';
    
    // Headers
    echo '<tr>';
    echo '<th>Offer ID</th><th>Offer Name</th><th>Status</th><th>Clicks</th>';
    echo '<th>Conversions</th><th>Approved</th><th>Pending</th><th>Rejected</th>';
    echo '<th>Revenue</th><th>Payout</th><th>Profit</th>';
    echo '</tr>';

    $totalClicks = $totalConversions = $totalRevenue = $totalPayout = 0;

    foreach ($offers as $offer) {
        // Get clicks
        $clickStmt = $pdo->prepare("SELECT COUNT(*) FROM clicks WHERE offer_id = ? AND DATE(created_at) BETWEEN ? AND ?");
        $clickStmt->execute([$offer['offer_id'], $dateFrom, $dateTo]);
        $clicks = $clickStmt->fetchColumn();

        // Get conversions
        $convStmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'approved' THEN revenue ELSE 0 END) as revenue,
                SUM(CASE WHEN status = 'approved' THEN payout ELSE 0 END) as payout
            FROM conversions 
            WHERE offer_id = ? AND DATE(created_at) BETWEEN ? AND ?
        ");
        $convStmt->execute([$offer['offer_id'], $dateFrom, $dateTo]);
        $conv = $convStmt->fetch(PDO::FETCH_ASSOC);

        $totalClicks += $clicks;
        $totalConversions += $conv['total'];
        $totalRevenue += $conv['revenue'];
        $totalPayout += $conv['payout'];
        $profit = $conv['revenue'] - $conv['payout'];

        echo '<tr>';
        echo '<td>' . $offer['offer_id'] . '</td>';
        echo '<td>' . htmlspecialchars($offer['offer_name']) . '</td>';
        echo '<td>' . ucfirst($offer['status']) . '</td>';
        echo '<td>' . number_format($clicks) . '</td>';
        echo '<td>' . number_format($conv['total']) . '</td>';
        echo '<td>' . number_format($conv['approved']) . '</td>';
        echo '<td>' . number_format($conv['pending']) . '</td>';
        echo '<td>' . number_format($conv['rejected']) . '</td>';
        echo '<td>$' . number_format($conv['revenue'], 2) . '</td>';
        echo '<td>$' . number_format($conv['payout'], 2) . '</td>';
        echo '<td>$' . number_format($profit, 2) . '</td>';
        echo '</tr>';
    }

    // Totals
    echo '<tr style="font-weight:bold;background:#e0e0e0">';
    echo '<td colspan="3">TOTAL</td>';
    echo '<td>' . number_format($totalClicks) . '</td>';
    echo '<td>' . number_format($totalConversions) . '</td>';
    echo '<td colspan="2"></td>';
    echo '<td></td>';
    echo '<td>$' . number_format($totalRevenue, 2) . '</td>';
    echo '<td>$' . number_format($totalPayout, 2) . '</td>';
    echo '<td>$' . number_format($totalRevenue - $totalPayout, 2) . '</td>';
    echo '</tr>';

    echo '</table></body></html>';
    exit;
}

/* ===============================
   FETCH DROPDOWN DATA
================================ */
$offers = $pdo->query("SELECT offer_id, offer_name FROM offers ORDER BY offer_name")->fetchAll();
$advertisers = $pdo->query("SELECT user_id, name FROM users WHERE role_id = 4 ORDER BY name")->fetchAll();

/* ===============================
   BUILD FILTER CONDITIONS
================================ */
$where = ['1=1'];
$params = [];

if ($search) {
    $where[] = '(o.offer_name LIKE ? OR o.offer_description LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($offerFilter !== 'all') {
    $where[] = 'o.offer_id = ?';
    $params[] = (int)$offerFilter;
}

if ($advertiserFilter !== 'all') {
    $where[] = 'o.advertiser_id = ?';
    $params[] = (int)$advertiserFilter;
}

if ($statusFilter !== 'all') {
    $where[] = 'o.status = ?';
    $params[] = $statusFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ===============================
   GET TOTAL COUNT
================================ */
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM offers o $whereSql");
$countStmt->execute($params);
$totalOffers = $countStmt->fetchColumn();
$totalPages = ceil($totalOffers / $perPage);
$offset = ($page - 1) * $perPage;

/* ===============================
   GET OFFERS FOR CURRENT PAGE
================================ */
$sql = "SELECT offer_id, offer_name, status, advertiser_id, created_at as offer_created 
        FROM offers o 
        $whereSql 
        ORDER BY 
            CASE WHEN ? = 'date' AND ? = 'desc' THEN o.created_at END DESC,
            CASE WHEN ? = 'date' AND ? = 'asc' THEN o.created_at END ASC,
            CASE WHEN ? = 'name' AND ? = 'asc' THEN o.offer_name END ASC,
            CASE WHEN ? = 'name' AND ? = 'desc' THEN o.offer_name END DESC
        LIMIT ?, ?";

$stmt = $pdo->prepare($sql);
$paramIndex = 1;
foreach ($params as $param) {
    $stmt->bindValue($paramIndex++, $param);
}
$stmt->bindValue($paramIndex++, $sortBy);
$stmt->bindValue($paramIndex++, $sortOrder);
$stmt->bindValue($paramIndex++, $sortBy);
$stmt->bindValue($paramIndex++, $sortOrder);
$stmt->bindValue($paramIndex++, $sortBy);
$stmt->bindValue($paramIndex++, $sortOrder);
$stmt->bindValue($paramIndex++, $sortBy);
$stmt->bindValue($paramIndex++, $sortOrder);
$stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
$stmt->bindValue($paramIndex++, $perPage, PDO::PARAM_INT);
$stmt->execute();
$offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   GET STATS FOR EACH OFFER
================================ */
$reportData = [];
$grandTotalClicks = $grandTotalConversions = $grandTotalRevenue = $grandTotalPayout = 0;

foreach ($offers as $offer) {
    // Get clicks
    $clickStmt = $pdo->prepare("SELECT COUNT(*) FROM clicks WHERE offer_id = ? AND DATE(created_at) BETWEEN ? AND ?");
    $clickStmt->execute([$offer['offer_id'], $dateFrom, $dateTo]);
    $filteredClicks = $clickStmt->fetchColumn();
    
    $clickStmt = $pdo->prepare("SELECT COUNT(*) FROM clicks WHERE offer_id = ?");
    $clickStmt->execute([$offer['offer_id']]);
    $totalClicks = $clickStmt->fetchColumn();

    // Get conversions
    $convStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_conversions,
            SUM(CASE WHEN DATE(created_at) BETWEEN ? AND ? THEN 1 ELSE 0 END) as filtered_conversions,
            SUM(CASE WHEN status = 'approved' AND DATE(created_at) BETWEEN ? AND ? THEN 1 ELSE 0 END) as approved_conversions,
            SUM(CASE WHEN status = 'pending' AND DATE(created_at) BETWEEN ? AND ? THEN 1 ELSE 0 END) as pending_conversions,
            SUM(CASE WHEN status = 'rejected' AND DATE(created_at) BETWEEN ? AND ? THEN 1 ELSE 0 END) as rejected_conversions,
            SUM(CASE WHEN status = 'approved' AND DATE(created_at) BETWEEN ? AND ? THEN revenue ELSE 0 END) as total_revenue,
            SUM(CASE WHEN status = 'approved' AND DATE(created_at) BETWEEN ? AND ? THEN payout ELSE 0 END) as total_payout
        FROM conversions 
        WHERE offer_id = ?
    ");
    $convStmt->execute([
        $dateFrom, $dateTo,
        $dateFrom, $dateTo,
        $dateFrom, $dateTo,
        $dateFrom, $dateTo,
        $dateFrom, $dateTo,
        $dateFrom, $dateTo,
        $offer['offer_id']
    ]);
    $conv = $convStmt->fetch(PDO::FETCH_ASSOC);

    // Get advertiser info
    $advStmt = $pdo->prepare("SELECT name, company FROM users WHERE user_id = ?");
    $advStmt->execute([$offer['advertiser_id']]);
    $advertiser = $advStmt->fetch(PDO::FETCH_ASSOC);

    $conversionRate = $filteredClicks > 0 ? ($conv['approved_conversions'] / $filteredClicks * 100) : 0;
    $profit = $conv['total_revenue'] - $conv['total_payout'];

    $grandTotalClicks += $filteredClicks;
    $grandTotalConversions += $conv['filtered_conversions'];
    $grandTotalRevenue += $conv['total_revenue'];
    $grandTotalPayout += $conv['total_payout'];

    $reportData[] = [
        'offer_id' => $offer['offer_id'],
        'offer_name' => $offer['offer_name'],
        'status' => $offer['status'],
        'offer_created' => $offer['offer_created'],
        'advertiser_name' => $advertiser['name'] ?? 'Unknown',
        'advertiser_company' => $advertiser['company'] ?? '',
        'total_clicks' => $totalClicks,
        'filtered_clicks' => $filteredClicks,
        'total_conversions' => $conv['total_conversions'] ?? 0,
        'filtered_conversions' => $conv['filtered_conversions'] ?? 0,
        'approved_conversions' => $conv['approved_conversions'] ?? 0,
        'pending_conversions' => $conv['pending_conversions'] ?? 0,
        'rejected_conversions' => $conv['rejected_conversions'] ?? 0,
        'total_revenue' => $conv['total_revenue'] ?? 0,
        'total_payout' => $conv['total_payout'] ?? 0,
        'conversion_rate' => $conversionRate,
        'profit' => $profit
    ];
}

/* ===============================
   SORT DATA IN PHP
================================ */
usort($reportData, function($a, $b) use ($sortBy, $sortOrder) {
    $factor = ($sortOrder === 'asc') ? 1 : -1;
    
    switch ($sortBy) {
        case 'name':
            return $factor * strcmp($a['offer_name'], $b['offer_name']);
        case 'clicks':
            return $factor * ($a['filtered_clicks'] - $b['filtered_clicks']);
        case 'conversions':
            return $factor * ($a['filtered_conversions'] - $b['filtered_conversions']);
        case 'revenue':
            return $factor * ($a['total_revenue'] - $b['total_revenue']);
        case 'date':
        default:
            return $factor * (strtotime($a['offer_created']) - strtotime($b['offer_created']));
    }
});

/* ===============================
   SUMMARY STATISTICS
================================ */
$summary = [
    'total_offers' => $totalOffers,
    'active_offers' => 0,
    'pending_offers' => 0,
    'total_clicks_all' => $grandTotalClicks,
    'total_conversions_all' => $grandTotalConversions,
    'total_revenue_all' => $grandTotalRevenue,
    'total_payout_all' => $grandTotalPayout
];

// Get status counts
$statusStmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM offers GROUP BY status");
$statusStmt->execute();
while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
    if ($row['status'] === 'active') $summary['active_offers'] = $row['count'];
    if ($row['status'] === 'pending') $summary['pending_offers'] = $row['count'];
}

$totalProfit = $summary['total_revenue_all'] - $summary['total_payout_all'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Campaign Report | Admin Panel</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
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
        
        .card-dashboard .card-body { padding: 25px; }
        
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e3e6f0;
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .filter-group { flex: 1; min-width: 180px; }
        
        .filter-group label {
            display: block;
            margin-bottom: 8px;
            color: #6c757d;
            font-size: 14px;
            font-weight: 600;
        }
        
        .filter-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e3e6f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #f8f9fc;
        }
        
        .filter-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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
        }
        
        .btn-success {
            background: #28a745;
            border: none;
            color: white;
            font-weight: 600;
        }
        
        .btn-success:hover { background: #218838; transform: translateY(-2px); }
        
        .table-dashboard {
            border: none;
            width: 100%;
        }
        
        .table-dashboard thead th {
            border: none;
            background: #f8f9fc;
            color: #4e73df;
            font-weight: 600;
            padding: 15px;
            border-bottom: 2px solid #e3e6f0;
        }
        
        .table-dashboard tbody td {
            padding: 15px;
            border-bottom: 1px solid #f8f9fa;
            vertical-align: middle;
        }
        
        .table-dashboard tbody tr:hover { background: #f8f9fc; }
        
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
            font-size: 24px;
            font-weight: 700;
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
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-pending { background: rgba(255,193,7,0.15); color: #ffc107; }
        .status-active { background: rgba(40,167,69,0.15); color: #28a745; }
        .status-paused { background: rgba(108,117,125,0.15); color: #6c757d; }
        .status-rejected { background: rgba(220,53,69,0.15); color: #dc3545; }
        
        .profit-positive { color: #28a745; font-weight: 600; }
        .profit-negative { color: #dc3545; font-weight: 600; }
        
        .btn-action {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(78,115,223,0.1);
            color: #4e73df;
            border: 1px solid rgba(78,115,223,0.2);
        }
        
        .btn-action:hover { background: rgba(78,115,223,0.2); }
        
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
        
        .pagination-container {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 5px;
        }
        
        .page-link {
            padding: 8px 15px;
            border: 1px solid #e3e6f0;
            border-radius: 6px;
            color: #4e73df;
            text-decoration: none;
        }
        
        .page-link:hover { background: #f8f9fc; border-color: #4e73df; }
        .page-link.active { background: #4e73df; color: white; border-color: #4e73df; }
        
        .date-range-badge {
            background: #f8f9fa;
            color: #6c757d;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .admin-avatar {
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
        
        .total-row { background: #f8f9fc; font-weight: 600; }
        .total-row td { border-top: 2px solid #4e73df; }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item"><a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link">Dashboard</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="reports_campaigns.php" class="nav-link active">Campaign Report</a></li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <li class="nav-item"><a class="nav-link" data-widget="fullscreen" href="#"><i class="fas fa-expand-arrows-alt"></i></a></li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" data-toggle="dropdown">
                    <div class="admin-avatar mr-2"><?php echo strtoupper(substr($adminName, 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($adminName); ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="profile.php" class="dropdown-item"><i class="fas fa-user mr-2"></i> Profile</a>
                    <a href="settings.php" class="dropdown-item"><i class="fas fa-cog mr-2"></i> Settings</a>
                    <div class="dropdown-divider"></div>
                    <a href="../logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
                </div>
            </li>
            <li class="nav-item"><a class="nav-link" href="#" id="darkModeToggle"><i class="fas fa-moon"></i></a></li>
        </ul>
    </nav>

    <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <!-- Brand Logo -->
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.5rem;">
                <i class="fas fa-crown mr-2"></i>
                <strong>Admin</strong>
            </span>
        </a>

        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Sidebar Menu -->
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-line"></i>
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
                        <a href="create_campaign.php" class="nav-link">
                            <i class="nav-icon fas fa-plus"></i>
                            <p>Create Campaign</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="campaign_access.php" class="nav-link">
                            <i class="nav-icon fas fa-key"></i>
                            <p>Campaign Access</p>
                        </a>
                    </li>

                    <li class="nav-header">REPORTS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link active">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Campaign Report</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link">
                            <i class="nav-icon fas fa-users"></i>
                            <p>Affiliate Report</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_advertisers.php" class="nav-link">
                            <i class="nav-icon fas fa-building"></i>
                            <p>Advertiser Report</p>
                        </a>
                    </li>

                    <li class="nav-header">PUBLISHERS</li>
                    <li class="nav-item">
                        <a href="publishers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-friends"></i>
                            <p>Manage Publishers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="publisher_postbacks.php" class="nav-link">
                            <i class="nav-icon fas fa-link"></i>
                            <p>Publisher Postbacks</p>
                        </a>
                    </li>

                    <li class="nav-header">ADVERTISERS</li>
                    <li class="nav-item">
                        <a href="advertisers.php" class="nav-link">
                            <i class="nav-icon fas fa-briefcase"></i>
                            <p>Manage Advertisers</p>
                        </a>
                    </li>

                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="account_managers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-tie"></i>
                            <p>Account Managers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="settings.php" class="nav-link">
                            <i class="nav-icon fas fa-cog"></i>
                            <p>Settings</p>
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
                    <div class="col-sm-6"><h1>Campaign Performance Report</h1></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Campaign Report</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">
                <!-- Summary Stats -->
                <div class="summary-stats">
                    <div class="metric-card"><div class="metric-value"><?php echo number_format($summary['total_offers']); ?></div><div class="metric-label">Total Campaigns</div></div>
                    <div class="metric-card"><div class="metric-value"><?php echo number_format($summary['active_offers']); ?></div><div class="metric-label">Active</div></div>
                    <div class="metric-card"><div class="metric-value"><?php echo number_format($summary['pending_offers']); ?></div><div class="metric-label">Pending</div></div>
                    <div class="metric-card"><div class="metric-value"><?php echo number_format($summary['total_clicks_all']); ?></div><div class="metric-label">Clicks</div></div>
                    <div class="metric-card"><div class="metric-value"><?php echo number_format($summary['total_conversions_all']); ?></div><div class="metric-label">Conversions</div></div>
                    <div class="metric-card"><div class="metric-value">$<?php echo number_format($summary['total_revenue_all'], 2); ?></div><div class="metric-label">Revenue</div></div>
                    <div class="metric-card"><div class="metric-value">$<?php echo number_format($totalProfit, 2); ?></div><div class="metric-label">Profit</div></div>
                </div>

                <!-- Filters -->
                <div class="filter-card">
                    <form method="get" id="filterForm">
                        <div class="filter-row">
                            <div class="filter-group">
                                <label>Search</label>
                                <input type="text" name="search" class="filter-control" value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="filter-group">
                                <label>Campaign</label>
                                <select name="offer_id" class="filter-control">
                                    <option value="all">All Campaigns</option>
                                    <?php foreach ($offers as $off): ?>
                                    <option value="<?php echo $off['offer_id']; ?>" <?php echo $offerFilter == $off['offer_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($off['offer_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>Advertiser</label>
                                <select name="advertiser_id" class="filter-control">
                                    <option value="all">All Advertisers</option>
                                    <?php foreach ($advertisers as $adv): ?>
                                    <option value="<?php echo $adv['user_id']; ?>" <?php echo $advertiserFilter == $adv['user_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($adv['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>Status</label>
                                <select name="status" class="filter-control">
                                    <option value="all">All Status</option>
                                    <option value="active" <?php echo $statusFilter=='active'?'selected':''; ?>>Active</option>
                                    <option value="pending" <?php echo $statusFilter=='pending'?'selected':''; ?>>Pending</option>
                                    <option value="paused" <?php echo $statusFilter=='paused'?'selected':''; ?>>Paused</option>
                                </select>
                            </div>
                        </div>
                        <div class="filter-row mt-3">
                            <div class="filter-group">
                                <label>Date Range</label>
                                <input type="text" id="date_range" class="filter-control" value="<?php echo $dateFrom; ?> to <?php echo $dateTo; ?>">
                                <input type="hidden" name="from" id="from" value="<?php echo $dateFrom; ?>">
                                <input type="hidden" name="to" id="to" value="<?php echo $dateTo; ?>">
                            </div>
                            <div class="filter-group">
                                <label>Sort By</label>
                                <select name="sort" class="filter-control">
                                    <option value="date" <?php echo $sortBy=='date'?'selected':''; ?>>Date</option>
                                    <option value="name" <?php echo $sortBy=='name'?'selected':''; ?>>Name</option>
                                    <option value="clicks" <?php echo $sortBy=='clicks'?'selected':''; ?>>Clicks</option>
                                    <option value="conversions" <?php echo $sortBy=='conversions'?'selected':''; ?>>Conversions</option>
                                    <option value="revenue" <?php echo $sortBy=='revenue'?'selected':''; ?>>Revenue</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>Order</label>
                                <select name="order" class="filter-control">
                                    <option value="desc" <?php echo $sortOrder=='desc'?'selected':''; ?>>Descending</option>
                                    <option value="asc" <?php echo $sortOrder=='asc'?'selected':''; ?>>Ascending</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>&nbsp;</label>
                                <button type="submit" class="btn-gradient" style="width:100%"><i class="fas fa-filter mr-2"></i>Apply</button>
                            </div>
                            <div class="filter-group">
                                <label>&nbsp;</label>
                                <a href="reports_campaigns.php" class="btn btn-outline-primary" style="width:100%;display:flex;align-items:center;justify-content:center"><i class="fas fa-redo mr-2"></i>Reset</a>
                            </div>
                            <div class="filter-group">
                                <label>&nbsp;</label>
                                <a href="?export=excel&<?php echo http_build_query($_GET); ?>" class="btn btn-success" style="width:100%;display:flex;align-items:center;justify-content:center"><i class="fas fa-file-excel mr-2"></i>Export</a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Report Table -->
                <div class="card-dashboard">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i> Campaign Performance</h3>
                        <div class="card-tools">
                            <span class="badge badge-light">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                            <span class="date-range-badge ml-2"><i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($dateFrom)); ?> - <?php echo date('M d, Y', strtotime($dateTo)); ?></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($reportData)): ?>
                            <div class="empty-state"><i class="fas fa-chart-bar empty-state-icon"></i><h5>No Data Found</h5><p>No campaign data matches your filters.</p></div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-dashboard">
                                    <thead><tr><th>Campaign</th><th>Advertiser</th><th>Status</th><th>Clicks</th><th>Conversions</th><th>CR%</th><th>Revenue</th><th>Payout</th><th>Profit</th><th>Actions</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($reportData as $row): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($row['offer_name']); ?></strong><br><small class="text-muted">ID: #<?php echo $row['offer_id']; ?></small></td>
                                            <td><strong><?php echo htmlspecialchars($row['advertiser_name']); ?></strong><?php if($row['advertiser_company']): ?><br><small><?php echo htmlspecialchars($row['advertiser_company']); ?></small><?php endif; ?></td>
                                            <td><span class="status-badge status-<?php echo $row['status']; ?>"><?php echo ucfirst($row['status']); ?></span></td>
                                            <td><strong><?php echo number_format($row['filtered_clicks']); ?></strong><br><small>Total: <?php echo number_format($row['total_clicks']); ?></small></td>
                                            <td><strong><?php echo number_format($row['filtered_conversions']); ?></strong><br><small class="text-success">A:<?php echo number_format($row['approved_conversions']); ?></small> <small class="text-warning">P:<?php echo number_format($row['pending_conversions']); ?></small></td>
                                            <td><span class="<?php echo $row['conversion_rate']>=1?'text-success':'text-warning'; ?>"><?php echo number_format($row['conversion_rate'],2); ?>%</span></td>
                                            <td class="text-success">$<?php echo number_format($row['total_revenue'],2); ?></td>
                                            <td class="text-warning">$<?php echo number_format($row['total_payout'],2); ?></td>
                                            <td class="<?php echo $row['profit']>=0?'profit-positive':'profit-negative'; ?>">$<?php echo number_format($row['profit'],2); ?></td>
                                            <td><a href="offer_details.php?id=<?php echo $row['offer_id']; ?>" class="btn-action"><i class="fas fa-eye"></i> View</a></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="total-row">
                                        <tr>
                                            <td colspan="3"><strong>GRAND TOTAL</strong></td>
                                            <td><strong><?php echo number_format($grandTotalClicks); ?></strong></td>
                                            <td><strong><?php echo number_format($grandTotalConversions); ?></strong></td>
                                            <td>-</td>
                                            <td><strong>$<?php echo number_format($grandTotalRevenue,2); ?></strong></td>
                                            <td><strong>$<?php echo number_format($grandTotalPayout,2); ?></strong></td>
                                            <td><strong class="<?php echo ($grandTotalRevenue-$grandTotalPayout)>=0?'profit-positive':'profit-negative'; ?>">$<?php echo number_format($grandTotalRevenue-$grandTotalPayout,2); ?></strong></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                            <div class="pagination-container">
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page'=>1])); ?>" class="page-link"><i class="fas fa-angle-double-left"></i></a>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page'=>$page-1])); ?>" class="page-link"><i class="fas fa-angle-left"></i></a>
                                <?php endif; ?>
                                <?php for ($i=max(1,$page-2); $i<=min($totalPages,$page+2); $i++): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page'=>$i])); ?>" class="page-link <?php echo $i==$page?'active':''; ?>"><?php echo $i; ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $totalPages): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page'=>$page+1])); ?>" class="page-link"><i class="fas fa-angle-right"></i></a>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page'=>$totalPages])); ?>" class="page-link"><i class="fas fa-angle-double-right"></i></a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <footer class="main-footer"><strong>Copyright &copy; <?php echo date('Y'); ?> GVS Icon Media.</strong> All rights reserved.</footer>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
<script>
$(document).ready(function() {
    $('#darkModeToggle').click(function(e) {
        e.preventDefault();
        $('body').toggleClass('dark-mode');
        $(this).find('i').toggleClass('fa-moon fa-sun');
    });

    $('#date_range').daterangepicker({
        startDate: moment('<?php echo $dateFrom; ?>'),
        endDate: moment('<?php echo $dateTo; ?>'),
        maxDate: moment(),
        ranges: {
           'Today': [moment(), moment()],
           'Yesterday': [moment().subtract(1,'days'), moment().subtract(1,'days')],
           'Last 7 Days': [moment().subtract(6,'days'), moment()],
           'Last 30 Days': [moment().subtract(29,'days'), moment()],
           'This Month': [moment().startOf('month'), moment().endOf('month')]
        },
        locale: { format: 'YYYY-MM-DD' }
    }, function(start, end) {
        $('#from').val(start.format('YYYY-MM-DD'));
        $('#to').val(end.format('YYYY-MM-DD'));
    });

    $('#date_range').on('apply.daterangepicker', function(ev, picker) {
        $('#from').val(picker.startDate.format('YYYY-MM-DD'));
        $('#to').val(picker.endDate.format('YYYY-MM-DD'));
        $('#filterForm').submit();
    });
});
</script>
</body>
</html>