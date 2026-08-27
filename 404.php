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
    $homeUrl = '/index.php';
}

// Get the requested URL for debugging (optional)
$requestedUrl = $_SERVER['REQUEST_URI'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 - Page Not Found | GVS Icon Media</title>
    
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
            background: #f4f6f9;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }
        
        /* Animated Background */
        .bg-animation {
            position: absolute;
            width: 100%;
            height: 100%;
            z-index: 0;
            overflow: hidden;
        }
        
        .particle {
            position: absolute;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea20, #764ba220);
            animation: float 20s infinite linear;
        }
        
        @keyframes float {
            0% { transform: translateY(0) translateX(0) rotate(0deg); }
            25% { transform: translateY(-40px) translateX(20px) rotate(90deg); }
            50% { transform: translateY(20px) translateX(-20px) rotate(180deg); }
            75% { transform: translateY(-30px) translateX(30px) rotate(270deg); }
            100% { transform: translateY(0) translateX(0) rotate(360deg); }
        }
        
        /* Main Container */
        .error-container {
            position: relative;
            z-index: 10;
            max-width: 800px;
            width: 90%;
            padding: 20px;
            text-align: center;
        }
        
        /* Error Card */
        .error-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 30px;
            padding: 60px 40px;
            box-shadow: 
                0 25px 50px -12px rgba(0, 0, 0, 0.25),
                0 0 0 1px rgba(255, 255, 255, 0.5),
                inset 0 1px 0 rgba(255, 255, 255, 0.8);
            transform-style: preserve-3d;
            transform: perspective(1000px);
            animation: cardFloat 6s infinite ease-in-out;
        }
        
        @keyframes cardFloat {
            0%, 100% { transform: perspective(1000px) translateY(0); }
            50% { transform: perspective(1000px) translateY(-10px); }
        }
        
        /* Error Icon */
        .error-icon {
            width: 180px;
            height: 180px;
            margin: 0 auto 30px;
            position: relative;
            animation: iconPulse 3s infinite ease-in-out;
        }
        
        @keyframes iconPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .error-icon-circle {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #f43f5e, #fb7185);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            box-shadow: 0 20px 40px rgba(244, 63, 94, 0.3);
        }
        
        .error-icon-circle::before {
            content: '';
            position: absolute;
            width: 110%;
            height: 110%;
            border: 3px solid rgba(244, 63, 94, 0.2);
            border-radius: 50%;
            animation: ringPulse 2s infinite;
        }
        
        @keyframes ringPulse {
            0% { transform: scale(1); opacity: 1; }
            100% { transform: scale(1.5); opacity: 0; }
        }
        
        .error-icon-circle i {
            font-size: 70px;
            color: white;
            filter: drop-shadow(0 10px 20px rgba(0,0,0,0.2));
        }
        
        /* Error Code */
        .error-code {
            font-size: 120px;
            font-weight: 800;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1;
            margin-bottom: 10px;
            text-shadow: 0 10px 20px rgba(102, 126, 234, 0.2);
            animation: numberGlow 3s infinite alternate;
        }
        
        @keyframes numberGlow {
            0% { text-shadow: 0 10px 20px rgba(102, 126, 234, 0.2); }
            100% { text-shadow: 0 20px 40px rgba(102, 126, 234, 0.4); }
        }
        
        /* Error Title */
        .error-title {
            font-size: 36px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 15px;
        }
        
        /* Error Message */
        .error-message {
            font-size: 18px;
            color: #64748b;
            margin-bottom: 40px;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }
        
        /* Search Box (optional) */
        .search-box {
            max-width: 400px;
            margin: 0 auto 30px;
            position: relative;
        }
        
        .search-box input {
            width: 100%;
            padding: 18px 25px;
            border: 2px solid #e2e8f0;
            border-radius: 50px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: white;
        }
        
        .search-box input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .search-box button {
            position: absolute;
            right: 8px;
            top: 8px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            color: white;
            padding: 10px 25px;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .search-box button:hover {
            transform: translateX(2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
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
        
        /* Quick Links */
        .quick-links {
            margin-top: 40px;
            padding-top: 30px;
            border-top: 2px dashed #e2e8f0;
        }
        
        .quick-links h4 {
            color: #475569;
            font-size: 16px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .links-grid {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .quick-link {
            color: #667eea;
            text-decoration: none;
            padding: 8px 20px;
            background: #f1f5f9;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .quick-link:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }
        
        /* Debug Info (optional - remove in production) */
        .debug-info {
            margin-top: 30px;
            padding: 15px;
            background: #f1f5f9;
            border-radius: 15px;
            font-size: 12px;
            color: #64748b;
            word-break: break-all;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .error-card {
                padding: 40px 20px;
            }
            
            .error-code {
                font-size: 80px;
            }
            
            .error-title {
                font-size: 28px;
            }
            
            .error-icon {
                width: 140px;
                height: 140px;
            }
            
            .error-icon-circle i {
                font-size: 50px;
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
    <div class="bg-animation" id="bgAnimation"></div>
    
    <!-- Error Container -->
    <div class="error-container">
        <div class="error-card">
            <!-- Error Icon -->
            <div class="error-icon">
                <div class="error-icon-circle">
                    <i class="fas fa-compass"></i>
                </div>
            </div>
            
            <!-- Error Code -->
            <div class="error-code">404</div>
            
            <!-- Error Title -->
            <h1 class="error-title">Page Not Found</h1>
            
            <!-- Error Message -->
            <p class="error-message">
                The page you're looking for doesn't exist or has been moved.<br>
                Let's get you back on track!
            </p>
            
            
            
            <!-- Quick Links -->
            <div class="quick-links">
                <h4>Popular Destinations</h4>
                <div class="links-grid">
                    <?php if ($isLoggedIn): ?>
                        <?php if ($userRole === 'admin'): ?>
                            <a href="/admin/dashboard.php" class="quick-link">Dashboard</a>
                            <a href="/admin/offers.php" class="quick-link">Campaigns</a>
                            <a href="/admin/publishers.php" class="quick-link">Publishers</a>
                            <a href="/admin/advertisers.php" class="quick-link">Advertisers</a>
                            <a href="/admin/reports_campaigns.php" class="quick-link">Reports</a>
                        <?php elseif ($userRole === 'advertiser'): ?>
                            <a href="/advertiser/dashboard.php" class="quick-link">Dashboard</a>
                            <a href="/advertiser/campaigns.php" class="quick-link">Campaigns</a>
                            <a href="/advertiser/reports_campaigns.php" class="quick-link">Reports</a>
                            <a href="/advertiser/create_offer.php" class="quick-link">Create Offer</a>
                            <a href="/advertiser/postback.php" class="quick-link">Postback</a>
                        <?php elseif ($userRole === 'affiliate'): ?>
                            <a href="/affiliate/dashboard.php" class="quick-link">Dashboard</a>
                            <a href="/affiliate/offers.php" class="quick-link">Offers</a>
                            <a href="/affiliate/reports.php" class="quick-link">Reports</a>
                            <a href="/affiliate/tools.php" class="quick-link">Tools</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="/index.php" class="quick-link">Home</a>
                        <a href="/login.php" class="quick-link">Login</a>
                        <a href="/register.php" class="quick-link">Register</a>
                        <a href="/about.php" class="quick-link">About Us</a>
                        <a href="/contact.php" class="quick-link">Contact</a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Debug Info (remove in production) -->
            <?php if (isset($_SERVER['HTTP_HOST'])): ?>
            <div class="debug-info">
                <i class="fas fa-info-circle mr-2"></i>
                Requested URL: <?php echo htmlspecialchars($requestedUrl); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Create animated background particles
        document.addEventListener('DOMContentLoaded', function() {
            const bgAnimation = document.getElementById('bgAnimation');
            
            for (let i = 0; i < 25; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                
                const size = Math.random() * 100 + 50;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                particle.style.left = `${Math.random() * 100}%`;
                particle.style.top = `${Math.random() * 100}%`;
                
                const delay = Math.random() * 5;
                const duration = 20 + Math.random() * 15;
                particle.style.animationDelay = `${delay}s`;
                particle.style.animationDuration = `${duration}s`;
                particle.style.opacity = Math.random() * 0.1 + 0.05;
                
                bgAnimation.appendChild(particle);
            }
            
            // Add 3D tilt effect on card
            const card = document.querySelector('.error-card');
            
            card.addEventListener('mousemove', (e) => {
                const rect = card.getBoundingClientRect();
                const x = (e.clientX - rect.left) / rect.width - 0.5;
                const y = (e.clientY - rect.top) / rect.height - 0.5;
                
                card.style.transform = `perspective(1000px) rotateY(${x * 5}deg) rotateX(${y * -5}deg) translateY(0)`;
            });
            
            card.addEventListener('mouseleave', () => {
                card.style.transform = 'perspective(1000px) rotateY(0deg) rotateX(0deg) translateY(0)';
            });
        });
    </script>
</body>
</html>