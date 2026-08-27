<?php
/**
 * Database connection (PHP 7.1 compatible)
 */

if (!defined('APP_INIT')) {
    die('Direct access not allowed');
}

$DB_HOST = 'localhost';
$DB_NAME = 'zktddbzk_iconmedia';
$DB_USER = 'zktddbzk_azhar';
$DB_PASS = 'Soumojit1234@';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // NEVER show DB errors in production
    error_log('DB Connection failed: ' . $e->getMessage());
    die('Database connection error');
}
