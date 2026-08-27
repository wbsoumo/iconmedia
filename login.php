<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/app/config/database.php';
require_once __DIR__ . '/app/core/auth.php';

$error = null;
$success = null;

// Check for session timeout or logout message
if (isset($_GET['logout'])) {
    $success = 'You have been successfully logged out.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role   = $_POST['role'] ?? '';
    $email  = trim($_POST['email'] ?? '');
    $pass   = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (!in_array($role, ['affiliate', 'advertiser'], true)) {
        $error = 'Please select your account type';
    } elseif ($email === '' || $pass === '') {
        $error = 'Email and password are required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        $result = auth_login($email, $pass, $remember);
        
        if (!$result['success']) {
            $error = $result['error'] ?? 'Invalid credentials. Please try again.';
        } else {
            // Role-based redirect with secure session
            session_regenerate_id(true);
            if ($result['role'] === 'affiliate') {
                header('Location: /affiliate/dashboard.php');
            } else {
                header('Location: /advertiser/dashboard.php');
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.5">
    <title>IconMedia Network · Secure Partner Login</title>
    
    <!-- Google Fonts: Inter (professional, clean) -->
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
            display: flex;
            flex-direction: column;
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
        .login-wrapper {
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
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 80px;
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
            margin-bottom: 48px;
            line-height: 1.6;
        }

        .testimonial {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 28px;
            margin-top: 40px;
        }

        .testimonial-quote {
            color: white;
            font-size: 18px;
            font-style: italic;
            margin-bottom: 20px;
            opacity: 0.95;
        }

        .testimonial-author {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .author-avatar {
            width: 48px;
            height: 48px;
            background: linear-gradient(145deg, #2563eb, #7c3aed);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
        }

        .author-info h4 {
            color: white;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .author-info p {
            color: #b0c9e0;
            font-size: 14px;
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

        /* ===== RIGHT SIDE - LOGIN FORM ===== */
        .form-panel {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 24px;
            background-color: #ffffff;
        }

        .form-container {
            width: 100%;
            max-width: 440px;
            margin: 0 auto;
        }

        .form-header {
            margin-bottom: 40px;
            text-align: center;
        }

        .mobile-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 24px;
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

        /* Role Cards */
        .role-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 28px;
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
            padding: 24px 12px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
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
            width: 48px;
            height: 48px;
            background: white;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
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
            margin-bottom: 4px;
        }

        .role-desc {
            font-size: 12px;
            color: #64748b;
            text-align: center;
        }

        /* Form Groups */
        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 8px;
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
            font-size: 18px;
            transition: color 0.2s;
        }

        .form-control {
            width: 100%;
            padding: 16px 20px 16px 52px;
            font-size: 16px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
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

        /* Form Options */
        .form-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #475569;
            font-size: 15px;
            cursor: pointer;
        }

        .remember-me input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #2563eb;
            border-radius: 4px;
            cursor: pointer;
        }

        .forgot-link {
            color: #2563eb;
            font-weight: 600;
            font-size: 15px;
        }

        /* Submit Button */
        .btn-login {
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
        }

        .btn-login:hover {
            background: linear-gradient(145deg, #1d4ed8, #1e40af);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.25);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login i {
            font-size: 18px;
        }

        /* Register Link */
        .register-link {
            text-align: center;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #e2e8f0;
            color: #64748b;
        }

        .register-link a {
            color: #2563eb;
            font-weight: 700;
        }

        /* Trust Badges */
        .trust-badges {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 32px;
            margin-top: 32px;
            color: #94a3b8;
            font-size: 14px;
        }

        .trust-badges i {
            margin-right: 6px;
        }

        /* Responsive adjustments */
        @media (max-width: 640px) {
            .form-container {
                padding: 0 8px;
            }
            
            .role-container {
                gap: 12px;
            }
            
            .role-card label {
                padding: 18px 8px;
            }
            
            .form-header h2 {
                font-size: 28px;
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

        /* Input validation styles */
        .form-control.error {
            border-color: #ef4444;
            background-color: #fef2f2;
        }

        .error-message {
            color: #ef4444;
            font-size: 13px;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <!-- LEFT PANEL - Brand Showcase (Premium) -->
        <div class="brand-panel">
            <div class="gradient-sphere"></div>
            <div class="gradient-sphere-2"></div>
            
            <div class="brand-content">
                <div class="brand-logo">
                    <img src="favicon.png" alt="IconMedia Logo" style="width: 48px; height: 48px; object-fit: contain; border-radius: 12px; background: rgba(255,255,255,0.1); padding: 4px;">
                    <span class="brand-name">IconMedia</span>
                </div>

                <h1 class="brand-tagline">
                    Performance intelligence for the digital age
                </h1>
                
                <p class="brand-description">
                    Join the leading global affiliate network. Access real-time analytics, 
                    premium offers, and AI-powered optimization tools trusted by 100,000+ partners.
                </p>

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

                <div class="testimonial">
                    <div class="testimonial-quote">
                        "IconMedia Network has transformed our acquisition strategy. 
                        The platform's AI-driven matching increased our ROAS by 230%."
                    </div>
                    <div class="testimonial-author">
                        <div class="author-avatar">JD</div>
                        <div class="author-info">
                            <h4>James Davidson</h4>
                            <p>Performance Director, Elevate Brands</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT PANEL - Login Form -->
        <div class="form-panel">
            <div class="form-container">
                <!-- Mobile Logo (Visible only on mobile/tablet) -->
                <div class="mobile-logo">
                    <img src="favicon.png" alt="IconMedia Logo" style="width: 36px; height: 36px; object-fit: contain; border-radius: 8px;">
                    <span>IconMedia Network</span>
                </div>

                <div class="form-header">
                    <h2>Welcome back</h2>
                    <p>Sign in to access your partner dashboard</p>
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

                <!-- Login Form -->
                <form method="post" id="loginForm" novalidate>
                    <!-- Role Selection - Premium Card Style -->
                    <div class="form-group">
                        <label class="form-label">I am a...</label>
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

                    <!-- Email Field -->
                    <div class="form-group">
                        <label class="form-label" for="email">Email address</label>
                        <div class="input-wrapper">
                            <i class="fas fa-envelope input-icon"></i>
                            <input 
                                type="email" 
                                class="form-control" 
                                id="email" 
                                name="email" 
                                placeholder="partner@company.com" 
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                autocomplete="email"
                            >
                        </div>
                    </div>

                    <!-- Password Field -->
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <label class="form-label" for="password">Password</label>
                            <a href="#" class="forgot-link">Forgot?</a>
                        </div>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input 
                                type="password" 
                                class="form-control" 
                                id="password" 
                                name="password" 
                                placeholder="Enter your password"
                                autocomplete="current-password"
                            >
                        </div>
                    </div>

                    <!-- Remember Me -->
                    <div class="form-options">
                        <label class="remember-me">
                            <input type="checkbox" name="remember" value="1" <?= isset($_POST['remember']) ? 'checked' : '' ?>>
                            <span>Remember me for 30 days</span>
                        </label>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="btn-login" id="submitBtn">
                        <span>Sign in to dashboard</span>
                        <i class="fas fa-arrow-right"></i>
                    </button>

                    <!-- Registration Link -->
                    <div class="register-link">
                        <span>New to IconMedia Network? </span>
                        <a href="/register.php">Create an account →</a>
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

            // Form validation and interaction enhancements
            const form = document.getElementById('loginForm');
            const submitBtn = document.getElementById('submitBtn');
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');
            const roleInputs = document.querySelectorAll('input[name="role"]');

            // Real-time email validation
            emailInput.addEventListener('blur', function() {
                if (this.value && !isValidEmail(this.value)) {
                    showError(this, 'Please enter a valid email address');
                } else {
                    clearError(this);
                }
            });

            // Clear individual field error
            function clearError(input) {
                input.classList.remove('error');
                const existingError = input.parentElement.nextElementSibling;
                if (existingError && existingError.classList.contains('error-message')) {
                    existingError.remove();
                }
            }

            // Show error for field
            function showError(input, message) {
                input.classList.add('error');
                
                // Remove existing error message
                const existingError = input.parentElement.nextElementSibling;
                if (existingError && existingError.classList.contains('error-message')) {
                    existingError.remove();
                }
                
                // Add new error message
                const errorDiv = document.createElement('div');
                errorDiv.className = 'error-message';
                errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
                input.parentElement.parentElement.appendChild(errorDiv);
            }

            // Email validation helper
            function isValidEmail(email) {
                const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                return re.test(email);
            }

            // Form submission with loading state
            form.addEventListener('submit', function(e) {
                let isValid = true;
                
                // Validate role selection
                const roleSelected = Array.from(roleInputs).some(input => input.checked);
                if (!roleSelected) {
                    e.preventDefault();
                    document.querySelector('.role-container').classList.add('error');
                    isValid = false;
                    
                    // Create or update error message
                    let roleError = document.querySelector('.role-error');
                    if (!roleError) {
                        roleError = document.createElement('div');
                        roleError.className = 'error-message role-error';
                        roleError.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please select your account type';
                        document.querySelector('.role-container').after(roleError);
                    }
                }

                // Validate email
                if (!emailInput.value) {
                    showError(emailInput, 'Email address is required');
                    isValid = false;
                } else if (!isValidEmail(emailInput.value)) {
                    showError(emailInput, 'Please enter a valid email address');
                    isValid = false;
                }

                // Validate password
                if (!passwordInput.value) {
                    showError(passwordInput, 'Password is required');
                    isValid = false;
                } else if (passwordInput.value.length < 6) {
                    showError(passwordInput, 'Password must be at least 6 characters');
                    isValid = false;
                }

                if (!isValid) {
                    e.preventDefault();
                    return false;
                }

                // Add loading state to button
                submitBtn.classList.add('btn-loading');
                submitBtn.innerHTML = `
                    <span>Authenticating...</span>
                    <i class="fas fa-spinner"></i>
                `;
            });

            // Remove role error when selection is made
            roleInputs.forEach(input => {
                input.addEventListener('change', function() {
                    document.querySelector('.role-container')?.classList.remove('error');
                    document.querySelector('.role-error')?.remove();
                });
            });

            // Clear password error on input
            passwordInput.addEventListener('input', function() {
                clearError(this);
            });

            // Clear email error on input
            emailInput.addEventListener('input', function() {
                clearError(this);
            });

            // Add smooth 3D tilt effect for left panel (optional)
            const brandPanel = document.querySelector('.brand-panel');
            if (brandPanel && window.innerWidth >= 1024) {
                brandPanel.addEventListener('mousemove', function(e) {
                    const rect = this.getBoundingClientRect();
                    const x = (e.clientX - rect.left) / rect.width - 0.5;
                    const y = (e.clientY - rect.top) / rect.height - 0.5;
                    
                    this.style.transform = `translate(${x * 10}px, ${y * 10}px)`;
                });

                brandPanel.addEventListener('mouseleave', function() {
                    this.style.transform = 'translate(0, 0)';
                });
            }

            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });
        })();
    </script>
</body>
</html>