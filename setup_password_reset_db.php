<?php
/**
 * Create password_resets table script
 * Executed via web browser or CLI
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/app/config/database.php';

try {
    $sql = "
    CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        email VARCHAR(255) NOT NULL,
        token VARCHAR(100) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    $pdo->exec($sql);
    echo "<div style='font-family: sans-serif; padding: 30px; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; border-radius: 8px;'>";
    echo "<h2>✅ Database Table Created Successfully!</h2>";
    echo "<p>Table <code>password_resets</code> is ready. You can now use the Forgot Password email system.</p>";
    echo "<a href='/login.php' style='color: #2563eb; font-weight: bold;'>Return to Login →</a>";
    echo "</div>";
} catch (PDOException $e) {
    echo "<div style='font-family: sans-serif; padding: 30px; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 8px;'>";
    echo "<h2>❌ Database Migration Error</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
