<?php
/**
 * Show client IP (for testing postback IP whitelist)
 * PHP 7.1+
 */

header('Content-Type: text/plain');

echo "REMOTE_ADDR: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . PHP_EOL;

echo "HTTP_X_FORWARDED_FOR: " . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'N/A') . PHP_EOL;

echo "HTTP_CLIENT_IP: " . ($_SERVER['HTTP_CLIENT_IP'] ?? 'N/A') . PHP_EOL;
