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
    <title>About Us | GVS Icon Media Network</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 0; line-height: 1.6; }
        .hero { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 80px 20px; text-align: center; border-bottom: 1px solid #334155; }
        .hero h1 { font-size: 42px; font-weight: 800; color: #38bdf8; margin-bottom: 15px; }
        .hero p { font-size: 18px; color: #94a3b8; max-width: 700px; margin: 0 auto 30px; }
        .container { max-width: 1100px; margin: 50px auto; padding: 0 20px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; }
        .card { background: #1e293b; border-radius: 16px; padding: 30px; border: 1px solid #334155; box-shadow: 0 4px 20px rgba(0,0,0,0.2); }
        .card i { font-size: 36px; color: #38bdf8; margin-bottom: 15px; }
        .card h3 { font-size: 20px; margin-bottom: 10px; }
        .card p { color: #94a3b8; font-size: 14px; }
        .btn-home { display: inline-block; background: #38bdf8; color: #0f172a; font-weight: 700; padding: 12px 28px; border-radius: 8px; text-decoration: none; }
    </style>
</head>
<body>
    <div class="hero">
        <h1>Driven by Industry Pioneers</h1>
        <p>GVS Icon Media is a premier global affiliate & performance marketing network delivering real-time S2S postback tracking, direct advertiser budgets, and ultra-fast publisher payouts.</p>
        <a href="/login.php" class="btn-home"><i class="fas fa-sign-in-alt mr-2"></i> Partner Portal Login</a>
    </div>
    <div class="container">
        <div class="grid">
            <div class="card">
                <i class="fas fa-chart-line"></i>
                <h3>Performance Marketing</h3>
                <p>Scaling global campaigns across CPA, CPL, CPI, and CPS pricing models with maximum conversion optimization.</p>
            </div>
            <div class="card">
                <i class="fas fa-bolt"></i>
                <h3>Real-Time Tracking</h3>
                <p>Enterprise server-to-server (S2S) postback architecture guaranteeing sub-millisecond click and lead reporting.</p>
            </div>
            <div class="card">
                <i class="fas fa-shield-alt"></i>
                <h3>Fraud Defense</h3>
                <p>AI-driven anti-fraud filters protecting advertiser spend while ensuring legitimate publisher earnings.</p>
            </div>
        </div>
    </div>
</body>
</html>
