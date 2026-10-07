<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
$page_title = 'Employee Status';
require_once dirname(__DIR__, 2) . '/app/Helpers/auth_helper.php';

// Only department access permitted
requirePermission('employee.view');
if (!hasPermission('dept.access') && !(hasPermission('user.access') && hasDepartment()) && !isSuperadmin()) {
    header('Location: ../admin/dashboard.php');
    exit();
}

$db = new Database();
$company_name = $_SESSION['company_name'] ?? '';
$department = $_SESSION['department'] ?? '';
$safeCompany = $db->escapeString($company_name);
$safeDept = $db->escapeString($department);
$message = '';
$error = '';


// Get filter from URL
$filter = isset($_GET['filter']) ? $db->escapeString($_GET['filter']) : '';

// Build WHERE clause for filter
$filter_parts = [];
if (!empty($safeDept)) {
    $filter_parts[] = "e.department = '$safeDept'";
    $filter_parts[] = "e.contractor_company = '$safeDept'";
}
if (!empty($safeCompany) && $safeCompany !== $safeDept) {
    $filter_parts[] = "e.contractor_company = '$safeCompany'";
    $filter_parts[] = "e.department = '$safeCompany'";
}

$dept_filter = !empty($filter_parts) ? "(" . implode(" OR ", array_unique($filter_parts)) . ")" : "1=1";

$where_clause = "e.is_active = 1 AND $dept_filter";

