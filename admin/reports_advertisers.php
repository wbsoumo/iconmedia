<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['admin', 'manager']);

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

/* ===============================
   FILTER PARAMETERS
================================ */
$search = $_GET['search'] ?? '';
$dateFrom = $_GET['from'] ?? date('Y-m-01'); // Start of current month
$dateTo = $_GET['to'] ?? date('Y-m-d'); // Today
$advertiserFilter = $_GET['advertiser'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';
$roiFilter = $_GET['roi'] ?? 'all';
$sortBy = $_GET['sort'] ?? 'revenue';
$sortOrder = $_GET['order'] ?? 'desc';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 25;

// Validate dates
if (!strtotime($dateFrom)) $dateFrom = date('Y-m-01');
if (!strtotime($dateTo)) $dateTo = date('Y-m-d');

/* ===============================
   BUILD WHERE CLAUSE
================================ */
$where = ['u.role_id = 2']; // Advertisers only (role_id = 2)
$params = [];

// Date filter
if ($dateFrom && $dateTo) {
    $where[] = 'DATE(c.created_at) BETWEEN :date_from AND :date_to';
    $params['date_from'] = $dateFrom;
    $params['date_to'] = $dateTo;
}

// Search filter
if ($search) {
    $where[] = '(u.name LIKE :search OR u.email LIKE :search OR u.company LIKE :search)';
    $params['search'] = "%$search%";
}

// Advertiser filter
if ($advertiserFilter !== 'all') {
    $where[] = 'u.user_id = :advertiser_id';
    $params['advertiser_id'] = (int)$advertiserFilter;
}

// Status filter for conversions
if ($statusFilter !== 'all') {
    $where[] = 'c.status = :status';
    $params['status'] = $statusFilter;
}

// ROI filter
if ($roiFilter !== 'all') {
    switch ($roiFilter) {
        case 'profitable':
            $havingClause = 'HAVING (revenue - payout) > 0';
            break;
        case 'break_even':
            $havingClause = 'HAVING (revenue - payout) = 0';
            break;
        case 'unprofitable':
            $havingClause = 'HAVING (revenue - payout) < 0';
            break;
        case 'high_roi':
            $havingClause = 'HAVING (payout > 0 AND ((revenue - payout) / payout * 100) > 50)';
            break;
    }
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$havingSql = $havingClause ?? '';

/* ===============================
   GET TOTAL COUNT
================================ */
$countSql = "
    SELECT COUNT(DISTINCT u.user_id)
    FROM users u
    INNER JOIN offers o ON o.advertiser_id = u.user_id
    LEFT JOIN clicks cl ON cl.offer_id = o.offer_id
    LEFT JOIN conversions c ON c.offer_id = o.offer_id
    $whereSql
";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalAdvertisers = $countStmt->fetchColumn();
$totalPages = ceil($totalAdvertisers / $perPage);
$offset = ($page - 1) * $perPage;

/* ===============================
   GET ADVERTISER PERFORMANCE DATA
================================ */
// Determine sort order
$orderBy = "revenue {$sortOrder}";
switch ($sortBy) {
    case 'name':
        $orderBy = "advertiser_name {$sortOrder}";
        break;
    case 'offers':
        $orderBy = "total_offers {$sortOrder}";
        break;
    case 'clicks':
        $orderBy = "clicks {$sortOrder}";
        break;
    case 'conversions':
        $orderBy = "conversions {$sortOrder}";
        break;
    case 'approved':
        $orderBy = "approved_conversions {$sortOrder}";
        break;
    case 'payout':
        $orderBy = "payout {$sortOrder}";
        break;
    case 'profit':
        $orderBy = "profit {$sortOrder}";
        break;
    case 'roi':
        $orderBy = "roi_percentage {$sortOrder}";
        break;
    case 'cr':
        $orderBy = "conversion_rate {$sortOrder}";
        break;
    case 'epc':
        $orderBy = "epc {$sortOrder}";
        break;
}

$sql = "
    SELECT
        u.user_id AS advertiser_id,
        u.name AS advertiser_name,
        u.email,
        u.company,
        u.status as advertiser_status,
        u.created_at as advertiser_joined,
        u.balance,
        
        COUNT(DISTINCT o.offer_id) AS total_offers,
        COUNT(DISTINCT o_active.offer_id) AS active_offers,
        COUNT(DISTINCT cl.click_id) AS clicks,
        COUNT(DISTINCT c.conversion_id) AS conversions,
        
        SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        SUM(CASE WHEN c.status = 'pending' THEN 1 ELSE 0 END) AS pending_conversions,
        SUM(CASE WHEN c.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_conversions,
        
        SUM(CASE WHEN c.status = 'approved' THEN c.revenue ELSE 0 END) AS revenue,
        SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END) AS payout,
        SUM(CASE WHEN c.status = 'pending' THEN c.revenue ELSE 0 END) AS pending_revenue,
        SUM(CASE WHEN c.status = 'pending' THEN c.payout ELSE 0 END) AS pending_payout,
        
        -- Performance metrics
        CASE 
            WHEN COUNT(DISTINCT cl.click_id) > 0 
            THEN (SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) / COUNT(DISTINCT cl.click_id)) * 100
            ELSE 0
        END as conversion_rate,
        
        CASE 
            WHEN COUNT(DISTINCT cl.click_id) > 0 
            THEN SUM(CASE WHEN c.status = 'approved' THEN c.revenue ELSE 0 END) / COUNT(DISTINCT cl.click_id)
            ELSE 0
        END as epc,
        
        -- Profit and ROI
        (SUM(CASE WHEN c.status = 'approved' THEN c.revenue ELSE 0 END) - 
         SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END)) as profit,
        
        CASE 
            WHEN SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END) > 0 
            THEN ((SUM(CASE WHEN c.status = 'approved' THEN c.revenue ELSE 0 END) - 
                   SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END)) / 
                   SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END)) * 100
            WHEN SUM(CASE WHEN c.status = 'approved' THEN c.revenue ELSE 0 END) > 0 
            THEN 100
            ELSE 0
        END as roi_percentage,
        
        -- Activity metrics
        MAX(cl.created_at) as last_click,
        MAX(c.created_at) as last_conversion,
        MIN(o.created_at) as first_offer,
        
        -- Offer status summary
        SUM(CASE WHEN o.status = 'active' THEN 1 ELSE 0 END) as offers_active,
        SUM(CASE WHEN o.status = 'paused' THEN 1 ELSE 0 END) as offers_paused,
        SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END) as offers_pending
        
    FROM users u
    INNER JOIN offers o ON o.advertiser_id = u.user_id
    LEFT JOIN offers o_active ON o_active.advertiser_id = u.user_id AND o_active.status = 'active'
    LEFT JOIN clicks cl ON cl.offer_id = o.offer_id
    LEFT JOIN conversions c ON c.offer_id = o.offer_id
    
    $whereSql
    GROUP BY u.user_id
    $havingSql
    ORDER BY $orderBy
    LIMIT :offset, :per_page
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $perPage, PDO::PARAM_INT);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$advertisers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   GET SUMMARY STATISTICS
================================ */
$summarySql = "
    SELECT
    COUNT(*) AS total_advertisers,
    SUM(active_advertiser) AS active_advertisers,

    SUM(total_offers) AS total_offers,
    SUM(active_offers) AS active_offers,

    SUM(clicks) AS total_clicks,
    SUM(conversions) AS total_conversions,
    SUM(approved_conversions) AS total_approved,

    SUM(revenue) AS total_revenue,
    SUM(payout) AS total_payout,

    SUM(revenue - payout) AS total_profit,

    AVG(conversion_rate) AS avg_conversion_rate,
    AVG(roi_percentage) AS avg_roi

