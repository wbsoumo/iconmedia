<?php
define('APP_INIT', true);
require_once __DIR__ . '/app/config/database.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GDPR & Compliance · IconMedia Network</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #0b1120; color: #f8fafc; padding: 40px 20px; line-height: 1.6; max-width: 900px; margin: 0 auto; }
        h1 { color: #3b82f6; font-size: 32px; margin-bottom: 20px; }
        h2 { color: #f1f5f9; font-size: 20px; margin-top: 30px; margin-bottom: 10px; }
        p { color: #94a3b8; margin-bottom: 16px; }
        a { color: #3b82f6; text-decoration: none; font-weight: 600; }
        .back-link { display: inline-block; margin-bottom: 30px; }
    </style>
</head>
<body>
    <a href="index.html" class="back-link">&larr; Back to Home</a>
    <h1>GDPR Compliance</h1>
    <p>Last updated: August 27, 2026</p>

    <h2>1. Data Subject Rights</h2>
    <p>Under the General Data Protection Regulation (GDPR), partners located in the European Union have the right to request access, rectification, or erasure of their personal data stored within our systems.</p>

    <h2>2. Data Minimization</h2>
    <p>We process only data strictly necessary for attribution, click tracking, payout calculations, and fraud detection.</p>

    <h2>3. Request Processing</h2>
    <p>To submit a data access or deletion request, please email <a href="mailto:dpo@iconmedia.in">dpo@iconmedia.in</a>.</p>
</body>
</html>
