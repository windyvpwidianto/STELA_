<?php
require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once dirname(__DIR__) . '/app/Helpers/auth_helper.php';
require_once dirname(__DIR__) . '/app/Services/ExpiryReminderService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Only allow admin and superadmin to trigger manual check
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['superadmin', 'admin'])) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Unauthorized access. Only administrators can run expiry checks.'
        ]);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. POST required.']);
        exit();
    }

    if (!function_exists('verify_csrf_token')) {
        require_once dirname(__DIR__) . '/app/Helpers/csrf_helper.php';
    }
    if (!verify_csrf_token()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or missing CSRF token.']);
        exit();
    }

    $service = new ExpiryReminderService();
    $summary = $service->runDailyCheck();

    echo json_encode([
        'status' => 'success',
        'message' => 'Pengecekan masa berlaku sertifikat dan SK berhasil dijalankan.',
        'data' => $summary
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
