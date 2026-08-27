<?php
/**
 * Admin Login - Redesigned Premium Version
 * PHP 7.1+
 */

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';

$error = null;

// If already logged in and admin → redirect
if (isset($_SESSION['auth']) && $_SESSION['auth']['role'] === 'admin') {
    header('Location: /admin/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Email and password are required';
    } else {
        $result = auth_login($email, $password);

        if (!$result['success']) {
            $error = $result['error'];
        } else {
            // EXTRA SAFETY: only admin allowed here
            if ($_SESSION['auth']['role'] !== 'admin') {
                auth_logout();
                $error = 'Access denied. Admin privileges required.';
            } else {
                header('Location: /admin/dashboard.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login · Quantum Control Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.5">
    
    <!-- Google Fonts: Inter (clean, professional) -->
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
            background: linear-gradient(135deg, #0b1120 0%, #1a2235 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated background grid */
        .grid-background {
            position: absolute;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(37, 99, 235, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(37, 99, 235, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            z-index: 1;
        }

        .glow-orb {
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle at 30% 30%, rgba(37, 99, 235, 0.15), rgba(124, 58, 237, 0.1));
            border-radius: 50%;
            top: -200px;
            right: -200px;
            filter: blur(80px);
            animation: float 20s infinite alternate;
            z-index: 1;
        }

        .glow-orb-2 {
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle at 70% 70%, rgba(59, 130, 246, 0.1), rgba(139, 92, 246, 0.1));
            border-radius: 50%;
            bottom: -200px;
            left: -100px;
            filter: blur(80px);
            animation: float 25s infinite alternate-reverse;
            z-index: 1;
        }

        @keyframes float {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(30px, 30px) scale(1.1); }
        }

        /* ===== MAIN CONTAINER ===== */
        .login-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 440px;
            margin: 20px;
        }

        /* ===== PREMIUM CARD ===== */
        .admin-card {
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 32px;
            padding: 48px 40px;
            box-shadow: 
                0 25px 50px -12px rgba(0, 0, 0, 0.5),
                0 0 0 1px rgba(255, 255, 255, 0.05) inset;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .admin-card:hover {
            transform: translateY(-2px);
            box-shadow: 
                0 30px 60px -12px rgba(0, 0, 0, 0.6),
                0 0 0 1px rgba(37, 99, 235, 0.3) inset;
        }

        /* ===== HEADER SECTION ===== */
        .card-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(37, 99, 235, 0.15);
            border: 1px solid rgba(37, 99, 235, 0.3);
            padding: 8px 18px;
            border-radius: 100px;
            margin-bottom: 24px;
        }

        .admin-badge i {
            color: #60a5fa;
            font-size: 14px;
        }

        .admin-badge span {
            color: #94a3b8;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 1px;
        }

        .lock-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(145deg, #1e293b, #0f172a);
            border: 2px solid rgba(37, 99, 235, 0.3);
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            position: relative;
        }

        .lock-icon i {
            font-size: 36px;
            color: #3b82f6;
            filter: drop-shadow(0 0 10px rgba(59, 130, 246, 0.5));
        }

        .lock-icon::after {
            content: '';
            position: absolute;
            width: 90px;
            height: 90px;
            border: 2px solid rgba(59, 130, 246, 0.2);
            border-radius: 35px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); opacity: 0.5; }
            70% { transform: scale(1.1); opacity: 0; }
            100% { transform: scale(1); opacity: 0; }
        }

        .card-header h1 {
            color: white;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .card-header p {
            color: #94a3b8;
            font-size: 15px;
        }

        /* ===== ERROR MESSAGE ===== */
        .error-alert {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 28px;
            animation: slideDown 0.4s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .error-alert i {
            color: #ef4444;
            font-size: 18px;
        }

        .error-alert span {
            color: #fca5a5;
            font-size: 14px;
            font-weight: 500;
        }

        /* ===== FORM ===== */
        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #94a3b8;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .form-label i {
            color: #3b82f6;
            font-size: 14px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #475569;
            font-size: 18px;
            transition: color 0.2s;
            z-index: 2;
        }

        .form-control {
            width: 100%;
            padding: 16px 20px 16px 52px;
            background: rgba(30, 41, 59, 0.7);
            border: 2px solid rgba(255, 255, 255, 0.05);
            border-radius: 18px;
            color: white;
            font-size: 16px;
            transition: all 0.25s;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            background: rgba(30, 41, 59, 0.9);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .form-control::placeholder {
            color: #475569;
            font-weight: 400;
        }

        /* ===== FORM OPTIONS ===== */
        .form-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 28px 0 32px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #94a3b8;
            font-size: 14px;
            cursor: pointer;
        }

        .remember-me input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #3b82f6;
            border-radius: 4px;
            cursor: pointer;
        }

        .forgot-link {
            color: #94a3b8;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s;
        }

        .forgot-link:hover {
            color: #60a5fa;
        }

        /* ===== SUBMIT BUTTON ===== */
        .btn-login {
            width: 100%;
            padding: 18px 24px;
            background: linear-gradient(145deg, #3b82f6, #2563eb);
            border: none;
            border-radius: 18px;
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
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.3);
        }

        .btn-login:hover {
            background: linear-gradient(145deg, #2563eb, #1d4ed8);
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(37, 99, 235, 0.4);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login i {
            font-size: 18px;
            transition: transform 0.2s;
        }

        .btn-login:hover i {
            transform: translateX(4px);
        }

        .btn-login::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.7s ease;
        }

        .btn-login:hover::after {
            left: 100%;
        }

        /* ===== SECURITY BADGES ===== */
        .security-badges {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 24px;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }

        .security-item {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #475569;
            font-size: 12px;
            font-weight: 500;
        }

        .security-item i {
            color: #3b82f6;
            font-size: 12px;
        }

        /* ===== ADMIN FOOTER ===== */
        .admin-footer {
            margin-top: 32px;
            text-align: center;
        }

        .admin-footer p {
            color: #475569;
            font-size: 13px;
        }

        .admin-footer a {
            color: #94a3b8;
            font-weight: 600;
            transition: color 0.2s;
        }

        .admin-footer a:hover {
            color: #60a5fa;
        }

        /* ===== LOADING STATE ===== */
        .btn-loading {
            position: relative;
            pointer-events: none;
            opacity: 0.9;
        }

        .btn-loading i.fa-spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* ===== ERROR STATE ===== */
        .form-control.error {
            border-color: #ef4444;
            background: rgba(239, 68, 68, 0.05);
        }

        .error-text {
            color: #f87171;
            font-size: 12px;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 480px) {
            .login-container {
                margin: 12px;
            }

            .admin-card {
                padding: 36px 24px;
            }

            .lock-icon {
                width: 64px;
                height: 64px;
                border-radius: 22px;
            }

            .lock-icon i {
                font-size: 28px;
            }

            .lock-icon::after {
                width: 74px;
                height: 74px;
                border-radius: 27px;
            }

            .card-header h1 {
                font-size: 24px;
            }

            .form-control {
                padding: 14px 16px 14px 48px;
                font-size: 15px;
            }

            .btn-login {
                padding: 16px 20px;
            }

            .security-badges {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
        }

        @media (max-width: 360px) {
            .admin-card {
                padding: 28px 18px;
            }

            .form-options {
                flex-direction: column;
                gap: 16px;
                align-items: flex-start;
            }
        }

        /* Shake animation for errors */
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        .shake {
            animation: shake 0.4s ease;
        }
    </style>
</head>
<body>
    <!-- Animated Background -->
    <div class="grid-background"></div>
    <div class="glow-orb"></div>
    <div class="glow-orb-2"></div>

    <!-- Login Container -->
    <div class="login-container">
        <div class="admin-card">
            <!-- Header -->
            <div class="card-header">
                <div class="admin-badge">
                    <i class="fas fa-shield-halved"></i>
                    <span>SECURE ADMIN ACCESS</span>
                </div>
                
                <div class="lock-icon">
                    <i class="fas fa-lock"></i>
                </div>
                
                <h1>Administrator Login</h1>
                <p>Enter your credentials to access the control panel</p>
            </div>

            <!-- Error Message -->
            <?php if ($error): ?>
                <div class="error-alert">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="post" id="loginForm" novalidate autocomplete="off">
                <!-- Email Field -->
                <div class="form-group">
                    <label class="form-label" for="email">
                        <i class="fas fa-envelope"></i>
                        <span>EMAIL ADDRESS</span>
                    </label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope input-icon"></i>
                        <input 
                            type="email" 
                            class="form-control" 
                            id="email" 
                            name="email" 
                            placeholder="admin@quantum.com"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                            autocomplete="off"
                            required
                        >
                    </div>
                </div>

                <!-- Password Field -->
                <div class="form-group">
                    <label class="form-label" for="password">
                        <i class="fas fa-lock"></i>
                        <span>PASSWORD</span>
                    </label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input 
                            type="password" 
                            class="form-control" 
                            id="password" 
                            name="password" 
                            placeholder="••••••••••••"
                            autocomplete="off"
                            required
                        >
                    </div>
                </div>

                <!-- Form Options -->
                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember" value="1">
                        <span>Remember this device</span>
                    </label>
                    <a href="#" class="forgot-link">
                        <i class="fas fa-key"></i> Reset 2FA
                    </a>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-login" id="submitBtn">
                    <span>Access Admin Panel</span>
                    <i class="fas fa-arrow-right"></i>
                </button>

                <!-- Security Badges -->
                <div class="security-badges">
                    <span class="security-item">
                        <i class="fas fa-shield-halved"></i>
                        <span>256-bit SSL</span>
                    </span>
                    <span class="security-item">
                        <i class="fas fa-fingerprint"></i>
                        <span>2FA Protected</span>
                    </span>
                    <span class="security-item">
                        <i class="fas fa-clock"></i>
                        <span>Session Timeout: 15min</span>
                    </span>
                </div>
            </form>

            <!-- Footer -->
            <div class="admin-footer">
                <p>© <?= date('Y') ?> Quantum Affiliate · <a href="#">Privacy</a> · <a href="#">Security</a></p>
            </div>
        </div>
    </div>

    <script>
        (function() {
            'use strict';

            const form = document.getElementById('loginForm');
            const submitBtn = document.getElementById('submitBtn');
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');

            // Email validation helper
            function isValidEmail(email) {
                const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                return re.test(email);
            }

            // Show error for field
            function markError(input, message) {
                input.classList.add('error');
                
                // Remove existing error
                const existingError = input.parentElement.nextElementSibling;
                if (existingError && existingError.classList.contains('error-text')) {
                    existingError.remove();
                }
                
                // Add new error
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

            // Clear field error
            function clearError(input) {
                input.classList.remove('error');
                const existingError = input.parentElement.nextElementSibling;
                if (existingError && existingError.classList.contains('error-text')) {
                    existingError.remove();
                }
            }

            // Form submission
            if (form) {
                form.addEventListener('submit', function(e) {
                    let isValid = true;

                    // Validate email
                    if (!emailInput.value.trim()) {
                        markError(emailInput, 'Email address is required');
                        isValid = false;
                    } else if (!isValidEmail(emailInput.value.trim())) {
                        markError(emailInput, 'Please enter a valid email address');
                        isValid = false;
                    }

                    // Validate password
                    if (!passwordInput.value) {
                        markError(passwordInput, 'Password is required');
                        isValid = false;
                    }

                    if (!isValid) {
                        e.preventDefault();
                        
                        // Add shake animation to card
                        const card = document.querySelector('.admin-card');
                        card.classList.add('shake');
                        setTimeout(() => {
                            card.classList.remove('shake');
                        }, 400);
                        
                        return false;
                    }

                    // Add loading state
                    submitBtn.classList.add('btn-loading');
                    submitBtn.innerHTML = `
                        <span>Authenticating...</span>
                        <i class="fas fa-spinner"></i>
                    `;
                });
            }

            // Real-time email validation
            if (emailInput) {
                emailInput.addEventListener('blur', function() {
                    if (this.value && !isValidEmail(this.value)) {
                        markError(this, 'Please enter a valid email address');
                    } else {
                        clearError(this);
                    }
                });
            }

            // Auto-dismiss error alert after 5 seconds
            const errorAlert = document.querySelector('.error-alert');
            if (errorAlert) {
                setTimeout(() => {
                    errorAlert.style.transition = 'opacity 0.5s ease';
                    errorAlert.style.opacity = '0';
                    setTimeout(() => errorAlert.remove(), 500);
                }, 5000);
            }

            // Focus on email field
            if (emailInput) {
                emailInput.focus();
            }

            // Prevent zoom on mobile for inputs
            if (window.innerWidth <= 768) {
                const inputs = document.querySelectorAll('.form-control');
                inputs.forEach(input => {
                    input.style.fontSize = '16px';
                });
            }

            // Add subtle tilt effect (optional)
            const card = document.querySelector('.admin-card');
            if (card && window.innerWidth >= 1024) {
                card.addEventListener('mousemove', function(e) {
                    const rect = this.getBoundingClientRect();
                    const x = (e.clientX - rect.left) / rect.width - 0.5;
                    const y = (e.clientY - rect.top) / rect.height - 0.5;
                    
                    this.style.transform = `perspective(1000px) rotateY(${x * 2}deg) rotateX(${y * -2}deg) translateY(-2px)`;
                });

                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'perspective(1000px) rotateY(0) rotateX(0) translateY(0)';
                });
            }

            // Prevent form resubmission on page refresh
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        })();
    </script>
</body>
</html>