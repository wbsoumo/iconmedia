<?php
/**
 * User Registration Core
 * PHP 7.1+
 */

if (!defined('APP_INIT')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../config/database.php';

/**
 * Register user (affiliate or advertiser)
 */
function register_user($role_name, array $data)
{
    global $pdo;

    if (!in_array($role_name, ['affiliate', 'advertiser'], true)) {
        return ['success' => false, 'error' => 'Invalid role'];
    }

    // Basic validation
    if (
        empty($data['name']) ||
        empty($data['email']) ||
        empty($data['password'])
    ) {
        return ['success' => false, 'error' => 'Missing required fields'];
    }

    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid email address'];
    }

    if (strlen($data['password']) < 8) {
        return ['success' => false, 'error' => 'Password must be at least 8 characters'];
    }

    // Get role_id
    $roleStmt = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = :role LIMIT 1");
    $roleStmt->execute(['role' => $role_name]);
    $role = $roleStmt->fetch();

    if (!$role) {
        return ['success' => false, 'error' => 'Role not found'];
    }

    // Check email uniqueness
    $check = $pdo->prepare("SELECT user_id FROM users WHERE email = :email LIMIT 1");
    $check->execute(['email' => $data['email']]);

    if ($check->fetch()) {
        return ['success' => false, 'error' => 'Email already registered'];
    }

    // Hash password
    $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);

    // Insert user
    $insert = $pdo->prepare("
        INSERT INTO users (
            role_id,
            name,
            email,
            password_hash,
            company,
            status,
            created_at
        ) VALUES (
            :role_id,
            :name,
            :email,
            :password,
            :company,
            'pending',
            NOW()
        )
    ");

    $insert->execute([
        'role_id'  => $role['role_id'],
        'name'     => trim($data['name']),
        'email'    => strtolower(trim($data['email'])),
        'password' => $passwordHash,
        'company'  => isset($data['company']) ? trim($data['company']) : null
    ]);

    return ['success' => true];
}
