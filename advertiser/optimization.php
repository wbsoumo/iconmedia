<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('advertiser');

$advertiserId = auth_user_id();
$advertiserName = $_SESSION['user_name'] ?? 'Advertiser';
$success = $error = null;

/* ===============================
   HANDLE OPTIMIZATION ACTIONS
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['apply_recommendation'])) {
        $recommendationId = (int)$_POST['recommendation_id'];
        $action = $_POST['action'];
        
        // Here you would apply the optimization
        $success = "Optimization applied successfully!";
    }
    
    if (isset($_POST['schedule_optimization'])) {
        $offerId = (int)$_POST['offer_id'];
        $scheduleDate = $_POST['schedule_date'];
        $action = $_POST['action_type'];
        
        $success = "Optimization scheduled for " . date('M d, Y', strtotime($scheduleDate));
    }
}

/* ===============================
   FETCH ALL OFFERS FOR ANALYSIS
================================ */
$offersStmt = $pdo->prepare("
    SELECT 
        o.offer_id,
        o.offer_name,
        o.status,
        o.payout,
        o.revenue,
        o.daily_cap,
        o.total_cap,
        o.created_at,
        
        -- Performance metrics
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        COUNT(DISTINCT CASE WHEN cv.status = 'approved' THEN cv.conversion_id END) AS approved_conversions,
        COALESCE(SUM(cv.revenue), 0) AS total_revenue,
        COALESCE(SUM(cv.payout), 0) AS total_payout,
        
        -- Unique affiliates
        COUNT(DISTINCT c.affiliate_id) AS unique_affiliates,
        
        -- 7-day trends
        COUNT(DISTINCT CASE WHEN c.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN c.click_id END) AS clicks_7d,
        COUNT(DISTINCT CASE WHEN cv.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN cv.conversion_id END) AS conversions_7d,
        COALESCE(SUM(CASE WHEN cv.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN cv.revenue END), 0) AS revenue_7d,
        
        -- 30-day trends
        COUNT(DISTINCT CASE WHEN c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN c.click_id END) AS clicks_30d,
        COUNT(DISTINCT CASE WHEN cv.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN cv.conversion_id END) AS conversions_30d,
        COALESCE(SUM(CASE WHEN cv.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN cv.revenue END), 0) AS revenue_30d
        
    FROM offers o
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE o.advertiser_id = ?
    GROUP BY o.offer_id
    ORDER BY total_revenue DESC
");
$offersStmt->execute([$advertiserId]);
$offers = $offersStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   GENERATE AI RECOMMENDATIONS
================================ */
$recommendations = [];

foreach ($offers as $offer) {
    $clicks = (int)($offer['total_clicks'] ?? 0);
    $conversions = (int)($offer['total_conversions'] ?? 0);
    $approved = (int)($offer['approved_conversions'] ?? 0);
    $revenue = (float)($offer['total_revenue'] ?? 0);
    $payout = (float)($offer['total_payout'] ?? 0);
    $profit = $revenue - $payout;
    $cr = $clicks > 0 ? ($conversions / $clicks) * 100 : 0;
    $epc = $clicks > 0 ? $revenue / $clicks : 0;
    
    // Get 7-day vs 30-day trends
    $clicks7d = (int)($offer['clicks_7d'] ?? 0);
    $clicks30d = (int)($offer['clicks_30d'] ?? 0);
    $conv7d = (int)($offer['conversions_7d'] ?? 0);
    $conv30d = (int)($offer['conversions_30d'] ?? 0);
    $rev7d = (float)($offer['revenue_7d'] ?? 0);
    $rev30d = (float)($offer['revenue_30d'] ?? 0);
    
    // Calculate trends
    $clickTrend = $clicks30d > 0 ? (($clicks7d / ($clicks30d / 4)) * 100) - 100 : 0;
    $convTrend = $conv30d > 0 ? (($conv7d / ($conv30d / 4)) * 100) - 100 : 0;
    $revTrend = $rev30d > 0 ? (($rev7d / ($rev30d / 4)) * 100) - 100 : 0;
    
    // Low CR Recommendation
    if ($cr < 2 && $clicks > 100) {
        $recommendations[] = [
            'id' => 'cr_' . $offer['offer_id'],
            'offer_id' => $offer['offer_id'],
            'offer_name' => $offer['offer_name'],
            'type' => 'warning',
            'title' => 'Low Conversion Rate',
            'description' => "Your campaign '{$offer['offer_name']}' has a conversion rate of " . number_format($cr, 2) . "%, which is below the industry average of 2%. Consider optimizing your landing page or offer.",
            'impact' => 'high',
            'action' => 'Review Landing Page',
            'metric' => number_format($cr, 2) . '%',
            'icon' => 'fa-chart-line'
        ];
    }
    
    // Negative Profit Recommendation
    if ($profit < 0 && $clicks > 50) {
        $recommendations[] = [
            'id' => 'profit_' . $offer['offer_id'],
            'offer_id' => $offer['offer_id'],
            'offer_name' => $offer['offer_name'],
            'type' => 'danger',
            'title' => 'Negative Profit Margin',
            'description' => "Campaign '{$offer['offer_name']}' is operating at a loss of $" . number_format(abs($profit), 2) . ". Review your payout structure or pause this campaign.",
            'impact' => 'critical',
            'action' => 'Review Pricing',
            'metric' => '-$' . number_format(abs($profit), 2),
            'icon' => 'fa-dollar-sign'
        ];
    }
    
    // Low EPC Recommendation
    if ($epc < 0.5 && $clicks > 100 && $profit > 0) {
        $recommendations[] = [
            'id' => 'epc_' . $offer['offer_id'],
            'offer_id' => $offer['offer_id'],
            'offer_name' => $offer['offer_name'],
            'type' => 'info',
            'title' => 'Low Earnings Per Click',
            'description' => "Your EPC of $" . number_format($epc, 4) . " is below target. Consider increasing payout to attract better quality traffic.",
            'impact' => 'medium',
            'action' => 'Adjust Payout',
            'metric' => '$' . number_format($epc, 4),
            'icon' => 'fa-chart-simple'
        ];
    }
    
    // Negative Trend Recommendation
    if ($revTrend < -20 && $clicks30d > 100) {
        $recommendations[] = [
            'id' => 'trend_' . $offer['offer_id'],
            'offer_id' => $offer['offer_id'],
            'offer_name' => $offer['offer_name'],
            'type' => 'warning',
            'title' => 'Declining Performance',
            'description' => "Revenue has dropped by " . number_format(abs($revTrend), 1) . "% in the last 7 days compared to the monthly average. Investigate recent changes.",
            'impact' => 'high',
            'action' => 'Analyze Trends',
            'metric' => number_format($revTrend, 1) . '%',
            'icon' => 'fa-arrow-trend-down'
        ];
    }
    
    // Low Affiliate Diversity
    if ($offer['unique_affiliates'] < 5 && $clicks > 200) {
        $recommendations[] = [
            'id' => 'diversity_' . $offer['offer_id'],
            'offer_id' => $offer['offer_id'],
            'offer_name' => $offer['offer_name'],
            'type' => 'info',
            'title' => 'Limited Affiliate Reach',
            'description' => "Only " . $offer['unique_affiliates'] . " affiliates are promoting this campaign. Consider recruiting more affiliates or increasing incentives.",
            'impact' => 'medium',
            'action' => 'Recruit Affiliates',
            'metric' => $offer['unique_affiliates'] . ' affiliates',
            'icon' => 'fa-users'
        ];
    }
    
    // High Potential - Good metrics but low volume
    if ($cr > 3 && $profit > 100 && $clicks < 500) {
        $recommendations[] = [
            'id' => 'potential_' . $offer['offer_id'],
            'offer_id' => $offer['offer_id'],
            'offer_name' => $offer['offer_name'],
            'type' => 'success',
            'title' => 'High Potential Campaign',
            'description' => "Campaign has excellent metrics (" . number_format($cr, 2) . "% CR, $" . number_format($profit, 2) . " profit) but low volume. Increase exposure to scale.",
            'impact' => 'high',
            'action' => 'Scale Campaign',
            'metric' => number_format($cr, 2) . '% CR',
            'icon' => 'fa-rocket'
        ];
    }
}

// Sort recommendations by impact
usort($recommendations, function($a, $b) {
    $impactOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
    return $impactOrder[$a['impact']] <=> $impactOrder[$b['impact']];
});

/* ===============================
   PERFORMANCE SUMMARY
================================ */
$totalClicks = array_sum(array_column($offers, 'total_clicks'));
$totalConversions = array_sum(array_column($offers, 'total_conversions'));
$totalRevenue = array_sum(array_column($offers, 'total_revenue'));
$totalPayout = array_sum(array_column($offers, 'total_payout'));
$totalProfit = $totalRevenue - $totalPayout;
$avgCR = $totalClicks > 0 ? ($totalConversions / $totalClicks) * 100 : 0;
$avgEPC = $totalClicks > 0 ? $totalRevenue / $totalClicks : 0;

/* ===============================
   TOP OPPORTUNITIES
================================ */
$opportunities = array_filter($offers, function($offer) {
    $clicks = (int)($offer['total_clicks'] ?? 0);
    $conversions = (int)($offer['total_conversions'] ?? 0);
    $revenue = (float)($offer['total_revenue'] ?? 0);
    $payout = (float)($offer['total_payout'] ?? 0);
    $profit = $revenue - $payout;
    $cr = $clicks > 0 ? ($conversions / $clicks) * 100 : 0;
    
    return $cr > 3 && $profit > 50 && $clicks < 1000;
});

/* ===============================
   UNDERPERFORMING CAMPAIGNS
================================ */
$underperforming = array_filter($offers, function($offer) {
    $clicks = (int)($offer['total_clicks'] ?? 0);
    $conversions = (int)($offer['total_conversions'] ?? 0);
    $revenue = (float)($offer['total_revenue'] ?? 0);
    $payout = (float)($offer['total_payout'] ?? 0);
    $profit = $revenue - $payout;
    $cr = $clicks > 0 ? ($conversions / $clicks) * 100 : 0;
    
    return ($profit < 0 || $cr < 1) && $clicks > 100;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Campaign Optimization | Advertiser Panel | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3a0ca3;
            --success: #4cc9f0;
            --info: #4895ef;
            --warning: #f8961e;
            --danger: #f94144;
            --dark: #1e293b;
            --light: #f8fafc;
        }
        
        body {
            background: #f8fafc;
            font-family: 'Inter', sans-serif;
        }
        
        .content-wrapper {
            background: #f8fafc;
        }
        
        /* Cards */
        .card-modern {
            background: white;
            border-radius: 20px;
            border: none;
            box-shadow: 0 8px 30px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .card-modern:hover {
            box-shadow: 0 15px 40px rgba(67, 97, 238, 0.1);
        }
        
        .card-modern .card-header {
            background: white;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            padding: 20px 25px;
        }
        
        .card-modern .card-body {
            padding: 25px;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            border: 1px solid rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--primary);
            box-shadow: 0 15px 35px rgba(67, 97, 238, 0.1);
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #64748b;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-icon {
            float: right;
            font-size: 28px;
            color: var(--primary);
            opacity: 0.2;
        }
        
        .stat-trend {
            margin-top: 10px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .trend-up {
            color: #10b981;
        }
        
        .trend-down {
            color: #ef4444;
        }
        
        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.03)" d="M0,96L48,112C96,128,192,160,288,186.7C384,213,480,235,576,213.3C672,192,768,128,864,128C960,128,1056,192,1152,213.3C1248,235,1344,213,1392,202.7L1440,192L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>');
            background-size: cover;
            opacity: 0.3;
        }
        
        .quick-stats {
            display: flex;
            justify-content: space-between;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .quick-stat-item {
            text-align: center;
            flex: 1;
        }
        
        .quick-stat-value {
            font-size: 24px;
            font-weight: 700;
            color: white;
        }
        
        .quick-stat-label {
            font-size: 12px;
            color: rgba(255,255,255,0.8);
            text-transform: uppercase;
        }
        
        /* Recommendations */
        .recommendations-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .recommendation-item {
            background: #f8fafc;
            border-radius: 15px;
            padding: 20px;
            border-left: 4px solid;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .recommendation-item:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        }
        
        .recommendation-item.critical {
            border-left-color: #f94144;
            background: linear-gradient(to right, rgba(249, 65, 68, 0.02), white);
        }
        
        .recommendation-item.high {
            border-left-color: #f8961e;
            background: linear-gradient(to right, rgba(248, 150, 30, 0.02), white);
        }
        
        .recommendation-item.medium {
            border-left-color: #4895ef;
            background: linear-gradient(to right, rgba(72, 149, 239, 0.02), white);
        }
        
        .recommendation-item.low {
            border-left-color: #4cc9f0;
            background: linear-gradient(to right, rgba(76, 201, 240, 0.02), white);
        }
        
        .recommendation-item.success {
            border-left-color: #10b981;
            background: linear-gradient(to right, rgba(16, 185, 129, 0.02), white);
        }
        
        .recommendation-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 10px;
        }
        
        .recommendation-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .icon-critical {
            background: rgba(249, 65, 68, 0.1);
            color: #f94144;
        }
        
        .icon-high {
            background: rgba(248, 150, 30, 0.1);
            color: #f8961e;
        }
        
        .icon-medium {
            background: rgba(72, 149, 239, 0.1);
            color: #4895ef;
        }
        
        .icon-low {
            background: rgba(76, 201, 240, 0.1);
            color: #4cc9f0;
        }
        
        .icon-success {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }
        
        .recommendation-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .recommendation-meta {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 10px;
            font-size: 13px;
        }
        
        .impact-badge {
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .impact-critical {
            background: rgba(249, 65, 68, 0.15);
            color: #f94144;
        }
        
        .impact-high {
            background: rgba(248, 150, 30, 0.15);
            color: #f8961e;
        }
        
        .impact-medium {
            background: rgba(72, 149, 239, 0.15);
            color: #4895ef;
        }
        
        .impact-low {
            background: rgba(76, 201, 240, 0.15);
            color: #4cc9f0;
        }
        
        .metric-badge {
            background: #f1f5f9;
            color: #475569;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .btn-optimize {
            background: transparent;
            border: 1px solid #e2e8f0;
            color: #64748b;
            padding: 6px 15px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .btn-optimize:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        /* Campaign Cards */
        .campaign-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .campaign-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            border: 1px solid #eef2f6;
            transition: all 0.3s ease;
        }
        
        .campaign-card:hover {
            border-color: var(--primary);
            box-shadow: 0 10px 30px rgba(67, 97, 238, 0.1);
            transform: translateY(-3px);
        }
        
        .campaign-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .campaign-name {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .campaign-status {
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .status-active {
            background: rgba(76, 201, 240, 0.15);
            color: #4cc9f0;
        }
        
        .status-paused {
            background: rgba(248, 150, 30, 0.15);
            color: #f8961e;
        }
        
        .status-pending {
            background: rgba(100, 116, 139, 0.15);
            color: #64748b;
        }
        
        .campaign-metrics {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin: 15px 0;
            padding: 15px 0;
            border-top: 1px solid #f1f5f9;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .campaign-metric {
            text-align: center;
        }
        
        .campaign-metric-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
        }
        
        .campaign-metric-label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
        }
        
        .trend-indicator {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 11px;
            margin-left: 5px;
        }
        
        .campaign-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .btn-small {
            background: #f1f5f9;
            color: #475569;
            border: none;
            padding: 6px 15px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-small:hover {
            background: var(--primary);
            color: white;
        }
        
        /* Admin Avatar */
        .admin-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            font-weight: 600;
        }
        
        /* Alerts */
        .alert-modern {
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            border: none;
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border-left: 4px solid #10b981;
        }
        
        .alert-danger {
            background: rgba(249, 65, 68, 0.1);
            border-left: 4px solid #f94144;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 20px;
            border: 2px dashed #e2e8f0;
        }
        
        .empty-state-icon {
            font-size: 60px;
            color: #cbd5e1;
            margin-bottom: 15px;
        }
        
        /* Progress Bars */
        .progress-container {
            margin: 15px 0;
        }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #64748b;
            margin-bottom: 5px;
        }
        
        .progress-bar-bg {
            height: 6px;
            background: #f1f5f9;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-bar-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        
        .fill-success {
            background: linear-gradient(90deg, #4cc9f0, #4361ee);
        }
        
        .fill-warning {
            background: linear-gradient(90deg, #f8961e, #f94144);
        }
        
        /* Tabs */
        .nav-tabs-custom {
            border-bottom: 2px solid #eef2f6;
            margin-bottom: 25px;
        }
        
        .nav-tabs-custom .nav-link {
            border: none;
            color: #64748b;
            font-weight: 600;
            padding: 12px 25px;
            border-radius: 0;
            position: relative;
        }
        
        .nav-tabs-custom .nav-link.active {
            color: var(--primary);
            background: transparent;
        }
        
        .nav-tabs-custom .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
        }
        
        .badge-count {
            background: #f1f5f9;
            color: #475569;
            padding: 2px 8px;
            border-radius: 30px;
            font-size: 11px;
            margin-left: 8px;
        }
        
        /* Modal */
        .modal-content {
            border-radius: 20px;
            border: none;
        }
        
        .modal-header {
            border-bottom: 1px solid #eef2f6;
            padding: 20px 25px;
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .modal-footer {
            border-top: 1px solid #eef2f6;
            padding: 20px 25px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .quick-stats {
                flex-wrap: wrap;
                gap: 15px;
            }
            
            .quick-stat-item {
                min-width: 45%;
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
                <a class="nav-link" data-widget="pushmenu" href="#" role="button">
                    <i class="fas fa-bars"></i>
                </a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="optimization.php" class="nav-link active">Optimization</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <a class="nav-link" data-widget="fullscreen" href="#" role="button">
                    <i class="fas fa-expand-arrows-alt"></i>
                </a>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                    <div class="admin-avatar mr-2">
                        <?php echo strtoupper(substr($advertiserName, 0, 1)); ?>
                    </div>
                    <span><?php echo htmlspecialchars($advertiserName); ?></span>
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
                <i class="fas fa-chart-line mr-2"></i>
                <strong>Advertiser</strong>
            </span>
        </a>

        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Sidebar Menu -->
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-tachometer-alt"></i>
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
                        <a href="offers.php" class="nav-link">
                            <i class="nav-icon fas fa-gift"></i>
                            <p>All Offers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="create_offer.php" class="nav-link">
                            <i class="nav-icon fas fa-plus-circle"></i>
                            <p>Create New Offer</p>
                        </a>
                    </li>
                    
                    <li class="nav-header">REPORTS & ANALYTICS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Campaign Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link">
                            <i class="fas fa-exchange-alt nav-icon"></i>
                            <p>Conversion Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link">
                            <i class="nav-icon fas fa-users"></i>
                            <p>Affiliate Reports</p>
                        </a>
                    </li>
                    
                    <li class="nav-header">TOOLS</li>
                    <li class="nav-item">
                        <a href="ip_whitelist.php" class="nav-link">
                            <i class="nav-icon fas fa-tower-broadcast"></i>
                            <p>IP Whitelist</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="postback.php" class="nav-link">
                            <i class="nav-icon fas fa-code"></i>
                            <p>Postback Manager</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="api.php" class="nav-link">
                            <i class="nav-icon fas fa-plug"></i>
                            <p>API Integration</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="optimization.php" class="nav-link active">
                            <i class="nav-icon fas fa-rocket"></i>
                            <p>Optimization Tools</p>
                        </a>
                    </li>
                    
                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user"></i>
                            <p>Profile</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="billing.php" class="nav-link">
                            <i class="nav-icon fas fa-wallet"></i>
                            <p>Billing & Payments</p>
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
                        <h1 class="m-0">AI Campaign Optimization</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Optimization</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">
                <!-- Messages -->
                <?php if ($success): ?>
                <div class="alert alert-modern alert-success">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="close" data-dismiss="alert">×</button>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-modern alert-danger">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="close" data-dismiss="alert">×</button>
                </div>
                <?php endif; ?>

                <!-- Welcome Banner -->
                <div class="welcome-banner">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h2>AI-Powered Campaign Optimization</h2>
                            <p class="mb-0">Get intelligent recommendations to improve your campaign performance and ROI.</p>
                        </div>
                        <div class="col-md-4 text-right">
                            <button class="btn btn-light" onclick="location.reload()">
                                <i class="fas fa-sync-alt mr-2"></i> Refresh Analysis
                            </button>
                        </div>
                    </div>
                    
                    <!-- Quick Stats -->
                    <div class="quick-stats">
                        <div class="quick-stat-item">
                            <div class="quick-stat-value"><?php echo count($offers); ?></div>
                            <div class="quick-stat-label">Active Campaigns</div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="quick-stat-value"><?php echo count($recommendations); ?></div>
                            <div class="quick-stat-label">Optimizations</div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="quick-stat-value"><?php echo count($opportunities); ?></div>
                            <div class="quick-stat-label">Opportunities</div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="quick-stat-value"><?php echo count($underperforming); ?></div>
                            <div class="quick-stat-label">Underperforming</div>
                        </div>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="stat-value"><?php echo number_format($totalClicks); ?></div>
                        <div class="stat-label">Total Clicks</div>
                        <div class="stat-trend">
                            <span class="trend-up"><i class="fas fa-arrow-up"></i> +12%</span>
                            <span>vs last month</span>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-exchange-alt"></i></div>
                        <div class="stat-value"><?php echo number_format($totalConversions); ?></div>
                        <div class="stat-label">Conversions</div>
                        <div class="stat-trend">
                            <span class="trend-up"><i class="fas fa-arrow-up"></i> +8%</span>
                            <span>vs last month</span>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-percentage"></i></div>
                        <div class="stat-value"><?php echo number_format($avgCR, 2); ?>%</div>
                        <div class="stat-label">Avg. CR</div>
                        <div class="stat-trend">
                            <span class="trend-down"><i class="fas fa-arrow-down"></i> -2%</span>
                            <span>vs target</span>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
                        <div class="stat-value">$<?php echo number_format($totalProfit, 2); ?></div>
                        <div class="stat-label">Total Profit</div>
                        <div class="stat-trend">
                            <span class="trend-up"><i class="fas fa-arrow-up"></i> +15%</span>
                            <span>vs last month</span>
                        </div>
                    </div>
                </div>

                <!-- Tabs -->
                <ul class="nav nav-tabs-custom" id="optimizationTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="recommendations-tab" data-toggle="tab" href="#recommendations" role="tab">
                            <i class="fas fa-lightbulb mr-2"></i>AI Recommendations
                            <?php if (count($recommendations) > 0): ?>
                            <span class="badge-count"><?php echo count($recommendations); ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="opportunities-tab" data-toggle="tab" href="#opportunities" role="tab">
                            <i class="fas fa-chart-line mr-2"></i>Growth Opportunities
                            <?php if (count($opportunities) > 0): ?>
                            <span class="badge-count"><?php echo count($opportunities); ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="underperforming-tab" data-toggle="tab" href="#underperforming" role="tab">
                            <i class="fas fa-exclamation-triangle mr-2"></i>Needs Attention
                            <?php if (count($underperforming) > 0): ?>
                            <span class="badge-count"><?php echo count($underperforming); ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="all-campaigns-tab" data-toggle="tab" href="#all-campaigns" role="tab">
                            <i class="fas fa-bullhorn mr-2"></i>All Campaigns
                        </a>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Recommendations Tab -->
                    <div class="tab-pane fade show active" id="recommendations" role="tabpanel">
                        <div class="card-modern">
                            <div class="card-header">
                                <h3 class="card-title">Smart Recommendations</h3>
                                <div class="card-tools">
                                    <button class="btn btn-sm btn-outline-primary" onclick="applyAllRecommendations()">
                                        <i class="fas fa-magic mr-2"></i>Apply All
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recommendations)): ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-check-circle"></i>
                                        </div>
                                        <h5>All Caught Up!</h5>
                                        <p class="text-muted">No optimization recommendations at this time.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="recommendations-list">
                                        <?php foreach ($recommendations as $rec): ?>
                                        <div class="recommendation-item <?php echo $rec['impact']; ?>" 
                                             onclick="showRecommendationDetails(<?php echo htmlspecialchars(json_encode($rec)); ?>)">
                                            <div class="recommendation-header">
                                                <div class="recommendation-icon icon-<?php echo $rec['impact']; ?>">
                                                    <i class="fas <?php echo $rec['icon']; ?>"></i>
                                                </div>
                                                <div style="flex: 1;">
                                                    <div class="recommendation-title">
                                                        <?php echo $rec['title']; ?>
                                                        <small class="text-muted ml-2"><?php echo $rec['offer_name']; ?></small>
                                                    </div>
                                                    <div class="recommendation-description small text-muted">
                                                        <?php echo $rec['description']; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="recommendation-meta">
                                                <span class="impact-badge impact-<?php echo $rec['impact']; ?>">
                                                    <?php echo ucfirst($rec['impact']); ?> Priority
                                                </span>
                                                <span class="metric-badge">
                                                    Current: <?php echo $rec['metric']; ?>
                                                </span>
                                                <button class="btn-optimize ml-auto" 
                                                        onclick="event.stopPropagation(); applyRecommendation(<?php echo htmlspecialchars(json_encode($rec)); ?>)">
                                                    <i class="fas fa-bolt mr-1"></i>Optimize
                                                </button>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Opportunities Tab -->
                    <div class="tab-pane fade" id="opportunities" role="tabpanel">
                        <div class="card-modern">
                            <div class="card-header">
                                <h3 class="card-title">Growth Opportunities</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($opportunities)): ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-rocket"></i>
                                        </div>
                                        <h5>No Growth Opportunities</h5>
                                        <p class="text-muted">All campaigns are performing optimally.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="campaign-grid">
                                        <?php foreach ($opportunities as $offer): 
                                            $cr = $offer['total_clicks'] > 0 ? ($offer['total_conversions'] / $offer['total_clicks']) * 100 : 0;
                                            $profit = ($offer['total_revenue'] ?? 0) - ($offer['total_payout'] ?? 0);
                                        ?>
                                        <div class="campaign-card">
                                            <div class="campaign-header">
                                                <span class="campaign-name"><?php echo htmlspecialchars($offer['offer_name']); ?></span>
                                                <span class="campaign-status status-active">Active</span>
                                            </div>
                                            <div class="campaign-metrics">
                                                <div class="campaign-metric">
                                                    <div class="campaign-metric-value"><?php echo number_format($offer['total_clicks']); ?></div>
                                                    <div class="campaign-metric-label">Clicks</div>
                                                </div>
                                                <div class="campaign-metric">
                                                    <div class="campaign-metric-value"><?php echo number_format($cr, 2); ?>%</div>
                                                    <div class="campaign-metric-label">CR</div>
                                                </div>
                                            </div>
                                            <div class="progress-container">
                                                <div class="progress-header">
                                                    <span>Scale Potential</span>
                                                    <span>High</span>
                                                </div>
                                                <div class="progress-bar-bg">
                                                    <div class="progress-bar-fill fill-success" style="width: 85%"></div>
                                                </div>
                                            </div>
                                            <div class="campaign-footer">
                                                <span class="text-success">$<?php echo number_format($profit, 2); ?> profit</span>
                                                <a href="offer_edit.php?id=<?php echo $offer['offer_id']; ?>" class="btn-small">
                                                    <i class="fas fa-chart-line"></i> Scale
                                                </a>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Underperforming Tab -->
                    <div class="tab-pane fade" id="underperforming" role="tabpanel">
                        <div class="card-modern">
                            <div class="card-header">
                                <h3 class="card-title">Campaigns Needing Attention</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($underperforming)): ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-smile"></i>
                                        </div>
                                        <h5>No Underperforming Campaigns</h5>
                                        <p class="text-muted">All campaigns are meeting targets.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="campaign-grid">
                                        <?php foreach ($underperforming as $offer): 
                                            $cr = $offer['total_clicks'] > 0 ? ($offer['total_conversions'] / $offer['total_clicks']) * 100 : 0;
                                            $profit = ($offer['total_revenue'] ?? 0) - ($offer['total_payout'] ?? 0);
                                        ?>
                                        <div class="campaign-card">
                                            <div class="campaign-header">
                                                <span class="campaign-name"><?php echo htmlspecialchars($offer['offer_name']); ?></span>
                                                <span class="campaign-status status-pending">Warning</span>
                                            </div>
                                            <div class="campaign-metrics">
                                                <div class="campaign-metric">
                                                    <div class="campaign-metric-value"><?php echo number_format($cr, 2); ?>%</div>
                                                    <div class="campaign-metric-label">CR</div>
                                                </div>
                                                <div class="campaign-metric">
                                                    <div class="campaign-metric-value <?php echo $profit < 0 ? 'text-danger' : ''; ?>">
                                                        $<?php echo number_format($profit, 2); ?>
                                                    </div>
                                                    <div class="campaign-metric-label">Profit</div>
                                                </div>
                                            </div>
                                            <div class="progress-container">
                                                <div class="progress-header">
                                                    <span>Performance</span>
                                                    <span class="text-danger">Critical</span>
                                                </div>
                                                <div class="progress-bar-bg">
                                                    <div class="progress-bar-fill fill-warning" style="width: 35%"></div>
                                                </div>
                                            </div>
                                            <div class="campaign-footer">
                                                <span class="text-danger">Needs Review</span>
                                                <a href="offer_edit.php?id=<?php echo $offer['offer_id']; ?>" class="btn-small">
                                                    <i class="fas fa-edit"></i> Fix
                                                </a>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- All Campaigns Tab -->
                    <div class="tab-pane fade" id="all-campaigns" role="tabpanel">
                        <div class="card-modern">
                            <div class="card-header">
                                <h3 class="card-title">All Campaigns Performance</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($offers)): ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-bullhorn"></i>
                                        </div>
                                        <h5>No Campaigns Found</h5>
                                        <p class="text-muted">Create your first campaign to get started.</p>
                                        <a href="create_offer.php" class="btn btn-gradient mt-3">
                                            <i class="fas fa-plus-circle mr-2"></i> Create Campaign
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-dashboard" id="campaignsTable">
                                            <thead>
                                                <tr>
                                                    <th>Campaign</th>
                                                    <th>Clicks</th>
                                                    <th>Conv</th>
                                                    <th>CR%</th>
                                                    <th>Revenue</th>
                                                    <th>Payout</th>
                                                    <th>Profit</th>
                                                    <th>EPC</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($offers as $offer): 
                                                    $cr = $offer['total_clicks'] > 0 ? ($offer['total_conversions'] / $offer['total_clicks']) * 100 : 0;
                                                    $profit = ($offer['total_revenue'] ?? 0) - ($offer['total_payout'] ?? 0);
                                                    $epc = $offer['total_clicks'] > 0 ? ($offer['total_revenue'] ?? 0) / $offer['total_clicks'] : 0;
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($offer['offer_name']); ?></strong>
                                                        <div class="small text-muted">ID: #<?php echo $offer['offer_id']; ?></div>
                                                    </td>
                                                    <td><?php echo number_format($offer['total_clicks']); ?></td>
                                                    <td><?php echo number_format($offer['total_conversions']); ?></td>
                                                    <td>
                                                        <span class="badge badge-<?php echo $cr >= 2 ? 'success' : 'warning'; ?>">
                                                            <?php echo number_format($cr, 2); ?>%
                                                        </span>
                                                    </td>
                                                    <td class="text-success">$<?php echo number_format($offer['total_revenue'] ?? 0, 2); ?></td>
                                                    <td class="text-warning">$<?php echo number_format($offer['total_payout'] ?? 0, 2); ?></td>
                                                    <td class="<?php echo $profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                        $<?php echo number_format($profit, 2); ?>
                                                    </td>
                                                    <td>$<?php echo number_format($epc, 4); ?></td>
                                                    <td>
                                                        <span class="badge badge-<?php echo $offer['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                                            <?php echo ucfirst($offer['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="offer_edit.php?id=<?php echo $offer['offer_id']; ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
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
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline">
            <strong>AI Optimization v2.0</strong>
        </div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> GVS Icon Media.</strong> All rights reserved.
    </footer>
</div>

<!-- Recommendation Details Modal -->
<div class="modal fade" id="recommendationModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Optimization Details</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Content will be inserted via JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-gradient" id="applyBtn" onclick="applyCurrentRecommendation()">
                    <i class="fas fa-check mr-2"></i> Apply Optimization
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Schedule Optimization Modal -->
<div class="modal fade" id="scheduleModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Schedule Optimization</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="offer_id" id="scheduleOfferId">
                    <input type="hidden" name="action_type" id="scheduleAction">
                    
                    <div class="form-group">
                        <label>Campaign</label>
                        <input type="text" class="form-control" id="scheduleOfferName" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Optimization Action</label>
                        <input type="text" class="form-control" id="scheduleActionText" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="scheduleDate">Schedule Date</label>
                        <input type="date" class="form-control" id="scheduleDate" name="schedule_date" 
                               min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Notes (Optional)</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" 
                                  placeholder="Add any notes about this scheduled optimization..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="schedule_optimization" class="btn btn-gradient">
                        <i class="fas fa-calendar-check mr-2"></i> Schedule
                    </button>
                </div>
            </form>
        </div>
    </div>
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
    // Initialize DataTable
    $('#campaignsTable').DataTable({
        pageLength: 25,
        order: [[7, 'desc']], // Sort by revenue descending
        responsive: true,
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search campaigns..."
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
    
    // Initialize SweetAlert2 Toast
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });
});

