<?php
/**
 * Expiry Reminder Service
 * 
 * Automatically scans certificates and appointments approaching expiration,
 * sends notifications via Email & WhatsApp, logs reminders to prevent duplicate spam,
 * and updates expired status.
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__) . '/Models/Database.php';
require_once dirname(__DIR__) . '/Services/NotificationService.php';

class ExpiryReminderService {
    private $db;
    private $notificationService;
    
    // Notification threshold intervals (in days before expiry)
    private $certThresholds = [90, 60, 30, 7, 0];
    private $appointmentThresholds = [90, 30, 0];

    public function __construct() {
        $this->db = new Database();
        $this->notificationService = new NotificationService();
        $this->ensureTables();
    }

    /**
     * Ensure tracking table exists to prevent duplicate reminder spam
     */
    private function ensureTables() {
        $sql = "CREATE TABLE IF NOT EXISTS expiry_reminder_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reminder_type ENUM('certification', 'appointment') NOT NULL,
            reference_id INT NOT NULL,
            recipient_email VARCHAR(255) NULL,
            recipient_phone VARCHAR(50) NULL,
            days_threshold INT NOT NULL,
            sent_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lookup (reminder_type, reference_id, days_threshold),
            INDEX idx_sent_date (sent_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        
        $this->db->query($sql);
    }

    /**
     * Run full automated reminder cycle
     * 
     * @return array Summary of processed reminders
     */
    public function runDailyCheck() {
        $results = [
            'timestamp' => date('Y-m-d H:i:s'),
            'cert_reminders_sent' => 0,
            'cert_auto_expired' => 0,
            'appt_reminders_sent' => 0,
            'appt_auto_expired' => 0,
            'details' => []
        ];

        // 1. Process Certificate Expirations
        $certRes = $this->processCertificateReminders();
        $results['cert_reminders_sent'] = $certRes['sent'];
        $results['cert_auto_expired'] = $certRes['expired_count'];
        $results['details']['certificates'] = $certRes['details'];

        // 2. Process Appointment Expirations
        $apptRes = $this->processAppointmentReminders();
        $results['appt_reminders_sent'] = $apptRes['sent'];
        $results['appt_auto_expired'] = $apptRes['expired_count'];
        $results['details']['appointments'] = $apptRes['details'];

        return $results;
    }

    /**
     * Check employee certifications approaching expiration or expired
     */
    public function processCertificateReminders() {
        $sentCount = 0;
        $expiredCount = 0;
        $details = [];

        // Fetch active/verified certificates with a valid expiry date
        $query = "SELECT ec.id, ec.cert_number, ec.expiry_date, ec.status as current_status,
                         c.cert_name,
                         e.id as employee_id, e.employee_code, e.full_name as employee_name,
                         e.contractor_company, e.department,
                         DATEDIFF(ec.expiry_date, CURDATE()) as days_left
                  FROM employee_certifications ec
                  JOIN certifications c ON ec.certification_id = c.id
                  JOIN employees e ON ec.employee_id = e.id
                  WHERE ec.expiry_date IS NOT NULL 
                    AND ec.expiry_date > '1970-01-01'
                    AND e.is_active = 1
                    AND e.deleted_at IS NULL
                  ORDER BY ec.expiry_date ASC";

        $res = $this->db->query($query);
        if (!$res) return ['sent' => 0, 'expired_count' => 0, 'details' => []];

        while ($row = $res->fetch_assoc()) {
            $daysLeft = (int) $row['days_left'];
            $certId = (int) $row['id'];
            $certName = $row['cert_name'];
            $empName = $row['employee_name'];
            $company = $row['contractor_company'];
            $dept = $row['department'];

            // 1. Check if expired today or earlier
            if ($daysLeft <= 0) {
                // Auto-update status to expired if not yet marked
                if ($row['current_status'] !== 'expired') {
                    $this->db->query("UPDATE employee_certifications SET status = 'expired' WHERE id = ?", [$certId], "i");
                    $expiredCount++;
                }

                // Send expired notice if not already sent
                if (!$this->hasReminderSent('certification', $certId, 0)) {
                    $this->sendCertReminderMessage($row, 0);
                    $this->logReminderSent('certification', $certId, 0, null, null);
                    $sentCount++;
                    $details[] = "Expired: {$certName} ({$empName} - {$company})";
                }
                continue;
            }

            // 2. Check approaching thresholds (90, 60, 30, 7)
            foreach ($this->certThresholds as $threshold) {
                if ($threshold === 0) continue;

                // Trigger if days_left is within window of threshold (e.g. threshold >= days_left and > next_lower)
                if ($daysLeft <= $threshold) {
                    if (!$this->hasReminderSent('certification', $certId, $threshold)) {
                        $this->sendCertReminderMessage($row, $threshold);
                        $this->logReminderSent('certification', $certId, $threshold, null, null);
                        $sentCount++;
                        $details[] = "H-{$threshold} Reminder: {$certName} ({$empName} - {$company}, sisa {$daysLeft} hari)";
                    }
                    break; // Only send the most urgent matching threshold in one run
                }
            }
        }

        return [
            'sent' => $sentCount,
            'expired_count' => $expiredCount,
            'details' => $details
        ];
    }

    /**
     * Check appointments approaching expiration or expired
     */
    public function processAppointmentReminders() {
        $sentCount = 0;
        $expiredCount = 0;
        $details = [];

        $query = "SELECT a.id, a.appointment_number, a.expiry_date, a.status as current_status,
                         p.position_name,
                         e.id as employee_id, e.employee_code, e.full_name as employee_name,
                         e.contractor_company, e.department,
                         DATEDIFF(a.expiry_date, CURDATE()) as days_left
                  FROM appointments a
                  JOIN employees e ON a.employee_id = e.id
                  LEFT JOIN positions p ON a.position_id = p.id
                  WHERE a.expiry_date IS NOT NULL 
                    AND a.expiry_date > '1970-01-01'
                    AND a.deleted_at IS NULL
                    AND e.deleted_at IS NULL
                    AND a.status = 'approved'
                  ORDER BY a.expiry_date ASC";

        $res = $this->db->query($query);
        if (!$res) return ['sent' => 0, 'expired_count' => 0, 'details' => []];

        while ($row = $res->fetch_assoc()) {
            $daysLeft = (int) $row['days_left'];
            $apptId = (int) $row['id'];
            $apptNo = $row['appointment_number'];
            $empName = $row['employee_name'];
            $company = $row['contractor_company'];

            if ($daysLeft <= 0) {
                // Auto mark appointment expired
                if ($row['current_status'] !== 'expired') {
                    $this->db->query("UPDATE appointments SET status = 'expired' WHERE id = ?", [$apptId], "i");
                    $expiredCount++;
                }

                if (!$this->hasReminderSent('appointment', $apptId, 0)) {
                    $this->sendApptReminderMessage($row, 0);
                    $this->logReminderSent('appointment', $apptId, 0, null, null);
                    $sentCount++;
                    $details[] = "Expired SK: {$apptNo} ({$empName} - {$company})";
                }
                continue;
            }

            foreach ($this->appointmentThresholds as $threshold) {
                if ($threshold === 0) continue;

                if ($daysLeft <= $threshold) {
                    if (!$this->hasReminderSent('appointment', $apptId, $threshold)) {
                        $this->sendApptReminderMessage($row, $threshold);
                        $this->logReminderSent('appointment', $apptId, $threshold, null, null);
                        $sentCount++;
                        $details[] = "H-{$threshold} SK Reminder: {$apptNo} ({$empName} - {$company}, sisa {$daysLeft} hari)";
                    }
                    break;
                }
            }
        }

        return [
            'sent' => $sentCount,
            'expired_count' => $expiredCount,
            'details' => $details
        ];
    }

    /**
     * Send email and WhatsApp for certification expiration reminder
     */
    private function sendCertReminderMessage(array $cert, int $threshold) {
        $daysLeft = max(0, (int)$cert['days_left']);
        $expiryDateFormatted = date('d-M-Y', strtotime($cert['expiry_date']));
        $certName = $cert['cert_name'];
        $certNo = $cert['cert_number'] ?: '-';
        $empName = $cert['employee_name'];
        $empCode = $cert['employee_code'];
        $company = $cert['contractor_company'];
        $dept = $cert['department'] ?: '-';

        $isExpired = ($threshold === 0 || $daysLeft <= 0);
        $urgency = $isExpired ? '🚨 [KEDALUWARSA]' : ($threshold <= 30 ? '⚠️ [PERINGATAN MENDESAK]' : 'ℹ️ [PENGINGAT]');
        
        $subject = "{$urgency} Masa Berlaku Sertifikat {$certName} - {$empName} ({$company})";

        $message  = "{$urgency} *PENGINGAT MASA BERLAKU SERTIFIKAT STELA*\n\n";
        if ($isExpired) {
            $message .= "Sertifikat berikut telah *KEDALUWARSA* dan memerlukan perpanjangan segera:\n\n";
        } else {
            $message .= "Sertifikat kompetensi berikut akan segera kedaluwarsa dalam *{$daysLeft} hari*:\n\n";
        }

        $message .= "📋 *Rincian Sertifikat:*\n";
        $message .= "• Pekerja: {$empName} ({$empCode})\n";
        $message .= "• Perusahaan: {$company}\n";
        $message .= "• Departemen: {$dept}\n";
        $message .= "• Sertifikat: {$certName}\n";
        $message .= "• No. Sertifikat: {$certNo}\n";
        $message .= "• Tanggal Berakhir: *{$expiryDateFormatted}*\n";
        $message .= "• Sisa Waktu: *" . ($isExpired ? "TELAH BERAKHIR" : "{$daysLeft} hari") . "*\n\n";

        if ($isExpired) {
            $message .= "⚠️ *Perhatian:* Penunjukan SK terkait dapat dinonaktifkan secara otomatis hingga sertifikat diperpanjang.\n";
        } else {
            $message .= "💡 *Tindakan:* Mohon segera jadwalkan perpanjangan atau re-sertifikasi sebelum masa berlaku habis.\n";
        }

        // 1. Send to Company / Department Contacts
        $contacts = $this->notificationService->getUserDeptContacts($company, $dept);
        foreach ($contacts as $contact) {
            if (!empty($contact['email'])) {
                $this->notificationService->sendEmailAndTrack('cert_expiry_reminder', $cert['id'], $company, $contact['email'], $contact['full_name'], $subject, $message);
            }
            if (!empty($contact['phone'])) {
                $this->notificationService->sendWhatsApp($contact['phone'], $contact['full_name'], $message, 'cert_expiry_reminder', $cert['id']);
            }
        }

        // 2. Send to Superadmin & Admin
        $admins = $this->notificationService->getAdminContacts();
        foreach ($admins as $admin) {
            if (!empty($admin['email'])) {
                $this->notificationService->sendEmailAndTrack('cert_expiry_reminder_admin', $cert['id'], $company, $admin['email'], $admin['full_name'], $subject, $message);
            }
        }
    }

    /**
     * Send email and WhatsApp for appointment expiration reminder
     */
    private function sendApptReminderMessage(array $appt, int $threshold) {
        $daysLeft = max(0, (int)$appt['days_left']);
        $expiryDateFormatted = date('d-M-Y', strtotime($appt['expiry_date']));
        $apptNo = $appt['appointment_number'];
        $posName = $appt['position_name'] ?: '-';
        $empName = $appt['employee_name'];
        $empCode = $appt['employee_code'];
        $company = $appt['contractor_company'];
        $dept = $appt['department'] ?: '-';

        $isExpired = ($threshold === 0 || $daysLeft <= 0);
        $urgency = $isExpired ? '🚨 [SK KEDALUWARSA]' : ($threshold <= 30 ? '⚠️ [PERINGATAN MENDESAK SK]' : 'ℹ️ [PENGINGAT SK]');
        
        $subject = "{$urgency} Masa Berlaku SK Penunjukan {$apptNo} - {$empName}";

        $message  = "{$urgency} *PENGINGAT MASA BERLAKU SK PENUNJUKAN STELA*\n\n";
        if ($isExpired) {
            $message .= "Surat Penunjukan (SK) berikut telah *KEDALUWARSA*:\n\n";
        } else {
            $message .= "Surat Penunjukan (SK) berikut akan berakhir dalam *{$daysLeft} hari*:\n\n";
        }

        $message .= "📋 *Rincian Surat Penunjukan:*\n";
        $message .= "• No. SK: {$apptNo}\n";
        $message .= "• Pekerja: {$empName} ({$empCode})\n";
        $message .= "• Jabatan Penunjukan: {$posName}\n";
        $message .= "• Perusahaan: {$company}\n";
        $message .= "• Tanggal Berakhir: *{$expiryDateFormatted}*\n";
        $message .= "• Sisa Waktu: *" . ($isExpired ? "TELAH BERAKHIR" : "{$daysLeft} hari") . "*\n\n";

        $message .= "💡 Mohon lakukan evaluasi penunjukan dan ajukan resubmit/perpanjangan SK melalui sistem STELA.\n";

        // Send to Company/Dept PIC
        $contacts = $this->notificationService->getUserDeptContacts($company, $dept);
        foreach ($contacts as $contact) {
            if (!empty($contact['email'])) {
                $this->notificationService->sendEmailAndTrack('appt_expiry_reminder', $appt['id'], $company, $contact['email'], $contact['full_name'], $subject, $message);
            }
            if (!empty($contact['phone'])) {
                $this->notificationService->sendWhatsApp($contact['phone'], $contact['full_name'], $message, 'appt_expiry_reminder', $appt['id']);
            }
        }
    }

    /**
     * Check if reminder for this reference and threshold was already logged
     */
    private function hasReminderSent(string $type, int $referenceId, int $threshold): bool {
        $stmt = $this->db->prepare("SELECT id FROM expiry_reminder_logs WHERE reminder_type = ? AND reference_id = ? AND days_threshold = ? LIMIT 1");
        if (!$stmt) return false;

        $stmt->bind_param("sii", $type, $referenceId, $threshold);
        $stmt->execute();
        $res = $stmt->get_result();
        $hasSent = ($res && $res->num_rows > 0);
        $stmt->close();

        return $hasSent;
    }

    /**
     * Record that a reminder was sent
     */
    private function logReminderSent(string $type, int $referenceId, int $threshold, ?string $email, ?string $phone) {
        $today = date('Y-m-d');
        $stmt = $this->db->prepare("INSERT INTO expiry_reminder_logs (reminder_type, reference_id, days_threshold, recipient_email, recipient_phone, sent_date) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("siisss", $type, $referenceId, $threshold, $email, $phone, $today);
            $stmt->execute();
            $stmt->close();
        }
    }
}
