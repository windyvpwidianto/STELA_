<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
$page_title = 'Settings';
require_once dirname(__DIR__, 2) . '/app/Helpers/auth_helper.php';
// Included via bootstrap/app.php

// Semua role yang sudah login bisa mengakses halaman ini
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/resources/auth/login.php');
    exit;
}

// Pastikan ini ditaruh di baris paling awal sebelum ada output HTML/spasi
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate token CSRF jika belum ada di session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$db = new Database();
$message = '';
$error = '';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    // 1. Validasi Token CSRF terlebih dahulu
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($csrf_token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        // Menggunakan stela_t untuk translasi error CSRF jika tersedia
        $error = function_exists('stela_t') ? stela_t('csrf-validation-failed') : 'Security validation failed. Request denied.';
    } else {
        // 2. Jika token lolos validasi, jalankan logika ganti password Anda
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validate inputs
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = stela_t('all-fields-required');
        } elseif ($new_password !== $confirm_password) {
            $error = stela_t('password-confirmation-not-match');
        } elseif (strlen($new_password) < 8 || !preg_match('/[A-Z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
            $error = function_exists('stela_t') ? stela_t('password-policy-error') : 'Password must be at least 8 characters long and contain at least one uppercase letter and one number.';
        } else {
            // Verify current password
            $user_id = $_SESSION['user_id'];
            $user = $db->query("SELECT password FROM users WHERE id = ?", [$user_id])->fetch_assoc();
            
            if (!$user || !password_verify($current_password, $user['password'])) {
                $error = stela_t('current-password-incorrect');
            } else {
                // Update password securely using parameterized query
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                if ($db->query("UPDATE users SET password = ? WHERE id = ?", [$new_password_hash, $user_id], "si")) {
                    // [SECURITY] Invalidate all existing remember-me tokens to prevent session hijacking
                    $db->query("DELETE FROM user_tokens WHERE user_id = ?", [$user_id], "i");
                    $message = stela_t('password-changed');
                    // Clear form
                    $_POST = array();
                } else {
                    $error = stela_t('failed-change-password');
                }
            }
        }
    }
}
if ($_SESSION['role'] === 'superadmin') {
    require_once dirname(__DIR__) . '/layouts/superadmin_header.php';
} else {
    require_once dirname(__DIR__) . '/layouts/header.php';
}
?>

<div class="change-password-container">
    <div class="page-header">
        <h2><i class="fas fa-cog"></i> Settings</h2>
        <p>Manage your account security by changing your password regularly</p>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <div>
            <strong>Success!</strong>
            <p><?php echo htmlspecialchars($message); ?></p>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <div>
            <strong>Error!</strong>
            <p><?php echo htmlspecialchars($error); ?></p>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-shield-alt"></i> Change Password Form</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="" class="password-form">
    <?= csrf_field() ?>
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">

    <input type="hidden" name="change_password" value="1">

    <div class="form-group">
        <label for="current_password">
            <i class="fas fa-lock"></i> Current Password
            <span class="required">*</span>
        </label>
        <input type="password"
               id="current_password"
               name="current_password"
               class="form-control"
               required
               placeholder="Enter current password" data-lang-placeholder="enter-current-password">
    </div>

    <div class="form-group">
        <label for="new_password">
            <i class="fas fa-key"></i> New Password
            <span class="required">*</span>
        </label>
        <input type="password"
               id="new_password"
               name="new_password"
               class="form-control"
               required
               minlength="6"
               placeholder="Enter new password (min. 6 characters)" data-lang-placeholder="enter-new-password-min-6">
        <small class="form-text">Minimum 6 characters. Use combination of letters, numbers, and symbols for better security.</small>
    </div>

    <div class="form-group">
        <label for="confirm_password">
            <i class="fas fa-check-circle"></i> Confirm New Password
            <span class="required">*</span>
        </label>
        <input type="password"
               id="confirm_password"
               name="confirm_password"
               class="form-control"
               required
               minlength="6"
               placeholder="Repeat new password" data-lang-placeholder="repeat-new-password">
    </div>
    
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i>
        <div>
            <strong>Security Tips:</strong>
            <ul>
                <li>Use at least 6 characters</li>
                <li>Combine uppercase letters, lowercase letters, numbers, and symbols</li>
                <li>Don't use easily guessable passwords</li>
                <li>Don't use personal information such as date of birth</li>
                <li>Change your password regularly for account security</li>
            </ul>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Change Password
        </button>
        <a href="<?php
            if ($_SESSION['role'] === 'superadmin') {
                echo BASE_URL . '/resources/superadmin/dashboard.php';
            } elseif (hasPermission('admin.access')) {
                echo BASE_URL . '/resources/admin/dashboard.php';
            } elseif (hasPermission('ktt.access')) {
                echo BASE_URL . '/resources/ktt/dashboard.php';
            } elseif (hasPermission('user.access') && !hasDepartment()) {
                echo BASE_URL . '/resources/user/dashboard.php';
            } else {
                echo BASE_URL . '/resources/dept/dashboard.php';
            }
        ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
</form>
        </div>
    </div>
</div>



<?php 
if ($_SESSION['role'] === 'superadmin') {
    require_once dirname(__DIR__) . '/layouts/superadmin_footer.php';
} else {
    require_once dirname(__DIR__) . '/layouts/footer.php';
}
?>
