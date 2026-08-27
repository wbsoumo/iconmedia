<?php
define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('advertiser');
$advId = auth_user_id();

if($_SERVER['REQUEST_METHOD']==='POST'){
    $name = trim($_POST['name']);
    $url  = trim($_POST['url']);
    $payout = (float)$_POST['payout'];

    $token = bin2hex(random_bytes(16));

    $stmt = $pdo->prepare("
        INSERT INTO offers
        (advertiser_id, offer_name, offer_url, payout, revenue, postback_token, status, created_at)
        VALUES (:aid,:n,:u,:p,:r,:t,'pending',NOW())
    ");
    $stmt->execute([
        'aid'=>$advId,
        'n'=>$name,
        'u'=>$url,
        'p'=>$payout,
        'r'=>$payout * 1.3,
        't'=>$token
    ]);

    header("Location: offers.php");
    exit;
}
?>
<h2>Create Offer</h2>
<form method="post">
Name <input name="name" required><br>
URL <input name="url" required><br>
Payout <input type="number" step="0.01" name="payout" required><br>
<button>Create</button>
</form>
