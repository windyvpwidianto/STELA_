<?php
/**
 * Security: Rate Limiter and Web Application Firewall (WAF)
 * 
 * First line of defense to prevent DoS attacks, SQL Injection, and XSS.
 * Placed at the very beginning of application bootstrap.
 */

class Firewall {
    private static $maxRequestsPerMinute = 200;
    private static $blockDuration = 600; // 10 minutes in seconds

    public static function run() {
        self::checkCors();
        self::checkCsrf();
        self::checkWafRules();
        self::checkRateLimit();
    }

    private static function checkCors() {
        // Define explicitly allowed origins (no wildcard '*')
        $allowedOrigins = [
            'http://localhost',
            'http://localhost:8000',
            'http://localhost:8080',
            'http://127.0.0.1',
            'http://127.0.0.1:8000',
            'http://127.0.0.1:8080'
        ];

        // Additional allowed origins from environment/config
        if (defined('ALLOWED_CORS_ORIGINS') && is_array(ALLOWED_CORS_ORIGINS)) {
            $allowedOrigins = array_merge($allowedOrigins, ALLOWED_CORS_ORIGINS);
        } elseif (getenv('ALLOWED_CORS_ORIGINS')) {
            $allowedOrigins = array_merge($allowedOrigins, array_map('trim', explode(',', getenv('ALLOWED_CORS_ORIGINS'))));
        }

        // Validate and allow the current server's own host if standard format
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        if ($currentHost && preg_match('/^[a-zA-Z0-9.:_-]+$/', $currentHost)) {
            $allowedOrigins[] = $protocol . '://' . $currentHost;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // If Origin header is present, check against whitelist
        if ($origin) {
            if (in_array($origin, $allowedOrigins, true)) {
                header("Access-Control-Allow-Origin: $origin");
                header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
                header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Cache-Control");
                header("Access-Control-Allow-Credentials: true");
            } else {
                // If it's a preflight request from an unauthorized origin, block it
                if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
                    self::abort(403, "CORS Policy: Origin not allowed.");
                }
            }
        }

        // Always intercept preflight OPTIONS requests to avoid executing app logic
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    private static function checkCsrf() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $helperPath = dirname(__DIR__) . '/Helpers/csrf_helper.php';
            if (file_exists($helperPath)) {
                require_once $helperPath;
            }
            
            // Allow login endpoint to pass without CSRF if it hasn't been fully adapted yet, 
            // though ideally login should have CSRF too. Assuming verify_csrf_token works everywhere.
            if (function_exists('verify_csrf_token') && !verify_csrf_token()) {
                self::abort(403, "Access Denied: CSRF Token Validation Failed.");
            }
        }
    }

    private static function getClientIp() {
        $remoteAddr = trim($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN');
        
        // Trusted proxy support (prevents IP spoofing while allowing reverse proxies/load balancers)
        $trustedProxies = defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : (getenv('TRUSTED_PROXIES') ? explode(',', getenv('TRUSTED_PROXIES')) : []);
        if (!empty($trustedProxies)) {
            $isTrusted = in_array('*', $trustedProxies, true) || in_array($remoteAddr, $trustedProxies, true);
            if ($isTrusted) {
                if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                    return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
                }
                if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                    return trim($_SERVER['HTTP_X_REAL_IP']);
                }
                if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                    $forwardedIps = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                    return trim($forwardedIps[0]);
                }
            }
        }
        
        return $remoteAddr;
    }

    private static function checkRateLimit() {
        $ip = self::getClientIp();
        if ($ip === 'UNKNOWN') return;

        // Determine if this is a sensitive endpoint that requires stricter limits
        $isSensitive = false;
        $maxLimit = self::$maxRequestsPerMinute;
        
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $sensitiveEndpoints = [
            'login.php',
            'change_password.php',
            'users.php',
            'add_employee.php'
        ];
        
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            foreach ($sensitiveEndpoints as $endpoint) {
                if (strpos($scriptPath, $endpoint) !== false) {
                    $isSensitive = true;
                    $maxLimit = 10; // Strict limit: 10 requests per minute for sensitive POST actions
                    break;
                }
            }
        }

        // Use system temp directory for high-speed, volatile storage
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stela_waf_cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0750, true);
        }

        $ipHash = md5($ip);
        $file = $cacheDir . DIRECTORY_SEPARATOR . $ipHash . '.json';
        
        $currentTime = time();
        $currentMinute = floor($currentTime / 60);

        // Atomic file read and write using flock to prevent race conditions
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return; // If temp file cannot be opened, fail-open to not block users
        }

        if (!@flock($fp, LOCK_EX)) {
            @fclose($fp);
            return;
        }

        $content = '';
        while (!feof($fp)) {
            $content .= fread($fp, 8192);
        }

        $data = ['minute' => $currentMinute, 'count' => 0, 'blocked_until' => 0];
        if (!empty($content)) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        // Check if currently blocked
        if ($data['blocked_until'] > $currentTime) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
            self::abort(429, "Too Many Requests. Your access has been temporarily blocked for suspicious activity. Please try again later.");
        }

        // Reset counter if minute changed
        if ($data['minute'] != $currentMinute) {
            $data['minute'] = $currentMinute;
            $data['count'] = 0;
        }

        $data['count']++;

        $shouldBlock = ($data['count'] > $maxLimit);
        if ($shouldBlock) {
            $data['blocked_until'] = $currentTime + self::$blockDuration;
        }

        // Write updated data atomically
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        fflush($fp);
        @flock($fp, LOCK_UN);
        @fclose($fp);

        if ($shouldBlock) {
            $message = $isSensitive 
                ? "Too Many Requests. Security rate limit exceeded for sensitive operation. IP blocked for 10 minutes."
                : "Too Many Requests. Global rate limit exceeded. IP blocked for 10 minutes.";
            self::abort(429, $message);
        }
    }

    private static function checkWafRules() {
        // Bad Bots Block
        $userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        $badBots = ['sqlmap', 'nikto', 'dirb', 'nmap', 'python-requests'];
        foreach ($badBots as $bot) {
            if (strpos($userAgent, $bot) !== false) {
                self::abort(403, "Access Denied: Suspicious User-Agent.");
            }
        }

        // Inspect all incoming data without union collision (check GET, POST, and COOKIE independently)
        $inputs = [$_GET, $_POST, $_COOKIE];
        foreach ($inputs as $payload) {
            array_walk_recursive($payload, function($value) {
                if (is_string($value)) {
                    // 1. High-confidence SQL Injection attack patterns (avoids false-positives on normal text)
                    $sqlPatterns = [
                        '/\bunion\s+(all\s+)?select\b/i',
                        '/\bdrop\s+table\b/i',
                        '/\btruncate\s+table\b/i',
                        '/\bbenchmark\s*\(\s*\d+/i',
                        '/\bsleep\s*\(\s*\d+/i',
                        '/\bload_file\s*\(/i',
                        '/\binto\s+(outfile|dumpfile)\b/i',
                        '/\binformation_schema\b/i'
                    ];

                    foreach ($sqlPatterns as $pattern) {
                        if (preg_match($pattern, $value)) {
                            self::abort(403, "Access Denied: Malicious SQL payload detected.");
                        }
                    }

                    // 2. Cross-Site Scripting (XSS)
                    $xssPatterns = [
                        '/<script.*?>/i',
                        '/javascript:/i',
                        '/vbscript:/i',
                        '/onload=/i',
                        '/onerror=/i',
                        '/onmouseover=/i'
                    ];

                    foreach ($xssPatterns as $pattern) {
                        if (preg_match($pattern, $value)) {
                            self::abort(403, "Access Denied: Malicious XSS payload detected.");
                        }
                    }

                    // 3. Path Traversal
                    if (strpos($value, '../') !== false || strpos($value, '..\\') !== false) {
                        self::abort(403, "Access Denied: Path Traversal detected.");
                    }
                }
            });
        }
    }

    private static function abort($code, $message) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }
}
