<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/core/auth.php';

$error = null;
$success = null;

if (isset($_GET['logout'])) {
    $success = 'You have been successfully logged out.';
}

// Redirect if already logged in as admin
if (isset($_SESSION['auth']) && $_SESSION['auth']['role'] === 'admin') {
    header('Location: /admin/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email  = trim($_POST['email'] ?? '');
    $pass   = $_POST['password'] ?? '';

    if ($email === '' || $pass === '') {
        $error = 'Email and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $result = auth_login($email, $pass, false, 'admin');
        
        if (!$result['success']) {
            $error = $result['error'] ?? 'Invalid Administrator credentials.';
        } else {
            session_regenerate_id(true);
            header('Location: /admin/dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator Login | IconMedia Network</title>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=fallback" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 440px;
            padding: 40px;
        }
        .brand-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .brand-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #4f46e5, #4338ca);
            color: #ffffff;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            margin-bottom: 15px;
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);
        }
        .brand-title {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
        }
        .brand-subtitle {
            font-size: 13px;
            color: #64748b;
            margin-top: 4px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }
        .input-wrapper {
            position: relative;
        }
        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 15px;
        }
        .form-control {
            width: 100%;
            padding: 12px 14px 12px 42px;
            font-size: 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            outline: none;
            transition: all 0.2s;
        }
        .form-control:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }
        .btn-submit {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #4f46e5, #4338ca);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.1s, box-shadow 0.2s;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }
        .btn-submit:hover {
            box-shadow: 0 6px 16px rgba(79, 70, 229, 0.35);
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-danger {
            background-color: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .alert-success {
            background-color: #f0fdf4;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="brand-header">
        <div class="brand-icon">
            <i class="fas fa-crown"></i>
        </div>
        <h1 class="brand-title">Admin Control Center</h1>
        <p class="brand-subtitle">IconMedia Network Master Admin Access</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
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

    <form method="post">
        <div class="form-group">
            <label class="form-label" for="email">Admin Email</label>
            <div class="input-wrapper">
                <i class="fas fa-envelope input-icon"></i>
                <input type="email" id="email" name="email" class="form-control" placeholder="admin@iconmedia.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label" for="password">Password</label>
            <div class="input-wrapper">
                <i class="fas fa-lock input-icon"></i>
                <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>
        </div>

        <button type="submit" class="btn-submit">
            <i class="fas fa-shield-alt mr-2"></i> Log In to Admin Panel
        </button>
    </form>
</div>

</body>
</html>