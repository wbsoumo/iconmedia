<?php
define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('advertiser');
$advId = auth_user_id();

$stmt = $pdo->prepare("
SELECT
o.offer_name,
COUNT(c.conversion_id) AS conversions,
SUM(CASE WHEN c.status='approved' THEN c.revenue ELSE 0 END) revenue
FROM offers o
LEFT JOIN conversions c ON c.offer_id=o.offer_id
WHERE o.advertiser_id=:aid
GROUP BY o.offer_id
");
$stmt->execute(['aid'=>$advId]);
$r=$stmt->fetchAll();
?>
<h2>Offer Reports</h2>
<table border="1">
<tr><th>Offer</th><th>Conversions</th><th>Spend</th></tr>
<?php foreach($r as $row): ?>
<tr>
<td><?= htmlspecialchars($row['offer_name']) ?></td>
<td><?= $row['conversions'] ?></td>
<td>$<?= number_format($row['revenue'],2) ?></td>
</tr>
<?php endforeach;?>
</table>