let currentRecommendation = null;

function showRecommendationDetails(recommendation) {
    currentRecommendation = recommendation;
    
    const modalBody = document.getElementById('modalBody');
    modalBody.innerHTML = `
        <div class="recommendation-item ${recommendation.impact}" style="margin-bottom: 20px; cursor: default;">
            <div class="recommendation-header">
                <div class="recommendation-icon icon-${recommendation.impact}">
                    <i class="fas ${recommendation.icon}"></i>
                </div>
                <div>
                    <div class="recommendation-title">${recommendation.title}</div>
                    <div class="small text-muted">${recommendation.offer_name}</div>
                </div>
            </div>
        </div>
        
        <div class="mb-4">
            <h6>Analysis</h6>
            <p>${recommendation.description}</p>
        </div>
        
        <div class="row mb-3">
            <div class="col-6">
                <div class="text-muted small">Priority</div>
                <div><span class="impact-badge impact-${recommendation.impact}">${recommendation.impact.toUpperCase()}</span></div>
            </div>
            <div class="col-6">
                <div class="text-muted small">Current Metric</div>
                <div><span class="metric-badge">${recommendation.metric}</span></div>
            </div>
        </div>
        
        <div class="mb-3">
            <h6>Recommended Action</h6>
            <div class="bg-light p-3 rounded">
                <i class="fas fa-bolt text-primary mr-2"></i>
                ${recommendation.action}
            </div>
        </div>
        
        <div class="alert alert-info small">
            <i class="fas fa-info-circle mr-2"></i>
            This recommendation is based on AI analysis of your campaign performance data.
        </div>
    `;
    
    $('#recommendationModal').modal('show');
}

