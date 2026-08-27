<?php
define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['admin', 'manager']);

$adminName = $_SESSION['user_name'] ?? 'Admin';

// Date filter
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');

$where = '';
$params = [];

if ($from && $to) {
    $where = "WHERE DATE(c.created_at) BETWEEN :from AND :to";
    $params['from'] = $from;
    $params['to']   = $to;
}

$sql = "
SELECT
    o.offer_id,
    o.offer_name,
    COUNT(DISTINCT cl.click_id) AS clicks,
    COUNT(c.conversion_id) AS conversions,
    SUM(CASE WHEN c.status='approved' THEN c.payout ELSE 0 END) AS payout,
    SUM(CASE WHEN c.status='approved' THEN c.revenue ELSE 0 END) AS revenue
FROM offers o
LEFT JOIN clicks cl ON cl.offer_id = o.offer_id
LEFT JOIN conversions c ON c.offer_id = o.offer_id
{$where}
GROUP BY o.offer_id
ORDER BY revenue DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offer Performance Report · Admin Control Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background-color: #0b1120; color: #f8fafc; padding: 24px; min-height: 100vh; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .header h1 { font-size: 24px; font-weight: 700; }
        .back-btn { color: #3b82f6; text-decoration: none; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; }
        .card { background: rgba(15, 23, 42, 0.8); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 24px; margin-bottom: 24px; }
        .filter-form { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
        .form-group { display: flex; align-items: center; gap: 8px; }
        label { font-size: 14px; color: #94a3b8; font-weight: 500; }
        input[type="date"] { background: #1e293b; border: 1px solid #334155; color: #fff; padding: 8px 12px; border-radius: 8px; font-size: 14px; }
        .btn-filter { background: #2563eb; color: #fff; border: none; padding: 8px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 14px; text-align: left; }
        th { background: #1e293b; color: #94a3b8; padding: 12px 16px; font-weight: 600; border-bottom: 1px solid #334155; }
        td { padding: 14px 16px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        tr:hover { background: rgba(30, 41, 59, 0.5); }
        .badge { background: rgba(59,130,246,0.15); color: #60a5fa; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="header">
        <h1><i class="fas fa-chart-line" style="color: #3b82f6; margin-right: 8px;"></i> Offer Performance Report</h1>
        <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <div class="card">
        <form method="get" class="filter-form">
            <div class="form-group">
                <label for="from">From:</label>
                <input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>">
            </div>
            <div class="form-group">
                <label for="to">To:</label>
                <input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>">
            </div>
            <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply Filter</button>
        </form>
    </div>

    <div class="card table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Offer Name</th>
                    <th>Clicks</th>
                    <th>Conversions</th>
                    <th>Payout</th>
                    <th>Revenue</th>
                    <th>Profit</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: #64748b; padding: 24px;">No performance data found for the selected date range.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td style="font-weight: 600; color: #fff;"><?= htmlspecialchars($r['offer_name']) ?></td>
                            <td><span class="badge"><?= (int)$r['clicks'] ?></span></td>
                            <td><?= (int)$r['conversions'] ?></td>
                            <td style="color: #f87171;">$<?= number_format((float)$r['payout'], 2) ?></td>
                            <td style="color: #4ade80;">$<?= number_format((float)$r['revenue'], 2) ?></td>
                            <td style="font-weight: 700; color: <?= ($r['revenue'] - $r['payout'] >= 0) ? '#60a5fa' : '#f87171' ?>;">
                                $<?= number_format(($r['revenue'] - $r['payout']), 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
