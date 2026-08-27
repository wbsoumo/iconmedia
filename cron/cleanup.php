<?php
/**
 * Daily cleanup job
 * PHP 7.1+
 */

require_once __DIR__ . '/../app/config/database.php';

// Delete old clicks (90 days)
$pdo->exec("
    DELETE FROM clicks
    WHERE created_at < NOW() - INTERVAL 90 DAY
");

// Delete old postback logs (30 days)
$pdo->exec("
    DELETE FROM postback_logs
    WHERE created_at < NOW() - INTERVAL 30 DAY
");

// Optimize tables (optional, light)
$pdo->exec("OPTIMIZE TABLE clicks, postback_logs");

echo "Cleanup done\n";
