<?php
/**
 * Authentication Core
 * PHP 7.1+
 * CLEAN & STABLE VERSION
 */

if (!defined('APP_INIT')) {
    die('Direct access not allowed');
}

/* =================================================
   SESSION INITIALIZATION (DO THIS ONCE, PROPERLY)
================================================= */

/**
 * IMPORTANT RULES:
 * - session_set_cookie_params() MUST be called BEFORE session_start()
 * - session_start() MUST be called ONCE
 * - Do NOT mix ini_set + session_set_cookie_params
 */

// Detect HTTPS
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['SERVER_PORT'] ?? null) == 443
);

// Force cookie params (shared hosting safe)
session_set_cookie_params(
    0,          // lifetime (session)
    '/',        // path (ENTIRE DOMAIN)
    '',         // domain (auto)
    $isHttps,   // secure
    true        // httponly
);

// Start session ONCE
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =================================================
   DATABASE
================================================= */

require_once __DIR__ . '/../config/database.php';

/* =================================================
   AUTH FUNCTIONS
================================================= */

/**
 * Perform login (email + password)
 */
function auth_login($email, $password)
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT 
            u.user_id,
            u.name,
            u.email,
            u.password_hash,
            u.status,
            r.role_name
        FROM users u
        INNER JOIN roles r ON r.role_id = u.role_id
        WHERE u.email = :email
        LIMIT 1
    ");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'error' => 'Invalid credentials'];
    }

    if ($user['status'] !== 'active') {
        return ['success' => false, 'error' => 'Account not active'];
    }

    // Prevent session fixation
    session_regenerate_id(true);

    // Store auth state (SINGLE SOURCE OF TRUTH)
    $_SESSION['auth'] = [
        'user_id'  => (int)$user['user_id'],
        'role'     => $user['role_name'],
        'login_at' => time()
    ];
    $_SESSION['user_name']  = $user['name'] ?? 'User';
    $_SESSION['user_email'] = $user['email'] ?? '';

    // Update last login
    $upd = $pdo->prepare("
        UPDATE users
        SET last_login_ip = INET6_ATON(:ip),
            last_login_at = NOW()
        WHERE user_id = :uid
    ");
    $upd->execute([
        'ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
        'uid' => $user['user_id']
    ]);

    return ['success' => true, 'role' => $user['role_name']];
}

/**
 * Logout
 */
function auth_logout()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
}

/**
 * Is user logged in?
 */
function auth_check()
{
    return isset($_SESSION['auth']['user_id']);
}

/**
 * Get logged-in user ID
 */
function auth_user_id()
{
    return auth_check() ? (int)$_SESSION['auth']['user_id'] : null;
}

/**
 * Get logged-in role
 */
function auth_role()
{
    return auth_check() ? $_SESSION['auth']['role'] : null;
}

/**
 * Require login
 */
function require_auth()
{
    if (!auth_check()) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Require specific role
 */
function require_role($role)
{
    require_auth();

    if ($_SESSION['auth']['role'] !== $role) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Require any role from list
 */
function require_any_role(array $roles)
{
    require_auth();

    if (!in_array($_SESSION['auth']['role'], $roles, true)) {
        header('Location: /login.php');
        exit;
    }
}
