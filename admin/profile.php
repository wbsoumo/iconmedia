<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminId = auth_user_id();
$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

/* ===============================
   FETCH ADMIN PROFILE DATA
================================ */
$stmt = $pdo->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.mobile,
        u.profile_image,
        u.bio,
        u.department,
        u.designation,
        u.last_login_at,
        u.last_login_ip,
        u.created_at,
        u.updated_at,
        u.two_factor_enabled,
        u.notification_email,
        u.notification_sms,
        u.theme_preference,
        
        -- Admin specific fields
        ua.permission_level,
        ua.can_manage_users,
        ua.can_manage_finance,
        ua.can_manage_reports,
        ua.can_manage_settings,
        
        -- Session info
        COUNT(DISTINCT s.session_id) as active_sessions
        
    FROM users u
    LEFT JOIN user_permissions ua ON ua.user_id = u.user_id
    LEFT JOIN user_sessions s ON s.user_id = u.user_id AND s.is_active = 1
    WHERE u.user_id = :user_id AND u.role_id = 1
    GROUP BY u.user_id
");

$stmt->execute(['user_id' => $adminId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    die('Admin profile not found');
}

/* ===============================
   UPDATE PROFILE INFORMATION
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Update Basic Info
    if (isset($_POST['update_profile'])) {
        
        $name = trim($_POST['name'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $notification_email = isset($_POST['notification_email']) ? 1 : 0;
        $notification_sms = isset($_POST['notification_sms']) ? 1 : 0;
        
        if (!$name) {
            $error = 'Name is required';
        } else {
            
            // Update users table
            $stmt = $pdo->prepare("
                UPDATE users 
                SET name = :name,
                    mobile = :mobile,
                    bio = :bio,
                    department = :department,
                    designation = :designation,
                    notification_email = :notification_email,
                    notification_sms = :notification_sms,
                    updated_at = NOW()
                WHERE user_id = :user_id
            ");
            
            $stmt->execute([
                'name' => $name,
                'mobile' => $mobile,
                'bio' => $bio,
                'department' => $department,
                'designation' => $designation,
                'notification_email' => $notification_email,
                'notification_sms' => $notification_sms,
                'user_id' => $adminId
            ]);
            
            // Update session name
            $_SESSION['user_name'] = $name;
            
            $success = 'Profile updated successfully';
            
            // Refresh profile data
            $stmt->execute(['user_id' => $adminId]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    
    // Update Password
    if (isset($_POST['update_password'])) {
        
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (!$current_password || !$new_password || !$confirm_password) {
            $error = 'All password fields are required';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match';
        } elseif (strlen($new_password) < 6) {
            $error = 'Password must be at least 6 characters';
        } else {
            
            // Verify current password
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $stmt->execute([$adminId]);
            $user = $stmt->fetch();
            
            if (!password_verify($current_password, $user['password_hash'])) {
                $error = 'Current password is incorrect';
            } else {
                
                $passwordHash = password_hash($new_password, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET password_hash = :password,
                        updated_at = NOW()
                    WHERE user_id = :user_id
                ");
                
                $stmt->execute([
                    'password' => $passwordHash,
                    'user_id' => $adminId
                ]);
                
                $success = 'Password updated successfully';
            }
        }
    }
    
    // Update Profile Image
    if (isset($_POST['update_image']) && isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $maxSize = 2 * 1024 * 1024; // 2MB
        
        $fileType = $_FILES['profile_image']['type'];
        $fileSize = $_FILES['profile_image']['size'];
        
        if (!in_array($fileType, $allowedTypes)) {
            $error = 'Only JPG, PNG and GIF images are allowed';
        } elseif ($fileSize > $maxSize) {
            $error = 'Image size must be less than 2MB';
        } else {
            
            $uploadDir = __DIR__ . '/../uploads/profile/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $filename = 'admin_' . $adminId . '_' . time() . '.' . $extension;
            $filepath = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $filepath)) {
                
                // Delete old image if exists
                if ($profile['profile_image'] && file_exists($uploadDir . $profile['profile_image'])) {
                    unlink($uploadDir . $profile['profile_image']);
                }
                
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET profile_image = :image,
                        updated_at = NOW()
                    WHERE user_id = :user_id
                ");
                
                $stmt->execute([
                    'image' => $filename,
                    'user_id' => $adminId
                ]);
                
                $success = 'Profile image updated successfully';
                
                // Refresh profile data
                $profile['profile_image'] = $filename;
            } else {
                $error = 'Failed to upload image';
            }
        }
    }
    
    // Enable 2FA
    if (isset($_POST['enable_2fa'])) {
        
        // Generate 2FA secret
        $secret = bin2hex(random_bytes(10));
        $qrCodeUrl = "otpauth://totp/GVSIconMedia:{$profile['email']}?secret={$secret}&issuer=GVSIconMedia";
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET two_factor_secret = :secret,
                two_factor_enabled = 1,
                updated_at = NOW()
            WHERE user_id = :user_id
        ");
        
        $stmt->execute([
            'secret' => $secret,
            'user_id' => $adminId
        ]);
        
        $success = '2FA enabled successfully';
        $profile['two_factor_enabled'] = 1;
        
        // Store QR URL for display
        $twoFactorQR = $qrCodeUrl;
    }
    
    // Disable 2FA
    if (isset($_POST['disable_2fa'])) {
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET two_factor_secret = NULL,
                two_factor_enabled = 0,
                updated_at = NOW()
            WHERE user_id = :user_id
        ");
        
        $stmt->execute(['user_id' => $adminId]);
        
        $success = '2FA disabled successfully';
        $profile['two_factor_enabled'] = 0;
    }
    
    // Update Theme
    if (isset($_POST['update_theme'])) {
        
        $theme = $_POST['theme_preference'] ?? 'light';
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET theme_preference = :theme,
                updated_at = NOW()
            WHERE user_id = :user_id
        ");
        
        $stmt->execute([
            'theme' => $theme,
            'user_id' => $adminId
        ]);
        
        $_SESSION['theme'] = $theme;
        
        $success = 'Theme preference updated';
        $profile['theme_preference'] = $theme;
    }
}

/* ===============================
   FETCH RECENT ACTIVITY
================================ */
$activityStmt = $pdo->prepare("
    SELECT 
        action_type,
        action_description,
        ip_address,
        user_agent,
        created_at
    FROM user_activity_log
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$activityStmt->execute([$adminId]);
$recentActivity = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH ACTIVE SESSIONS
================================ */
$sessionsStmt = $pdo->prepare("
    SELECT 
        session_id,
        ip_address,
        user_agent,
        login_time,
        last_activity,
        is_current_session
    FROM user_sessions
    WHERE user_id = ? AND is_active = 1
    ORDER BY last_activity DESC
");
$sessionsStmt->execute([$adminId]);
$activeSessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Profile | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --info-gradient: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            --warning-gradient: linear-gradient(135deg, #f7971e 0%, #ffd200 100%);
            --danger-gradient: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
            --dark-gradient: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
        }
        
        .card-dashboard {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            overflow: hidden;
        }
        
        .card-dashboard .card-header {
            border-radius: 15px 15px 0 0;
            background: white;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            padding: 20px;
        }
        
        .card-dashboard .card-body {
            padding: 25px;
        }
        
        .profile-header {
            background: var(--dark-gradient);
            color: white;
            padding: 40px;
            border-radius: 15px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.1)" d="M0,96L48,112C96,128,192,160,288,186.7C384,213,480,235,576,213.3C672,192,768,128,864,128C960,128,1056,192,1152,213.3C1248,235,1344,213,1392,202.7L1440,192L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>');
            background-size: cover;
            opacity: 0.1;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            background: var(--primary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            font-weight: 600;
            border: 4px solid rgba(255,255,255,0.3);
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-avatar .initials {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
        }
        
        .avatar-upload {
            position: relative;
            display: inline-block;
        }
        
        .avatar-upload-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            background: var(--primary-gradient);
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 2px solid white;
            transition: all 0.3s ease;
        }
        
        .avatar-upload-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .avatar-upload input[type="file"] {
            display: none;
        }
        
        .filter-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e3e6f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #f8f9fc;
        }
        
        .filter-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: white;
        }
        
        .btn-gradient {
            background: var(--primary-gradient);
            border: none;
            color: white;
            font-weight: 600;
            padding: 10px 25px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }
        
        .btn-outline-primary {
            border: 2px solid #667eea;
            color: #667eea;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary-gradient);
            border-color: transparent;
            color: white;
        }
        
        .admin-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            font-weight: 600;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-active {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        
        .status-inactive {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
        }
        
        .info-item {
            padding: 15px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }
        
        .activity-item {
            padding: 12px;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.3s ease;
        }
        
        .activity-item:hover {
            background: #f8f9fc;
        }
        
        .activity-icon {
            width: 36px;
            height: 36px;
            background: rgba(102, 126, 234, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #667eea;
        }
        
        .session-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            background: #f8f9fc;
            border-radius: 10px;
            margin-bottom: 10px;
        }
        
        .session-item.current {
            border: 2px solid #28a745;
        }
        
        .device-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .device-icon {
            width: 40px;
            height: 40px;
            background: var(--primary-gradient);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }
        
        .alert {
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            border: none;
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            border-left: 4px solid #28a745;
            color: #155724;
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        
        .twofa-qr {
            background: white;
            padding: 20px;
            border-radius: 10px;
            display: inline-block;
            margin: 15px 0;
        }
        
        .nav-tabs {
            border-bottom: 2px solid #e3e6f0;
            margin-bottom: 25px;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 8px 8px 0 0;
        }
        
        .nav-tabs .nav-link.active {
            color: #4e73df;
            border-bottom: 3px solid #4e73df;
            background: rgba(78, 115, 223, 0.05);
        }
        
        .password-requirements {
            background: #f8f9fc;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
        }
        
        .password-requirements li {
            list-style: none;
            margin-bottom: 5px;
            color: #6c757d;
        }
        
        .password-requirements li.valid {
            color: #28a745;
        }
        
        .password-requirements li i {
            width: 20px;
        }
        
        .refresh-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            border-radius: 20px;
            padding: 8px 20px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .refresh-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button">
                    <i class="fas fa-bars"></i>
                </a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="profile.php" class="nav-link active">My Profile</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <a class="nav-link" data-widget="fullscreen" href="#" role="button">
                    <i class="fas fa-expand-arrows-alt"></i>
                </a>
            </li>
            
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                    <div class="admin-avatar mr-2">
                        <?php echo strtoupper(substr($profile['name'], 0, 1)); ?>
                    </div>
                    <span><?php echo htmlspecialchars($profile['name']); ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="profile.php" class="dropdown-item active">
                        <i class="fas fa-user mr-2"></i> My Profile
                    </a>
                    <a href="settings.php" class="dropdown-item">
                        <i class="fas fa-cog mr-2"></i> System Settings
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="../logout.php" class="dropdown-item">
                        <i class="fas fa-sign-out-alt mr-2"></i> Logout
                    </a>
                </div>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="#" id="darkModeToggle">
                    <i class="fas fa-moon"></i>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.5rem;">
                <i class="fas fa-crown mr-2"></i>
                <strong>Admin</strong>
            </span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>

                    <li class="nav-header">CAMPAIGNS</li>
                    <li class="nav-item">
                        <a href="campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-bullhorn"></i>
                            <p>Manage Campaigns</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="create_campaign.php" class="nav-link">
                            <i class="nav-icon fas fa-plus"></i>
                            <p>Create Campaign</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="campaign_access.php" class="nav-link">
                            <i class="nav-icon fas fa-key"></i>
                            <p>Campaign Access</p>
                        </a>
                    </li>

                    <li class="nav-header">REPORTS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Campaign Report</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link">
                            <i class="nav-icon fas fa-users"></i>
                            <p>Affiliate Report</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_advertisers.php" class="nav-link">
                            <i class="nav-icon fas fa-building"></i>
                            <p>Advertiser Report</p>
                        </a>
                    </li>

                    <li class="nav-header">PUBLISHERS</li>
                    <li class="nav-item">
                        <a href="publishers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-friends"></i>
                            <p>Manage Publishers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="publisher_postbacks.php" class="nav-link">
                            <i class="nav-icon fas fa-link"></i>
                            <p>Publisher Postbacks</p>
                        </a>
                    </li>

                    <li class="nav-header">ADVERTISERS</li>
                    <li class="nav-item">
                        <a href="advertisers.php" class="nav-link">
                            <i class="nav-icon fas fa-briefcase"></i>
                            <p>Manage Advertisers</p>
                        </a>
                    </li>

                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="account_managers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-tie"></i>
                            <p>Account Managers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link active">
                            <i class="nav-icon fas fa-user-circle"></i>
                            <p>My Profile</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="settings.php" class="nav-link">
                            <i class="nav-icon fas fa-cog"></i>
                            <p>Settings</p>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    </aside>

    <!-- Content Wrapper -->
    <div class="content-wrapper">
        <!-- Content Header -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0">My Profile</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">My Profile</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">
                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <div class="avatar-upload">
                                <div class="profile-avatar">
                                    <?php if (!empty($profile['profile_image']) && file_exists(__DIR__ . '/../uploads/profile/' . $profile['profile_image'])): ?>
                                        <img src="../uploads/profile/<?php echo $profile['profile_image']; ?>" alt="Profile">
                                    <?php else: ?>
                                        <div class="initials">
                                            <?php echo strtoupper(substr($profile['name'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <form method="post" enctype="multipart/form-data" id="avatarForm">
                                    <label for="profile_image" class="avatar-upload-btn">
                                        <i class="fas fa-camera"></i>
                                    </label>
                                    <input type="file" id="profile_image" name="profile_image" accept="image/*" onchange="document.getElementById('avatarForm').submit();">
                                    <input type="hidden" name="update_image" value="1">
                                </form>
                            </div>
                        </div>
                        <div class="col">
                            <h2 class="mb-1"><?php echo htmlspecialchars($profile['name']); ?></h2>
                            <p class="mb-2"><?php echo htmlspecialchars($profile['designation'] ?? 'Administrator'); ?></p>
                            <p class="mb-0">
                                <i class="fas fa-envelope mr-2"></i><?php echo htmlspecialchars($profile['email']); ?>
                                <?php if ($profile['mobile']): ?>
                                <i class="fas fa-phone ml-4 mr-2"></i><?php echo htmlspecialchars($profile['mobile']); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-auto">
                            <span class="status-badge status-active">
                                <i class="fas fa-circle mr-1"></i> Active
                            </span>
                            <p class="mt-2 mb-0 small">
                                <i class="far fa-clock mr-1"></i>
                                Last login: <?php echo $profile['last_login_at'] ? date('M d, Y H:i', strtotime($profile['last_login_at'])) : 'Never'; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>

                <!-- Tabs -->
                <ul class="nav nav-tabs" id="profileTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="profile-tab" data-toggle="tab" href="#profile" role="tab">
                            <i class="fas fa-user mr-2"></i> Profile Information
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="security-tab" data-toggle="tab" href="#security" role="tab">
                            <i class="fas fa-shield-alt mr-2"></i> Security
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="activity-tab" data-toggle="tab" href="#activity" role="tab">
                            <i class="fas fa-history mr-2"></i> Recent Activity
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="sessions-tab" data-toggle="tab" href="#sessions" role="tab">
                            <i class="fas fa-desktop mr-2"></i> Active Sessions
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="preferences-tab" data-toggle="tab" href="#preferences" role="tab">
                            <i class="fas fa-sliders-h mr-2"></i> Preferences
                        </a>
                    </li>
                </ul>

                <div class="tab-content" id="profileTabsContent">
                    <!-- PROFILE INFORMATION TAB -->
                    <div class="tab-pane fade show active" id="profile" role="tabpanel">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="card-dashboard">
                                    <div class="card-header">
                                        <h3 class="card-title">
                                            <i class="fas fa-edit mr-2"></i> Edit Profile Information
                                        </h3>
                                    </div>
                                    <div class="card-body">
                                        <form method="post">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label class="required" for="name">Full Name</label>
                                                        <input type="text" class="filter-control" id="name" name="name" 
                                                               value="<?php echo htmlspecialchars($profile['name']); ?>" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label for="email">Email Address</label>
                                                        <input type="email" class="filter-control" id="email" 
                                                               value="<?php echo htmlspecialchars($profile['email']); ?>" disabled readonly>
                                                        <small class="text-muted">Email cannot be changed</small>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label for="mobile">Mobile Number</label>
                                                        <input type="text" class="filter-control" id="mobile" name="mobile" 
                                                               value="<?php echo htmlspecialchars($profile['mobile'] ?? ''); ?>"
                                                               placeholder="+1 234 567 8900">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label for="designation">Designation</label>
                                                        <input type="text" class="filter-control" id="designation" name="designation" 
                                                               value="<?php echo htmlspecialchars($profile['designation'] ?? ''); ?>"
                                                               placeholder="e.g., Senior Administrator">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label for="department">Department</label>
                                                        <input type="text" class="filter-control" id="department" name="department" 
                                                               value="<?php echo htmlspecialchars($profile['department'] ?? ''); ?>"
                                                               placeholder="e.g., Operations">
                                                    </div>
                                                </div>
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label for="bio">Bio / About</label>
                                                        <textarea class="filter-control" id="bio" name="bio" rows="4" 
                                                                  placeholder="Tell us a little about yourself..."><?php echo htmlspecialchars($profile['bio'] ?? ''); ?></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mt-3">
                                                <button type="submit" name="update_profile" class="btn-gradient">
                                                    <i class="fas fa-save mr-2"></i> Update Profile
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="card-dashboard">
                                    <div class="card-header">
                                        <h3 class="card-title">
                                            <i class="fas fa-info-circle mr-2"></i> Account Information
                                        </h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="info-item">
                                            <div class="info-label">Member Since</div>
                                            <div class="info-value">
                                                <?php echo date('F d, Y', strtotime($profile['created_at'])); ?>
                                            </div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Last Profile Update</div>
                                            <div class="info-value">
                                                <?php echo $profile['updated_at'] ? date('F d, Y', strtotime($profile['updated_at'])) : 'Never'; ?>
                                            </div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Last Login IP</div>
                                            <div class="info-value">
                                                <?php echo $profile['last_login_ip'] ?? 'Not recorded'; ?>
                                            </div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Account Status</div>
                                            <div class="info-value">
                                                <span class="status-badge status-active">Active</span>
                                            </div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">2FA Status</div>
                                            <div class="info-value">
                                                <?php if ($profile['two_factor_enabled']): ?>
                                                <span class="text-success"><i class="fas fa-check-circle mr-1"></i> Enabled</span>
                                                <?php else: ?>
                                                <span class="text-warning"><i class="fas fa-exclamation-triangle mr-1"></i> Disabled</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="card-dashboard">
                                    <div class="card-header">
                                        <h3 class="card-title">
                                            <i class="fas fa-key mr-2"></i> Permissions
                                        </h3>
                                    </div>
                                    <div class="card-body">
                                        <ul class="list-unstyled">
                                            <li class="mb-2">
                                                <i class="fas fa-<?php echo $profile['can_manage_users'] ? 'check-circle text-success' : 'times-circle text-danger'; ?> mr-2"></i>
                                                Manage Users
                                            </li>
                                            <li class="mb-2">
                                                <i class="fas fa-<?php echo $profile['can_manage_finance'] ? 'check-circle text-success' : 'times-circle text-danger'; ?> mr-2"></i>
                                                Manage Finance
                                            </li>
                                            <li class="mb-2">
                                                <i class="fas fa-<?php echo $profile['can_manage_reports'] ? 'check-circle text-success' : 'times-circle text-danger'; ?> mr-2"></i>
                                                Manage Reports
                                            </li>
                                            <li class="mb-2">
                                                <i class="fas fa-<?php echo $profile['can_manage_settings'] ? 'check-circle text-success' : 'times-circle text-danger'; ?> mr-2"></i>
                                                Manage Settings
                                            </li>
                                        </ul>
                                        <p class="small text-muted mt-3">
                                            <i class="fas fa-info-circle mr-1"></i>
                                            Permission level: <?php echo ucfirst($profile['permission_level'] ?? 'standard'); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECURITY TAB -->
                    <div class="tab-pane fade" id="security" role="tabpanel">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card-dashboard">
                                    <div class="card-header">
                                        <h3 class="card-title">
                                            <i class="fas fa-key mr-2"></i> Change Password
                                        </h3>
                                    </div>
                                    <div class="card-body">
                                        <form method="post" id="passwordForm">
                                            <div class="form-group">
                                                <label class="required" for="current_password">Current Password</label>
                                                <input type="password" class="filter-control" id="current_password" name="current_password" required>
                                            </div>
                                            <div class="form-group">
                                                <label class="required" for="new_password">New Password</label>
                                                <input type="password" class="filter-control" id="new_password" name="new_password" required>
                                            </div>
                                            <div class="form-group">
                                                <label class="required" for="confirm_password">Confirm New Password</label>
                                                <input type="password" class="filter-control" id="confirm_password" name="confirm_password" required>
                                            </div>
                                            
                                            <div class="password-requirements">
                                                <h6>Password Requirements:</h6>
                                                <ul class="pl-0">
                                                    <li id="req-length"><i class="fas fa-circle mr-2"></i> At least 6 characters</li>
                                                    <li id="req-uppercase"><i class="fas fa-circle mr-2"></i> At least one uppercase letter</li>
                                                    <li id="req-number"><i class="fas fa-circle mr-2"></i> At least one number</li>
                                                    <li id="req-match"><i class="fas fa-circle mr-2"></i> Passwords match</li>
                                                </ul>
                                            </div>
                                            
                                            <button type="submit" name="update_password" class="btn-gradient">
                                                <i class="fas fa-save mr-2"></i> Update Password
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="card-dashboard">
                                    <div class="card-header">
                                        <h3 class="card-title">
                                            <i class="fas fa-shield-alt mr-2"></i> Two-Factor Authentication
                                        </h3>
                                    </div>
                                    <div class="card-body">
                                        <?php if ($profile['two_factor_enabled']): ?>
                                            <div class="text-center">
                                                <i class="fas fa-shield-alt fa-4x text-success mb-3"></i>
                                                <h5>2FA is Enabled</h5>
                                                <p class="text-muted">Your account is protected by two-factor authentication.</p>
                                                <form method="post">
                                                    <button type="submit" name="disable_2fa" class="btn btn-danger" onclick="return confirm('Are you sure you want to disable 2FA?')">
                                                        <i class="fas fa-times-circle mr-2"></i> Disable 2FA
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-center">
                                                <i class="fas fa-shield-alt fa-4x text-warning mb-3"></i>
                                                <h5>2FA is Disabled</h5>
                                                <p class="text-muted">Enable two-factor authentication for enhanced security.</p>
                                                
                                                <?php if (isset($twoFactorQR)): ?>
                                                    <div class="twofa-qr">
                                                        <img src="https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=<?php echo urlencode($twoFactorQR); ?>" alt="2FA QR Code">
                                                    </div>
                                                    <p class="small text-muted">Scan this QR code with your authenticator app</p>
                                                <?php endif; ?>
                                                
                                                <form method="post">
                                                    <button type="submit" name="enable_2fa" class="btn-gradient">
                                                        <i class="fas fa-shield-alt mr-2"></i> Enable 2FA
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- RECENT ACTIVITY TAB -->
                    <div class="tab-pane fade" id="activity" role="tabpanel">
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-history mr-2"></i> Recent Activity Log
                                </h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recentActivity)): ?>
                                <div class="empty-state text-center py-5">
                                    <i class="fas fa-history fa-4x text-muted mb-3"></i>
                                    <h5>No Activity Found</h5>
                                    <p class="text-muted">Your recent activity will appear here.</p>
                                </div>
                                <?php else: ?>
                                    <?php foreach ($recentActivity as $activity): ?>
                                    <div class="activity-item d-flex align-items-start">
                                        <div class="activity-icon mr-3">
                                            <?php
                                            $icon = 'circle';
                                            switch ($activity['action_type']) {
                                                case 'login': $icon = 'sign-in-alt'; break;
                                                case 'logout': $icon = 'sign-out-alt'; break;
                                                case 'update': $icon = 'edit'; break;
                                                case 'create': $icon = 'plus-circle'; break;
                                                case 'delete': $icon = 'trash'; break;
                                            }
                                            ?>
                                            <i class="fas fa-<?php echo $icon; ?>"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between">
                                                <strong><?php echo htmlspecialchars($activity['action_description']); ?></strong>
                                                <small class="text-muted"><?php echo date('M d, H:i', strtotime($activity['created_at'])); ?></small>
                                            </div>
                                            <div class="small text-muted">
                                                <i class="fas fa-globe mr-1"></i> <?php echo $activity['ip_address']; ?>
                                                <i class="fas fa-laptop ml-3 mr-1"></i> <?php echo substr($activity['user_agent'], 0, 50); ?>...
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ACTIVE SESSIONS TAB -->
                    <div class="tab-pane fade" id="sessions" role="tabpanel">
                        <div class="card-dashboard">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-desktop mr-2"></i> Active Sessions
                                </h3>
                                <div class="card-tools">
                                    <span class="badge badge-light"><?php echo count($activeSessions); ?> active session(s)</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($activeSessions)): ?>
                                <div class="empty-state text-center py-5">
                                    <i class="fas fa-desktop fa-4x text-muted mb-3"></i>
                                    <h5>No Active Sessions</h5>
                                    <p class="text-muted">You have no active sessions.</p>
                                </div>
                                <?php else: ?>
                                    <?php foreach ($activeSessions as $session): ?>
                                    <div class="session-item <?php echo $session['is_current_session'] ? 'current' : ''; ?>">
                                        <div class="device-info">
                                            <div class="device-icon">
                                                <i class="fas fa-<?php echo strpos($session['user_agent'], 'Mobile') !== false ? 'mobile-alt' : 'desktop'; ?>"></i>
                                            </div>
                                            <div>
                                                <div class="d-flex align-items-center">
                                                    <strong><?php echo $session['is_current_session'] ? 'Current Session' : 'Other Session'; ?></strong>
                                                    <?php if ($session['is_current_session']): ?>
                                                    <span class="badge badge-success ml-2">Active Now</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="small text-muted">
                                                    <i class="fas fa-globe mr-1"></i> <?php echo $session['ip_address']; ?>
                                                    <i class="fas fa-clock ml-3 mr-1"></i> 
                                                    Last activity: <?php echo date('M d, H:i', strtotime($session['last_activity'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if (!$session['is_current_session']): ?>
                                        <button class="btn btn-sm btn-outline-danger" onclick="terminateSession('<?php echo $session['session_id']; ?>')">
                                            <i class="fas fa-times"></i> Terminate
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if (count($activeSessions) > 1): ?>
                                    <div class="text-right mt-3">
                                        <button class="btn btn-warning" onclick="terminateAllSessions()">
                                            <i class="fas fa-power-off mr-2"></i> Terminate All Other Sessions
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- PREFERENCES TAB -->
                    <div class="tab-pane fade" id="preferences" role="tabpanel">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card-dashboard">
                                    <div class="card-header">
                                        <h3 class="card-title">
                                            <i class="fas fa-palette mr-2"></i> Theme Preferences
                                        </h3>
                                    </div>
                                    <div class="card-body">
                                        <form method="post">
                                            <div class="form-group">
                                                <label for="theme_preference">Color Theme</label>
                                                <select class="filter-control" id="theme_preference" name="theme_preference">
                                                    <option value="light" <?php echo ($profile['theme_preference'] ?? 'light') === 'light' ? 'selected' : ''; ?>>Light Mode</option>
                                                    <option value="dark" <?php echo ($profile['theme_preference'] ?? 'light') === 'dark' ? 'selected' : ''; ?>>Dark Mode</option>
                                                    <option value="auto" <?php echo ($profile['theme_preference'] ?? 'light') === 'auto' ? 'selected' : ''; ?>>Auto (System Default)</option>
                                                </select>
                                            </div>
                                            <button type="submit" name="update_theme" class="btn-gradient">
                                                <i class="fas fa-save mr-2"></i> Save Theme Preference
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="card-dashboard">
                                    <div class="card-header">
                                        <h3 class="card-title">
                                            <i class="fas fa-bell mr-2"></i> Notification Preferences
                                        </h3>
                                    </div>
                                    <div class="card-body">
                                        <form method="post">
                                            <div class="custom-control custom-switch mb-3">
                                                <input type="checkbox" class="custom-control-input" id="notification_email" name="notification_email" 
                                                       <?php echo $profile['notification_email'] ? 'checked' : ''; ?>>
                                                <label class="custom-control-label" for="notification_email">Email Notifications</label>
                                            </div>
                                            <div class="custom-control custom-switch mb-3">
                                                <input type="checkbox" class="custom-control-input" id="notification_sms" name="notification_sms" 
                                                       <?php echo $profile['notification_sms'] ? 'checked' : ''; ?>>
                                                <label class="custom-control-label" for="notification_sms">SMS Notifications</label>
                                            </div>
                                            <button type="submit" name="update_profile" class="btn-gradient">
                                                <i class="fas fa-save mr-2"></i> Save Preferences
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline">
            <strong>Admin Panel v3.0</strong>
        </div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- REQUIRED SCRIPTS -->
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<!-- Bootstrap 4 -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- AdminLTE App -->
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    // Dark mode toggle
    $('#darkModeToggle').click(function(e) {
        e.preventDefault();
        $('body').toggleClass('dark-mode');
        $(this).find('i').toggleClass('fa-moon fa-sun');
        localStorage.setItem('darkMode', $('body').hasClass('dark-mode'));
    });
    
    if (localStorage.getItem('darkMode') === 'true') {
        $('body').addClass('dark-mode');
        $('#darkModeToggle i').removeClass('fa-moon').addClass('fa-sun');
    }
    
    // Password validation
    $('#new_password, #confirm_password').on('keyup', function() {
        const password = $('#new_password').val();
        const confirm = $('#confirm_password').val();
        
        // Length check
        if (password.length >= 6) {
            $('#req-length i').removeClass('fa-circle text-muted').addClass('fa-check-circle text-success');
        } else {
            $('#req-length i').removeClass('fa-check-circle text-success').addClass('fa-circle text-muted');
        }
        
        // Uppercase check
        if (/[A-Z]/.test(password)) {
            $('#req-uppercase i').removeClass('fa-circle text-muted').addClass('fa-check-circle text-success');
        } else {
            $('#req-uppercase i').removeClass('fa-check-circle text-success').addClass('fa-circle text-muted');
        }
        
        // Number check
        if (/[0-9]/.test(password)) {
            $('#req-number i').removeClass('fa-circle text-muted').addClass('fa-check-circle text-success');
        } else {
            $('#req-number i').removeClass('fa-check-circle text-success').addClass('fa-circle text-muted');
        }
        
        // Match check
        if (password && confirm && password === confirm) {
            $('#req-match i').removeClass('fa-circle text-muted').addClass('fa-check-circle text-success');
        } else {
            $('#req-match i').removeClass('fa-check-circle text-success').addClass('fa-circle text-muted');
        }
    });
    
    // Auto-submit avatar form
    $('#profile_image').change(function() {
        $('#avatarForm').submit();
    });
    
    // Tab handling from URL
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab');
    if (tabParam === 'security') {
        $('#security-tab').tab('show');
    } else if (tabParam === 'activity') {
        $('#activity-tab').tab('show');
    } else if (tabParam === 'sessions') {
        $('#sessions-tab').tab('show');
    } else if (tabParam === 'preferences') {
        $('#preferences-tab').tab('show');
    }
    
    // Auto-dismiss alerts after 5 seconds
    $('.alert').delay(5000).fadeOut('slow');
    
    // Form validation
    $('#passwordForm').submit(function(e) {
        const password = $('#new_password').val();
        const confirm = $('#confirm_password').val();
        
        if (password.length < 6) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                text: 'Password must be at least 6 characters'
            });
            return false;
        }
        
        if (password !== confirm) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                text: 'Passwords do not match'
            });
            return false;
        }
        
        return true;
    });
    
    // Initialize SweetAlert2 Toast
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });
});

// Session management
function terminateSession(sessionId) {
    Swal.fire({
        title: 'Terminate Session?',
        text: 'This will log out the user from that device',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, terminate'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'terminate_session.php?id=' + sessionId;
        }
    });
}

function terminateAllSessions() {
    Swal.fire({
        title: 'Terminate All Other Sessions?',
        text: 'You will be logged out from all other devices',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, terminate all'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'terminate_all_sessions.php';
        }
    });
}
</script>

</body>
</html>