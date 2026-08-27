<?php
define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['admin', 'manager']);

$stmt = $pdo->prepare("
    SELECT
        a.id,
        u.name AS affiliate_name,
        u.email AS affiliate_email,
        o.offer_name,
        a.status,
        a.approved_at
    FROM affiliate_offer_approval a
    INNER JOIN users u ON u.user_id = a.affiliate_id
    INNER JOIN offers o ON o.offer_id = a.offer_id
    WHERE a.status = 'pending'
    ORDER BY a.id DESC
");
$stmt->execute();

$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Offer Requests</title>
</head>
<body>

<h2>Pending Offer Requests</h2>

<table border="1" cellpadding="6">
<tr>
    <th>Affiliate</th>
    <th>Email</th>
    <th>Offer</th>
    <th>Action</th>
</tr>

<?php if (!$rows): ?>
<tr><td colspan="4">No pending requests</td></tr>
<?php endif; ?>

<?php foreach ($rows as $r): ?>
<tr>
    <td><?= htmlspecialchars($r['affiliate_name']) ?></td>
    <td><?= htmlspecialchars($r['affiliate_email']) ?></td>
    <td><?= htmlspecialchars($r['offer_name']) ?></td>
    <td>
        <a href="offer_request_action.php?id=<?= $r['id'] ?>&action=approve">Approve</a> |
        <a href="offer_request_action.php?id=<?= $r['id'] ?>&action=reject"
           onclick="return confirm('Reject this request?')">Reject</a>
    </td>
</tr>
<?php endforeach; ?>
</table>

</body>
</html>
