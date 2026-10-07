<?php
/**
 * STELA Automated Expiry Reminder Cron Runner
 * 
 * Schedule this script to run daily at 06:00:
 * Linux Crontab:
 *   0 6 * * * /usr/bin/php /path/to/stela/cron/run_expiry_reminders.php >> /path/to/stela/storage/logs/cron.log 2>&1
 * 
 * Windows Task Scheduler:
 *   Action: Start a program
 *   Program: php.exe
 *   Arguments: c:\path\to\stela\cron\run_expiry_reminders.php
 */

// If invoked via web URL, require CLI or authorization
if (php_sapi_name() !== 'cli') {
    require_once dirname(__DIR__) . '/bootstrap/app.php';
    require_once dirname(__DIR__) . '/app/Helpers/auth_helper.php';

    $cronKey = $_GET['key'] ?? '';
    $expectedKey = getenv('CRON_SECRET') ?: '';

    $isAuthorizedAdmin = (isset($_SESSION['role']) && in_array($_SESSION['role'], ['superadmin', 'admin']));
    $isValidSecret = (!empty($expectedKey) && hash_equals($expectedKey, $cronKey));

    if (!$isAuthorizedAdmin && !$isValidSecret) {
        http_response_code(403);
        die("Access Denied: Cron runner requires CLI execution or authorized secret key.");
    }
} else {
    require_once dirname(__DIR__) . '/bootstrap/app.php';
}

require_once dirname(__DIR__) . '/app/Services/ExpiryReminderService.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting STELA Automated Expiry Reminders Check...\n";

try {
    $service = new ExpiryReminderService();
    $result = $service->runDailyCheck();

    echo "[" . date('Y-m-d H:i:s') . "] Check completed successfully.\n";
    echo "- Certificate Reminders Sent: " . $result['cert_reminders_sent'] . "\n";
    echo "- Certificates Auto-Expired:  " . $result['cert_auto_expired'] . "\n";
    echo "- Appointment Reminders Sent: " . $result['appt_reminders_sent'] . "\n";
    echo "- Appointments Auto-Expired:  " . $result['appt_auto_expired'] . "\n";

    if (!empty($result['details']['certificates'])) {
        echo "Certificate Details:\n";
        foreach ($result['details']['certificates'] as $detail) {
            echo "  * {$detail}\n";
        }
    }

    if (!empty($result['details']['appointments'])) {
        echo "Appointment Details:\n";
        foreach ($result['details']['appointments'] as $detail) {
            echo "  * {$detail}\n";
        }
    }

    // If run via web browser by authorized admin, return JSON
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => 'Automated check completed.',
            'summary' => $result
        ]);
    }
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Error during execution: " . $e->getMessage() . "\n";
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit(1);
}
