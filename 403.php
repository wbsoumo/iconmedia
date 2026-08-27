<?php
define('APP_INIT', true);
require_once __DIR__ . '/app/config/database.php';

// Check if user is logged in to customize the experience
$isLoggedIn = isset($_SESSION['user_id']) || isset($_SESSION['auth']);
$userRole = $_SESSION['auth']['role'] ?? $_SESSION['role'] ?? null;
$userName = $_SESSION['user_name'] ?? $_SESSION['auth']['name'] ?? 'User';

// Determine redirect based on login status
if ($isLoggedIn) {
    if ($userRole === 'admin') {
        $homeUrl = '/admin/dashboard.php';
    } elseif ($userRole === 'advertiser') {
        $homeUrl = '/advertiser/dashboard.php';
    } elseif ($userRole === 'affiliate') {
        $homeUrl = '/affiliate/dashboard.php';
    } else {
        $homeUrl = '/index.php';
    }
} else {
    $homeUrl = '/login.php';
}

// Get the requested URL for debugging
$requestedUrl = $_SERVER['REQUEST_URI'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 - Access Forbidden | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --info-gradient: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            --warning-gradient: linear-gradient(135deg, #f7971e 0%, #ffd200 100%);
            --danger-gradient: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
        }
        
        body {
            background: #0f172a;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }
        
        /* Animated Grid Background */
        .grid-background {
            position: absolute;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(255, 255, 255, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            z-index: 0;
            animation: gridMove 20s linear infinite;
        }
        
        @keyframes gridMove {
            0% { transform: translate(0, 0); }
            100% { transform: translate(50px, 50px); }
        }
        
        /* Glowing Orbs */
        .glow-orb {
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle at 30% 30%, rgba(249, 65, 68, 0.15), rgba(248, 150, 30, 0.1));
            border-radius: 50%;
            top: -200px;
            right: -200px;
            filter: blur(80px);
            animation: orbFloat 20s infinite alternate;
            z-index: 0;
        }
        
        .glow-orb-2 {
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle at 70% 70%, rgba(67, 97, 238, 0.15), rgba(76, 201, 240, 0.1));
            border-radius: 50%;
            bottom: -200px;
            left: -100px;
            filter: blur(80px);
            animation: orbFloat 25s infinite alternate-reverse;
            z-index: 0;
        }
        
        @keyframes orbFloat {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(30px, 30px) scale(1.1); }
        }
        
        /* Main Container */
        .error-container {
            position: relative;
            z-index: 10;
            max-width: 700px;
            width: 90%;
            padding: 20px;
            text-align: center;
        }
        
        /* Error Card */
        .error-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border-radius: 30px;
            padding: 50px 40px;
            box-shadow: 
                0 30px 60px -15px rgba(0, 0, 0, 0.5),
                0 0 0 1px rgba(255, 255, 255, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
        }
        
        .error-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #f94144, #f8961e, #f9c74f, #43aa8b, #577590);
            animation: gradientShift 5s infinite alternate;
        }
        
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            100% { background-position: 100% 50%; }
        }
        
        /* Lock Icon */
        .lock-icon {
            width: 140px;
            height: 140px;
            margin: 0 auto 30px;
            position: relative;
        }
        
        .lock-circle {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #f94144, #f8961e);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            box-shadow: 0 20px 40px rgba(249, 65, 68, 0.3);
            animation: lockPulse 3s infinite ease-in-out;
        }
        
        @keyframes lockPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .lock-circle::before {
            content: '';
            position: absolute;
            width: 120%;
            height: 120%;
            border: 3px solid rgba(249, 65, 68, 0.2);
            border-radius: 50%;
            animation: ringWave 2s infinite;
        }
        
        .lock-circle::after {
            content: '';
            position: absolute;
            width: 140%;
            height: 140%;
            border: 2px solid rgba(249, 65, 68, 0.1);
            border-radius: 50%;
            animation: ringWave 2s infinite 0.5s;
        }
        
        @keyframes ringWave {
            0% { transform: scale(0.8); opacity: 1; }
            100% { transform: scale(1.5); opacity: 0; }
        }
        
        .lock-circle i {
            font-size: 50px;
            color: white;
            filter: drop-shadow(0 10px 20px rgba(0,0,0,0.3));
        }
        
        /* Error Code */
        .error-code {
            font-size: 100px;
            font-weight: 800;
            background: linear-gradient(135deg, #f94144, #f8961e);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1;
            margin-bottom: 10px;
            text-shadow: 0 10px 20px rgba(249, 65, 68, 0.2);
            position: relative;
            display: inline-block;
        }
        
        .error-code::before {
            content: '403';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #f94144, #f8961e);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: blur(20px);
            opacity: 0.5;
            z-index: -1;
        }
        
        /* Error Title */
        .error-title {
            font-size: 32px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 15px;
        }
        
        /* Error Message */
        .error-message {
            font-size: 18px;
            color: #64748b;
            margin-bottom: 30px;
            max-width: 450px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }
        
        /* User Info (if logged in) */
        <?php if ($isLoggedIn): ?>
        .user-info {
            background: #f1f5f9;
            border-radius: 60px;
            padding: 15px 25px;
            display: inline-flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 22px;
            font-weight: 600;
        }
        
        .user-details {
            text-align: left;
        }
        
        .user-name {
            font-weight: 600;
            color: #1e293b;
        }
        
        .user-role {
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        <?php endif; ?>
        
        /* Permission Required */
        .permission-box {
            background: #fef2f2;
            border: 1px solid #fee2e2;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: left;
        }
        
        .permission-title {
            color: #b91c1c;
            font-weight: 600;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .permission-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .permission-list li {
            padding: 8px 0;
            color: #475569;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid #fee2e2;
        }
        
        .permission-list li:last-child {
            border-bottom: none;
        }
        
        .permission-list i.fa-check {
            color: #22c55e;
        }
        
        .permission-list i.fa-times {
            color: #ef4444;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 30px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 16px 35px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.2);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(102, 126, 234, 0.3);
            color: white;
        }
        
        .btn-secondary {
            background: white;
            color: #1e293b;
            border: 2px solid #e2e8f0;
            padding: 14px 33px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            border-color: #667eea;
            color: #667eea;
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #f94144, #f8961e);
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(249, 65, 68, 0.3);
            color: white;
        }
        
        /* Support Options */
        .support-options {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
            padding-top: 30px;
            border-top: 2px dashed #e2e8f0;
        }
        
        .support-link {
            color: #64748b;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .support-link:hover {
            color: #667eea;
            transform: translateY(-2px);
        }
        
        /* Debug Info (optional) */
        .debug-info {
            margin-top: 30px;
            padding: 15px;
            background: #f1f5f9;
            border-radius: 15px;
            font-size: 12px;
            color: #64748b;
            word-break: break-all;
            text-align: left;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .error-card {
                padding: 40px 20px;
            }
            
            .error-code {
                font-size: 70px;
            }
            
            .error-title {
                font-size: 26px;
            }
            
            .lock-icon {
                width: 100px;
                height: 100px;
            }
            
            .lock-circle i {
                font-size: 35px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-primary, .btn-secondary {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Animated Background -->
    <div class="grid-background"></div>
    <div class="glow-orb"></div>
    <div class="glow-orb-2"></div>
    
    <!-- Error Container -->
    <div class="error-container">
        <div class="error-card">
            <!-- Lock Icon -->
            <div class="lock-icon">
                <div class="lock-circle">
                    <i class="fas fa-lock"></i>
                </div>
            </div>
            
            <!-- Error Code -->
            <div class="error-code">403</div>
            
            <!-- Error Title -->
            <h1 class="error-title">Access Forbidden</h1>
            
            <!-- Error Message -->
            <p class="error-message">
                You don't have permission to access this resource.<br>
                Please contact your administrator if you believe this is a mistake.
            </p>
            
            <?php if ($isLoggedIn): ?>
            <!-- User Info -->
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($userName, 0, 1)); ?>
                </div>
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($userName); ?></div>
                    <div class="user-role">Role: <?php echo ucfirst($userRole ?? 'guest'); ?></div>
                </div>
            </div>
            
            <!-- Required Permissions -->
            <div class="permission-box">
                <div class="permission-title">
                    <i class="fas fa-shield-alt"></i>
                    Required Permissions
                </div>
                <ul class="permission-list">
                    <li>
                        <i class="fas fa-times" style="color: #ef4444;"></i>
                        Admin Access
                    </li>
                    <li>
                        <i class="fas fa-times" style="color: #ef4444;"></i>
                        Manager Access
                    </li>
                    <li>
                        <i class="fas fa-check" style="color: #22c55e;"></i>
                        Basic Authentication
                    </li>
                </ul>
            </div>
            <?php endif; ?>
            
            <!-- Action Buttons -->
            <div class="action-buttons">
                <a href="<?php echo $homeUrl; ?>" class="btn-primary">
                    <i class="fas fa-home mr-2"></i> Go to Dashboard
                </a>
                <a href="javascript:history.back()" class="btn-secondary">
                    <i class="fas fa-arrow-left mr-2"></i> Go Back
                </a>
            </div>
            
            <?php if ($isLoggedIn): ?>
            <!-- Additional Options for Logged-in Users -->
            <div style="margin-bottom: 20px;">
                <a href="/logout.php" class="btn-danger">
                    <i class="fas fa-sign-out-alt mr-2"></i> Logout & Try Again
                </a>
            </div>
            <?php endif; ?>
            
            <!-- Support Options -->
            <div class="support-options">
                <a href="/contact.php" class="support-link">
                    <i class="fas fa-envelope"></i> Contact Support
                </a>
                <a href="/help.php" class="support-link">
                    <i class="fas fa-question-circle"></i> Help Center
                </a>
                <a href="/request-access.php" class="support-link">
                    <i class="fas fa-key"></i> Request Access
                </a>
            </div>
            
            <!-- Debug Info (remove in production) -->
            <?php if (isset($_SERVER['HTTP_HOST'])): ?>
            <div class="debug-info">
                <i class="fas fa-info-circle mr-2"></i>
                <strong>Requested URL:</strong> <?php echo htmlspecialchars($requestedUrl); ?><br>
                <i class="fas fa-user mr-2"></i>
                <strong>User Role:</strong> <?php echo $userRole ?? 'guest'; ?><br>
                <i class="fas fa-clock mr-2"></i>
                <strong>Time:</strong> <?php echo date('Y-m-d H:i:s'); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>