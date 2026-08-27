<?php
define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('affiliate');

$affiliateId = auth_user_id();

$stmt = $pdo->prepare("
    SELECT
        o.offer_id,
        o.offer_name,
        o.payout,
        o.currency
    FROM offers o
    INNER JOIN affiliate_offer_approval a
        ON a.offer_id = o.offer_id
    WHERE a.affiliate_id = :aid
      AND a.status = 'approved'
      AND o.status = 'approved'
    ORDER BY o.created_at DESC
");
$stmt->execute(['aid' => $affiliateId]);
$offers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Approved Offers</title>
</head>
<body>

<h2>Approved Offers</h2>

<table border="1" cellpadding="6">
<tr>
    <th>Offer</th>
    <th>Payout</th>
    <th>Action</th>
</tr>

<?php if (!$offers): ?>
<tr><td colspan="3">No approved offers</td></tr>
<?php endif; ?>

<?php foreach ($offers as $o): ?>
<tr>
    <td><?= htmlspecialchars($o['offer_name']) ?></td>
    <td><?= $o['currency'] ?> <?= number_format($o['payout'], 2) ?></td>
    <td>
        <a href="offer_view.php?id=<?= $o['offer_id'] ?>">View</a>
    </td>
</tr>
<?php endforeach; ?>

</table>

</body>
</html>
