<?php
define('APP_INIT', true);

require_once __DIR__ . '/../../app/core/auth.php';
require_once __DIR__ . '/../../app/config/database.php';

require_any_role(['admin', 'manager']);

$from = $_GET['from'] ?? null;
$to   = $_GET['to'] ?? null;

$where = "WHERE cl.sub1 IS NOT NULL";
$params = [];

if ($from && $to) {
    $where .= " AND DATE(cl.created_at) BETWEEN :from AND :to";
    $params['from'] = $from;
    $params['to']   = $to;
}

$sql = "
SELECT
    cl.sub1,
    COUNT(cl.click_id) AS clicks,
    COUNT(c.conversion_id) AS conversions,
    SUM(CASE WHEN c.status='approved' THEN c.payout ELSE 0 END) AS payout
FROM clicks cl
LEFT JOIN conversions c ON c.click_id = cl.click_id
{$where}
GROUP BY cl.sub1
ORDER BY payout DESC
LIMIT 100
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>SubID Report</title>
</head>
<body>

<h2>SubID Performance (sub1)</h2>

<form method="get">
    From: <input type="date" name="from">
    To: <input type="date" name="to">
    <button type="submit">Filter</button>
</form>

<table border="1" cellpadding="6">
<tr>
    <th>SubID</th>
    <th>Clicks</th>
    <th>Conversions</th>
    <th>Payout</th>
    <th>CR (%)</th>
</tr>

<?php foreach ($rows as $r): 
    $cr = $r['clicks'] > 0 ? ($r['conversions'] / $r['clicks']) * 100 : 0;
?>
<tr>
    <td><?= htmlspecialchars($r['sub1']) ?></td>
    <td><?= (int)$r['clicks'] ?></td>
    <td><?= (int)$r['conversions'] ?></td>
    <td>$<?= number_format($r['payout'], 2) ?></td>
    <td><?= number_format($cr, 2) ?></td>
</tr>
<?php endforeach; ?>

</table>

</body>
</html>