FROM (
    SELECT
        u.user_id,

        CASE WHEN u.status = 'active' THEN 1 ELSE 0 END AS active_advertiser,

        COUNT(DISTINCT o.offer_id) AS total_offers,
        SUM(CASE WHEN o.status = 'active' THEN 1 ELSE 0 END) AS active_offers,

        COUNT(DISTINCT cl.click_id) AS clicks,
        COUNT(DISTINCT c.conversion_id) AS conversions,
        SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,

        SUM(CASE WHEN c.status = 'approved' THEN c.revenue ELSE 0 END) AS revenue,
        SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END) AS payout,

        CASE 
            WHEN COUNT(DISTINCT cl.click_id) > 0 
            THEN (SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END)
                 / COUNT(DISTINCT cl.click_id)) * 100
            ELSE 0
        END AS conversion_rate,

        CASE 
            WHEN SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END) > 0 
            THEN ((SUM(CASE WHEN c.status = 'approved' THEN c.revenue ELSE 0 END) -
                   SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END)) /
                   SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END)) * 100
            WHEN SUM(CASE WHEN c.status = 'approved' THEN c.revenue ELSE 0 END) > 0
            THEN 100
            ELSE 0
        END AS roi_percentage

    FROM users u
    INNER JOIN offers o ON o.advertiser_id = u.user_id
    LEFT JOIN clicks cl ON cl.offer_id = o.offer_id
    LEFT JOIN conversions c ON c.offer_id = o.offer_id
    $whereSql
    GROUP BY u.user_id
) t
";

$summaryStmt = $pdo->prepare($summarySql);
foreach ($params as $key => $value) {
    if ($key !== 'offset' && $key !== 'per_page') {
        $summaryStmt->bindValue($key, $value);
    }
}
$summaryStmt->execute();
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

