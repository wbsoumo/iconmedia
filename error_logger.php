<?php
/**
 * Error Logger Script
 * Logs 403 and 404 errors to database
 * Include this file in error handlers
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class ErrorLogger {
    private $pdo;
    private $errorCode;
    private $requestedUrl;
    private $referrer;
    private $ipAddress;
    private $userAgent;
    private $userId;
    private $userRole;
    private $requestMethod;
    private $queryString;
    
    /**
     * Constructor
     * @param int $errorCode 403 or 404
     */
    public function __construct($errorCode) {
        $this->errorCode = (int)$errorCode;
        $this->requestedUrl = $this->getCurrentUrl();
        $this->referrer = $_SERVER['HTTP_REFERER'] ?? null;
        $this->ipAddress = $this->getClientIp();
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $this->requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->queryString = $_SERVER['QUERY_STRING'] ?? null;
        
        // Get user info if logged in
        $this->userId = $_SESSION['user_id'] ?? $_SESSION['auth']['user_id'] ?? null;
        $this->userRole = $_SESSION['auth']['role'] ?? $_SESSION['role'] ?? null;
        
        // Initialize database connection
        $this->initDatabase();
    }
    
    /**
     * Initialize database connection
     */
    private function initDatabase() {
        try {
            // Include database config
            require_once __DIR__ . '/app/config/database.php';
            
            if (isset($pdo) && $pdo instanceof PDO) {
                $this->pdo = $pdo;
            } else {
                // Fallback connection if config not loaded
                $config = require __DIR__ . '/app/config/config.php';
                $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
                $this->pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
            }
        } catch (PDOException $e) {
            // Log to file if database fails
            $this->logToFile("Database connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get client IP address
     */
    private function getClientIp() {
        $ipHeaders = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                return trim($ip);
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Get current URL
     */
    private function getCurrentUrl() {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return $protocol . $host . $uri;
    }
    
    /**
     * Log error to database
     */
    public function log() {
        if (!$this->pdo) {
            $this->logToFile("No database connection for error {$this->errorCode}: {$this->requestedUrl}");
            return false;
        }
        
        try {
            $sql = "INSERT INTO error_logs (
                error_code, requested_url, referrer_url, ip_address, 
                user_agent, user_id, user_role, request_method, query_string, logged_at
            ) VALUES (
                :error_code, :requested_url, :referrer_url, :ip_address,
                :user_agent, :user_id, :user_role, :request_method, :query_string, NOW()
            )";
            
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                ':error_code' => $this->errorCode,
                ':requested_url' => $this->requestedUrl,
                ':referrer_url' => $this->referrer,
                ':ip_address' => $this->ipAddress,
                ':user_agent' => $this->userAgent,
                ':user_id' => $this->userId,
                ':user_role' => $this->userRole,
                ':request_method' => $this->requestMethod,
                ':query_string' => $this->queryString
            ]);
            
            if ($result) {
                $this->logToFile("Logged error {$this->errorCode}: {$this->requestedUrl}");
            }
            
            return $result;
        } catch (PDOException $e) {
            $this->logToFile("Failed to log error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log to file as fallback
     */
    private function logToFile($message) {
        $logFile = __DIR__ . '/logs/error_logger.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Get error statistics
     */
    public static function getStats($pdo, $days = 30) {
        $stats = [];
        
        // Total errors by type
        $sql = "SELECT 
                    error_code,
                    COUNT(*) as total,
                    COUNT(DISTINCT ip_address) as unique_ips,
                    COUNT(DISTINCT requested_url) as unique_urls
                FROM error_logs
                WHERE logged_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY error_code";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':days' => $days]);
        $stats['by_type'] = $stmt->fetchAll();
        
        // Top 10 URLs with most errors
        $sql = "SELECT 
                    requested_url,
                    error_code,
                    COUNT(*) as hits,
                    MAX(logged_at) as last_hit
                FROM error_logs
                WHERE logged_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY requested_url, error_code
                ORDER BY hits DESC
                LIMIT 10";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':days' => $days]);
        $stats['top_urls'] = $stmt->fetchAll();
        
        // Errors by IP (potential attackers)
        $sql = "SELECT 
                    ip_address,
                    COUNT(*) as hits,
                    GROUP_CONCAT(DISTINCT error_code) as error_types,
                    MIN(logged_at) as first_seen,
                    MAX(logged_at) as last_seen
                FROM error_logs
                WHERE logged_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY ip_address
                HAVING hits > 10
                ORDER BY hits DESC
                LIMIT 20";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':days' => $days]);
        $stats['suspicious_ips'] = $stmt->fetchAll();
        
        // Hourly distribution
        $sql = "SELECT 
                    HOUR(logged_at) as hour,
                    SUM(CASE WHEN error_code = 404 THEN 1 ELSE 0 END) as errors_404,
                    SUM(CASE WHEN error_code = 403 THEN 1 ELSE 0 END) as errors_403
                FROM error_logs
                WHERE logged_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY HOUR(logged_at)
                ORDER BY hour";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $stats['hourly'] = $stmt->fetchAll();
        
        return $stats;
    }
}

// If this file is called directly, log the current error
if (isset($_GET['code']) && in_array($_GET['code'], ['403', '404'])) {
    $logger = new ErrorLogger($_GET['code']);
    $logger->log();
}