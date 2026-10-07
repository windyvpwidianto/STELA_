<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
$page_title = 'Certificate Monitoring';
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once dirname(__DIR__, 2) . '/app/Helpers/auth_helper.php';
require_once dirname(__DIR__, 2) . '/app/Helpers/MonitoringHelper.php';

checkPageAccess(['superadmin']);

$db = new Database();
$monitoring = new MonitoringHelper($db);

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 20;

// Filters
$search = $_GET['search'] ?? '';
$filters = [
    'company' => $_GET['company'] ?? '',
    'department' => $_GET['department'] ?? '',
    'cert_status' => $_GET['cert_status'] ?? ''
];

// Fetch Data
$certificatesData = $monitoring->getCertificates($page, $limit, $search, $filters);
$certificates = $certificatesData['data'];
$totalPages = $certificatesData['pages'];
$totalRecords = $certificatesData['total'];

// Stats
$stats = $monitoring->getCertificateStats($filters);

// Dropdown Options
$companies = $monitoring->getCompanies();
$departments = $monitoring->getDepartments();

require_once dirname(__DIR__) . '/layouts/superadmin_header.php';
?>

<style>
    .monitor-dashboard { font-family: 'Inter', sans-serif; padding: 20px 0; }
    
    .stat-card {
        background: #fff;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        border-left: 4px solid transparent;
        transition: transform 0.2s;
        height: 100%;
    }
    .stat-card:hover { transform: translateY(-3px); }
    .stat-card.total { border-left-color: #3b82f6; }
    .stat-card.valid { border-left-color: #10b981; }
    .stat-card.expiring { border-left-color: #f59e0b; }
    .stat-card.expired { border-left-color: #ef4444; }
    
    .stat-title { color: #64748b; font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
    .stat-value { color: #0f172a; font-size: 1.8rem; font-weight: 700; margin-top: 10px; }
    .stat-icon { font-size: 2rem; opacity: 0.2; position: absolute; right: 20px; top: 25px; }

    .monitor-card { background: #fff; border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.04); }
    .monitor-card-header { background: transparent; border-bottom: 1px solid #f0f2f5; padding: 18px 24px; font-weight: 600; }
    
    .table-modern { width: 100%; border-collapse: separate; border-spacing: 0 8px; }
    .table-modern th { border: none; color: #64748b; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; padding: 10px 15px; }
    .table-modern td { background: #f8fafc; padding: 12px 15px; border: none; vertical-align: middle; }
    .table-modern td:first-child { border-radius: 8px 0 0 8px; }
    .table-modern td:last-child { border-radius: 0 8px 8px 0; }
    
    .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; }
    .status-badge i { margin-right: 5px; font-size: 0.7rem; }
    .status-valid { background: #dcfce7; color: #166534; }
    .status-expiring { background: #fef3c7; color: #b45309; }
    .status-expired { background: #fee2e2; color: #991b1b; }

    .action-btn { background: transparent; border: none; color: #64748b; padding: 6px 10px; border-radius: 6px; transition: 0.2s; font-size: 0.9rem;}
    .action-btn:hover { background: #e2e8f0; color: #0f172a; }
</style>

<div class="container-fluid monitor-dashboard">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="mb-1 fw-bold" style="color: #1e293b;">Certificate Monitoring</h2>
            <p class="text-muted mb-0">Track compliance and expiry of all employee certificates</p>
        </div>
        <div class="d-flex gap-2">
            <button id="btnRunReminder" class="btn btn-warning text-dark fw-bold shadow-sm" onclick="triggerExpiryCheck()">
                <i class="fas fa-bell me-1"></i> Cek & Kirim Pengingat Kedaluwarsa
            </button>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="stat-card total position-relative">
                <div class="stat-title">Total Certificates</div>
                <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                <i class="fas fa-certificate stat-icon text-primary"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card valid position-relative">
                <div class="stat-title">Valid</div>
                <div class="stat-value text-success"><?php echo number_format($stats['valid']); ?></div>
                <i class="fas fa-check-circle stat-icon text-success"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card expiring position-relative">
                <div class="stat-title">Expiring Soon (≤60 Days)</div>
                <div class="stat-value text-warning"><?php echo number_format($stats['expiring_soon']); ?></div>
                <i class="fas fa-exclamation-triangle stat-icon text-warning"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card expired position-relative">
                <div class="stat-title">Expired</div>
                <div class="stat-value text-danger"><?php echo number_format($stats['expired']); ?></div>
                <i class="fas fa-times-circle stat-icon text-danger"></i>
            </div>
        </div>
    </div>

    <div class="monitor-card">
        <div class="monitor-card-header">
            <!-- Filter Form -->
            <form method="GET" class="row g-3 align-items-center">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Search by name, cert number..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <select name="company" class="form-select">
                        <option value="">All Companies</option>
                        <?php foreach($companies as $c): ?>
                            <option value="<?php echo htmlspecialchars($c['contractor_company']); ?>" <?php echo $filters['company'] === $c['contractor_company'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['contractor_company']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="department" class="form-select">
                        <option value="">All Departments</option>
                        <?php foreach($departments as $d): ?>
                            <option value="<?php echo htmlspecialchars($d['department']); ?>" <?php echo $filters['department'] === $d['department'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['department']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="cert_status" class="form-select">
                        <option value="">All Status</option>
                        <option value="Valid" <?php echo $filters['cert_status'] === 'Valid' ? 'selected' : ''; ?>>Valid</option>
                        <option value="Expiring Soon" <?php echo $filters['cert_status'] === 'Expiring Soon' ? 'selected' : ''; ?>>Expiring Soon</option>
                        <option value="Expired" <?php echo $filters['cert_status'] === 'Expired' ? 'selected' : ''; ?>>Expired</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-outline-secondary w-100" title="Apply Filters"><i class="fas fa-filter"></i></button>
                </div>
            </form>
        </div>

        <div class="card-body p-4">
            <div class="mb-3 text-muted small d-flex justify-content-between align-items-center">
                <span>Showing <?php echo count($certificates); ?> of <?php echo number_format($totalRecords); ?> certificates</span>
                
                <div class="export-actions">
                    <?php 
                    $exportQuery = $_GET;
                    $exportQuery['type'] = 'certificates';
                    $baseQuery = http_build_query($exportQuery);
                    ?>
                    <a href="export_monitoring.php?<?php echo $baseQuery; ?>&format=pdf" target="_blank" class="btn btn-sm btn-outline-danger me-1">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </a>
                    <a href="export_monitoring.php?<?php echo $baseQuery; ?>&format=excel" class="btn btn-sm btn-outline-success">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </a>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>Certificate Type</th>
                            <th>Certificate Number</th>
                            <th>Employee</th>
                            <th>Company & Dept</th>
                            <th>Expiry Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($certificates)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fas fa-certificate fa-3x mb-3 d-block opacity-25"></i>No certificates found.</td></tr>
                        <?php else: ?>
                            <?php foreach($certificates as $cert): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($cert['master_cert_name'] ?: 'Custom/Other'); ?></div>
                                        <?php if (isset($cert['submission_count']) && (int)$cert['submission_count'] > 1): ?>
                                            <span class="badge bg-info text-white" style="font-size: 0.75em; margin-top: 4px;"><i class="fas fa-sync-alt"></i> Resubmitted</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="font-monospace small"><?php echo htmlspecialchars($cert['cert_number']); ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($cert['employee_name'] ?: 'Unknown'); ?></div>
                                        <div class="small text-muted">ID: <?php echo htmlspecialchars($cert['employee_code'] ?: '-'); ?></div>
                                    </td>
                                    <td>
                                        <div class="small fw-bold text-dark"><?php echo htmlspecialchars($cert['company'] ?: 'Internal'); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($cert['department'] ?: '-'); ?></div>
                                    </td>
                                    <td>
                                        <div class="small fw-bold <?php echo ($cert['monitoring_status'] === 'Expired') ? 'text-danger' : ''; ?>">
                                            <?php 
                                            if (empty($cert['expiry_date']) || $cert['expiry_date'] == '0000-00-00') {
                                                echo 'Lifetime / None';
                                            } else {
                                                echo date('d M Y', strtotime($cert['expiry_date'])); 
                                            }
                                            ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                        $s = $cert['monitoring_status'];
                                        if($s === 'Valid') {
                                            echo '<span class="status-badge status-valid"><i class="fas fa-check-circle"></i> Valid</span>';
                                        } elseif($s === 'Expiring Soon') {
                                            echo '<span class="status-badge status-expiring"><i class="fas fa-exclamation-triangle"></i> Expiring Soon</span>';
                                        } else {
                                            echo '<span class="status-badge status-expired"><i class="fas fa-times-circle"></i> Expired</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($cert['monitoring_status'] === 'Expired' || $cert['monitoring_status'] === 'Expiring Soon'): ?>
                                            <a href="resubmit_certificate.php?id=<?php echo (int)$cert['id']; ?>" class="action-btn text-decoration-none" title="Resubmit Certificate">
                                                <i class="fas fa-upload text-warning"></i> Resubmit
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if($totalPages > 1): ?>
                <nav class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php 
                        $q = $_GET;
                        $q['page'] = max(1, $page - 1);
                        $prevUrl = '?' . http_build_query($q);
                        
                        $q['page'] = min($totalPages, $page + 1);
                        $nextUrl = '?' . http_build_query($q);
                        ?>
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $prevUrl; ?>">Previous</a>
                        </li>
                        
                        <?php for($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <?php $q['page'] = $i; ?>
                            <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query($q); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>

                        <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $nextUrl; ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Result Pengingat Kedaluwarsa -->
<div class="modal fade" id="reminderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold"><i class="fas fa-bell me-2"></i> Pengingat Masa Berlaku</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="reminderModalBody">
                <div class="text-center py-3">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">Sedang memindai sertifikat & SK...</p>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
function triggerExpiryCheck() {
    const btn = document.getElementById('btnRunReminder');
    const modalEl = document.getElementById('reminderModal');
    const modal = new bootstrap.Modal(modalEl);
    const modalBody = document.getElementById('reminderModalBody');
    
    modalBody.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;"></div>
            <h6 class="fw-bold text-dark">Sedang memproses pengecekan masa berlaku...</h6>
            <p class="text-muted small mb-0">Memindai data sertifikat & SK, memeriksa threshold (H-90, H-60, H-30, H-7, Expired), memperbarui status, dan mengirimkan email serta WhatsApp.</p>
        </div>
    `;
    modal.show();
    btn.disabled = true;

    const csrfToken = "<?= $_SESSION['csrf_token'] ?? '' ?>";
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);

    fetch('../../api/trigger_expiry_reminders.php', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': csrfToken
        },
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        if (data.status === 'success') {
            const summary = data.data;
            let certList = '';
            if (summary.details.certificates && summary.details.certificates.length > 0) {
                certList = '<ul class="list-group list-group-flush mt-2 small text-start" style="max-height: 180px; overflow-y: auto;">';
                summary.details.certificates.forEach(c => {
                    certList += `<li class="list-group-item px-2 py-1"><i class="fas fa-angle-right me-1 text-primary"></i> ${c}</li>`;
                });
                certList += '</ul>';
            } else {
                certList = '<p class="text-muted small mt-2 mb-0">Semua notifikasi pengingat untuk periode saat ini sudah pernah terkirim (tidak ada duplikasi).</p>';
            }

            modalBody.innerHTML = `
                <div class="text-center mb-3">
                    <i class="fas fa-check-circle text-success" style="font-size: 3rem;"></i>
                    <h5 class="fw-bold mt-2">Pengecekan Selesai</h5>
                    <p class="text-muted small mb-0">${data.message}</p>
                </div>
                <div class="row g-2 text-center mb-3">
                    <div class="col-6">
                        <div class="p-2 border rounded bg-light">
                            <div class="fw-bold fs-5 text-primary">${summary.cert_reminders_sent}</div>
                            <small class="text-muted">Notifikasi Terkirim</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-2 border rounded bg-light">
                            <div class="fw-bold fs-5 text-danger">${summary.cert_auto_expired}</div>
                            <small class="text-muted">Status Auto-Expired</small>
                        </div>
                    </div>
                </div>
                <h6 class="fw-bold fs-6 mb-1 text-start">Rincian Tindakan:</h6>
                ${certList}
            `;
        } else {
            modalBody.innerHTML = `
                <div class="text-center py-3">
                    <i class="fas fa-exclamation-circle text-danger" style="font-size: 3rem;"></i>
                    <h5 class="fw-bold mt-2 text-danger">Gagal Menjalankan Pengecekan</h5>
                    <p class="text-muted mb-0">${data.message || 'Terjadi kesalahan sistem.'}</p>
                </div>
            `;
        }
    })
    .catch(err => {
        btn.disabled = false;
        modalBody.innerHTML = `
            <div class="text-center py-3">
                <i class="fas fa-times-circle text-danger" style="font-size: 3rem;"></i>
                <h5 class="fw-bold mt-2 text-danger">Koneksi Gagal</h5>
                <p class="text-muted mb-0">Tidak dapat terhubung ke server API.</p>
            </div>
        `;
    });
}
</script>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
