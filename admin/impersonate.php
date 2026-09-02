<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

// Ensure token parameter is supplied
$token = $_GET['token'] ?? '';
if (empty($token) || !isset($_SESSION['impersonate_tokens'][$token])) {
    die('Invalid or expired impersonation token.');
}

$targetData = $_SESSION['impersonate_tokens'][$token];
unset($_SESSION['impersonate_tokens'][$token]); // Single-use token

// Check expiration (2 minutes)
if (time() - $targetData['created_at'] > 120) {
    die('Impersonation token expired. Please try again from Admin Panel.');
}

$targetUserId = (int)$targetData['user_id'];

// Fetch target user from DB
$stmt = $pdo->prepare("
    SELECT u.user_id, u.name, u.email, u.status, r.role_name
    FROM users u
    INNER JOIN roles r ON r.role_id = u.role_id
    WHERE u.user_id = ? LIMIT 1
");
$stmt->execute([$targetUserId]);
$targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    die('User account not found.');
}

// Perform session switch
session_regenerate_id(true);

$_SESSION['auth'] = [
    'user_id'  => (int)$targetUser['user_id'],
    'role'     => $targetUser['role_name'],
    'login_at' => time()
];
$_SESSION['user_name']  = $targetUser['name'] ?? 'Publisher';
$_SESSION['user_email'] = $targetUser['email'] ?? '';
$_SESSION['is_impersonating'] = true;

// Redirect to target portal based on role
if ($targetUser['role_name'] === 'affiliate') {
    header('Location: /affiliate/dashboard.php');
} elseif ($targetUser['role_name'] === 'advertiser') {
    header('Location: /advertiser/dashboard.php');
} else {
    header('Location: /dashboard.php');
}
exit;
