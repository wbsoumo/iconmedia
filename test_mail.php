<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$version = "v1.4.0 (Robust Multi-Mode Delivery)";
$status = null;
$message = null;
$logs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $toEmail = trim($_POST['email'] ?? '');
    
    if (filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $subject = "GVS Icon Media - Mail Test " . $version . " (" . date('H:i:s') . ")";
        $body = "Hello,\n\nThis is a test email sent from GVS Icon Media (" . $version . ").\n\nSender: support@iconmedianetwork.in\nTime Sent: " . date('Y-m-d H:i:s T') . "\nServer: " . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\n\nIf you received this message, your PHP mail delivery system is working properly!";
        
        $fromEmail = "support@iconmedianetwork.in";
        $fromName  = "GVS Icon Media Support";

        // Standard clean headers
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: {$fromEmail}\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

        // Mode 1: Standard mail() call
        $sent1 = @mail($toEmail, $subject, $body, $headers);
        $logs[] = "Standard mail() dispatch result: " . ($sent1 ? "SUCCESS" : "FAILED");

        // Mode 2: mail() with -f parameter (if standard failed or for envelope override)
        $additionalParams = "-f" . $fromEmail;
        $sent2 = @mail($toEmail, $subject, $body, $headers, $additionalParams);
        $logs[] = "Envelope (-f) mail() dispatch result: " . ($sent2 ? "SUCCESS" : "FAILED");

        if ($sent1 || $sent2) {
            $status = 'success';
            $message = "Test email dispatched to <strong>" . htmlspecialchars($toEmail) . "</strong>! (Mode 1: " . ($sent1 ? "OK" : "Err") . ", Mode 2: " . ($sent2 ? "OK" : "Err") . "). Please check your inbox and SPAM folder.";
        } else {
            $status = 'danger';
            $message = "Server mail() function failed. cPanel PHP sendmail / SMTP configuration may require authentication or local email routing.";
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
    <title>PHP Mail Tester <?php echo $version; ?> | GVS Icon Media</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 0; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: #1e293b; border-radius: 16px; width: 100%; max-width: 560px; padding: 35px; border: 1px solid #334155; box-shadow: 0 10px 30px rgba(0,0,0,0.3); position: relative; }
        h1 { font-size: 24px; font-weight: 800; color: #38bdf8; margin-top: 0; margin-bottom: 8px; text-align: center; }
        p { color: #94a3b8; font-size: 14px; text-align: center; margin-bottom: 25px; }
        .version-badge { display: inline-block; background: #0369a1; color: #7dd3fc; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 20px; vertical-align: middle; margin-left: 6px; }
        .sender-badge { background: #0f172a; border: 1px solid #334155; padding: 10px 14px; border-radius: 8px; font-size: 13px; color: #cbd5e1; text-align: center; margin-bottom: 20px; }
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
        .log-box { background: #0f172a; border: 1px solid #334155; padding: 12px; border-radius: 8px; font-family: monospace; font-size: 12px; color: #38bdf8; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="card">
        <h1><i class="fas fa-paper-plane mr-2"></i>PHP Mail Tester <span class="version-badge"><?php echo $version; ?></span></h1>
        <p>Test real-time email dispatch with dual-mode fallback.</p>

        <div class="sender-badge">
            <i class="fas fa-envelope-open-text text-info mr-1"></i> Sender Email: <strong>support@iconmedianetwork.in</strong>
        </div>

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

        <?php if (!empty($logs)): ?>
            <div class="log-box">
                <strong>Execution Log:</strong><br>
                <?php foreach ($logs as $log): ?>
                    - <?php echo htmlspecialchars($log); ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
