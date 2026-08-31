<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/app/services/MailService.php';

$version = "v1.5.0 (HTML Registration Templates)";
$status = null;
$message = null;
$logs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $toEmail  = trim($_POST['email'] ?? '');
    $roleType = $_POST['role_type'] ?? 'affiliate';
    $name     = trim($_POST['name'] ?? 'John Doe');
    
    if (filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        // Send HTML Welcome Email Template via MailService
        send_welcome_email($toEmail, $name, $roleType);

        $status = 'success';
        $message = "Test <strong>" . strtoupper($roleType) . "</strong> welcome email (" . $version . ") successfully dispatched to <strong>" . htmlspecialchars($toEmail) . "</strong>! Please check your inbox and SPAM folder.";
        $logs[] = "Dispatched HTML welcome template for role: " . strtoupper($roleType);
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
    <title>PHP Mail & Template Tester <?php echo $version; ?> | GVS Icon Media</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 0; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: #1e293b; border-radius: 16px; width: 100%; max-width: 580px; padding: 35px; border: 1px solid #334155; box-shadow: 0 10px 30px rgba(0,0,0,0.3); position: relative; }
        h1 { font-size: 24px; font-weight: 800; color: #38bdf8; margin-top: 0; margin-bottom: 8px; text-align: center; }
        p { color: #94a3b8; font-size: 14px; text-align: center; margin-bottom: 25px; }
        .version-badge { display: inline-block; background: #0369a1; color: #7dd3fc; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 20px; vertical-align: middle; margin-left: 6px; }
        .sender-badge { background: #0f172a; border: 1px solid #334155; padding: 10px 14px; border-radius: 8px; font-size: 13px; color: #cbd5e1; text-align: center; margin-bottom: 20px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #cbd5e1; margin-bottom: 8px; }
        input[type="email"], input[type="text"], select { width: 100%; padding: 12px 14px; background: #0f172a; border: 1px solid #334155; border-radius: 8px; color: white; outline: none; font-family: inherit; font-size: 15px; box-sizing: border-box; }
        input:focus, select:focus { border-color: #38bdf8; }
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
        <h1><i class="fas fa-paper-plane mr-2"></i>Mail & Template Tester <span class="version-badge"><?php echo $version; ?></span></h1>
        <p>Test and preview registration HTML email templates live.</p>

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
                <label><i class="fas fa-user-tag mr-1"></i> Select Account Template Type *</label>
                <select name="role_type" required>
                    <option value="affiliate" <?php echo (isset($_POST['role_type']) && $_POST['role_type'] === 'affiliate') ? 'selected' : ''; ?>>Publisher / Affiliate Welcome Email</option>
                    <option value="advertiser" <?php echo (isset($_POST['role_type']) && $_POST['role_type'] === 'advertiser') ? 'selected' : ''; ?>>Advertiser Welcome Email</option>
                    <option value="manager" <?php echo (isset($_POST['role_type']) && $_POST['role_type'] === 'manager') ? 'selected' : ''; ?>>Account Manager Welcome Email</option>
                </select>
            </div>

            <div class="form-group">
                <label><i class="fas fa-user mr-1"></i> Test Partner Name</label>
                <input type="text" name="name" placeholder="John Doe" value="<?php echo htmlspecialchars($_POST['name'] ?? 'John Doe'); ?>">
            </div>

            <div class="form-group">
                <label><i class="fas fa-envelope mr-1"></i> Target Email Address *</label>
                <input type="email" name="email" required placeholder="name@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>

            <button type="submit" class="btn-submit"><i class="fas fa-paper-plane mr-2"></i> Send HTML Template Test Email</button>
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
