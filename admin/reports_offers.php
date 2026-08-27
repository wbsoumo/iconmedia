<?php
define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['admin', 'manager']);

// Date filter
$from = $_GET['from'] ?? null;
$to   = $_GET['to'] ?? null;

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
<html>
<head>
    <title>Offer Reports</title>
</head>
<body>

<h2>Offer Performance Report</h2>

<form method="get">
    From: <input type="date" name="from">
    To: <input type="date" name="to">
    <button type="submit">Filter</button>
</form>

<table border="1" cellpadding="6">
<tr>
    <th>Offer</th>
    <th>Clicks</th>
    <th>Conversions</th>
    <th>Payout</th>
    <th>Revenue</th>
    <th>Profit</th>
</tr>

<?php foreach ($rows as $r): ?>
<tr>
    <td><?= htmlspecialchars($r['offer_name']) ?></td>
    <td><?= (int)$r['clicks'] ?></td>
    <td><?= (int)$r['conversions'] ?></td>
    <td>$<?= number_format($r['payout'], 2) ?></td>
    <td>$<?= number_format($r['revenue'], 2) ?></td>
    <td>
        $<?= number_format(($r['revenue'] - $r['payout']), 2) ?>
    </td>
</tr>
<?php endforeach; ?>

</table>

</body>
</html>
