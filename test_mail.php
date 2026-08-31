<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$status = null;
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $toEmail = trim($_POST['email'] ?? '');
    
    if (filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $subject = "GVS Icon Media - Mail Delivery Test (" . date('H:i:s') . ")";
        $body = "Hello,\n\nThis is a test email sent from your GVS Icon Media PHP mail testing utility.\n\nTime Sent: " . date('Y-m-d H:i:s T') . "\nServer: " . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\n\nIf you received this message, your PHP mail delivery system is working properly!";
        $headers = "From: no-reply@iconmedianetwork.in\r\n" .
                   "Reply-To: support@iconmedianetwork.in\r\n" .
                   "X-Mailer: PHP/" . phpversion();

        // Attempt sending email via PHP mail()
        if (@mail($toEmail, $subject, $body, $headers)) {
            $status = 'success';
            $message = "Test email successfully dispatched to <strong>" . htmlspecialchars($toEmail) . "</strong>! Please check your inbox (and spam folder).";
        } else {
            $status = 'danger';
            $message = "Failed to send email. The server's <code>mail()</code> function returned false or sendmail/SMTP is not configured on this server.";
        }
    } else {
        $status = 'warning';
        $message = "Please enter a valid email address.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Mail Tester | GVS Icon Media</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 0; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: #1e293b; border-radius: 16px; width: 100%; max-width: 520px; padding: 35px; border: 1px solid #334155; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        h1 { font-size: 24px; font-weight: 800; color: #38bdf8; margin-top: 0; margin-bottom: 8px; text-align: center; }
        p { color: #94a3b8; font-size: 14px; text-align: center; margin-bottom: 25px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #cbd5e1; margin-bottom: 8px; }
        input[type="email"] { width: 100%; padding: 12px 14px; background: #0f172a; border: 1px solid #334155; border-radius: 8px; color: white; outline: none; font-family: inherit; font-size: 15px; box-sizing: border-box; }
        input[type="email"]:focus { border-color: #38bdf8; }
        .btn-submit { width: 100%; padding: 14px; background: #38bdf8; color: #0f172a; font-weight: 700; border: none; border-radius: 8px; cursor: pointer; font-size: 15px; transition: background 0.2s ease; }
        .btn-submit:hover { background: #7dd3fc; }
        .alert { padding: 14px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; line-height: 1.5; }
        .alert-success { background: rgba(6, 78, 59, 0.6); color: #6ee7b7; border: 1px solid #047857; }
        .alert-danger { background: rgba(127, 29, 29, 0.6); color: #fca5a5; border: 1px solid #b91c1c; }
        .alert-warning { background: rgba(120, 53, 15, 0.6); color: #fde047; border: 1px solid #b45309; }
    </style>
</head>
<body>
    <div class="card">
        <h1><i class="fas fa-paper-plane mr-2"></i>PHP Mail Tester</h1>
        <p>Test your server's email delivery system in real time.</p>

        <?php if ($status && $message): ?>
            <div class="alert alert-<?php echo $status; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label><i class="fas fa-envelope mr-1"></i> Target Email Address *</label>
                <input type="email" name="email" required placeholder="name@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            <button type="submit" class="btn-submit"><i class="fas fa-paper-plane mr-2"></i> Send Test Email</button>
        </form>
    </div>
</body>
</html>