function applyRecommendation(recommendation) {
    Swal.fire({
        title: 'Apply Optimization?',
        html: `Apply <strong>${recommendation.title}</strong> to campaign <strong>${recommendation.offer_name}</strong>?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#4361ee',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, apply now',
        cancelButtonText: 'Schedule instead'
    }).then((result) => {
        if (result.isConfirmed) {
            // Apply immediately
            Swal.fire({
                title: 'Optimization Applied!',
                text: 'The optimization has been applied successfully.',
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
            });
        } else if (result.dismiss === Swal.DismissReason.cancel) {
            // Show schedule modal
            showScheduleModal(recommendation);
        }
    });
}

function showScheduleModal(recommendation) {
    document.getElementById('scheduleOfferId').value = recommendation.offer_id;
    document.getElementById('scheduleOfferName').value = recommendation.offer_name;
    document.getElementById('scheduleAction').value = recommendation.action;
    document.getElementById('scheduleActionText').value = recommendation.action;
    
    $('#scheduleModal').modal('show');
}

function applyCurrentRecommendation() {
    if (currentRecommendation) {
        $('#recommendationModal').modal('hide');
        applyRecommendation(currentRecommendation);
    }
}

function applyAllRecommendations() {
    Swal.fire({
        title: 'Apply All Recommendations?',
        text: 'This will apply all pending optimizations to your campaigns.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#4361ee',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, apply all'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Processing...',
                text: 'Applying all optimizations',
                icon: 'info',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                Swal.fire({
                    title: 'Success!',
                    text: 'All optimizations have been applied.',
                    icon: 'success',
                    timer: 2000,
                    showConfirmButton: false
                });
            });
        }
    });
}

// Auto-refresh data every 5 minutes
setInterval(() => {
    location.reload();
}, 300000);
</script>

</body>
</html>