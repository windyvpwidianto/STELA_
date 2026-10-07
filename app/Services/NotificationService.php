<?php
/**
 * Notification System
 * Email notifications only
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__) . '/Models/Database.php';

// Check if autoload file exists before requiring it
$autoload_path = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (file_exists($autoload_path)) {
    require_once $autoload_path;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class NotificationService {
    private $db;
    
    // Email Configuration
    private $smtp_host;
    private $smtp_port;
    private $smtp_username;
    private $smtp_password;
    private $email_from;
    private $email_from_name;

    // Fonnte WhatsApp Configuration
    private $fonnte_token;
    private $fonnte_url   = 'https://api.fonnte.com/send';

    public function __construct() {
        $this->db = new Database();
        $this->loadSettings();
        $this->ensureEmailDeliveryLogTable();
    }
    
    private function loadSettings() {
        $res = $this->db->query("SELECT setting_key, setting_value FROM settings");
        $settings = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        
        $this->smtp_host = getenv('MAIL_HOST') ?: (getenv('SMTP_HOST') ?: (!empty($settings['smtp_host']) ? $settings['smtp_host'] : ''));
        $this->smtp_port = getenv('MAIL_PORT') ?: (getenv('SMTP_PORT') ?: (!empty($settings['smtp_port']) ? $settings['smtp_port'] : 465));
        $this->smtp_username = getenv('MAIL_USERNAME') ?: (getenv('SMTP_USER') ?: (!empty($settings['smtp_user']) ? $settings['smtp_user'] : ''));
        $this->smtp_password = getenv('MAIL_PASSWORD') ?: (getenv('SMTP_PASS') ?: (!empty($settings['smtp_pass']) ? $settings['smtp_pass'] : ''));
        $env_from = getenv('MAIL_FROM_ADDRESS');
        $this->email_from = !empty($env_from) ? $env_from : $this->smtp_username;
        $this->email_from_name = $settings['app_name'] ?? 'Mining Appointment System';
        
        $this->fonnte_token = $settings['fonnte_token'] ?? getenv('FONNTE_TOKEN') ?: '';
    }
    
    /**
     * Send notification when new employee is added by company user
     */
    public function notifyNewEmployeeAdded($employee_id, $company_name) {
        error_log("[NOTIFICATION] notifyNewEmployeeAdded called for employee_id=$employee_id, company=$company_name");
        
        // Get admin users
        $admins = $this->getAdminContacts();
        
        if (empty($admins)) {
            error_log("[NOTIFICATION ERROR] No admin contacts found for notification");
            return false;
        }
        error_log("[NOTIFICATION] Found " . count($admins) . " admin(s)");
        
        // Get employee details
        $employee_id = (int) $employee_id;
        $res = $this->db->query("
            SELECT employee_code, full_name, position, contractor_company
            FROM employees 
            WHERE id = $employee_id
        ");

        if (!$res) {
            error_log("[NOTIFICATION ERROR] Query failed for employee_id: $employee_id");
            return false;
        }

        $employee = $res->fetch_assoc();
        
        if (!$employee) {
            error_log("[NOTIFICATION ERROR] Employee not found: $employee_id");
            return false;
        }
        error_log("[NOTIFICATION] Employee found: {$employee['full_name']} ({$employee['employee_code']})");
        
        $message = $this->buildNewEmployeeMessage($employee, $company_name);
        
        // Send email to all admins
        $sent_count = 0;
        foreach ($admins as $admin) {
            if (!empty($admin['email'])) {
                $subject = "New Employee Needs Verification - {$company_name}";
                $result = $this->sendEmailAndTrack('new_employee', $employee_id, $company_name, $admin['email'], $admin['full_name'], $subject, $message);
                if ($result['sent']) {
                    $sent_count++;
                    error_log("[NOTIFICATION] Email sent to {$admin['email']}");
                } else {
                    error_log("[NOTIFICATION ERROR] Failed to send email to {$admin['email']}");
                }
            }
        }
        error_log("[NOTIFICATION] Total emails sent: $sent_count / " . count($admins));

        // Send WhatsApp notifications to admin
        foreach ($admins as $admin) {
            if (!empty($admin['phone'])) {
                $this->sendWhatsApp($admin['phone'], $admin['full_name'], $message, 'new_employee', $employee_id);
            }
        }

        // Log notification
        $this->logNotification('new_employee', $employee_id, $company_name, $message);
        
        if ($sent_count === 0) {
            error_log("[NOTIFICATION ERROR] No email was sent for new employee notification: $employee_id");
        }

        return $sent_count > 0;
    }
    
    /**
     * Send notification when appointment is rejected by KTT and needs admin review
     */
    public function notifyAppointmentRejectedForReview($appointment_id) {
        error_log("[NOTIFICATION] notifyAppointmentRejectedForReview called for appointment_id=$appointment_id");
        
        // Get admin users
        $admins = $this->getAdminContacts();
        
        if (empty($admins)) {
            error_log("[NOTIFICATION ERROR] No admin contacts found for notification");
            return false;
        }
        error_log("[NOTIFICATION] Found " . count($admins) . " admin(s)");
        
        // Get appointment details
        $appointment_id = (int) $appointment_id;
        $res = $this->db->query("
            SELECT a.id, a.appointment_number,
                   COALESCE(
                       (SELECT ka.approval_notes FROM ktt_approvals ka
                        WHERE ka.appointment_id = a.id AND ka.action = 'reject'
                        ORDER BY ka.approval_date DESC LIMIT 1),
                       a.last_rejection_notes
                   ) as rejection_reason,
                   COALESCE(
                       (SELECT u_rej.full_name FROM ktt_approvals ka
                        JOIN users u_rej ON ka.ktt_user_id = u_rej.id
                        WHERE ka.appointment_id = a.id AND ka.action = 'reject'
                        ORDER BY ka.approval_date DESC LIMIT 1),
                       a.last_rejection_by_name
                   ) as rejected_by_name,
                   e.full_name, e.employee_code, e.contractor_company,
                   p.position_name
            FROM appointments a
            JOIN employees e ON a.employee_id = e.id
            LEFT JOIN positions p ON a.position_id = p.id
            WHERE a.id = ?
        ", [$appointment_id]);

        if (!$res) {
            error_log("[NOTIFICATION ERROR] Query failed for appointment_id: $appointment_id");
            return false;
        }

        $appointment = $res->fetch_assoc();
        
        if (!$appointment) {
            error_log("[NOTIFICATION ERROR] Appointment not found: $appointment_id");
            return false;
        }
        error_log("[NOTIFICATION] Appointment found: {$appointment['appointment_number']} for {$appointment['full_name']}");
        
        $message = $this->buildRejectionReviewMessage($appointment);
        
        // Send email to all admins
        $sent_count = 0;
        foreach ($admins as $admin) {
            if (!empty($admin['email'])) {
                $subject = "Appointment Letter Rejected - Admin Review Required";
                $result = $this->sendEmailAndTrack('appointment_rejected', $appointment_id, $appointment['contractor_company'], $admin['email'], $admin['full_name'], $subject, $message);
                if ($result['sent']) {
                    $sent_count++;
                    error_log("[NOTIFICATION] Email sent to {$admin['email']}");
                } else {
                    error_log("[NOTIFICATION ERROR] Failed to send email to {$admin['email']}");
                }
            }
        }
        error_log("[NOTIFICATION] Total emails sent: $sent_count / " . count($admins));

        // Send WhatsApp notifications to admin
        foreach ($admins as $admin) {
            if (!empty($admin['phone'])) {
                $this->sendWhatsApp($admin['phone'], $admin['full_name'], $message, 'appointment_rejected', $appointment_id);
            }
        }

        // Log notification
        $this->logNotification('appointment_rejected', $appointment_id, $appointment['contractor_company'], $message);
        
        if ($sent_count === 0) {
            error_log("[NOTIFICATION ERROR] No email was sent for appointment rejection notification: $appointment_id");
        }

        return $sent_count > 0;
    }
    
    /**
     * Get admin user contacts (email only)
     */
    public function getAdminContacts() {
        $result = $this->db->query("
            SELECT id, username, full_name, email, phone
            FROM users
            WHERE role = 'admin' AND is_active = 1
        ");

        $admins = [];
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $admins[] = $row;
            }
        }

        return $admins;
    }

    /**
     * Get KTT user contacts
     * @param string|null $ktt_type - 'msm', 'ttn', or null for all KTTs
     */
    private function getKttContacts($ktt_type = null) {
        require_once dirname(__DIR__) . '/Helpers/ktt_helper.php';

        $where_clause = "is_active = 1";
        $ktts = [];

        if ($ktt_type === 'msm' || $ktt_type === 'ttn') {
            // First check if there is an active delegatee for this KTT type
            $delegation = getActiveKttDelegation($ktt_type, $this->db);
            if ($delegation) {
                // Return delegatee contact info
                $ktts[] = [
                    'id' => $delegation['delegate_user_id'],
                    'username' => 'Delegated User',
                    'full_name' => $delegation['delegate_user_name'] . ' (Mewakili KTT)',
                    'email' => $this->db->query("SELECT email FROM users WHERE id = {$delegation['delegate_user_id']}")->fetch_assoc()['email'] ?? null,
                    'phone' => $this->db->query("SELECT phone FROM users WHERE id = {$delegation['delegate_user_id']}")->fetch_assoc()['phone'] ?? null
                ];
                return $ktts;
            }

            // Otherwise, get the native KTT for this type
            $where_clause .= " AND ktt_type = '" . $this->db->escapeString($ktt_type) . "' AND role = 'ktt'";
        } else {
            // If no type specified, get all native KTTs
            $where_clause .= " AND ktt_type IS NOT NULL AND role = 'ktt'";
        }

        $result = $this->db->query("
            SELECT id, username, full_name, email, phone
            FROM users
            WHERE $where_clause
        ");

        $ktts = [];
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $ktts[] = $row;
            }
        }

        return $ktts;
    }

    /**
     * Notification: Notify KTT when admin submits appointment for approval
     * @param int $appointment_id - The appointment ID
     * @param bool $notify_msm - Whether to notify KTT MSM
     * @param bool $notify_ttn - Whether to notify KTT TTN
     */
    public function notifyKttForApproval($appointment_id, $notify_msm = true, $notify_ttn = true) {
        error_log("[NOTIFICATION] notifyKttForApproval called for appointment_id=$appointment_id, MSM=$notify_msm, TTN=$notify_ttn");

        // Get appointment details
        $appointment_id = (int) $appointment_id;
        $res = $this->db->query("
            SELECT a.appointment_number, a.id,
                   e.full_name, e.employee_code, e.contractor_company,
                   p.position_name
            FROM appointments a
            JOIN employees e ON a.employee_id = e.id
            LEFT JOIN positions p ON a.position_id = p.id
            WHERE a.id = ?
        ", [$appointment_id]);

        if (!$res) {
            error_log("[NOTIFICATION ERROR] Query failed for appointment_id: $appointment_id");
            return false;
        }

        $appointment = $res->fetch_assoc();

        if (!$appointment) {
            error_log("[NOTIFICATION ERROR] Appointment not found: $appointment_id");
            return false;
        }

        // Get KTT contacts based on which KTTs need to be notified
        $ktts = [];
        if ($notify_msm) {
            $ktts = array_merge($ktts, $this->getKttContacts('msm'));
        }
        if ($notify_ttn) {
            $ktts = array_merge($ktts, $this->getKttContacts('ttn'));
        }

        if (empty($ktts)) {
            error_log("[NOTIFICATION] No KTT contacts found for notification");
            return false;
        }

        $base_url = defined('SITE_URL') ? SITE_URL : 'http://localhost/windy';
        $message  = "🔔*NEW APPOINTMENT FOR APPROVAL*\n\n";
        $message .= "Admin has submitted an appointment letter that requires your approval:\n\n";
        $message .= "📋 *Letter Details:*\n";
        $message .= "• Letter No.: {$appointment['appointment_number']}\n";
        $message .= "• Employee: {$appointment['full_name']} ({$appointment['employee_code']})\n";
        $message .= "• Position: {$appointment['position_name']}\n";
        $message .= "• Company: {$appointment['contractor_company']}\n\n";
        $message .= "⚠️ Please login to review and approve/reject this appointment.\n";
        $message .= "📍 {$base_url}/approval.php";

        $subject = "New Appointment Letter Requires Approval - {$appointment['appointment_number']}";
        $sent = 0;
        foreach ($ktts as $ktt) {
            if (!empty($ktt['email'])) {
                $result = $this->sendEmailAndTrack('ktt_approval_request', $appointment_id, $appointment['contractor_company'], $ktt['email'], $ktt['full_name'], $subject, $message);
                if ($result['sent']) {
                    $sent++;
                    error_log("[NOTIFICATION] Email sent to KTT: {$ktt['email']}");
                }
            }
        }

        // Send WhatsApp notifications to KTT
        foreach ($ktts as $ktt) {
            if (!empty($ktt['phone'])) {
                $this->sendWhatsApp($ktt['phone'], $ktt['full_name'], $message, 'ktt_approval_request', $appointment_id);
            }
        }

        $this->logNotification('ktt_approval_request', $appointment_id, $appointment['contractor_company'], $message);
        error_log("[NOTIFICATION] notifyKttForApproval: sent $sent email(s)");

        return $sent > 0;
    }

    /**
     * Build message for new employee notification
     */
    private function buildNewEmployeeMessage($employee, $company_name) {
        $message = "🔔 *NEW EMPLOYEE NOTIFICATION*\n\n";
        $message .= "Company *{$company_name}* has added a new employee that requires verification:\n\n";
        $message .= "📋 *Employee Details:*\n";
        $message .= "• ID BADGE: {$employee['employee_code']}\n";
        $message .= "• Name: {$employee['full_name']}\n";
        $message .= "• Position: {$employee['position']}\n";
        $message .= "• Company: {$employee['contractor_company']}\n\n";
        $message .= "⚠️ Please login to the system to perform verification.\n";
        
        // Add system URL
        $base_url = defined('SITE_URL') ? SITE_URL : 'http://localhost/windy';
        $message .= "📍 {$base_url}/employees.php";
        
        return $message;
    }
    
    /**
     * Build message for appointment rejection review
     */
    private function buildRejectionReviewMessage($appointment) {
        $message = "❌ *ASSIGN LETTER REJECTED - DATA CORRECTION REQUIRED*\n\n";
        $message .= "An appointment letter has been rejected by KTT and has been done admin review:\n\n";
        $message .= "📋 *Letter Details:*\n";
        $message .= "• Letter No.: {$appointment['appointment_number']}\n";
        $message .= "• Employee: {$appointment['full_name']} ({$appointment['employee_code']})\n";
        $message .= "• Position: {$appointment['position_name']}\n";
        $message .= "• Company: {$appointment['contractor_company']}\n";
        $message .= "• Rejected by: {$appointment['rejected_by_name']}\n\n";
        $message .= "💬 *Rejection Reason:*\n";
        $message .= (!empty($appointment['rejection_reason']) ? $appointment['rejection_reason'] : 'No rejection reason provided') . "\n\n";
        $message .= "⚠️ Please login to review this rejection.\n";
        
        // Add system URL
        $base_url = defined('SITE_URL') ? SITE_URL : 'http://localhost/windy';
        $message .= "📍 {$base_url}/review_rejection.php";
        
        return $message;
    }
    
    /**
     * Send Email notification using PHPMailer with Gmail SMTP
     */
    private function sendEmail($to_email, $to_name, $subject, $message) {
        $result = $this->sendEmailDetailed($to_email, $to_name, $subject, $message);
        return $result['sent'];
    }

    /**
     * Send Email notification and return detailed status.
     */
    private function sendEmailDetailed($to_email, $to_name, $subject, $message) {
        $recipient_email = trim((string) $to_email);
        $recipient_name = trim((string) $to_name);

        if (empty($recipient_email) || !filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
            error_log("[NOTIFICATION ERROR] Invalid recipient email: $recipient_email");
            return [
                'recipient_email' => $recipient_email,
                'recipient_name' => $recipient_name,
                'is_valid' => false,
                'sent' => false,
                'error' => 'Invalid email address'
            ];
        }

        // Check if PHPMailer class exists
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            error_log("[NOTIFICATION ERROR] PHPMailer class not found. Please install PHPMailer via Composer.");
            error_log("[NOTIFICATION ERROR] Run: composer require phpmailer/phpmailer");
            error_log("[NOTIFICATION INFO] Email notification skipped for: $to_email");
            return [
                'recipient_email' => $recipient_email,
                'recipient_name' => $recipient_name,
                'is_valid' => true,
                'sent' => false,
                'error' => 'PHPMailer class not found'
            ];
        }
        
        try {
            $mail = new PHPMailer(true);
            
            // SMTP Configuration
            $mail->isSMTP();
            $mail->Host       = $this->smtp_host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->smtp_username;
            $mail->Password   = $this->smtp_password;
            if ($this->smtp_port == 465) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
            $mail->Port       = $this->smtp_port;
            $mail->CharSet    = 'UTF-8';
            
            // Set timeouts to prevent hanging
            $mail->Timeout    = 5; // Connection timeout (seconds)
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false
                )
            );
            
            // Recipients
            $mail->setFrom($this->email_from, $this->email_from_name);
            $mail->addAddress($recipient_email, $recipient_name);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            
            // Convert message to HTML format
            $html_message = nl2br(htmlspecialchars($message));
            // Replace *text* with <strong>text</strong> properly
            $html_message = preg_replace('/\*([^*]+)\*/U', '<strong>$1</strong>', $html_message);
            
            $safe_to_name = htmlspecialchars($to_name);
            $html_body = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; }
                    .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-top: none; }
                    .footer { background: #f0f0f0; padding: 15px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 8px 8px; }
                    .button { display: inline-block; padding: 12px 24px; background: #667eea; color: white; text-decoration: none; border-radius: 6px; margin-top: 15px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2 style='margin: 0;'>Mining Appointment System</h2>
                    </div>
                    <div class='content'>
                        <p>Hello <strong>{$safe_to_name}</strong>,</p>
                        <p>{$html_message}</p>
                    </div>
                    <div class='footer'>
                        <p>This email was sent automatically. Please do not reply.</p>
                        <p>&copy; 2026 Mining Appointment System. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $mail->Body = $html_body;
            $mail->AltBody = strip_tags($message);
            
            $mail->send();
            error_log("[NOTIFICATION SUCCESS] Email sent to $recipient_email: $subject");
            return [
                'recipient_email' => $recipient_email,
                'recipient_name' => $recipient_name,
                'is_valid' => true,
                'sent' => true,
                'error' => null
            ];
            
        } catch (Exception $e) {
            error_log("[NOTIFICATION ERROR] Failed to send email to $recipient_email");
            $mail_error = isset($mail) ? $mail->ErrorInfo : '';
            error_log("[NOTIFICATION ERROR] PHPMailer Error: $mail_error");
            error_log("[NOTIFICATION ERROR] Exception: " . $e->getMessage());
            return [
                'recipient_email' => $recipient_email,
                'recipient_name' => $recipient_name,
                'is_valid' => true,
                'sent' => false,
                'error' => !empty($mail_error) ? $mail_error : $e->getMessage()
            ];
        } catch (Error $e) {
            error_log("[NOTIFICATION ERROR] PHP Error while sending email: " . $e->getMessage());
            error_log("[NOTIFICATION ERROR] This usually means PHPMailer is not properly installed.");
            return [
                'recipient_email' => $recipient_email,
                'recipient_name' => $recipient_name,
                'is_valid' => true,
                'sent' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send email and persist delivery status.
     */
    public function sendEmailAndTrack($notification_type, $reference_id, $company_name, $to_email, $to_name, $subject, $message) {
        $result = $this->sendEmailDetailed($to_email, $to_name, $subject, $message);
        $this->logEmailDelivery(
            $notification_type,
            $reference_id,
            $company_name,
            $result['recipient_email'],
            $result['recipient_name'],
            $subject,
            $result['is_valid'],
            $result['sent'],
            $result['error']
        );
        return $result;
    }
    
    /**
     * Notification 1: Notify admin when BOTH KTTs have approved (final KTT approval)
     */
    public function notifyKttBothApprovedToAdmin($appointment_id) {
        error_log("[NOTIFICATION] notifyKttBothApprovedToAdmin called for appointment_id=$appointment_id");

        $admins = $this->getAdminContacts();
        if (empty($admins)) {
            error_log("[NOTIFICATION ERROR] No admin contacts found");
            return false;
        }

        $appointment_id = (int) $appointment_id;
        $res = $this->db->query("
            SELECT a.appointment_number, e.full_name, e.employee_code, e.contractor_company,
                   p.position_name,
                   u1.full_name as ktt1_name, u2.full_name as ktt2_name
            FROM appointments a
            JOIN employees e ON a.employee_id = e.id
            LEFT JOIN positions p ON a.position_id = p.id
            LEFT JOIN users u1 ON a.ktt1_approved_by = u1.id
            LEFT JOIN users u2 ON a.ktt2_approved_by = u2.id
            WHERE a.id = ?
        ", [$appointment_id]);

        if (!$res) {
            error_log("[NOTIFICATION ERROR] Query failed for appointment_id: $appointment_id");
            return false;
        }

        $appointment = $res->fetch_assoc();

        if (!$appointment) {
            error_log("[NOTIFICATION ERROR] Appointment not found: $appointment_id");
            return false;
        }

        $base_url = defined('SITE_URL') ? SITE_URL : 'http://localhost/windy';
        $message  = "🔔 *KTT FINAL APPROVAL NOTIFICATION*\n\n";
        $message .= "Both KTTs have approved the following assign letter:\n\n";
        $message .= "📋 *Letter Details:*\n";
        $message .= "• Letter No.: {$appointment['appointment_number']}\n";
        $message .= "• Employee: {$appointment['full_name']} ({$appointment['employee_code']})\n";
        $message .= "• Position: {$appointment['position_name']}\n";
        $message .= "• Company: {$appointment['contractor_company']}\n";
        $message .= "• KTT MSM: {$appointment['ktt1_name']}\n";
        $message .= "• KTT TTN: {$appointment['ktt2_name']}\n\n";
        $message .= "ℹ️ The assign letter is now fully approved by both KTTs.\n";
        $message .= "📍 {$base_url}/appointments.php";

        $subject = "Both KTTs Approved - {$appointment['appointment_number']}";
        $sent = 0;
        foreach ($admins as $admin) {
            if (!empty($admin['email'])) {
                $result = $this->sendEmailAndTrack('ktt_both_approved_admin', $appointment_id, $appointment['contractor_company'], $admin['email'], $admin['full_name'], $subject, $message);
                if ($result['sent']) {
                    $sent++;
                }
            }
        }

        $this->logNotification('ktt_both_approved_admin', $appointment_id, $appointment['contractor_company'], $message);
        error_log("[NOTIFICATION] notifyKttBothApprovedToAdmin: sent $sent email(s)");

        // Send WhatsApp notifications to admin
        foreach ($admins as $admin) {
            if (!empty($admin['phone'])) {
                $this->sendWhatsApp($admin['phone'], $admin['full_name'], $message, 'ktt_both_approved_admin', $appointment_id);
            }
        }

        return $sent > 0;
    }

    /**
     * Notification 2: Notify User/Dept when admin verifies (accepts) employee
     */
    public function notifyAdminAcceptedEmployee($employee_id, $reviewer_name = '') {
        error_log("[NOTIFICATION] notifyAdminAcceptedEmployee called for employee_id=$employee_id");

        $employee_id = (int) $employee_id;
        $res = $this->db->query("
            SELECT e.full_name, e.employee_code, e.contractor_company, e.department,
                   u.full_name as reviewer_full_name
            FROM employees e
            LEFT JOIN users u ON e.verified_by = u.id
            WHERE e.id = $employee_id
        ");

        if (!$res) {
            error_log("[NOTIFICATION ERROR] Query failed for employee_id: $employee_id");
            return false;
        }

        $employee = $res->fetch_assoc();

        if (!$employee) {
            error_log("[NOTIFICATION ERROR] Employee not found: $employee_id");
            return false;
        }

        // Use reviewer_name from parameter if provided, otherwise fetch from DB
        if (empty($reviewer_name)) {
            $reviewer_name = !empty($employee['reviewer_full_name']) ? $employee['reviewer_full_name'] : 'Admin';
        }

        $contacts = $this->getUserDeptContacts($employee['contractor_company'], $employee['department'] ?? null);
        if (empty($contacts)) {
            error_log("[NOTIFICATION] No user/dept contacts for employee $employee_id");
            return false;
        }

        $base_url = defined('SITE_URL') ? SITE_URL : 'http://localhost/windy';
        $message  = "✅ *EMPLOYEE VERIFICATION SUCCESSFUL*\n\n";
        $message .= "The following employee data has been *successfully verified by Admin - {$reviewer_name}* and is now awaiting KTT approval:\n\n";
        $message .= "📋 *Employee Details:*\n";
        $message .= "• ID BADGE: {$employee['employee_code']}\n";
        $message .= "• Name: {$employee['full_name']}\n";
        $message .= "• Company: {$employee['contractor_company']}\n\n";
        $message .= "The assign letter has been created and is currently pending KTT approval.\n";
        $message .= "📍 {$base_url}/employees.php";

        $subject = "Employee Verification Successful - {$employee['full_name']}";
        $sent = 0;
        foreach ($contacts as $contact) {
            if (!empty($contact['email'])) {
                $result = $this->sendEmailAndTrack('admin_accepted_employee', $employee_id, $employee['contractor_company'], $contact['email'], $contact['full_name'], $subject, $message);
                if ($result['sent']) {
                    $sent++;
                }
            }
        }

        $this->logNotification('admin_accepted_employee', $employee_id, $employee['contractor_company'], $message);
        error_log("[NOTIFICATION] notifyAdminAcceptedEmployee: sent $sent email(s)");

        // Send WhatsApp notifications to user/dept
        foreach ($contacts as $contact) {
            if (!empty($contact['phone'])) {
                $this->sendWhatsApp($contact['phone'], $contact['full_name'], $message, 'admin_accepted_employee', $employee_id);
            }
        }

        return $sent > 0;
    }

    /**
     * Notification 3: Notify User/Dept when admin rejects employee
     */
    public function notifyAdminRejectedEmployee($employee_id, $rejection_notes = '') {
        error_log("[NOTIFICATION] notifyAdminRejectedEmployee called for employee_id=$employee_id");

        $employee_id = (int) $employee_id;
        $res = $this->db->query("
            SELECT full_name, employee_code, contractor_company, department
            FROM employees WHERE id = $employee_id
        ");

        if (!$res) {
            error_log("[NOTIFICATION ERROR] Query failed for employee_id: $employee_id");
            return false;
        }

        $employee = $res->fetch_assoc();

        if (!$employee) {
            error_log("[NOTIFICATION ERROR] Employee not found: $employee_id");
            return false;
        }

        $contacts = $this->getUserDeptContacts($employee['contractor_company'], $employee['department'] ?? null);
        if (empty($contacts)) {
            error_log("[NOTIFICATION] No user/dept contacts for employee $employee_id");
            return false;
        }

        $base_url = defined('SITE_URL') ? SITE_URL : 'http://localhost/windy';
        $message  = "❌ *EMPLOYEE DATA REJECTED*\n\n";
        $message .= "The following employee data has been *rejected* by Admin:\n\n";
        $message .= "📋 *Employee Details:*\n";
        $message .= "• ID BADGE: {$employee['employee_code']}\n";
        $message .= "• Name: {$employee['full_name']}\n";
        $message .= "• Company: {$employee['contractor_company']}\n\n";
        if (!empty($rejection_notes)) {
            $message .= "💬 *Rejection Reason:*\n{$rejection_notes}\n\n";
        }
        $message .= "⚠️ Please login to correct and resubmit the employee data.\n";
        $message .= "📍 {$base_url}/employees.php";

        $subject = "Employee Data Rejected - {$employee['full_name']}";
        $sent = 0;
        foreach ($contacts as $contact) {
            if (!empty($contact['email'])) {
                $result = $this->sendEmailAndTrack('admin_rejected_employee', $employee_id, $employee['contractor_company'], $contact['email'], $contact['full_name'], $subject, $message);
                if ($result['sent']) {
                    $sent++;
                }
            }
        }

        $this->logNotification('admin_rejected_employee', $employee_id, $employee['contractor_company'], $message);
        error_log("[NOTIFICATION] notifyAdminRejectedEmployee: sent $sent email(s)");

        // Send WhatsApp notifications to user/dept
        foreach ($contacts as $contact) {
            if (!empty($contact['phone'])) {
                $this->sendWhatsApp($contact['phone'], $contact['full_name'], $message, 'admin_rejected_employee', $employee_id);
            }
        }

        return $sent > 0;
    }

    /**
     * Notification 4: Notify User/Dept when KTT final approval (both KTTs approved)
     */
    public function notifyKttApprovedFinalToUserDept($appointment_id) {
        error_log("[NOTIFICATION] notifyKttApprovedFinalToUserDept called for appointment_id=$appointment_id");

        $appointment_id = (int) $appointment_id;
        $res = $this->db->query("
            SELECT a.appointment_number, a.employee_id,
                   e.full_name, e.employee_code, e.contractor_company, e.department,
                   p.position_name
            FROM appointments a
            JOIN employees e ON a.employee_id = e.id
            LEFT JOIN positions p ON a.position_id = p.id
            WHERE a.id = ?
        ", [$appointment_id]);

        if (!$res) {
            error_log("[NOTIFICATION ERROR] Query failed for appointment_id: $appointment_id");
            return false;
        }

        $appointment = $res->fetch_assoc();

        if (!$appointment) {
            error_log("[NOTIFICATION ERROR] Appointment not found: $appointment_id");
            return false;
        }

        $contacts = $this->getUserDeptContacts($appointment['contractor_company'], $appointment['department'] ?? null);
        if (empty($contacts)) {
            error_log("[NOTIFICATION] No user/dept contacts for appointment $appointment_id");
            return false;
        }

        $base_url = defined('SITE_URL') ? SITE_URL : 'http://localhost/windy';
        $message  = "🎉 *ASSIGN LETTER SUCCESSFULLY APPROVED*\n\n";
        $message .= "The assign letter for the following employee has been *successfully approved* by KTT:\n\n";
        $message .= "📋 *Letter Details:*\n";
        $message .= "• Letter No.: {$appointment['appointment_number']}\n";
        $message .= "• Employee: {$appointment['full_name']} ({$appointment['employee_code']})\n";
        $message .= "• Position: {$appointment['position_name']}\n";
        $message .= "• Company: {$appointment['contractor_company']}\n\n";
        $message .= "✅ The assign letter is now active and fully approved.\n";
        $message .= "📍 {$base_url}/appointments.php";

        $subject = "Assign Letter Successfully Approved - {$appointment['full_name']}";
        $sent = 0;
        foreach ($contacts as $contact) {
            if (!empty($contact['email'])) {
                $result = $this->sendEmailAndTrack('ktt_approved_final_user_dept', $appointment_id, $appointment['contractor_company'], $contact['email'], $contact['full_name'], $subject, $message);
                if ($result['sent']) {
                    $sent++;
                }
            }
        }

        $this->logNotification('ktt_approved_final_user_dept', $appointment_id, $appointment['contractor_company'], $message);
        error_log("[NOTIFICATION] notifyKttApprovedFinalToUserDept: sent $sent email(s)");

        // Send WhatsApp notifications to user/dept
        foreach ($contacts as $contact) {
            if (!empty($contact['phone'])) {
                $this->sendWhatsApp($contact['phone'], $contact['full_name'], $message, 'ktt_approved_final_user_dept', $appointment_id);
            }
        }

        return $sent > 0;
    }

    /**
     * Notification 5: Notify User/Dept when Admin sends back to user after KTT rejection
     */
    public function notifyAdminFinalRejectionToUserDept($appointment_id, $admin_notes = '') {
        error_log("[NOTIFICATION] notifyAdminFinalRejectionToUserDept called for appointment_id=$appointment_id");

        $appointment_id = (int) $appointment_id;
        $res = $this->db->query("
            SELECT a.appointment_number,
                   e.full_name, e.employee_code, e.contractor_company, e.department,
                   p.position_name,
                   COALESCE(
                       (SELECT approval_notes FROM ktt_approvals WHERE appointment_id = a.id AND action = 'reject' ORDER BY approval_date DESC LIMIT 1),
                       a.last_rejection_notes
                   ) as ktt_rejection_notes,
                   COALESCE(
                       (SELECT u.full_name FROM ktt_approvals ka JOIN users u ON ka.ktt_user_id = u.id WHERE ka.appointment_id = a.id AND ka.action = 'reject' ORDER BY ka.approval_date DESC LIMIT 1),
                       a.last_rejection_by_name
                   ) as ktt_rejector_name
            FROM appointments a
            JOIN employees e ON a.employee_id = e.id
            LEFT JOIN positions p ON a.position_id = p.id
            WHERE a.id = ?
        ", [$appointment_id]);

        if (!$res) {
            error_log("[NOTIFICATION ERROR] Query failed for appointment_id: $appointment_id");
            return false;
        }

        $appointment = $res->fetch_assoc();

        if (!$appointment) {
            error_log("[NOTIFICATION ERROR] Appointment not found: $appointment_id");
            return false;
        }

        $contacts = $this->getUserDeptContacts($appointment['contractor_company'], $appointment['department'] ?? null);
        if (empty($contacts)) {
            error_log("[NOTIFICATION] No user/dept contacts for appointment $appointment_id");
            return false;
        }

        $base_url = defined('SITE_URL') ? SITE_URL : 'http://localhost/windy';
        $message  = "❌ *ASSIGN LETTER REJECTED - DATA CORRECTION REQUIRED*\n\n";
        $message .= "An appointment letter has been rejected by KTT and has been done admin review:\n\n";
        $message .= "📋 *Letter Details:*\n";
        $message .= "• Letter No.: {$appointment['appointment_number']}\n";
        $message .= "• Employee: {$appointment['full_name']} ({$appointment['employee_code']})\n";
        $message .= "• Position: {$appointment['position_name']}\n";
        $message .= "• Company: {$appointment['contractor_company']}\n";
        
        $rejector = !empty($appointment['ktt_rejector_name']) ? $appointment['ktt_rejector_name'] : 'Unknown';
        $message .= "• Rejected by: {$rejector}\n\n";
        
        $message .= "💬 *Rejection Reason:*\n";
        $reason = !empty($appointment['ktt_rejection_notes']) ? $appointment['ktt_rejection_notes'] : 'No rejection reason provided';
        $message .= $reason . "\n\n";


        $message .= "⚠️ Please login to update the employee data and resubmit.\n";
        $message .= "📍 {$base_url}/employees.php";

        $subject = "Assign Letter Rejected - Please Correct Data for {$appointment['full_name']}";
        $sent = 0;
        foreach ($contacts as $contact) {
            if (!empty($contact['email'])) {
                $result = $this->sendEmailAndTrack('admin_final_rejection_user_dept', $appointment_id, $appointment['contractor_company'], $contact['email'], $contact['full_name'], $subject, $message);
                if ($result['sent']) {
                    $sent++;
                }
            }
        }

        $this->logNotification('admin_final_rejection_user_dept', $appointment_id, $appointment['contractor_company'], $message);
        error_log("[NOTIFICATION] notifyAdminFinalRejectionToUserDept: sent $sent email(s)");

        // Send WhatsApp notifications to user/dept
        foreach ($contacts as $contact) {
            if (!empty($contact['phone'])) {
                $this->sendWhatsApp($contact['phone'], $contact['full_name'], $message, 'admin_final_rejection_user_dept', $appointment_id);
            }
        }

        return $sent > 0;
    }

    /**
     * Helper: Get user/dept contacts related to an employee's company or department
     */
    public function getUserDeptContacts($contractor_company, $department = null) {
        $contacts = [];

        // Get company users (role = 'user') matching by company_name
        if (!empty($contractor_company)) {
            $company_escaped = $this->db->escapeString($contractor_company);
            $result = $this->db->query("
                SELECT id, full_name, email, phone FROM users
                WHERE role = 'user' AND is_active = 1
                AND company_name = '$company_escaped'
            ");
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $contacts[] = $row;
                }
            }
        }

        // Get department users (role = 'department_user') matching by department
        if (!empty($department)) {
            $dept_escaped = $this->db->escapeString($department);
            $result = $this->db->query("
                SELECT id, full_name, email, phone FROM users
                WHERE role = 'department_user' AND is_active = 1
                AND department = '$dept_escaped'
            ");
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $contacts[] = $row;
                }
            }
        }

        return $contacts;
    }

    /**
     * Ensure email delivery log table exists.
     */
    private function ensureEmailDeliveryLogTable() {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS notification_email_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                notification_type VARCHAR(50) NOT NULL,
                reference_id INT DEFAULT NULL,
                company_name VARCHAR(255) DEFAULT NULL,
                recipient_name VARCHAR(255) DEFAULT NULL,
                recipient_email VARCHAR(255) NOT NULL,
                subject VARCHAR(255) DEFAULT NULL,
                email_is_valid TINYINT(1) NOT NULL DEFAULT 0,
                email_sent TINYINT(1) NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_type (notification_type),
                INDEX idx_reference (reference_id),
                INDEX idx_recipient_email (recipient_email)
            )
        ");
    }

    /**
     * Log per-recipient email delivery status.
     */
    private function logEmailDelivery($type, $reference_id, $company_name, $recipient_email, $recipient_name, $subject, $email_is_valid, $email_sent, $error_message = null) {
        $type_escaped = $this->db->escapeString($type);
        $company_escaped = $this->db->escapeString((string) $company_name);
        $recipient_email_escaped = $this->db->escapeString((string) $recipient_email);
        $recipient_name_escaped = $this->db->escapeString((string) $recipient_name);
        $subject_escaped = $this->db->escapeString((string) $subject);
        $error_clean = '';
        if ($error_message !== null) {
            $error_clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\xFF]/', '', (string)$error_message);
        }
        $error_escaped = $error_clean !== '' ? $this->db->escapeString($error_clean) : '';
        $error_sql = $error_clean !== '' ? "'$error_escaped'" : 'NULL';

        $this->db->query("
            INSERT INTO notification_email_logs (
                notification_type, reference_id, company_name, recipient_name, recipient_email,
                subject, email_is_valid, email_sent, error_message
            ) VALUES (
                '$type_escaped', " . (int) $reference_id . ", '$company_escaped', '$recipient_name_escaped', '$recipient_email_escaped',
                '$subject_escaped', " . ($email_is_valid ? 1 : 0) . ", " . ($email_sent ? 1 : 0) . ", $error_sql
            )
        ");
    }

    /**
     * Log notification to database
     */
    private function logNotification($type, $reference_id, $company_name, $message) {
        // Create notifications log table if not exists
        $this->db->query("
            CREATE TABLE IF NOT EXISTS notification_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                notification_type VARCHAR(50),
                reference_id INT,
                company_name VARCHAR(255),
                message TEXT,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_type (notification_type),
                INDEX idx_reference (reference_id)
            )
        ");
        
        $type_escaped = $this->db->escapeString($type);
        $company_escaped = $this->db->escapeString($company_name);
        $message_escaped = $this->db->escapeString($message);
        
        $this->db->query("
            INSERT INTO notification_logs (notification_type, reference_id, company_name, message)
            VALUES ('$type_escaped', $reference_id, '$company_escaped', '$message_escaped')
        ");
    }

    // =========================================================
    // WhatsApp (Fonnte) â€“ Direct Send Methods
    // =========================================================

    /**
     * Send a WhatsApp message directly via Fonnte API.
     * Delay (30â€“60 seconds) and typing simulation are handled by Fonnte.
     *
     * @param string $phone             Recipient number (any format, normalized to 628xxx)
     * @param string $recipient_name    Recipient name (for logging)
     * @param string $message           Message content (plain text / *bold* WA format)
     * @param string $notification_type Notification type (for logging)
     * @param int    $reference_id      Reference ID (employee/appointment)
     */
    public function sendWhatsApp($phone, $recipient_name, $message, $notification_type, $reference_id) {
        // Strip non-digit characters
        $phone = preg_replace('/\D/', '', $phone);
        if (empty($phone)) {
            error_log("[WA] Skip: empty phone number for $recipient_name");
            return false;
        }

        // Ensure number starts with Indonesian country code 62
        if (substr($phone, 0, 2) !== '62') {
            if (substr($phone, 0, 1) === '0') {
                $phone = '62' . substr($phone, 1);
            } else {
                $phone = '62' . $phone;
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->fonnte_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'target'      => $phone,
                'message'     => $message,
                'delay'       => '30-60',  // Fonnte random delay 30â€“60 seconds between messages
                'typing'      => true,     // Simulate typing indicator
                'countryCode' => '62',
            ],
            CURLOPT_HTTPHEADER     => ['Authorization: ' . $this->fonnte_token],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $response  = curl_exec($ch);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        $data    = json_decode($response, true);
        $success = isset($data['status']) && $data['status'] === true;

        if ($success) {
            error_log("[WA] Sent to $phone ($recipient_name) type=$notification_type ref=$reference_id");
        } else {
            $reason = $curl_err ?: ($data['reason'] ?? $response ?? 'unknown');
            error_log("[WA ERROR] Failed to send to $phone ($recipient_name): $reason");
        }

        return $success;
    }
}
?>


