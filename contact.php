<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/app/config/database.php';
require_once __DIR__ . '/app/core/auth.php';

$success = $error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $msg = trim($_POST['message'] ?? '');
    if ($name && $email && $msg) {
        $success = "Thank you! Your message has been received. Our support team will get back to you shortly.";
    } else {
        $error = "Please fill out all required fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Support | GVS Icon Media Network</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 80px auto; padding: 40px; background: #1e293b; border-radius: 16px; border: 1px solid #334155; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        h1 { font-size: 28px; font-weight: 700; color: #38bdf8; margin-bottom: 8px; text-align: center; }
        p { color: #94a3b8; font-size: 14px; text-align: center; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #cbd5e1; margin-bottom: 6px; }
        input, textarea { width: 100%; padding: 12px 14px; background: #0f172a; border: 1px solid #334155; border-radius: 8px; color: white; outline: none; font-family: inherit; }
        input:focus, textarea:focus { border-color: #38bdf8; }
        .btn-submit { width: 100%; padding: 14px; background: #38bdf8; color: #0f172a; font-weight: 700; border: none; border-radius: 8px; cursor: pointer; font-size: 15px; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; text-align: center; }
        .alert-success { background: #064e3b; color: #6ee7b7; border: 1px solid #047857; }
        .alert-danger { background: #7f1d1d; color: #fca5a5; border: 1px solid #b91c1c; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Contact Network Support</h1>
        <p>Have questions regarding account setup, campaign approvals, or payments?</p>

        <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle mr-2"></i><?php echo $success; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i><?php echo $error; ?></div><?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label>Your Name *</label>
                <input type="text" name="name" required placeholder="John Doe">
            </div>
            <div class="form-group">
                <label>Email Address *</label>
                <input type="email" name="email" required placeholder="partner@company.com">
            </div>
            <div class="form-group">
                <label>Message / Inquiry *</label>
                <textarea name="message" rows="5" required placeholder="Describe your question or technical issue..."></textarea>
            </div>
            <button type="submit" class="btn-submit"><i class="fas fa-paper-plane mr-2"></i> Send Message</button>
        </form>
    </div>
</body>
</html>
