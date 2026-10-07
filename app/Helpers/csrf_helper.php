<?php

/**
 * CSRF Protection Helper
 * Provides functions to generate and verify CSRF tokens.
 */

if (!function_exists('csrf_token')) {
    /**
     * Get the current CSRF token from the session.
     * Generates a new one if it doesn't exist.
     *
     * @return string
     */
    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Generate an HTML hidden input field containing the CSRF token.
     *
     * @return string
     */
    function csrf_field() {
        return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
    }
}

if (!function_exists('verify_csrf_token')) {
    /**
     * Verify that the CSRF token in the request matches the one in the session.
     * Supports $_POST, HTTP headers (X-CSRF-TOKEN), and JSON payloads.
     *
     * @param string|null $token The token to verify
     * @return bool True if valid, False otherwise
     */
    function verify_csrf_token($token = null) {
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? '';
            if (empty($token) && !empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
                $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
            }
            if (empty($token) && !empty($_SERVER['HTTP_X_XSRF_TOKEN'])) {
                $token = $_SERVER['HTTP_X_XSRF_TOKEN'];
            }
            if (empty($token)) {
                $raw = @file_get_contents('php://input');
                if (!empty($raw)) {
                    $json = @json_decode($raw, true);
                    if (is_array($json) && !empty($json['csrf_token'])) {
                        $token = $json['csrf_token'];
                    }
                }
            }
        }
        
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('regenerate_csrf_token')) {
    /**
     * Regenerate the CSRF token.
     * Useful to call after a successful state-changing operation.
     *
     * @return string
     */
    function regenerate_csrf_token() {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }
}
