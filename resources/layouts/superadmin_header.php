<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';

// Calculate base URL dynamically based on the calling script's location
$script_path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));

$depth = 0;
if (preg_match('#/resources/(admin|user|dept|ktt)$#', $script_path) || preg_match('#/superadmin$#', $script_path)) {
    $depth = 2;
} elseif (preg_match('#/(api|utils|migrations)$#', $script_path)) {
    $depth = 1;
}

$base_path = $script_path;
for ($i = 0; $i < $depth; $i++) {
    $base_path = dirname($base_path);
}
$base_path = rtrim($base_path, '/');
if ($base_path === '' || $base_path === '\\') {
    $base_path = '';
}

if (!defined('BASE_URL')) {
    define('BASE_URL', $base_path);
}

if (!function_exists('get_current_page')) {
    function get_current_page() {
        return basename($_SERVER['PHP_SELF']);
    }
}
$current_page = get_current_page();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>Superadmin | <?php echo SITE_NAME; ?></title>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/language-switcher.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/datatable-custom.css?v=<?php echo time(); ?>">
    
    <style>
        /* Premium Glassmorphism Dark Sidebar */
        .superadmin-sidebar {
            background: rgba(15, 23, 42, 0.85) !important;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
            box-shadow: 4px 0 24px rgba(0, 0, 0, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .superadmin-sidebar .sidebar-header {
            background: transparent !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding: 24px 20px;
            color: #fff;
            position: relative;
            overflow: hidden;
        }
        
        .superadmin-sidebar .sidebar-header::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(135deg, rgba(255,193,7,0.1) 0%, transparent 100%);
            z-index: -1;
        }

        .superadmin-sidebar .sidebar-header h3 {
            font-weight: 700;
            letter-spacing: 1px;
            background: linear-gradient(to right, #fff, #ffc107);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .superadmin-badge {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
            border-radius: 6px;
            font-weight: 600;
            letter-spacing: 0.5px;
            padding: 4px 8px;
            margin-top: 8px;
            display: inline-block;
        }

        .superadmin-sidebar .sidebar-menu {
            padding: 10px 0;
        }

        .superadmin-sidebar .menu-header {
            color: rgba(255, 255, 255, 0.4) !important;
            font-weight: 600;
            letter-spacing: 1.2px;
            margin-top: 15px;
        }

        .superadmin-sidebar .sidebar-menu li a {
            color: #94a3b8 !important;
            border-left: 3px solid transparent !important;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            margin: 4px 12px;
            border-radius: 8px;
            padding: 12px 15px;
        }

        .superadmin-sidebar .sidebar-menu li a i {
            transition: transform 0.3s ease, color 0.3s ease;
            width: 24px;
            text-align: center;
        }

        .superadmin-sidebar .sidebar-menu li a:hover {
            color: #fff !important;
            background: rgba(255, 255, 255, 0.05) !important;
            transform: translateX(4px);
        }

        .superadmin-sidebar .sidebar-menu li a:hover i {
            transform: scale(1.15);
            color: #ffc107;
        }

        .superadmin-sidebar .sidebar-menu li a.active {
            color: #fff !important;
            background: linear-gradient(90deg, rgba(255, 193, 7, 0.15) 0%, transparent 100%) !important;
            border-left: 3px solid #ffc107 !important;
            box-shadow: inset 2px 0 10px rgba(255, 193, 7, 0.05);
        }
        
        .superadmin-sidebar .sidebar-menu li a.active i {
            color: #ffc107;
        }

        /* Glassmorphism scrollbar for the sidebar */
        .superadmin-sidebar::-webkit-scrollbar {
            width: 6px;
        }
        .superadmin-sidebar::-webkit-scrollbar-track {
            background: transparent;
        }
        .superadmin-sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }
        .superadmin-sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.2);
        }
    </style>
    <script>
        // Global HTML escaping function for XSS prevention in JS rendering
        function escapeHtml(unsafe) {
            if (unsafe == null) return '';
            return String(unsafe)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
    </script>
</head>
<body>
    <div class="wrapper">
        <nav class="sidebar superadmin-sidebar">
            <div class="sidebar-header">
                <h3>STELA <i class="fas fa-crown text-warning"></i></h3>    
                <small class="text-muted"><?php echo htmlspecialchars($_SESSION['full_name']); ?></small>
                <span class="badge superadmin-badge">SUPER ADMIN</span>
            </div>
            
            <ul class="sidebar-menu">
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/superadmin/dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                    </a>
                </li>

                <?php if(hasPermission('manage_master_data') || hasPermission('manage_companies') || hasPermission('manage_departments')): ?>
                <li class="menu-header" style="color:#6c757d; font-size:12px; padding: 10px 20px; text-transform:uppercase;">Master Data Center</li>
                <?php if(hasPermission('manage_companies')): ?>
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/superadmin/companies.php" class="<?php echo $current_page == 'companies.php' ? 'active' : ''; ?>">
                        <i class="fas fa-building"></i> <span>Companies</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if(hasPermission('manage_departments')): ?>
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/superadmin/departments.php" class="<?php echo $current_page == 'departments.php' ? 'active' : ''; ?>">
                        <i class="fas fa-network-wired"></i> <span>Departments</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if(hasPermission('manage_master_data')): ?>
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/superadmin/competencies.php" class="<?php echo $current_page == 'competencies.php' ? 'active' : ''; ?>">
                        <i class="fas fa-award"></i> <span>Competencies</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/superadmin/sub_competencies.php" class="<?php echo $current_page == 'sub_competencies.php' ? 'active' : ''; ?>">
                        <i class="fas fa-list-ul"></i> <span>Sub Competencies</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/superadmin/certifications.php" class="<?php echo $current_page == 'certifications.php' ? 'active' : ''; ?>">
                        <i class="fas fa-certificate"></i> <span>Certifications</span>
                    </a>
                </li>

                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/superadmin/supervision_areas.php" class="<?php echo $current_page == 'supervision_areas.php' ? 'active' : ''; ?>">
                        <i class="fas fa-map-marker-alt"></i> <span>Supervision Areas</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php endif; ?>

                <?php if(hasPermission('user.view') || hasPermission('role.view')): ?>
                <li class="menu-header" style="color:#6c757d; font-size:12px; padding: 10px 20px; text-transform:uppercase;">Access Control</li>
                <?php if(hasPermission('user.view')): ?>
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/superadmin/users.php" class="<?php echo $current_page == 'users.php' ? 'active' : ''; ?>">
                        <i class="fas fa-users-cog"></i> <span>User Management</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/ktt/delegation.php" class="<?php echo $current_page == 'delegation.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user-clock"></i> <span>KTT Delegation</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if(hasPermission('role.view')): ?>
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/superadmin/roles_permissions.php" class="<?php echo $current_page == 'roles_permissions.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user-shield"></i> <span>Role & Permissions</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php endif; ?>

                <?php if(hasPermission('monitoring.view') || hasPermission('employee.view') || hasPermission('appointment.view') || hasPermission('certificate.view')): ?>
                <li class="menu-header" style="color:#6c757d; font-size:12px; padding: 10px 20px; text-transform:uppercase;">Monitoring Center</li>
                <?php if(hasPermission('employee.view') || hasPermission('monitoring.view')): ?>
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/superadmin/monitoring_employees.php" class="<?php echo $current_page == 'monitoring_employees.php' || $current_page == 'monitoring_employee_detail.php' ? 'active' : ''; ?>">
                        <i class="fas fa-users-viewfinder"></i> <span>Employee Monitoring</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if(hasPermission('appointment.view') || hasPermission('monitoring.view')): ?>
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/superadmin/monitoring_appointments.php" class="<?php echo $current_page == 'monitoring_appointments.php' || $current_page == 'monitoring_appointment_detail.php' ? 'active' : ''; ?>">
                        <i class="fas fa-file-signature"></i> <span>Appointment Monitoring</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if(hasPermission('certificate.view') || hasPermission('monitoring.view')): ?>
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/superadmin/monitoring_certificates.php" class="<?php echo $current_page == 'monitoring_certificates.php' ? 'active' : ''; ?>">
                        <i class="fas fa-certificate"></i> <span>Certificate Monitoring</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if(hasPermission('view_logs')): ?>
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/superadmin/monitoring_logs.php" class="<?php echo $current_page == 'monitoring_logs.php' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i> <span>System Logs</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php endif; ?>

                <li class="menu-header" style="color:#6c757d; font-size:12px; padding: 10px 20px; text-transform:uppercase;">Reports & Analytics</li>
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/superadmin/reports.php" class="<?php echo $current_page == 'reports.php' || $current_page == 'reports_competency.php' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-bar"></i> <span>All Reports</span>
                    </a>
                </li>



                <?php if(hasPermission('manage_elasticsearch')): ?>
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/superadmin/elasticsearch_manage.php" class="<?php echo $current_page == 'elasticsearch_manage.php' ? 'active' : ''; ?>">
                        <i class="fas fa-search"></i> <span>Elasticsearch Config</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if(hasPermission('settings.view')): ?>
                <li class="menu-header" style="color:#6c757d; font-size:12px; padding: 10px 20px; text-transform:uppercase;">System Maintenance</li>
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/superadmin/settings.php" class="<?php echo $current_page == 'settings.php' ? 'active' : ''; ?>">
                        <i class="fas fa-cogs"></i> <span>System Settings</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/superadmin/trash.php" class="<?php echo $current_page == 'trash.php' ? 'active' : ''; ?>">
                        <i class="fas fa-trash-restore"></i> <span>Trash & Recovery</span>
                    </a>
                </li>
                <?php endif; ?>

                <li class="menu-header" style="color:#6c757d; font-size:12px; padding: 10px 20px; text-transform:uppercase;">Account</li>
                <li>
                    <a href="<?php echo BASE_URL; ?>/resources/auth/change_password.php" class="<?php echo $current_page == 'change_password.php' ? 'active' : ''; ?>">
                        <i class="fas fa-cog"></i> <span>Change Password</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo BASE_URL; ?>/logout.php?csrf_token=<?php echo csrf_token(); ?>" class="logout text-danger">
                        <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
                    </a>
                </li>
            </ul>
        </nav>
        
        <div class="main-content" style="background-color:#f4f6f9;">
            <div class="topbar">
                <div class="topbar-left">
                    <h2><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Superadmin Dashboard'; ?></h2>
                </div>
                <div class="topbar-right topbar-meta-group">
                    <div class="topbar-meta-chip topbar-date-chip" aria-label="Current date">
                        <i class="fas fa-calendar-alt"></i>
                        <span class="date"><?php echo date('d F Y'); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="content p-4">
