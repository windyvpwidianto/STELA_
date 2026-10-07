<?php
require_once dirname(__DIR__) . '/bootstrap/app.php';
// Included via bootstrap/app.php

if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// Check if employee_id is provided
if (!isset($_GET['employee_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'employee_id parameter is required']);
    exit();
}

$employee_id = intval($_GET['employee_id']);
$db = new Database();

// [SECURITY] IDOR Protection: Verifikasi apakah user berhak melihat data employee ini
// Include auth_helper if not already included
if (!function_exists('isSuperadmin')) {
    require_once dirname(__DIR__) . '/app/Helpers/auth_helper.php';
}

if (!isSuperadmin() && !isAdmin() && !isKTT()) {
    $emp_check = $db->query("SELECT contractor_company, department FROM employees WHERE id = ?", [$employee_id], "i");
    if ($emp_check && $emp_check->num_rows > 0) {
        $emp_data = $emp_check->fetch_assoc();
        $user_role = $_SESSION['role'] ?? '';
        $user_company = $_SESSION['company_name'] ?? '';
        $user_dept = $_SESSION['department'] ?? '';
        
        if ($user_role === 'user' && $emp_data['contractor_company'] !== $user_company) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden: You do not have access to this employee.']);
            exit;
        }
        if (($user_role === 'department_user' || $user_role === 'dept') && $emp_data['department'] !== $user_dept) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden: You do not have access to this employee.']);
            exit;
        }
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Employee not found.']);
        exit;
    }
}

// Get the earliest expiry date from verified certificates
$result = $db->query("
    SELECT MIN(expiry_date) as earliest_expiry 
    FROM employee_certifications 
    WHERE employee_id = ? 
    AND verification_status = 'verified'
    AND expiry_date IS NOT NULL
", [$employee_id], "i");

if ($result) {
    $data = $result->fetch_assoc();
    echo json_encode($data);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Database query failed']);
}
?>

