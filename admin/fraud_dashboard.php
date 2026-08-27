<?php
define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['admin', 'manager']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Fraud Signals Dashboard</title>
</head>
<body>

<h2>Fraud Signals Dashboard</h2>

<hr>

<!-- ============================= -->
<h3>1️⃣ Fast Conversions (&lt; 5 sec)</h3>
<table border="1" cellpadding="6">
<tr>
    <th>Click ID</th>
    <th>Affiliate</th>
    <th>Offer</th>
    <th>Seconds</th>
</tr>
<?php
$fast = $pdo->query("
    SELECT
        c.click_id,
        u.name AS affiliate,
        o.offer_name,
        TIMESTAMPDIFF(SECOND, cl.created_at, c.created_at) AS seconds_diff
    FROM conversions c
    INNER JOIN clicks cl ON cl.click_id = c.click_id
    INNER JOIN users u ON u.user_id = cl.affiliate_id
    INNER JOIN offers o ON o.offer_id = cl.offer_id
    WHERE c.status = 'approved'
      AND TIMESTAMPDIFF(SECOND, cl.created_at, c.created_at) < 5
    ORDER BY seconds_diff ASC
    LIMIT 50
")->fetchAll();

foreach ($fast as $r): ?>
<tr>
    <td><?= $r['click_id'] ?></td>
    <td><?= htmlspecialchars($r['affiliate']) ?></td>
    <td><?= htmlspecialchars($r['offer_name']) ?></td>
    <td><?= (int)$r['seconds_diff'] ?></td>
</tr>
<?php endforeach; ?>
</table>

<hr>

<!-- ============================= -->
<h3>2️⃣ Multiple Conversions from Same IP</h3>
<table border="1" cellpadding="6">
<tr>
    <th>IP</th>
    <th>Conversions</th>
</tr>
<?php
$ips = $pdo->query("
    SELECT
        INET6_NTOA(cl.ip_address) AS ip,
        COUNT(c.conversion_id) AS cnt
    FROM conversions c
    INNER JOIN clicks cl ON cl.click_id = c.click_id
    WHERE c.status = 'approved'
    GROUP BY cl.ip_address
    HAVING cnt >= 3
    ORDER BY cnt DESC
    LIMIT 50
")->fetchAll();

foreach ($ips as $r): ?>
<tr>
    <td><?= $r['ip'] ?></td>
    <td><?= $r['cnt'] ?></td>
</tr>
<?php endforeach; ?>
</table>

<hr>

<!-- ============================= -->
<h3>3️⃣ High Clicks, Zero Conversions</h3>
<table border="1" cellpadding="6">
<tr>
    <th>Affiliate</th>
    <th>Clicks</th>
</tr>
<?php
$badAff = $pdo->query("
    SELECT
        u.name,
        COUNT(cl.click_id) AS clicks
    FROM users u
    LEFT JOIN clicks cl ON cl.affiliate_id = u.user_id
    LEFT JOIN conversions c ON c.click_id = cl.click_id
    WHERE u.role_id = (SELECT role_id FROM roles WHERE role_name='affiliate')
    GROUP BY u.user_id
    HAVING clicks >= 50 AND SUM(CASE WHEN c.conversion_id IS NOT NULL THEN 1 ELSE 0 END) = 0
    ORDER BY clicks DESC
")->fetchAll();

foreach ($badAff as $r): ?>
<tr>
    <td><?= htmlspecialchars($r['name']) ?></td>
    <td><?= (int)$r['clicks'] ?></td>
</tr>
<?php endforeach; ?>
</table>

<hr>

<!-- ============================= -->
<h3>4️⃣ Postback Abuse / Failures</h3>
<table border="1" cellpadding="6">
<tr>
    <th>Status</th>
    <th>Count</th>
</tr>
<?php
$pb = $pdo->query("
    SELECT status, COUNT(*) cnt
    FROM postback_logs
    WHERE status IN ('invalid_token','ip_blocked','duplicate')
    GROUP BY status
")->fetchAll();

foreach ($pb as $r): ?>
<tr>
    <td><?= strtoupper($r['status']) ?></td>
    <td><?= (int)$r['cnt'] ?></td>
</tr>
<?php endforeach; ?>
</table>

</body>
</html>