if (!empty($filter)) {
    $where_clause .= " AND e.employee_status = '$filter'";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resign_employee') {
    header('Content-Type: application/json');

    $id = (int)($_POST['employee_id'] ?? 0);
    $date = trim($_POST['resign_date'] ?? '');
    $reason = trim($_POST['resign_reason'] ?? '');

    if (!$id || empty($date) || empty($reason)) {
        echo json_encode([
            "success" => false,
            "message" => "Semua field harus diisi."
        ]);
        exit;
    }

    // [SECURITY] IDOR Protection: Verifikasi kepemilikan employee terhadap department/company
    $authCompany = $_SESSION['company_name'] ?? '';
    $authDept = $_SESSION['department'] ?? '';

    $empCheck = $db->query("SELECT id, contractor_company, department FROM employees WHERE id = ? AND deleted_at IS NULL", [$id], "i");
    if (!$empCheck || $empCheck->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Employee tidak ditemukan."]);
        exit;
    }

    $empRow = $empCheck->fetch_assoc();
    $canModify = false;
    if (!empty($authDept) && $empRow['department'] === $authDept) {
        $canModify = true;
    } elseif (!empty($authCompany) && $empRow['contractor_company'] === $authCompany) {
        $canModify = true;
    }

    if (!$canModify) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Akses ditolak: Anda tidak memiliki wewenang atas data karyawan ini."]);
        exit;
    }

    $stmt = $db->prepare("
        UPDATE employees
        SET
            employee_status='resigned',
            resign_date=?,
            resign_reason=?
        WHERE id=?
    ");

    $stmt->bind_param(
        "ssi",
        $date,
        $reason,
        $id
    );

    if($stmt->execute()){
        echo json_encode([
            "success"=>true
        ]);
    }else{
        echo json_encode([
            "success"=>false,
            "message"=>"Gagal memperbarui status karyawan."
        ]);
    }

    exit;
}
// Get all employees with verification status and KTT rejection awareness
$employees = $db->query("
SELECT
    e.id,
    e.employee_code,
    e.full_name,
    e.contractor_company,
    e.position,
    e.competency_type,
    e.competency_name,
    e.employee_status,
    e.resign_date,

    a.appointment_number,
    a.appointment_date

FROM employees e

INNER JOIN appointments a
    ON a.employee_id = e.id

WHERE
    $where_clause
    AND a.status='approved'

GROUP BY e.id
ORDER BY e.full_name ASC
");

require_once dirname(__DIR__) . '/layouts/header.php';

// Get statistics (only employees with approved appointments)
$total_employees =
$db->query("
SELECT COUNT(DISTINCT e.id) total
FROM employees e
INNER JOIN appointments a ON a.employee_id = e.id
WHERE
e.is_active=1
AND $dept_filter
AND a.status='approved'
")->fetch_assoc()['total'];

$active_count =
$db->query("
SELECT COUNT(DISTINCT e.id) total
FROM employees e
INNER JOIN appointments a ON a.employee_id = e.id
WHERE
e.is_active=1
AND e.employee_status='active'
AND $dept_filter
AND a.status='approved'
")->fetch_assoc()['total'];


$resigned_count =
$db->query("
SELECT COUNT(DISTINCT e.id) total
FROM employees e
INNER JOIN appointments a ON a.employee_id = e.id
WHERE
e.is_active=1
AND e.employee_status='resigned'
AND $dept_filter
AND a.status='approved'
")->fetch_assoc()['total'];

$inactive_count =
$db->query("
SELECT COUNT(DISTINCT e.id) total
FROM employees e
INNER JOIN appointments a ON a.employee_id = e.id
WHERE
e.is_active=0
AND $dept_filter
AND a.status='approved'
")->fetch_assoc()['total'];

?>

<div class="employees-admin-container">
    <!-- Page Header -->
    <div class="page-header-emp-admin">
    <div class="header-left">
        <h2><i class="fas fa-user-clock"></i>Employee Status</h2>
        <p>Manage Active and Resigned Employees</p>
    </div>
</div>

    <?php if (!empty($filter)): ?>
    <div class="alert alert-info alert-custom-emp"> 
        <i class="fas fa-filter"></i>
        <div>
            <strong data-lang="active-filter">Active Filter:</strong>
            <p><span data-lang="displaying-employees-status">Displaying employees with status:</span> <strong>
                <?php
                $filter_labels = [
                    'active' => 'Active',
                    'resigned' => 'Resigned'
                ];
                echo $filter_labels[$filter] ?? $filter;
                ?>
            </strong></p>
        </div>
        <a href="employees_status.php" class="btn btn-sm btn-secondary" style="margin-left: auto;">
            <i class="fas fa-times"></i> <span data-lang="remove-filter">Remove Filter</span>
        </a>
    </div>
    <?php endif; ?>
    
    <?php if ($message): ?>
    <div class="alert alert-success alert-custom-emp">
        <i class="fas fa-check-circle"></i>
        <div>
            <strong data-lang="success">Success!</strong>
            <p><?php echo htmlspecialchars($message); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error alert-custom-emp">
        <i class="fas fa-exclamation-circle"></i>
        <div>
            <strong data-lang="error">Error!</strong>
            <p><?php echo htmlspecialchars($error); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Statistics Cards - Overall -->
<div class="stats-section-title">
    <h4><span data-lang="overall-statistics">Overall Statistics</span></h4>
</div>

<style>
    .custom-stat-card {
        background: #fff;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        display: flex;
        justify-content: space-between;
        align-items: center;
        border: 1px solid #f0f0f0;
        border-left: 4px solid;
    }
    .custom-stat-card .stat-info { display: flex; flex-direction: column; }
    .custom-stat-card .stat-title { font-size: 14px; color: #6c757d; font-weight: 600; margin-bottom: 8px; }
    .custom-stat-card .stat-number { font-size: 32px; font-weight: bold; margin-bottom: 5px; line-height: 1; }
    .custom-stat-card .stat-desc { font-size: 12px; color: #adb5bd; font-weight: 500; }
    .custom-stat-card .stat-icon { width: 55px; height: 55px; border-radius: 12px; display: flex; justify-content: center; align-items: center; font-size: 28px; }
    
    .stat-variant-blue { border-left-color: #1A73E8; } .stat-variant-blue .stat-number { color: #1A73E8; } .stat-variant-blue .stat-icon { background: #E8F0FE; color: #1A73E8; }
    .stat-variant-green { border-left-color: #1E8E3E; } .stat-variant-green .stat-number { color: #1E8E3E; } .stat-variant-green .stat-icon { background: #E6F4EA; color: #1E8E3E; }
    .stat-variant-orange { border-left-color: #F57C00; } .stat-variant-orange .stat-number { color: #F57C00; } .stat-variant-orange .stat-icon { background: #FFF3E0; color: #F57C00; }
    .stat-variant-red { border-left-color: #D93025; } .stat-variant-red .stat-number { color: #D93025; } .stat-variant-red .stat-icon { background: #FCE8E6; color: #D93025; }
    .stat-variant-grey { border-left-color: #6B7280; } .stat-variant-grey .stat-number { color: #6B7280; } .stat-variant-grey .stat-icon { background: #F3F4F6; color: #6B7280; }
</style>
<div class="stats-grid-emp" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px;">
    <div class="custom-stat-card stat-variant-blue">
        <div class="stat-info">
            <div class="stat-title" data-lang="total-employee">Total Employee</div>
            <div class="stat-number"><?= $total_employees ?></div>
            <div class="stat-desc" data-lang="total-registered">Total registered workforce</div>
        </div>
        <div class="stat-icon"><i class="fas fa-users"></i></div>
    </div>
    
    <div class="custom-stat-card stat-variant-green">
        <div class="stat-info">
            <div class="stat-title" data-lang="active">Active</div>
            <div class="stat-number"><?= $active_count ?></div>
            <div class="stat-desc" data-lang="currently-working">Currently working</div>
        </div>
        <div class="stat-icon"><i class="fas fa-user-check"></i></div>
    </div>
    
    <div class="custom-stat-card stat-variant-red">
        <div class="stat-info">
            <div class="stat-title" data-lang="resigned">Resigned</div>
            <div class="stat-number"><?= $resigned_count ?></div>
            <div class="stat-desc" data-lang="left-company">Left the company</div>
        </div>
        <div class="stat-icon"><i class="fas fa-user-times"></i></div>
    </div>
    
    <div class="custom-stat-card stat-variant-grey">
        <div class="stat-info">
            <div class="stat-title" data-lang="inactive">Inactive</div>
            <div class="stat-number"><?= $inactive_count ?></div>
            <div class="stat-desc" data-lang="not-active">Not active</div>
        </div>
        <div class="stat-icon"><i class="fas fa-user-slash"></i></div>
    </div>
</div>
    
    <!-- Bonsai.io Pagination Search Section -->
    <div class="card es-search-card" style="margin-bottom: 16px; padding: 14px 18px; background: #ffffff; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #e9ecef; position: relative; z-index: 1050; overflow: visible;">
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 200px; position: relative;">
                <i class="fas fa-search" style="position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #6c757d; font-size: 14px; z-index: 2;"></i>
                <input type="text" id="esSearchInput" autocomplete="off"
                       placeholder="Cari Nama, ID Badge, Posisi, No Appointment..."
                       style="width:100%; padding-left: 38px; padding-right: 36px; height: 40px; border-radius: 8px; border: 1px solid #ced4da; font-size: 14px;">
                <button type="button" id="esClearBtn" title="Bersihkan"
                        style="display:none; position: absolute; right: 9px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #a0aec0; cursor: pointer; font-size: 16px; padding: 0; z-index: 2;">
                    <i class="fas fa-times-circle"></i>
                </button>
            </div>
            <select id="filterUserEmpStatus" style="height:40px; border-radius:8px; border:1px solid #ced4da; padding: 0 10px; font-size:13px; min-width:130px;">
                <option value="">Semua Status</option>
                <option value="active" <?php echo $filter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="resigned" <?php echo $filter === 'resigned' ? 'selected' : ''; ?>>Resigned</option>
            </select>
            <select id="userStatusPageLimit" style="height:40px; border-radius:8px; border:1px solid #ced4da; padding: 0 10px; font-size:13px; min-width:90px;">
                <option value="10">10 / hal</option>
                <option value="25">25 / hal</option>
                <option value="50">50 / hal</option>
            </select>
        </div>
        <div id="userStatusInfoContainer" style="margin-top:8px; font-size:13px; color:#6c757d;"></div>
    </div>

    <!-- Employees Table -->
    <div class="card-emp">
        <div class="card-header-emp">
            <h3><i class="fas fa-list"></i> <span data-lang="complete-workforce-list">Complete Workforce List</span></h3>
        </div>

        <div class="card-body-emp">
            <?php if ($employees->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table-emp" id="employeesTable">
                        <thead>
                            <tr>
                                <th class="col-code" data-lang="id-badge">ID BADGE</th>
                                <th class="col-name" data-lang="name">Name</th>
                                <th class="col-position no-required-marker" data-lang="position">Position</th>
                                <th class="col-company no-required-marker" data-lang="company">Company</th>
                                <th class="col-competency-type no-required-marker" data-lang="competency-type">Competency Type</th>
                                <th class="col-competency no-required-marker" data-lang="competency">Competency</th>
                                <th class="col-status">Appointment No</th>
                                <th class="col-employee-status" data-lang="employee-status">Employee Status</th>
                                <th class="col-action" data-lang="action">Action</th>
                            </tr>
                        </thead>
                        <tbody id="userStatusTbody">
                            <?php 
                            $employees->data_seek(0);
                            while ($row = $employees->fetch_assoc()): 
                               $employee_company = htmlspecialchars($row['contractor_company']);
                            ?>
                            <tr class="emp-row" data-company="<?= htmlspecialchars($employee_company ?? "", ENT_QUOTES, "UTF-8") ?>" data-status="<?= htmlspecialchars($row['employee_status']) ?>">
                                <td class="col-code">
                                    <span class="code-badge"><?php echo htmlspecialchars($row['employee_code']); ?></span>
                                </td>
                                <td class="col-name">
                                    <strong><?php echo htmlspecialchars($row['full_name']); ?></strong>
                                </td>
                                <td class="col-position">
                                    <span class="position-tag-emp"><?php echo htmlspecialchars($row['position']); ?></span>
                                </td>
                                <td class="col-company">
                                    <span class="company-tag-emp"><?= htmlspecialchars($employee_company ?? "", ENT_QUOTES, "UTF-8") ?></span>
                                </td>
                                <td class="col-competency-type">
                                    <?php $type = strtolower(str_replace(' ', '_', trim($row['competency_type'])));?>
                                    <span class="competency-type-badge competency-<?= htmlspecialchars($type ?? "", ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($row['competency_type']) ?></span>
                                    </td>      
                                <td class="col-competency">
                                    <?php if (!empty($row['competency_name'])): ?>
                                        <span class="competency-tag"><?php echo htmlspecialchars($row['competency_name']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-status">
                                    <?= htmlspecialchars($row['appointment_number']) ?>
                                </td>
                                <td>
                                    <?php if($row['employee_status']=="active"): ?>
                                    <span class="badge-status badge-success">ACTIVE</span>
                                    <?php else: ?>
                                    <span class="badge-status badge-danger">RESIGNED</span>
                                    <?php endif; ?>
                                    </td>
                                <td class="col-action text-center">
                                    <?php if($row['employee_status']=="active"): ?>
                                    <button
                                        type="button"
                                        class="btn btn-danger btn-sm resign-btn"
                                        data-id="<?= $row['id']; ?>"
                                        data-name="<?= htmlspecialchars($row['full_name']); ?>"
                                        data-company="<?= htmlspecialchars($row['contractor_company']); ?>"
                                        data-appointment="<?= htmlspecialchars($row['appointment_number']); ?>">
                                        <i class="fas fa-user-times"></i>
                                        Resign
                                    </button>
                                    <?php else: ?>
                                        <a href="employee_status_detail.php?id=<?= $row['id']; ?>"
                                        class="btn btn-secondary btn-sm detail-btn">
                                            <i class="fas fa-eye"></i>
                                            Detail
                                        </a>

                                        <?php endif; ?>

                                    </td>
                              </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px; flex-wrap:wrap; gap:8px;">
                    <div id="userStatusInfoContainerTable" style="font-size:13px; color:#6c757d;"></div>
                    <div id="userStatusPaginationContainer"></div>
                </div>

                <script src="../../assets/js/bonsai_pagination.js?v=<?php echo time(); ?>"></script>
                <script>
                (function() {
                    const deptName = <?php echo json_encode($_SESSION['department'] ?? ''); ?>;

                    window.userStatusPagination = new BonsaiPagination({
                        apiUrl: '../../api/search_elasticsearch.php',
                        target: 'employee_status',
                        tableSelector: '#employeesTable',
                        tbodySelector: '#userStatusTbody',
                        searchInputSelector: '#esSearchInput',
                        clearBtnSelector: '#esClearBtn',
                        paginationContainerSelector: '#userStatusPaginationContainer',
                        infoContainerSelector: '#userStatusInfoContainer',
                        limitSelector: '#userStatusPageLimit',
                        filters: {
                            department: deptName
                        },
                        filterSelectors: {
                            employee_status: '#filterUserEmpStatus'
                        },
                        defaultLimit: 10,
                        renderRow: function(item, index, rowNum) {
                            if (!item.appointment_number || item.appointment_number === '-') return '';
                            const empStatus = (item.employee_status || 'active').toLowerCase();
                            const statusBadge = empStatus === 'active'
                                ? '<span class="badge-status badge-success">ACTIVE</span>'
                                : '<span class="badge-status badge-danger">RESIGNED</span>';
                            
                            const compType = item.competency_type || '';
                            const typeClass = compType.toLowerCase().replace(/ /g, '_');
                            const compBadge = compType ? `<span class="competency-type-badge competency-${typeClass}">${compType}</span>` : '-';
                            const compName = item.competency_name ? `<span class="competency-tag">${item.competency_name}</span>` : '<span class="text-muted">-</span>';
                            const empCompany = item.contractor_company || item.department || '-';
                            const apptNo = item.appointment_number || '-';
                            const fullName = item.full_name || '-';
                            const empCode = item.employee_code || '-';
                            const pos = item.position || '-';

                            let actionBtn = '';
                            if (empStatus === 'active') {
                                actionBtn = `<button type="button" class="btn btn-danger btn-sm resign-btn"
                                                data-id="${item.id}"
                                                data-name="${fullName.replace(/"/g, '&quot;')}"
                                                data-company="${empCompany.replace(/"/g, '&quot;')}"
                                                data-appointment="${apptNo.replace(/"/g, '&quot;')}">
                                                <i class="fas fa-user-times"></i> Resign
                                             </button>`;
                            } else {
                                actionBtn = `<a href="employee_status_detail.php?id=${item.id}" class="btn btn-secondary btn-sm detail-btn">
                                                <i class="fas fa-eye"></i> Detail
                                             </a>`;
                            }

                            return `<tr class="emp-row" data-company="${empCompany.replace(/"/g, '&quot;')}" data-status="${empStatus}">
                                <td class="col-code"><span class="code-badge">${empCode}</span></td>
                                <td class="col-name"><strong>${fullName}</strong></td>
                                <td class="col-position"><span class="position-tag-emp">${pos}</span></td>
                                <td class="col-company"><span class="company-tag-emp">${empCompany}</span></td>
                                <td class="col-competency-type">${compBadge}</td>
                                <td class="col-competency">${compName}</td>
                                <td class="col-status">${apptNo}</td>
                                <td>${statusBadge}</td>
                                <td class="col-action text-center">${actionBtn}</td>
                            </tr>`;
                        }
                    });

                    window.userStatusPagination.filters['department'] = deptName;

                    const origInfo = window.userStatusPagination.renderInfo.bind(window.userStatusPagination);
                    window.userStatusPagination.renderInfo = function() {
                        origInfo();
                        const src = document.querySelector('#userStatusInfoContainer');
                        const dest = document.querySelector('#userStatusInfoContainerTable');
                        if (src && dest) dest.innerHTML = src.innerHTML;
                    };
                })();
                </script>
            <?php else: ?>
                <div class="empty-state-emp">
                    <i class="fas fa-inbox"></i>
                    <p data-lang="no-workforce-data">No workforce data yet</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>


<div class="modal fade" id="employeeStatusModal">

    <div class="modal-dialog">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">

                    Employee Resignation

                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal">
                </button>

            </div>

            <div class="modal-body">

                <input type="hidden" id="employee_id">

                <div class="mb-3">

                    <label>Employee</label>

                    <input
                        type="text"
                        id="employee_name"
                        class="form-control"
                        readonly>

                </div>

                <div class="mb-3">

                    <label>Company</label>

                    <input
                        type="text"
                        id="employee_company"
                        class="form-control"
                        readonly>

                </div>

                <div class="mb-3">

                    <label>Appointment Number</label>

                    <input
                        type="text"
                        id="appointment_number"
                        class="form-control"
                        readonly>

                </div>

                <div class="mb-3">

                    <label>Resign Date</label>

                    <input
                        type="date"
                        id="resign_date"
                        class="form-control">

                </div>

                <div class="mb-3">

                    <label>Resign Reason</label>

                    <textarea
                        id="resign_reason"
                        rows="4"
                        class="form-control"></textarea>

                </div>

            </div>

            <div class="modal-footer">

                <button
                    class="btn btn-secondary"
                    data-bs-dismiss="modal">

                    Cancel

                </button>

                <button
                    class="btn btn-danger"
                    id="saveStatusBtn">

                    Confirm Resign

                </button>

            </div>

        </div>

    </div>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    // Event Delegation for .resign-btn (works across all zoom levels and DataTables redraws)
    document.addEventListener("click", function(e) {
        const button = e.target.closest(".resign-btn");
        if (button) {
            e.preventDefault();
            e.stopPropagation();

            document.getElementById("employee_id").value = button.dataset.id || "";
            document.getElementById("employee_name").value = button.dataset.name || "";
            document.getElementById("employee_company").value = button.dataset.company || "";
            document.getElementById("appointment_number").value = button.dataset.appointment || "";

            document.getElementById("resign_date").value = "";
            document.getElementById("resign_reason").value = "";

            const modalEl = document.getElementById("employeeStatusModal");
            if (modalEl) {
                const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                modal.show();
            }
        }
    });

    const saveBtn = document.getElementById("saveStatusBtn");
    if (saveBtn) {
        saveBtn.addEventListener("click", function(){
            const employeeId = document.getElementById("employee_id").value;
            const resignDate = document.getElementById("resign_date").value;
            const resignReason = document.getElementById("resign_reason").value.trim();

            if (!resignDate) {
                alert("Please select resign date.");
                return;
            }

            if (!resignReason) {
                alert("Please enter resign reason.");
                return;
            }

            if (!confirm("Are you sure this employee has resigned?")) {
                return;
            }

            const formData = new FormData();
            formData.append("action", "resign_employee");
            formData.append("employee_id", employeeId);
            formData.append("resign_date", resignDate);
            formData.append("resign_reason", resignReason);

            fetch(window.location.href, {
                method: "POST",
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    alert("Employee status updated to Resigned!");
                    location.reload();
                } else {
                    alert("Failed to update status.");
                }
            })
            .catch(err => {
                alert("An error occurred. Please try again.");
            });
        });
    }

}); 
</script>



<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
