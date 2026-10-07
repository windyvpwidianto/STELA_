<?php
require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once dirname(__DIR__) . '/app/Helpers/auth_helper.php';
// Included via bootstrap/app.php

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID tidak ditemukan']);
    exit;
}

$id = intval($_GET['id']);
$db = new Database();

// Debug logging
error_log("=== GET APPOINTMENT DETAILS DEBUG ===");
error_log("Appointment ID: " . $id);

// Get appointment details with KTT rejection info and resubmit data
$appointment = $db->query("
    SELECT a.*,
           e.full_name as employee_name, e.employee_code, e.id_number, e.department,
           e.position as employee_position, e.position,
           e.signature_file, e.contractor_company, e.verification_status,
           e.cv_file, e.statement_file, COALESCE(a.resubmit_count, 0) as resubmit_count,
           CASE
               WHEN e.competency_name IS NOT NULL AND e.competency_name != '' THEN e.competency_name
               ELSE (SELECT competency_name FROM competencies WHERE deleted_at IS NULL AND position_type = e.competency_type LIMIT 1)
           END as competency_name,
           e.competency_type,
           e.ruang_lingkup,
           p.position_name as appointment_position_name, p.position_name, p.position_type,
           ktt1.full_name as ktt1_approved_by_name,
           ktt2.full_name as ktt2_approved_by_name,
           (SELECT ka.approval_notes FROM ktt_approvals ka
            WHERE ka.appointment_id = a.id AND ka.action = 'reject' AND ka.ktt_user_id = a.ktt1_approved_by
            LIMIT 1) as ktt1_rejection_notes,
           (SELECT ka.approval_date FROM ktt_approvals ka
            WHERE ka.appointment_id = a.id AND ka.action = 'reject' AND ka.ktt_user_id = a.ktt1_approved_by
            LIMIT 1) as ktt1_rejection_date,
           (SELECT ka.approval_notes FROM ktt_approvals ka
            WHERE ka.appointment_id = a.id AND ka.action = 'reject' AND ka.ktt_user_id = a.ktt2_approved_by
            LIMIT 1) as ktt2_rejection_notes,
           (SELECT ka.approval_date FROM ktt_approvals ka
            WHERE ka.appointment_id = a.id AND ka.action = 'reject' AND ka.ktt_user_id = a.ktt2_approved_by
            LIMIT 1) as ktt2_rejection_date,
           COALESCE(
               (SELECT ka_prev.approval_notes FROM ktt_approvals ka_prev
                WHERE ka_prev.appointment_id = a.id AND ka_prev.action = 'reject'
                ORDER BY ka_prev.approval_date DESC LIMIT 1),
               a.last_rejection_notes
           ) as previous_ktt_rejection_notes,
           COALESCE(
               (SELECT u_prev.full_name FROM ktt_approvals ka_prev
                JOIN users u_prev ON ka_prev.ktt_user_id = u_prev.id
                WHERE ka_prev.appointment_id = a.id AND ka_prev.action = 'reject'
                ORDER BY ka_prev.approval_date DESC LIMIT 1),
               a.last_rejection_by_name
           ) as previous_ktt_rejector_name
    FROM appointments a
    JOIN employees e ON a.employee_id = e.id
    LEFT JOIN positions p ON a.position_id = p.id
    LEFT JOIN users ktt1 ON a.ktt1_approved_by = ktt1.id
    LEFT JOIN users ktt2 ON a.ktt2_approved_by = ktt2.id
    WHERE a.id = ?
", [$id], "i")->fetch_assoc();

// Debug logging - Log rejection data
error_log("Previous KTT Rejection Notes: " . ($appointment['previous_ktt_rejection_notes'] ?? 'NULL'));
error_log("Previous KTT Rejector Name: " . ($appointment['previous_ktt_rejector_name'] ?? 'NULL'));
error_log("Resubmit Count: " . ($appointment['resubmit_count'] ?? '0'));
error_log("Last Rejection Notes (from table): " . ($appointment['last_rejection_notes'] ?? 'NULL'));
error_log("Last Rejection By Name (from table): " . ($appointment['last_rejection_by_name'] ?? 'NULL'));
error_log("Admin Approval Notes: " . ($appointment['admin_approval_notes'] ?? 'NULL'));

if (!$appointment) {
    echo json_encode(['success' => false, 'message' => 'Data not found']);
    exit;
}

// [SECURITY] IDOR Protection
$user_role = $_SESSION['role'] ?? '';
$user_company = $_SESSION['company_name'] ?? '';
$user_dept = $_SESSION['department'] ?? '';

if (!hasPermission('ktt.access') && $user_role !== 'admin' && $user_role !== 'superadmin') {
    if ($user_role === 'user' && $appointment['contractor_company'] !== $user_company) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden: You do not have access to this appointment.']);
        exit;
    }
    if (($user_role === 'department_user' || $user_role === 'dept') && $appointment['department'] !== $user_dept) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden: You do not have access to this appointment.']);
        exit;
    }
}

// Get certifications
$certifications = $db->query("
    SELECT ec.*, c.cert_name
    FROM employee_certifications ec
    JOIN certifications c ON ec.certification_id = c.id
    WHERE ec.employee_id = ?
    ORDER BY ec.created_at DESC
", [$appointment['employee_id']], "i")->fetch_all(MYSQLI_ASSOC);

// Build full URLs for file paths
// Build public URLs using upload_url() helper
if ($appointment['cv_file']) {
    $appointment['cv_file'] = upload_url($appointment['cv_file']);
}
if ($appointment['signature_file']) {
    $appointment['signature_file'] = upload_url($appointment['signature_file']);
}
if ($appointment['statement_file']) {
    $appointment['statement_file'] = upload_url($appointment['statement_file']);
}
if (is_array($certifications)) {
    foreach ($certifications as &$cert) {
        if ($cert['document_file']) {
            $cert['document_file'] = upload_url($cert['document_file']);
        }
    }
}

// Get workflow history HTML
require_once dirname(__DIR__) . '/app/Services/AuditService.php';
$auditService = new AuditService();
$workflow_history = $auditService->getHistoryByAppointment($id);

ob_start();
include dirname(__DIR__) . '/resources/components/workflow_timeline.php';
$workflow_html = ob_get_clean();

echo json_encode([
    'success' => true,
    'appointment' => $appointment,
    'employee' => [
        'full_name' => $appointment['employee_name'],
        'employee_code' => $appointment['employee_code'],
        'id_number' => $appointment['id_number'],
        'position' => $appointment['employee_position'] ?? $appointment['position'],
        'department' => $appointment['department'] ?? '',
        'contractor_company' => $appointment['contractor_company'],
        'verification_status' => $appointment['verification_status'],
        'cv_file' => $appointment['cv_file'],
        'statement_file' => $appointment['statement_file'],
        'competency_name' => $appointment['competency_name'],
        'competency_type' => $appointment['competency_type'],
        'ruang_lingkup' => $appointment['ruang_lingkup'],
        'resubmit_count' => $appointment['resubmit_count']
    ],
    'position' => [
        'position_name' => $appointment['appointment_position_name'] ?? $appointment['position_name'],
        'position_type' => $appointment['position_type']
    ],
    'certifications' => $certifications,
    'workflow_html' => $workflow_html
]);
