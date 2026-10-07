<?php
/**
 * Upload Helper Functions
 *
 * Centralizes all file upload logic so that the upload storage location
 * can be changed from one place: config/app.php (UPLOAD_PHYSICAL_PATH & UPLOAD_URL).
 *
 * Files are stored OUTSIDE the git repository so they survive git pull.
 */

/**
 * Get the absolute filesystem path for a given upload type.
 *
 * @param  string $type  'cv' | 'statements' | 'certifications'
 * @return string        Absolute path (with trailing slash)
 */
if (!function_exists('upload_physical_dir')) {
    function upload_physical_dir(string $type): string {
        $base = rtrim(str_replace('\\', '/', UPLOAD_PHYSICAL_PATH), '/');
        $dir  = $base . '/' . $type . '/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }
}

/**
 * Move an uploaded file to the correct upload directory.
 *
 * @param  array  $file_array   Entry from $_FILES (e.g. $_FILES['cv_file'])
 * @param  string $type         'cv' | 'statements' | 'certifications'
 * @param  string $prefix       Filename prefix (e.g. employee_code)
 * @return string|false         DB-storable relative path on success, false on failure
 */
if (!function_exists('handle_upload')) {
    function handle_upload(array $file_array, string $type, string $prefix): string|false {
        // [SECURITY] Validasi error code file upload
        if (!isset($file_array['error']) || $file_array['error'] !== UPLOAD_ERR_OK) {
            error_log("Upload rejected: file error code " . ($file_array['error'] ?? 'missing'));
            return false;
        }

        // [SECURITY] Validasi ukuran file terhadap batas maksimum (MAX_UPLOAD_SIZE)
        $max_size = defined('MAX_UPLOAD_SIZE') ? MAX_UPLOAD_SIZE : (10 * 1024 * 1024);
        if (!isset($file_array['size']) || $file_array['size'] <= 0 || $file_array['size'] > $max_size) {
            error_log("Upload rejected: file size " . ($file_array['size'] ?? 0) . " bytes exceeds limit ($max_size bytes).");
            return false;
        }

        $ext      = strtolower(pathinfo($file_array['name'], PATHINFO_EXTENSION));
        
        // [SECURITY] Validasi ekstensi file terhadap whitelist
        $allowed_extensions = array_merge(
            defined('ALLOWED_IMAGE_TYPES') ? ALLOWED_IMAGE_TYPES : ['jpg', 'jpeg', 'png'],
            defined('ALLOWED_DOC_TYPES') ? ALLOWED_DOC_TYPES : ['pdf', 'doc', 'docx', 'xls', 'xlsx']
        );
        if (!in_array($ext, $allowed_extensions)) {
            error_log("Upload rejected: extension '$ext' not in allowed list.");
            return false;
        }
        
        // [SECURITY] Generate cryptographically secure unguessable filename
        $safe_prefix = preg_replace('/[^a-zA-Z0-9_\-]/', '', $prefix);
        $random_suffix = bin2hex(random_bytes(16));
        $filename = ($safe_prefix ?: 'doc') . '_' . $type . '_' . time() . '_' . $random_suffix . '.' . $ext;
        $dir      = upload_physical_dir($type);
        $dest     = $dir . $filename;

        // [SECURITY] Gunakan safe_move_uploaded_file untuk validasi MIME
        if (!safe_move_uploaded_file($file_array['tmp_name'], $dest)) {
            return false;
        }

        // Return a DB-storable identifier (relative to UPLOAD_PHYSICAL_PATH)
        return $type . '/' . $filename;
    }
}

/**
 * Build the public URL for a stored file.
 *
 * @param  string|null $db_path  Value stored in DB (e.g. 'cv/emp001_cv_1234.pdf')
 *                                or old-style 'public/assets/uploads/cv/...'
 * @return string|null
 */
if (!function_exists('upload_url')) {
    function upload_url(?string $db_path): ?string {
        if (empty($db_path)) {
            return null;
        }
        $base = rtrim(UPLOAD_URL, '/');

        // Handle legacy paths stored with various prefixes
        foreach (['public/assets/uploads/', 'assets/uploads/', 'uploads/'] as $prefix) {
            if (strpos($db_path, $prefix) === 0) {
                $db_path = substr($db_path, strlen($prefix));
                break;
            }
        }

        return $base . '/' . ltrim($db_path, '/');
    }
}

/**
 * Delete a stored file from the physical upload directory.
 *
 * @param  string|null $db_path  Value stored in DB
 * @return bool
 */
if (!function_exists('delete_upload')) {
    function delete_upload(?string $db_path): bool {
        if (empty($db_path)) {
            return false;
        }
        foreach (['public/assets/uploads/', 'assets/uploads/', 'uploads/'] as $prefix) {
            if (strpos($db_path, $prefix) === 0) {
                $db_path = substr($db_path, strlen($prefix));
                break;
            }
        }
        $base = rtrim(str_replace('\\', '/', UPLOAD_PHYSICAL_PATH), '/');
        $path = $base . '/' . ltrim($db_path, '/');
        if (file_exists($path)) {
            return @unlink($path);
        }
        return false;
    }
}

/**
 * Validate MIME type using finfo before moving the uploaded file.
 * This function mitigates arbitrary file upload vulnerabilities.
 *
 * @param string $tmp_name
 * @param string $destination
 * @return bool
 */
if (!function_exists('safe_move_uploaded_file')) {
    function safe_move_uploaded_file(string $tmp_name, string $destination): bool {
        // [SECURITY] Log ke storage/logs/ bukan root project
        $log_dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0750, true);
        }
        $log_file = $log_dir . '/upload_debug.log';
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Upload attempt: dest=$destination\n", FILE_APPEND);

        if (!is_uploaded_file($tmp_name)) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Error: tmp file is not an uploaded file\n", FILE_APPEND);
            return false;
        }

        // Initialize finfo for MIME validation
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp_name);
        finfo_close($finfo);

        file_put_contents($log_file, date('Y-m-d H:i:s') . " - MIME detected: $mime\n", FILE_APPEND);

        // [SECURITY] Daftar MIME yang diizinkan — TANPA application/octet-stream
        $allowed_mimes = [
            'application/pdf',
            'application/x-pdf',
            'application/acrobat',
            'application/vnd.pdf',
            'text/pdf',
            'text/x-pdf',
            'image/jpeg', 
            'image/jpg', 
            'image/png',
            'application/msword', 
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ];

        if (!in_array($mime, $allowed_mimes)) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Error: MIME '$mime' not in allowed list\n", FILE_APPEND);
            error_log("Upload failed: MIME type '$mime' not allowed.");
            return false;
        }

        // If MIME type is valid, proceed with the actual move
        if (move_uploaded_file($tmp_name, $destination)) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Success: file moved\n", FILE_APPEND);
            return true;
        } else {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Error: move_uploaded_file returned false\n", FILE_APPEND);
            error_log("Upload failed: move_uploaded_file returned false for destination $destination");
            return false;
        }
    }
}
