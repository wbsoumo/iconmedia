<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/app/config/database.php';
require_once __DIR__ . '/app/core/auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help Center & Knowledge Base | GVS Icon Media</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 0; }
        .hero { background: #1e293b; padding: 60px 20px; text-align: center; border-bottom: 1px solid #334155; }
        .hero h1 { font-size: 36px; font-weight: 800; color: #38bdf8; margin-bottom: 10px; }
        .container { max-width: 900px; margin: 40px auto; padding: 0 20px; }
        .faq-item { background: #1e293b; border-radius: 12px; padding: 24px; margin-bottom: 20px; border: 1px solid #334155; }
        .faq-item h3 { font-size: 18px; color: #38bdf8; margin-bottom: 8px; }
        .faq-item p { color: #94a3b8; font-size: 14px; margin: 0; }
    </style>
</head>
<body>
    <div class="hero">
        <h1>Help & Knowledge Base</h1>
        <p>Guides and documentation for affiliates, advertisers, and account managers.</p>
    </div>
    <div class="container">
        <div class="faq-item">
            <h3><i class="fas fa-link mr-2"></i> How do S2S Postbacks work?</h3>
            <p>Postback tracking sends conversion parameters directly from your server to our system via HTTP GET requests using macro tokens like <code>{click_id}</code>, <code>{payout}</code>, and <code>{transaction_id}</code>.</p>
        </div>
        <div class="faq-item">
            <h3><i class="fas fa-clock mr-2"></i> What is the payout schedule?</h3>
            <p>Affiliate payouts are processed on weekly and bi-weekly schedules via wire transfer, PayPal, and cryptocurrency depending on account thresholds.</p>
        </div>
        <div class="faq-item">
            <h3><i class="fas fa-shield-alt mr-2"></i> How to complete KYC verification?</h3>
            <p>Submit your government ID proof inside your publisher or advertiser profile page to get approved for private campaigns and higher payment limits.</p>
        </div>
    </div>
</body>
</html>
