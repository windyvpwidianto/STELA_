<?php
require_once dirname(__DIR__) . '/bootstrap/app.php';
/**
 * Web Endpoint to Full Sync MySQL DB to Bonsai.io / Elasticsearch
 */
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once dirname(__DIR__) . '/config/app.php';
    require_once dirname(__DIR__) . '/app/Helpers/auth_helper.php';
    require_once dirname(__DIR__) . '/app/Models/Database.php';
    require_once dirname(__DIR__) . '/app/Services/ElasticsearchService.php';

    // Only allow superadmin to trigger full sync
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Unauthorized access'
        ]);
        exit();
    }

    // [SECURITY] Enforce POST and CSRF validation
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
        exit();
    }
    if (!function_exists('verify_csrf_token')) {
        require_once dirname(__DIR__) . '/app/Helpers/csrf_helper.php';
    }
    if (!verify_csrf_token()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or missing CSRF token']);
        exit();
    }

    $db = new Database();
    $es = ElasticsearchService::getInstance();

    if (!$es->isAvailable()) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Elasticsearch service is unavailable'
        ]);
        exit();
    }

    // Setup indices if missing
    $es->setupIndices();

    // 1. Fetch all active employees from MySQL
    $empRes = $db->query("SELECT e.*, u.full_name as verified_by_name 
                          FROM employees e 
                          LEFT JOIN users u ON e.verified_by = u.id 
                          WHERE e.is_active = 1 AND e.deleted_at IS NULL");

    $empSynced = 0;
    if ($empRes) {
        while ($emp = $empRes->fetch_assoc()) {
            $es->indexEmployee([
                'id' => (int)$emp['id'],
                'employee_code' => $emp['employee_code'] ?? '',
                'full_name' => $emp['full_name'] ?? '',
                'position' => $emp['position'] ?? '',
                'department' => $emp['department'] ?? '',
                'contractor_company' => $emp['contractor_company'] ?? '',
                'competency_type' => $emp['competency_type'] ?? '',
                'competency_name' => $emp['competency_name'] ?? '',
                'verified_by_name' => $emp['verified_by_name'] ?? '',
                'ruang_lingkup' => $emp['ruang_lingkup'] ?? '',
                'sub_competency' => $emp['sub_competency'] ?? '',
                'supervision_area' => $emp['supervision_area'] ?? '',
                'approval_status' => $emp['verification_status'] ?? ($emp['status'] ?? 'pending'),
                'is_active' => isset($emp['is_active']) ? (int)$emp['is_active'] : 1,
                'created_at' => $emp['created_at'] ?? date('Y-m-d H:i:s')
            ]);
            $empSynced++;
        }
    }

    echo json_encode([
        'status' => 'success',
        'message' => "Successfully synced {$empSynced} employees to Bonsai.io Elasticsearch!",
        'employees_synced' => $empSynced
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
