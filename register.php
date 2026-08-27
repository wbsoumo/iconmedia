<?php
define('APP_INIT', true);

require_once __DIR__ . '/app/config/database.php';

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role       = $_POST['role'] ?? '';
    $name       = trim($_POST['name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $mobile     = trim($_POST['mobile'] ?? '');
    $telegramId = trim($_POST['telegram_id'] ?? '');
    $teamsId    = trim($_POST['teams_id'] ?? '');

    // Basic validation
    if (!in_array($role, ['affiliate', 'advertiser'], true)) {
        $error = 'Please select your account type';
    } elseif ($name === '' || $email === '' || $password === '' || $mobile === '') {
        $error = 'Name, email, password and mobile are required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters';
    } else {
        // Check duplicate email
        $check = $pdo->prepare("SELECT user_id FROM users WHERE email = :email LIMIT 1");
        $check->execute(['email' => $email]);

        if ($check->fetch()) {
            $error = 'This email is already registered';
        } else {
            // Get role_id
            $roleStmt = $pdo->prepare("
                SELECT role_id FROM roles WHERE role_name = :role LIMIT 1
            ");
            $roleStmt->execute(['role' => $role]);
            $roleRow = $roleStmt->fetch();

            if (!$roleRow) {
                $error = 'Invalid role selected';
            } else {
                // Hash password
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                // Insert user
                $stmt = $pdo->prepare("
                    INSERT INTO users (
                        name,
                        email,
                        password_hash,
                        mobile,
                        telegram_id,
                        teams_id,
                        role_id,
                        status,
                        created_at
                    ) VALUES (
                        :name,
                        :email,
                        :password,
                        :mobile,
                        :telegram,
                        :teams,
                        :role_id,
                        'pending',
                        NOW()
                    )
                ");

                $stmt->execute([
                    'name'     => $name,
                    'email'    => $email,
                    'password' => $passwordHash,
                    'mobile'   => $mobile,
                    'telegram' => $telegramId !== '' ? $telegramId : null,
                    'teams'    => $teamsId !== '' ? $teamsId : null,
                    'role_id'  => $roleRow['role_id']
                ]);

                $success = 'Registration successful! Your account is pending approval.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.5">
    <title>IconMedia · Create Account</title>
    
    <!-- Google Fonts: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* ===== RESET & GLOBAL ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #f8fafd;
            color: #0a1e32;
            line-height: 1.5;
            min-height: 100vh;
        }

        a {
            text-decoration: none;
            color: #2563eb;
            font-weight: 500;
            transition: color 0.2s;
        }

        a:hover {
            color: #1d4ed8;
        }

        /* ===== MAIN LAYOUT ===== */
        .register-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* ===== LEFT SIDE - BRAND SHOWCASE ===== */
        .brand-panel {
            flex: 1;
            background: linear-gradient(145deg, #0a1e3c 0%, #0e2a4a 100%);
            display: none;
            position: relative;
            overflow: hidden;
            padding: 60px 48px;
            flex-direction: column;
            justify-content: space-between;
        }

        @media (min-width: 1024px) {
            .brand-panel {
                display: flex;
            }
        }

        .brand-content {
            position: relative;
            z-index: 10;
            color: white;
            max-width: 540px;
            margin: 0 auto;
            width: 100%;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 60px;
        }

        .brand-icon {
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }

        .brand-name {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.5px;
            background: linear-gradient(to right, #ffffff, #cbd5e1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .brand-tagline {
            font-size: 42px;
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -0.03em;
            margin-bottom: 24px;
        }

        .brand-description {
            font-size: 18px;
            color: #b0c9e0;
            margin-bottom: 40px;
            line-height: 1.6;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-top: 40px;
        }

        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .feature-icon {
            width: 40px;
            height: 40px;
            background: rgba(37, 99, 235, 0.15);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #60a5fa;
            font-size: 18px;
            flex-shrink: 0;
        }

        .feature-text h4 {
            color: white;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .feature-text p {
            color: #b0c9e0;
            font-size: 13px;
            line-height: 1.4;
        }

        .floating-stats {
            display: flex;
            gap: 32px;
            margin-top: 60px;
        }

        .stat {
            display: flex;
            flex-direction: column;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: white;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 14px;
            color: #b0c9e0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Animated background elements */
        .gradient-sphere {
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle at 30% 30%, rgba(37,99,235,0.2) 0%, rgba(124,58,237,0.1) 70%);
            border-radius: 50%;
            top: -200px;
            right: -100px;
            filter: blur(60px);
            animation: float 20s infinite alternate;
        }

        .gradient-sphere-2 {
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle at 70% 70%, rgba(6,182,212,0.15) 0%, rgba(59,130,246,0.1) 80%);
            border-radius: 50%;
            bottom: -100px;
            left: -50px;
            filter: blur(60px);
            animation: float 25s infinite alternate-reverse;
        }

        @keyframes float {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(30px, 30px) scale(1.1); }
        }

        /* ===== RIGHT SIDE - REGISTRATION FORM ===== */
        .form-panel {
            flex: 1;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 40px 24px;
            background-color: #ffffff;
            overflow-y: auto;
            min-height: 100vh;
        }

        .form-container {
            width: 100%;
            max-width: 560px;
            margin: 0 auto;
        }

        .mobile-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 32px;
        }

        .mobile-logo i {
            width: 40px;
            height: 40px;
            background: linear-gradient(145deg, #2563eb, #7c3aed);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .mobile-logo span {
            font-size: 22px;
            font-weight: 700;
            color: #0a1e32;
            letter-spacing: -0.5px;
        }

        @media (min-width: 1024px) {
            .mobile-logo {
                display: none;
            }
        }

        .form-header {
            margin-bottom: 32px;
            text-align: center;
        }

        .form-header h2 {
            font-size: 32px;
            font-weight: 700;
            color: #0a1e32;
            margin-bottom: 12px;
            letter-spacing: -0.02em;
        }

        .form-header p {
            color: #64748b;
            font-size: 16px;
        }

        /* Messages */
        .alert {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            border-radius: 14px;
            margin-bottom: 28px;
            font-size: 15px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-error {
            background-color: #fef2f2;
            border: 1px solid #fee2e2;
            color: #b91c1c;
        }

        .alert-success {
            background-color: #f0fdf4;
            border: 1px solid #dcfce7;
            color: #166534;
        }

        .alert i {
            font-size: 18px;
        }

        /* Form Layout */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .form-group {
            margin-bottom: 8px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 6px;
            letter-spacing: 0.3px;
        }

        .required::after {
            content: " *";
            color: #ef4444;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 16px;
            transition: color 0.2s;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px 14px 48px;
            font-size: 15px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            color: #0f172a;
            transition: all 0.25s;
        }

        .form-control:focus {
            outline: none;
            border-color: #2563eb;
            background: white;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.1);
        }

        .form-control::placeholder {
            color: #94a3b8;
        }

        /* Role Cards */
        .role-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 8px;
        }

        .role-card {
            position: relative;
        }

        .role-card input[type="radio"] {
            display: none;
        }

        .role-card label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px 12px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.25s ease;
        }

        .role-card:hover label {
            border-color: #94a3b8;
            background: #f1f5f9;
        }

        .role-card input[type="radio"]:checked + label {
            border-color: #2563eb;
            background: #eff6ff;
        }

        .role-icon {
            width: 44px;
            height: 44px;
            background: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
            color: #2563eb;
            font-size: 20px;
            transition: all 0.25s;
        }

        .role-card input[type="radio"]:checked + label .role-icon {
            background: #2563eb;
            color: white;
        }

        .role-title {
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 2px;
            font-size: 15px;
        }

        .role-desc {
            font-size: 11px;
            color: #64748b;
            text-align: center;
        }

        /* Password Strength */
        .password-strength {
            margin-top: 8px;
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0%;
            border-radius: 2px;
            transition: width 0.3s, background 0.3s;
        }

        .strength-weak { background: #ef4444; }
        .strength-fair { background: #f59e0b; }
        .strength-good { background: #10b981; }
        .strength-strong { background: #059669; }

        /* Hint Text */
        .field-hint {
            font-size: 12px;
            color: #64748b;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .field-hint i {
            font-size: 12px;
        }

        /* Submit Button */
        .btn-register {
            width: 100%;
            padding: 16px 24px;
            background: linear-gradient(145deg, #2563eb, #1d4ed8);
            border: none;
            border-radius: 14px;
            color: white;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            cursor: pointer;
            transition: all 0.25s;
            position: relative;
            overflow: hidden;
            margin-top: 16px;
        }

        .btn-register:hover {
            background: linear-gradient(145deg, #1d4ed8, #1e40af);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.25);
        }

        .btn-register:active {
            transform: translateY(0);
        }

        .btn-register i {
            font-size: 18px;
        }

        /* Footer Links */
        .register-footer {
            text-align: center;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #e2e8f0;
            color: #64748b;
        }

        .register-footer a {
            color: #2563eb;
            font-weight: 700;
        }

        .terms-links {
            margin-top: 16px;
            font-size: 13px;
        }

        /* Trust Badges */
        .trust-badges {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 32px;
            margin-top: 24px;
            color: #94a3b8;
            font-size: 13px;
        }

        .trust-badges i {
            margin-right: 6px;
            color: #2563eb;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-container {
                max-width: 480px;
            }
            
            .role-container {
                gap: 12px;
            }
        }

        @media (max-width: 480px) {
            .form-panel {
                padding: 24px 16px;
            }
            
            .role-card label {
                padding: 16px 8px;
            }
            
            .role-icon {
                width: 36px;
                height: 36px;
                font-size: 16px;
            }
            
            .btn-register {
                padding: 14px 20px;
            }
            
            .trust-badges {
                flex-direction: column;
                gap: 12px;
            }
        }

        /* Loading state */
        .btn-loading {
            position: relative;
            pointer-events: none;
            opacity: 0.9;
        }

        .btn-loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Error state */
        .form-control.error {
            border-color: #ef4444;
            background-color: #fef2f2;
        }

        .error-text {
            color: #ef4444;
            font-size: 12px;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Mobile optimizations */
        @media (max-width: 1024px) {
            .register-wrapper {
                background: white;
            }
            
            .form-panel {
                align-items: center;
                padding: 40px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="register-wrapper">
        <!-- LEFT PANEL - Brand Showcase -->
        <div class="brand-panel">
            <div class="gradient-sphere"></div>
            <div class="gradient-sphere-2"></div>
            
            <div class="brand-content">
                <div class="brand-logo">
                    <img src="favicon.png" alt="IconMedia Logo" style="width: 48px; height: 48px; object-fit: contain; border-radius: 12px; background: rgba(255,255,255,0.1); padding: 4px;">
                    <span class="brand-name">IconMedia</span>
                </div>

                <h1 class="brand-tagline">
                    Join the leading performance network
                </h1>
                
                <p class="brand-description">
                    Start your journey with 100,000+ partners. Access premium offers, 
                    real-time analytics, and industry-leading tools to scale your success.
                </p>

                <div class="feature-grid">
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Real-time Analytics</h4>
                            <p>Live dashboards with actionable insights</p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Instant Payouts</h4>
                            <p>Weekly payments, multiple methods</p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Enterprise Security</h4>
                            <p>SOC2 Type II, GDPR compliant</p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-headset"></i>
                        </div>
                        <div class="feature-text">
                            <h4>24/7 Support</h4>
                            <p>Dedicated account managers</p>
                        </div>
                    </div>
                </div>

                <div class="floating-stats">
                    <div class="stat">
                        <span class="stat-number">100K+</span>
                        <span class="stat-label">Active partners</span>
                    </div>
                    <div class="stat">
                        <span class="stat-number">$450M+</span>
                        <span class="stat-label">Annual payouts</span>
                    </div>
                    <div class="stat">
                        <span class="stat-number">40+</span>
                        <span class="stat-label">Countries</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT PANEL - Registration Form -->
        <div class="form-panel">
            <div class="form-container">
                <!-- Mobile Logo -->
                <div class="mobile-logo">
                    <img src="favicon.png" alt="IconMedia Logo" style="width: 36px; height: 36px; object-fit: contain; border-radius: 8px;">
                    <span>IconMedia</span>
                </div>

                <div class="form-header">
                    <h2>Create your account</h2>
                    <p>Join the network and start earning today</p>
                </div>

                <!-- Alert Messages -->
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <span><?= htmlspecialchars($success) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Registration Form -->
                <form method="post" id="registerForm" novalidate>
                    <div class="form-grid">
                        <!-- Role Selection -->
                        <div class="form-group full-width">
                            <label class="form-label required">I am registering as</label>
                            <div class="role-container">
                                <div class="role-card">
                                    <input type="radio" id="role_affiliate" name="role" value="affiliate" <?= (isset($_POST['role']) && $_POST['role'] === 'affiliate') ? 'checked' : '' ?>>
                                    <label for="role_affiliate">
                                        <div class="role-icon">
                                            <i class="fas fa-users"></i>
                                        </div>
                                        <span class="role-title">Affiliate</span>
                                        <span class="role-desc">Publisher / Partner</span>
                                    </label>
                                </div>
                                <div class="role-card">
                                    <input type="radio" id="role_advertiser" name="role" value="advertiser" <?= (isset($_POST['role']) && $_POST['role'] === 'advertiser') ? 'checked' : '' ?>>
                                    <label for="role_advertiser">
                                        <div class="role-icon">
                                            <i class="fas fa-bullhorn"></i>
                                        </div>
                                        <span class="role-title">Advertiser</span>
                                        <span class="role-desc">Brand / Merchant</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Full Name -->
                        <div class="form-group">
                            <label class="form-label required" for="name">Full name</label>
                            <div class="input-wrapper">
                                <i class="fas fa-user input-icon"></i>
                                <input 
                                    type="text" 
                                    class="form-control" 
                                    id="name" 
                                    name="name" 
                                    placeholder="John Smith"
                                    value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                                >
                            </div>
                        </div>

                        <!-- Email -->
                        <div class="form-group">
                            <label class="form-label required" for="email">Email address</label>
                            <div class="input-wrapper">
                                <i class="fas fa-envelope input-icon"></i>
                                <input 
                                    type="email" 
                                    class="form-control" 
                                    id="email" 
                                    name="email" 
                                    placeholder="partner@company.com"
                                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                >
                            </div>
                        </div>

                        <!-- Password -->
                        <div class="form-group">
                            <label class="form-label required" for="password">Password</label>
                            <div class="input-wrapper">
                                <i class="fas fa-lock input-icon"></i>
                                <input 
                                    type="password" 
                                    class="form-control" 
                                    id="password" 
                                    name="password" 
                                    placeholder="Create a password"
                                >
                            </div>
                            <div class="password-strength">
                                <div class="strength-bar" id="strengthBar"></div>
                            </div>
                            <div class="field-hint">
                                <i class="fas fa-info-circle"></i>
                                <span>Minimum 6 characters with uppercase & numbers</span>
                            </div>
                        </div>

                        <!-- Mobile -->
                        <div class="form-group">
                            <label class="form-label required" for="mobile">Mobile number</label>
                            <div class="input-wrapper">
                                <i class="fas fa-phone input-icon"></i>
                                <input 
                                    type="tel" 
                                    class="form-control" 
                                    id="mobile" 
                                    name="mobile" 
                                    placeholder="+1 (234) 567-8900"
                                    value="<?= htmlspecialchars($_POST['mobile'] ?? '') ?>"
                                >
                            </div>
                        </div>

                        <!-- Telegram ID -->
                        <div class="form-group">
                            <label class="form-label" for="telegram_id">Telegram ID</label>
                            <div class="input-wrapper">
                                <i class="fab fa-telegram input-icon"></i>
                                <input 
                                    type="text" 
                                    class="form-control" 
                                    id="telegram_id" 
                                    name="telegram_id" 
                                    placeholder="@username"
                                    value="<?= htmlspecialchars($_POST['telegram_id'] ?? '') ?>"
                                >
                            </div>
                            <div class="field-hint">
                                <i class="fas fa-info-circle"></i>
                                <span>For instant notifications</span>
                            </div>
                        </div>

                        <!-- Teams ID -->
                        <div class="form-group">
                            <label class="form-label" for="teams_id">Microsoft Teams ID</label>
                            <div class="input-wrapper">
                                <i class="fas fa-video input-icon"></i>
                                <input 
                                    type="text" 
                                    class="form-control" 
                                    id="teams_id" 
                                    name="teams_id" 
                                    placeholder="username@domain.com"
                                    value="<?= htmlspecialchars($_POST['teams_id'] ?? '') ?>"
                                >
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="form-group full-width">
                            <button type="submit" class="btn-register" id="submitBtn">
                                <span>Create account</span>
                                <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Footer Links -->
                    <div class="register-footer">
                        <p>Already have an account? <a href="login.php">Sign in to your dashboard →</a></p>
                        <div class="terms-links">
                            By creating an account, you agree to our 
                            <a href="#">Terms of Service</a> and 
                            <a href="#">Privacy Policy</a>
                        </div>
                    </div>

                    <!-- Trust Badges -->
                    <div class="trust-badges">
                        <span><i class="fas fa-shield-alt"></i> SSL Encrypted</span>
                        <span><i class="fas fa-lock"></i> SOC2 Type II</span>
                        <span><i class="fas fa-check-circle"></i> GDPR Compliant</span>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        (function() {
            'use strict';

            // Password strength indicator
            const passwordInput = document.getElementById('password');
            const strengthBar = document.getElementById('strengthBar');

            if (passwordInput && strengthBar) {
                passwordInput.addEventListener('input', function() {
                    const password = this.value;
                    let strength = 0;
                    
                    // Length check
                    if (password.length >= 6) strength += 20;
                    if (password.length >= 8) strength += 10;
                    
                    // Complexity checks
                    if (/[A-Z]/.test(password)) strength += 25;
                    if (/[0-9]/.test(password)) strength += 25;
                    if (/[^A-Za-z0-9]/.test(password)) strength += 20;
                    
                    // Cap at 100
                    strength = Math.min(strength, 100);
                    
                    // Update bar width
                    strengthBar.style.width = `${strength}%`;
                    
                    // Update color class
                    strengthBar.className = 'strength-bar';
                    if (strength < 30) {
                        strengthBar.classList.add('strength-weak');
                    } else if (strength < 50) {
                        strengthBar.classList.add('strength-fair');
                    } else if (strength < 75) {
                        strengthBar.classList.add('strength-good');
                    } else {
                        strengthBar.classList.add('strength-strong');
                    }
                });
            }

            // Form validation
            const form = document.getElementById('registerForm');
            const submitBtn = document.getElementById('submitBtn');

            if (form) {
                form.addEventListener('submit', function(e) {
                    let isValid = true;
                    
                    // Validate role selection
                    const roleSelected = document.querySelector('input[name="role"]:checked');
                    if (!roleSelected) {
                        e.preventDefault();
                        isValid = false;
                        
                        const roleContainer = document.querySelector('.role-container');
                        roleContainer.style.animation = 'shake 0.5s ease';
                        
                        let roleError = document.querySelector('.role-error');
                        if (!roleError) {
                            roleError = document.createElement('div');
                            roleError.className = 'error-text role-error';
                            roleError.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please select your account type';
                            roleContainer.parentElement.appendChild(roleError);
                        }
                        
                        setTimeout(() => {
                            roleContainer.style.animation = '';
                        }, 500);
                    }

                    // Validate name
                    const nameInput = document.getElementById('name');
                    if (!nameInput.value.trim()) {
                        markError(nameInput, 'Full name is required');
                        isValid = false;
                    }

                    // Validate email
                    const emailInput = document.getElementById('email');
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailInput.value.trim()) {
                        markError(emailInput, 'Email address is required');
                        isValid = false;
                    } else if (!emailRegex.test(emailInput.value)) {
                        markError(emailInput, 'Please enter a valid email address');
                        isValid = false;
                    }

                    // Validate password
                    if (!passwordInput.value) {
                        markError(passwordInput, 'Password is required');
                        isValid = false;
                    } else if (passwordInput.value.length < 6) {
                        markError(passwordInput, 'Password must be at least 6 characters');
                        isValid = false;
                    }

                    // Validate mobile
                    const mobileInput = document.getElementById('mobile');
                    if (!mobileInput.value.trim()) {
                        markError(mobileInput, 'Mobile number is required');
                        isValid = false;
                    }

                    if (!isValid) {
                        e.preventDefault();
                        return false;
                    }

                    // Add loading state
                    submitBtn.classList.add('btn-loading');
                    submitBtn.innerHTML = `
                        <span>Creating account...</span>
                        <i class="fas fa-spinner"></i>
                    `;
                });
            }

            // Helper function to mark input errors
            function markError(input, message) {
                input.classList.add('error');
                
                // Remove existing error message
                const existingError = input.parentElement.nextElementSibling;
                if (existingError && existingError.classList.contains('error-text')) {
                    existingError.remove();
                }
                
                // Add new error message
                const errorDiv = document.createElement('div');
                errorDiv.className = 'error-text';
                errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
                input.parentElement.parentElement.appendChild(errorDiv);
                
                // Remove error on input
                input.addEventListener('input', function() {
                    this.classList.remove('error');
                    const error = this.parentElement.nextElementSibling;
                    if (error && error.classList.contains('error-text')) {
                        error.remove();
                    }
                }, { once: true });
            }

            // Remove role error when selection is made
            document.querySelectorAll('input[name="role"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    document.querySelector('.role-error')?.remove();
                    document.querySelector('.role-container').style.animation = '';
                });
            });

            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });

            // Mobile optimizations
            if (window.innerWidth <= 768) {
                const formControls = document.querySelectorAll('.form-control');
                formControls.forEach(input => {
                    input.style.fontSize = '16px'; // Prevent zoom on mobile
                });
            }

            // Shake animation keyframe (add if not exists)
            const style = document.createElement('style');
            style.textContent = `
                @keyframes shake {
                    0%, 100% { transform: translateX(0); }
                    25% { transform: translateX(-5px); }
                    75% { transform: translateX(5px); }
                }
            `;
            document.head.appendChild(style);
        })();
    </script>
</body>
</html>