/* ===============================
   GET ADVERTISERS FOR FILTER
================================ */
$allAdvertisers = $pdo->query("
    SELECT user_id, name, email 
    FROM users 
    WHERE role_id = 2 
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   EXPORT FUNCTIONALITY
================================ */
if (isset($_GET['export'])) {
    $exportStmt = $pdo->prepare("
        SELECT
            u.name AS advertiser_name,
            u.email,
            u.company,
            u.status as advertiser_status,
            u.created_at as join_date,
            u.balance,
            
            COUNT(DISTINCT o.offer_id) AS total_offers,
            SUM(CASE WHEN o.status = 'active' THEN 1 ELSE 0 END) as active_offers,
            COUNT(DISTINCT cl.click_id) AS clicks,
            COUNT(DISTINCT c.conversion_id) AS conversions,
            
            SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
            SUM(CASE WHEN c.status = 'pending' THEN 1 ELSE 0 END) AS pending_conversions,
            
            SUM(CASE WHEN c.status = 'approved' THEN c.revenue ELSE 0 END) AS revenue,
            SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END) AS payout,
            
            (SUM(CASE WHEN c.status = 'approved' THEN c.revenue ELSE 0 END) - 
             SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END)) as profit,
            
            CASE 
                WHEN SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END) > 0 
                THEN ((SUM(CASE WHEN c.status = 'approved' THEN c.revenue ELSE 0 END) - 
                       SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END)) / 
                       SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END)) * 100
                WHEN SUM(CASE WHEN c.status = 'approved' THEN c.revenue ELSE 0 END) > 0 
                THEN 100
                ELSE 0
            END as roi_percentage,
            
            CASE 
                WHEN COUNT(DISTINCT cl.click_id) > 0 
                THEN (SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) / COUNT(DISTINCT cl.click_id)) * 100
                ELSE 0
            END as conversion_rate
            
        FROM users u
        INNER JOIN offers o ON o.advertiser_id = u.user_id
        LEFT JOIN clicks cl ON cl.offer_id = o.offer_id
        LEFT JOIN conversions c ON c.offer_id = o.offer_id
        $whereSql
        GROUP BY u.user_id
        ORDER BY revenue DESC
    ");
    
    foreach ($params as $key => $value) {
        if ($key !== 'offset' && $key !== 'per_page') {
            $exportStmt->bindValue($key, $value);
        }
    }
    
    $exportStmt->execute();
    $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="advertiser-performance-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'Advertiser Name', 'Email', 'Company', 'Status', 'Join Date', 'Balance',
        'Total Offers', 'Active Offers', 'Clicks', 'Total Conversions',
        'Approved Conversions', 'Pending Conversions', 'Revenue', 'Payout',
        'Profit', 'ROI %', 'Conversion Rate %',
        'Date Range: ' . $dateFrom . ' to ' . $dateTo
    ]);
    
    foreach ($exportData as $row) {
        fputcsv($output, [
            $row['advertiser_name'],
            $row['email'],
            $row['company'],
            $row['advertiser_status'],
            $row['join_date'],
            $row['balance'],
            $row['total_offers'],
            $row['active_offers'],
            $row['clicks'],
            $row['conversions'],
            $row['approved_conversions'],
            $row['pending_conversions'],
            number_format($row['revenue'], 2),
            number_format($row['payout'], 2),
            number_format($row['profit'], 2),
            number_format($row['roi_percentage'], 2),
            number_format($row['conversion_rate'], 2)
        ]);
    }
    
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Advertiser Performance Report | Admin Panel | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
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
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --info-gradient: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            --warning-gradient: linear-gradient(135deg, #f7971e 0%, #ffd200 100%);
            --danger-gradient: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
            --profit-gradient: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            --loss-gradient: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
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
        
        .filter-group {
            flex: 1;
            min-width: 180px;
        }
        
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
            background: white;
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
            text-align: left;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .table-dashboard thead th:hover {
            background: #f1f3f9;
        }
        
        .table-dashboard thead th i {
            font-size: 12px;
            margin-left: 5px;
            opacity: 0.5;
        }
        
        .table-dashboard tbody td {
            padding: 15px;
            border-bottom: 1px solid #f8f9fa;
            vertical-align: middle;
        }
        
        .table-dashboard tbody tr:hover {
            background: #f8f9fc;
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
        
        .total-value {
            color: #4e73df;
            font-weight: 700;
        }
        
        .offers-value {
            color: #6610f2;
            font-weight: 700;
        }
        
        .clicks-value {
            color: #20c997;
            font-weight: 700;
        }
        
        .conversions-value {
            color: #fd7e14;
            font-weight: 700;
        }
        
        .revenue-value {
            color: #28a745;
            font-weight: 700;
        }
        
        .payout-value {
            color: #6f42c1;
            font-weight: 700;
        }
        
        .profit-value {
            color: #20c997;
            font-weight: 700;
        }
        
        .roi-value {
            color: #ffc107;
            font-weight: 700;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-active {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .status-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .status-inactive {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.2);
        }
        
        .roi-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .roi-excellent {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .roi-good {
            background: rgba(32, 201, 151, 0.15);
            color: #20c997;
            border: 1px solid rgba(32, 201, 151, 0.2);
        }
        
        .roi-fair {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .roi-poor {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .profit-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .profit-positive {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .profit-negative {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .profit-neutral {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.2);
        }
        
        .performance-bar {
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 5px;
        }
        
        .performance-fill {
            height: 100%;
            border-radius: 3px;
        }
        
        .performance-excellent {
            background: linear-gradient(90deg, #28a745, #20c997);
        }
        
        .performance-good {
            background: linear-gradient(90deg, #20c997, #17a2b8);
        }
        
        .performance-fair {
            background: linear-gradient(90deg, #ffc107, #fd7e14);
        }
        
        .performance-poor {
            background: linear-gradient(90deg, #dc3545, #fd7e14);
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn-action {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        
        .btn-view {
            background: rgba(78, 115, 223, 0.1);
            color: #4e73df;
            border: 1px solid rgba(78, 115, 223, 0.2);
        }
        
        .btn-view:hover {
            background: rgba(78, 115, 223, 0.2);
            color: #4e73df;
        }
        
        .btn-chart {
            background: rgba(32, 201, 151, 0.1);
            color: #20c997;
            border: 1px solid rgba(32, 201, 151, 0.2);
        }
        
        .btn-chart:hover {
            background: rgba(32, 201, 151, 0.2);
            color: #20c997;
        }
        
        .btn-offers {
            background: rgba(102, 16, 242, 0.1);
            color: #6610f2;
            border: 1px solid rgba(102, 16, 242, 0.2);
        }
        
        .btn-offers:hover {
            background: rgba(102, 16, 242, 0.2);
            color: #6610f2;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .empty-state-icon {
            font-size: 50px;
            color: #e3e6f0;
            margin-bottom: 15px;
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
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
            transition: all 0.3s ease;
        }
        
        .page-link:hover {
            background: #f8f9fc;
            border-color: #4e73df;
        }
        
        .page-link.active {
            background: #4e73df;
            color: white;
            border-color: #4e73df;
        }
        
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .stat-item {
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .stat-label {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
        }
        
        .stat-value {
            font-size: 14px;
            font-weight: 600;
            margin-top: 3px;
        }
        
        .sort-indicator {
            display: inline-block;
            width: 0;
            height: 0;
            margin-left: 5px;
            vertical-align: middle;
            border-right: 4px solid transparent;
            border-left: 4px solid transparent;
        }
        
        .sort-asc {
            border-bottom: 4px solid #4e73df;
            border-top: none;
        }
        
        .sort-desc {
            border-top: 4px solid #4e73df;
            border-bottom: none;
        }
        
        .financial-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            border-left: 4px solid;
            margin-bottom: 10px;
        }
        
        .financial-revenue {
            border-left-color: #28a745;
            background: rgba(40, 167, 69, 0.05);
        }
        
        .financial-payout {
            border-left-color: #6f42c1;
            background: rgba(111, 66, 193, 0.05);
        }
        
        .financial-profit {
            border-left-color: #20c997;
            background: rgba(32, 201, 151, 0.05);
        }
        
        .offer-status-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 5px;
            margin-top: 5px;
        }
        
        .offer-status-item {
            text-align: center;
            padding: 4px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .status-active {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }
        
        .status-paused {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }
        
        .status-pending {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
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
                <a href="reports_advertisers.php" class="nav-link active">Advertiser Report</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-bell"></i>
                    <?php if (($summary['total_profit'] ?? 0) < 0): ?>
                    <span class="badge badge-danger navbar-badge">
                        Loss Alert
                    </span>
                    <?php endif; ?>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <span class="dropdown-item dropdown-header">
                        Profit Status: $<?php echo number_format($summary['total_profit'] ?? 0, 2); ?>
                    </span>
                    <div class="dropdown-divider"></div>
                    <a href="reports_advertisers.php?roi=unprofitable" class="dropdown-item">
                        <i class="fas fa-chart-line mr-2 text-danger"></i>
                        Review Unprofitable Advertisers
                    </a>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-widget="fullscreen" href="#" role="button">
                    <i class="fas fa-expand-arrows-alt"></i>
                </a>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                    <i class="fas fa-user-circle mr-1"></i> <?php echo htmlspecialchars($adminName); ?>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user mr-2"></i> Profile
                    </a>
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-cog mr-2"></i> Settings
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
                        <a href="reports_campaigns.php" class="nav-link">
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
                        <a href="reports_advertisers.php" class="nav-link active">
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
                        <a href="profile.php" class="nav-link">
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
        <!-- Content Header -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0">Advertiser Performance Report</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="reports_campaigns.php">Reports</a></li>
                            <li class="breadcrumb-item active">Advertiser Performance</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">
                <!-- Dashboard Header -->
                <div class="dashboard-header">
                    <h2 class="mb-0">Advertiser Performance Analytics</h2>
                    <div class="action-buttons-group">
                        <span class="date-range-badge">
                            <i class="fas fa-calendar-alt"></i>
                            <?php echo date('M d, Y', strtotime($dateFrom)); ?> - <?php echo date('M d, Y', strtotime($dateTo)); ?>
                        </span>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-outline-primary">
                            <i class="fas fa-download mr-2"></i> Export CSV
                        </a>
                        <button type="button" class="btn btn-gradient" onclick="printReport()">
                            <i class="fas fa-print mr-2"></i> Print Report
                        </button>
                    </div>
                </div>

                <!-- Summary Stats -->
                <div class="summary-stats">
                    <div class="metric-card">
                        <div class="metric-value total-value"><?php echo number_format($summary['total_advertisers'] ?? 0); ?></div>
                        <div class="metric-label">Total Advertisers</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value offers-value"><?php echo number_format($summary['total_offers'] ?? 0); ?></div>
                        <div class="metric-label">Total Offers</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value clicks-value"><?php echo number_format($summary['total_clicks'] ?? 0); ?></div>
                        <div class="metric-label">Total Clicks</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value conversions-value"><?php echo number_format($summary['total_conversions'] ?? 0); ?></div>
                        <div class="metric-label">Total Conversions</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value revenue-value">$<?php echo number_format($summary['total_revenue'] ?? 0, 2); ?></div>
                        <div class="metric-label">Total Revenue</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value payout-value">$<?php echo number_format($summary['total_payout'] ?? 0, 2); ?></div>
                        <div class="metric-label">Total Payout</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value profit-value <?php echo ($summary['total_profit'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?>">
                            $<?php echo number_format($summary['total_profit'] ?? 0, 2); ?>
                        </div>
                        <div class="metric-label">Total Profit/Loss</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value roi-value"><?php echo number_format($summary['avg_roi'] ?? 0, 2); ?>%</div>
                        <div class="metric-label">Average ROI</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card-dashboard">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-filter mr-2"></i> Filter Report Data
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="get" class="filter-row">
                            <div class="filter-group">
                                <label for="search"><i class="fas fa-search mr-1"></i> Search Advertisers</label>
                                <input type="text" name="search" id="search" class="filter-control" 
                                       placeholder="Search by name, email, company..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label for="from"><i class="fas fa-calendar-start mr-1"></i> From Date</label>
                                <input type="date" name="from" id="from" class="filter-control" 
                                       value="<?php echo htmlspecialchars($dateFrom); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label for="to"><i class="fas fa-calendar-end mr-1"></i> To Date</label>
                                <input type="date" name="to" id="to" class="filter-control" 
                                       value="<?php echo htmlspecialchars($dateTo); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label for="advertiser"><i class="fas fa-building mr-1"></i> Advertiser</label>
                                <select name="advertiser" id="advertiser" class="filter-control">
                                    <option value="all" <?php echo $advertiserFilter === 'all' ? 'selected' : ''; ?>>All Advertisers</option>
                                    <?php foreach ($allAdvertisers as $adv): ?>
                                    <option value="<?php echo $adv['user_id']; ?>" <?php echo $advertiserFilter == $adv['user_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($adv['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="roi"><i class="fas fa-chart-line mr-1"></i> ROI Filter</label>
                                <select name="roi" id="roi" class="filter-control">
                                    <option value="all" <?php echo $roiFilter === 'all' ? 'selected' : ''; ?>>All ROI</option>
                                    <option value="profitable" <?php echo $roiFilter === 'profitable' ? 'selected' : ''; ?>>Profitable Only</option>
                                    <option value="unprofitable" <?php echo $roiFilter === 'unprofitable' ? 'selected' : ''; ?>>Unprofitable Only</option>
                                    <option value="high_roi" <?php echo $roiFilter === 'high_roi' ? 'selected' : ''; ?>>High ROI (>50%)</option>
                                    <option value="break_even" <?php echo $roiFilter === 'break_even' ? 'selected' : ''; ?>>Break Even</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <button type="submit" class="btn-gradient" style="height: 45px; width: 100%;">
                                    <i class="fas fa-chart-line mr-2"></i> Generate Report
                                </button>
                            </div>
                            
                            <div class="filter-group">
                                <a href="reports_advertisers.php" class="btn btn-outline-primary" style="height: 45px; width: 100%; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-redo mr-2"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Performance Table -->
                <div class="card-dashboard">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-chart-bar mr-2"></i> Advertiser Performance Summary
                        </h3>
                        <div class="card-tools">
                            <span class="badge badge-light">
                                Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($advertisers)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fas fa-building"></i>
                                </div>
                                <h5>No Performance Data Found</h5>
                                <p class="text-muted">No advertiser data matches your search criteria.</p>
                                <a href="reports_advertisers.php" class="btn btn-gradient btn-sm">
                                    <i class="fas fa-redo mr-2"></i> Reset Filters
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-dashboard" id="performanceTable">
                                    <thead>
                                        <tr>
                                            <th>
                                                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'name', 'order' => $sortBy === 'name' && $sortOrder === 'asc' ? 'desc' : 'asc'])); ?>">
                                                    Advertiser
                                                    <?php if ($sortBy === 'name'): ?>
                                                    <span class="sort-indicator sort-<?php echo $sortOrder; ?>"></span>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>Campaigns</th>
                                            <th>
                                                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'clicks', 'order' => $sortBy === 'clicks' && $sortOrder === 'asc' ? 'desc' : 'asc'])); ?>">
                                                    Clicks
                                                    <?php if ($sortBy === 'clicks'): ?>
                                                    <span class="sort-indicator sort-<?php echo $sortOrder; ?>"></span>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>Conversions</th>
                                            <th>
                                                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'revenue', 'order' => $sortBy === 'revenue' && $sortOrder === 'asc' ? 'desc' : 'asc'])); ?>">
                                                    Revenue
                                                    <?php if ($sortBy === 'revenue'): ?>
                                                    <span class="sort-indicator sort-<?php echo $sortOrder; ?>"></span>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'payout', 'order' => $sortBy === 'payout' && $sortOrder === 'asc' ? 'desc' : 'asc'])); ?>">
                                                    Payout
                                                    <?php if ($sortBy === 'payout'): ?>
                                                    <span class="sort-indicator sort-<?php echo $sortOrder; ?>"></span>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'profit', 'order' => $sortBy === 'profit' && $sortOrder === 'asc' ? 'desc' : 'asc'])); ?>">
                                                    Profit
                                                    <?php if ($sortBy === 'profit'): ?>
                                                    <span class="sort-indicator sort-<?php echo $sortOrder; ?>"></span>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'roi', 'order' => $sortBy === 'roi' && $sortOrder === 'asc' ? 'desc' : 'asc'])); ?>">
                                                    ROI
                                                    <?php if ($sortBy === 'roi'): ?>
                                                    <span class="sort-indicator sort-<?php echo $sortOrder; ?>"></span>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>Performance</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($advertisers as $row): 
                                            $totalOffers = (int)$row['total_offers'];
                                            $activeOffers = (int)$row['offers_active'];
                                            $clicks = (int)$row['clicks'];
                                            $conversions = (int)$row['conversions'];
                                            $approved = (int)$row['approved_conversions'];
                                            $revenue = (float)$row['revenue'];
                                            $payout = (float)$row['payout'];
                                            $profit = (float)$row['profit'];
                                            $roi = (float)$row['roi_percentage'];
                                            $cr = (float)$row['conversion_rate'];
                                            $epc = (float)$row['epc'];
                                            
                                            // Determine ROI badge class
                                            if ($roi >= 50) {
                                                $roiClass = 'roi-excellent';
                                                $roiLabel = 'Excellent';
                                                $performanceClass = 'performance-excellent';
                                            } elseif ($roi >= 20) {
                                                $roiClass = 'roi-good';
                                                $roiLabel = 'Good';
                                                $performanceClass = 'performance-good';
                                            } elseif ($roi >= 0) {
                                                $roiClass = 'roi-fair';
                                                $roiLabel = 'Fair';
                                                $performanceClass = 'performance-fair';
                                            } else {
                                                $roiClass = 'roi-poor';
                                                $roiLabel = 'Poor';
                                                $performanceClass = 'performance-poor';
                                            }
                                            
                                            // Profit badge
                                            if ($profit > 0) {
                                                $profitClass = 'profit-positive';
                                                $profitSymbol = '+';
                                            } elseif ($profit < 0) {
                                                $profitClass = 'profit-negative';
                                                $profitSymbol = '-';
                                            } else {
                                                $profitClass = 'profit-neutral';
                                                $profitSymbol = '';
                                            }
                                            
                                            // Status badge
                                            $statusClass = 'status-' . ($row['advertiser_status'] ?? 'pending');
                                            $statusLabel = ucfirst($row['advertiser_status'] ?? 'pending');
                                            
                                            // Last activity
                                            $lastClick = $row['last_click'] ? date('M d', strtotime($row['last_click'])) : 'Never';
                                            $lastConversion = $row['last_conversion'] ? date('M d', strtotime($row['last_conversion'])) : 'Never';
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="mr-3">
                                                        <div style="width: 40px; height: 40px; background: #6f42c1; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600;">
                                                            <?php echo strtoupper(substr($row['advertiser_name'], 0, 1)); ?>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($row['advertiser_name']); ?></strong>
                                                        <div class="text-muted small">
                                                            <?php echo htmlspecialchars($row['email']); ?>
                                                            <?php if ($row['company']): ?>
                                                                &nbsp;•&nbsp; <?php echo htmlspecialchars($row['company']); ?>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="d-flex align-items-center gap-2 mt-1">
                                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                                <?php echo $statusLabel; ?>
                                                            </span>
                                                            <span class="text-muted small">
                                                                Balance: $<?php echo number_format($row['balance'] ?? 0, 2); ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="font-weight-bold"><?php echo number_format($totalOffers); ?></div>
                                                <div class="offer-status-grid">
                                                    <div class="offer-status-item status-active" title="Active Offers">
                                                        <?php echo $activeOffers; ?>
                                                    </div>
                                                    <div class="offer-status-item status-paused" title="Paused Offers">
                                                        <?php echo $row['offers_paused']; ?>
                                                    </div>
                                                    <div class="offer-status-item status-pending" title="Pending Offers">
                                                        <?php echo $row['offers_pending']; ?>
                                                    </div>
                                                </div>
                                                <div class="performance-bar">
                                                    <?php if ($totalOffers > 0): ?>
                                                    <div class="performance-fill <?php echo $performanceClass; ?>" 
                                                         style="width: <?php echo ($activeOffers / $totalOffers) * 100; ?>%">
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="font-weight-bold"><?php echo number_format($clicks); ?></div>
                                                <div class="small text-muted">
                                                    EPC: $<?php echo number_format($epc, 4); ?>
                                                </div>
                                                <div class="performance-bar">
                                                    <?php if ($clicks > 0 && $summary['total_clicks'] > 0): ?>
                                                    <div class="performance-fill <?php echo $performanceClass; ?>" 
                                                         style="width: <?php echo min(100, ($clicks / $summary['total_clicks']) * 100); ?>%">
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="stats-grid">
                                                    <div class="stat-item">
                                                        <div class="stat-label">Total</div>
                                                        <div class="stat-value"><?php echo number_format($conversions); ?></div>
                                                    </div>
                                                    <div class="stat-item">
                                                        <div class="stat-label">Approved</div>
                                                        <div class="stat-value text-success"><?php echo number_format($approved); ?></div>
                                                    </div>
                                                    <div class="stat-item">
                                                        <div class="stat-label">Pending</div>
                                                        <div class="stat-value text-warning"><?php echo number_format($row['pending_conversions']); ?></div>
                                                    </div>
                                                    <div class="stat-item">
                                                        <div class="stat-label">CR %</div>
                                                        <div class="stat-value <?php echo $cr >= 1 ? 'text-success' : 'text-warning'; ?>">
                                                            <?php echo number_format($cr, 2); ?>%
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="financial-card financial-revenue">
                                                    <div class="font-weight-bold text-success">
                                                        $<?php echo number_format($revenue, 2); ?>
                                                    </div>
                                                    <?php if ($row['pending_revenue'] > 0): ?>
                                                    <div class="small text-warning">
                                                        Pending: $<?php echo number_format($row['pending_revenue'], 2); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="financial-card financial-payout">
                                                    <div class="font-weight-bold text-primary">
                                                        $<?php echo number_format($payout, 2); ?>
                                                    </div>
                                                    <?php if ($row['pending_payout'] > 0): ?>
                                                    <div class="small text-warning">
                                                        Pending: $<?php echo number_format($row['pending_payout'], 2); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="financial-card financial-profit">
                                                    <span class="profit-badge <?php echo $profitClass; ?>">
                                                        <?php echo $profitSymbol; ?>$<?php echo number_format(abs($profit), 2); ?>
                                                    </span>
                                                    <div class="small mt-1">
                                                        Margin: <?php echo $revenue > 0 ? number_format(($profit / $revenue) * 100, 2) : '0'; ?>%
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <span class="roi-badge <?php echo $roiClass; ?>">
                                                        <?php echo number_format($roi, 2); ?>%
                                                    </span>
                                                    <div class="ml-2 small text-muted">
                                                        <?php echo $roiLabel; ?>
                                                    </div>
                                                </div>
                                                <div class="performance-bar mt-2">
                                                    <div class="performance-fill <?php echo $performanceClass; ?>" 
                                                         style="width: <?php echo min(100, max(0, $roi)); ?>%">
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <div class="mb-1">
                                                        <i class="fas fa-mouse-pointer mr-1 text-muted"></i>
                                                        Last click: <?php echo $lastClick; ?>
                                                    </div>
                                                    <div class="mb-1">
                                                        <i class="fas fa-exchange-alt mr-1 text-muted"></i>
                                                        Last conv: <?php echo $lastConversion; ?>
                                                    </div>
                                                    <div>
                                                        <i class="fas fa-calendar-alt mr-1 text-muted"></i>
                                                        Joined: <?php echo date('M Y', strtotime($row['first_offer'])); ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="reports_advertisers.php?id=<?php echo $row['advertiser_id']; ?>" 
                                                       class="btn-action btn-view"
                                                       title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="reports_advertisers.php?id=<?php echo $row['advertiser_id']; ?>&from=<?php echo $dateFrom; ?>&to=<?php echo $dateTo; ?>" 
                                                       class="btn-action btn-chart"
                                                       title="View Detailed Report">
                                                        <i class="fas fa-chart-line"></i>
                                                    </a>
                                                    <a href="reports_advertisers.php?id=<?php echo $row['advertiser_id']; ?>" 
                                                       class="btn-action btn-offers"
                                                       title="View Campaigns">
                                                        <i class="fas fa-bullhorn"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                            <div class="pagination-container">
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="page-link">
                                        <i class="fas fa-angle-double-left"></i>
                                    </a>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
                                        <i class="fas fa-angle-left"></i>
                                    </a>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                       class="page-link <?php echo $i == $page ? 'active' : '' ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
                                        <i class="fas fa-angle-right"></i>
                                    </a>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>" class="page-link">
                                        <i class="fas fa-angle-double-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline">
            <strong>Admin Panel v3.0</strong>
        </div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
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
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable with custom sorting
    $('#performanceTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [], // Disable initial sort (we use server-side sorting)
        responsive: true,
        searching: false,
        info: false,
        paging: false, // We use custom pagination
        language: {
            emptyTable: "No performance data found"
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
    
    // Date range validation
    $('#from, #to').change(function() {
        const fromDate = new Date($('#from').val());
        const toDate = new Date($('#to').val());
        
        if (fromDate > toDate) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Date Range',
                text: 'From date cannot be after To date'
            });
            $('#from').val($('#to').val());
        }
    });
    
    // Quick date filters
    $('#quickFilter').change(function() {
        const value = $(this).val();
        const today = new Date();
        
        switch(value) {
            case 'today':
                $('#from').val(today.toISOString().split('T')[0]);
                $('#to').val(today.toISOString().split('T')[0]);
                break;
            case 'yesterday':
                const yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);
                $('#from').val(yesterday.toISOString().split('T')[0]);
                $('#to').val(yesterday.toISOString().split('T')[0]);
                break;
            case 'week':
                const weekStart = new Date(today);
                weekStart.setDate(weekStart.getDate() - 7);
                $('#from').val(weekStart.toISOString().split('T')[0]);
                $('#to').val(today.toISOString().split('T')[0]);
                break;
            case 'month':
                const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
                $('#from').val(monthStart.toISOString().split('T')[0]);
                $('#to').val(today.toISOString().split('T')[0]);
                break;
            case 'quarter':
                const quarterStart = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3, 1);
                $('#from').val(quarterStart.toISOString().split('T')[0]);
                $('#to').val(today.toISOString().split('T')[0]);
                break;
            case 'year':
                $('#from').val(today.getFullYear() + '-01-01');
                $('#to').val(today.toISOString().split('T')[0]);
                break;
        }
        
        if (value !== '') {
            $('form').submit();
        }
    });
    
    // Print report function
    window.printReport = function() {
        const originalTitle = document.title;
        document.title = 'Advertiser Performance Report - ' + 
                         $('#from').val() + ' to ' + $('#to').val() + ' - GVS Icon Media';
        
        // Hide elements that shouldn't print
        $('.main-header, .main-sidebar, .content-header, .dashboard-header .action-buttons-group, .footer').hide();
        
        // Print
        window.print();
        
        // Restore
        document.title = originalTitle;
        $('.main-header, .main-sidebar, .content-header, .dashboard-header .action-buttons-group, .footer').show();
    };
    
    // Search focus
    $('#search').focus();
    
    // ROI score tooltips
    $('.roi-badge').hover(function() {
        const roiText = $(this).text().replace('%', '');
        const roiValue = parseFloat(roiText);
        
        let rating = '';
        if (roiValue >= 50) rating = 'Excellent (≥50%)';
        else if (roiValue >= 20) rating = 'Good (20-49%)';
        else if (roiValue >= 0) rating = 'Fair (0-19%)';
        else rating = 'Poor (<0%)';
        
        $(this).attr('title', 'ROI: ' + roiText + '% - ' + rating);
    });
    
    // Auto-refresh data every 5 minutes if no filters are set
    const hasFilters = window.location.search.includes('search=') || 
                      window.location.search.includes('advertiser=') || 
                      window.location.search.includes('roi=');
    
    if (!hasFilters) {
        setTimeout(function() {
            // Refresh page to get updated data
            window.location.reload();
        }, 300000); // 5 minutes
    }
    
    // Profit/Loss color coding
    $('.profit-badge').each(function() {
        const profitText = $(this).text();
        const profitValue = parseFloat(profitText.replace(/[^0-9.-]/g, ''));
        
        if (profitValue > 1000) {
            $(this).addClass('font-weight-bold');
        } else if (profitValue < -1000) {
            $(this).addClass('font-weight-bold');
        }
    });
});
</script>

</body>
</html>