<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/app/config/database.php';

$error = null;
$success = null;

$token = $_GET['token'] ?? $_POST['token'] ?? null;
$userReset = null;

if ($token) {
    $stmt = $pdo->prepare("
        SELECT pr.*, u.name, u.email 
        FROM password_resets pr 
        INNER JOIN users u ON u.user_id = pr.user_id 
        WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW() 
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $userReset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userReset) {
        $error = "This password reset link is invalid or has expired. Please request a new link.";
    }
}

// Handle Password Reset Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $newPass = $_POST['password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (!$userReset) {
        $error = "Invalid or expired reset session.";
    } elseif (strlen($newPass) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($newPass !== $confirmPass) {
        $error = "Passwords do not match.";
    } else {
        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
        
        $up = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
        $up->execute([$newHash, $userReset['user_id']]);

        $mark = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
        $mark->execute([$token]);

        $success = "Your password has been reset successfully! You can now log in.";
        $userReset = null;
    }
}

// Handle Forgot Password Email Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_reset') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $stmt = $pdo->prepare("SELECT user_id, name, email FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Generate secure token
            $resetToken = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+2 hours'));

            // Check if password_resets table exists, or fallback
            try {
                $ins = $pdo->prepare("INSERT INTO password_resets (user_id, email, token, expires_at, created_at) VALUES (?, ?, ?, ?, NOW())");
                $ins->execute([$user['user_id'], $user['email'], $resetToken, $expiresAt]);
            } catch (PDOException $e) {
                // Table auto-creation attempt
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS password_resets (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        email VARCHAR(255) NOT NULL,
                        token VARCHAR(100) NOT NULL UNIQUE,
                        expires_at DATETIME NOT NULL,
                        used TINYINT(1) DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
                $ins = $pdo->prepare("INSERT INTO password_resets (user_id, email, token, expires_at, created_at) VALUES (?, ?, ?, ?, NOW())");
                $ins->execute([$user['user_id'], $user['email'], $resetToken, $expiresAt]);
            }

            $resetUrl = "https://iconmedianetwork.in/forgot_password.php?token=" . $resetToken;

            // Send Mail using SMTP credentials
            $to = $user['email'];
            $subject = "Password Reset Request - IconMedia Network";
            $message = "Hello " . htmlspecialchars($user['name']) . ",\n\n";
            $message .= "We received a request to reset your password for your IconMedia Network account.\n\n";
            $message .= "Click the link below to set a new password (valid for 2 hours):\n";
            $message .= $resetUrl . "\n\n";
            $message .= "If you did not request this, please ignore this email.\n\n";
            $message .= "Best regards,\nIconMedia Network Support\nsupport@iconmedianetwork.in";

            $headers = "From: IconMedia Network Support <support@iconmedianetwork.in>\r\n";
            $headers .= "Reply-To: support@iconmedianetwork.in\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();

            @mail($to, $subject, $message, $headers);
        }

        // Always show success message to prevent user enumeration
        $success = "If an account with that email exists, we have sent a password reset link to your inbox.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password · IconMedia Network</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=fallback" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(145deg, #0a1e3c 0%, #0e2a4a 100%);
            color: #0f172a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .card-panel {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 480px;
            padding: 40px;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 24px;
        }

        .brand-logo img {
            width: 42px;
            height: 42px;
            object-fit: contain;
        }

        .brand-logo span {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
        }

        h2 {
            font-size: 24px;
            font-weight: 700;
            text-align: center;
            color: #0f172a;
            margin-bottom: 8px;
        }

        p.sub-text {
            color: #64748b;
            font-size: 14px;
            text-align: center;
            margin-bottom: 24px;
            line-height: 1.5;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 12px;
            font-size: 14px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error { background: #fef2f2; border: 1px solid #fee2e2; color: #b91c1c; }
        .alert-success { background: #f0fdf4; border: 1px solid #dcfce7; color: #166534; }

        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; color: #0f172a; }
        .input-wrapper { position: relative; }
        .input-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 16px; }

        .form-control {
            width: 100%;
            padding: 14px 16px 14px 48px;
            font-size: 15px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            color: #0f172a;
            transition: all 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: #2563eb;
            background: white;
        }

        .btn-primary {
            width: 100%;
            padding: 14px;
            background: linear-gradient(145deg, #2563eb, #1d4ed8);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary:hover { background: #1d4ed8; transform: translateY(-2px); }

        .back-link {
            text-align: center;
            margin-top: 24px;
        }

        .back-link a {
            color: #2563eb;
            font-weight: 600;
            text-decoration: none;
            font-size: 14px;
        }

        .db-link-box {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 12px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 12px;
            color: #475569;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="card-panel">
        <div class="brand-logo">
            <img src="favicon.png" alt="IconMedia Logo">
            <span>IconMedia</span>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($success); ?></span>
        </div>
        <?php endif; ?>

        <?php if ($token && $userReset): ?>
            <!-- RESET PASSWORD FORM -->
            <h2>Set New Password</h2>
            <p class="sub-text">Enter a new secure password for <strong><?php echo htmlspecialchars($userReset['email']); ?></strong>.</p>
            
            <form method="post" action="forgot_password.php?token=<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="password" class="form-control" placeholder="At least 6 characters" required minlength="6">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter new password" required minlength="6">
                    </div>
                </div>

                <button type="submit" class="btn-primary">
                    <span>Update Password</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>
        <?php else: ?>
            <!-- REQUEST PASSWORD RESET FORM -->
            <h2>Forgot Password?</h2>
            <p class="sub-text">Enter your registered email address and we'll send you a password reset link.</p>

            <form method="post" action="forgot_password.php">
                <input type="hidden" name="action" value="request_reset">

                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" name="email" class="form-control" placeholder="partner@company.com" required>
                    </div>
                </div>

                <button type="submit" class="btn-primary">
                    <span>Send Reset Link</span>
                    <i class="fas fa-paper-plane"></i>
                </button>
            </form>
        <?php endif; ?>

        <div class="back-link">
            <a href="/login.php">← Back to Login</a>
        </div>
    </div>
</body>
</html>
