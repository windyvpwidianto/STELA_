<?php
/**
 * Logout Handler
 */
require_once __DIR__ . '/bootstrap/app.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// [SECURITY] Logout CSRF Protection
$token = $_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? '');
$isTimeout = (isset($_GET['reason']) && $_GET['reason'] === 'timeout');
$isTokenValid = (!empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token));

if (!$isTimeout && !$isTokenValid && isset($_SESSION['user_id'])) {
    // If accessed without valid CSRF token, show confirmation to prevent Logout CSRF
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Konfirmasi Logout</title><style>body{font-family:sans-serif;text-align:center;padding:50px;background:#f8f9fa;color:#333;}button{padding:10px 20px;cursor:pointer;background:#dc3545;color:#fff;border:none;border-radius:4px;font-size:16px;}a{padding:10px 20px;display:inline-block;color:#6c757d;text-decoration:none;}</style></head><body>';
    echo '<h2>Konfirmasi Keluar</h2><p>Apakah Anda yakin ingin keluar dari sistem?</p>';
    echo '<form method="POST" action="' . BASE_URL . '/logout.php">';
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') . '">';
    echo '<button type="submit">Ya, Keluar</button> ';
    echo '<a href="javascript:history.back()">Batal</a>';
    echo '</form></body></html>';
    exit;
}

if (isset($_COOKIE['remember_me'])) {
    if (isset($_SESSION['user_id'])) {
        require_once __DIR__ . '/app/Models/Database.php';
        $db = new Database();
        $conn = $db->getConnection();
        
        $cookieParts = explode(':', $_COOKIE['remember_me']);
        if (count($cookieParts) === 2) {
            $selector = $cookieParts[0];
            $stmt = $conn->prepare("DELETE FROM user_tokens WHERE selector = ? AND user_id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $selector, $_SESSION['user_id']);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    
    // Hapus cookie remember_me dari browser
    setcookie(
        'remember_me',
        '',
        time() - 3600,
        '/',
        '',
        isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        true
    );
}

// Hapus cookie sesi dari browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_unset();
session_destroy();
redirect(BASE_URL . '/index.php' . ($isTimeout ? '?error=timeout' : ''));
