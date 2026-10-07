<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once dirname(__DIR__, 2) . '/app/Helpers/auth_helper.php';
require_once dirname(__DIR__, 2) . '/app/Helpers/SuperadminReportsHelper.php';

requirePermission('admin.access');
requirePermission('reports.view');

if (!isSuperadmin()) {
    die('Unauthorized');
}

$db = new Database();
$helper = new SuperadminReportsHelper($db);

$filters = [
    'scope' => $_GET['scope'] ?? '',
    'competency_type' => $_GET['competency_type'] ?? '',
    'department' => $_GET['department'] ?? '',
    'status' => $_GET['status'] ?? '',
    'date_start' => $_GET['date_start'] ?? '',
    'date_end' => $_GET['date_end'] ?? ''
];

$format = $_GET['format'] ?? 'excel';

$detailData = $helper->getAppointmentDetails($filters);

$title = "Laporan Detail Appointment STELA";
$filename = "STELA_Reports_" . date('Ymd_His');

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($title ?? "", ENT_QUOTES, "UTF-8") ?></title>
    <style>
        body { font-family: sans-serif; font-size: 10pt; }
        h2 { text-align: center; margin-bottom: 5px; }
        .filter-info { text-align: center; font-size: 9pt; color: #555; margin-bottom: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        th, td { border: 1px solid #000; text-align: left; padding: 6px; }
        th { background-color: #f2f2f2; font-weight: bold; text-align: center; }
        .text-center { text-align: center; }
    </style>
</head>
<body>
    <h2><?= htmlspecialchars($title ?? "", ENT_QUOTES, "UTF-8") ?></h2>
    <div class="filter-info">
        Waktu Ekspor: <?= date('d M Y H:i:s') ?><br>
        Filter: Scope (<?= htmlspecialchars($filters['scope'] ?: 'Semua', ENT_QUOTES, 'UTF-8') ?>) | Tipe (<?= htmlspecialchars($filters['competency_type'] ?: 'Semua', ENT_QUOTES, 'UTF-8') ?>) | Dept (<?= htmlspecialchars($filters['department'] ?: 'Semua', ENT_QUOTES, 'UTF-8') ?>) | Status (<?= htmlspecialchars($filters['status'] ?: 'Semua', ENT_QUOTES, 'UTF-8') ?>)
    </div>
    
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Scope</th>
                <th>Tipe Kompetensi</th>
                <th>No. Appointment</th>
                <th>Nama Karyawan</th>
                <th>Perusahaan</th>
                <th>Departemen</th>
                <th>Kompetensi</th>
                <th>Status</th>
                <th>KTT</th>
                <th>Action By</th>
                <th>Tanggal & Waktu</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($detailData)): ?>
                <tr><td colspan="13" class="text-center">Tidak ada data ditemukan.</td></tr>
            <?php else: ?>
                <?php $i=1; foreach($detailData as $row): 
                    $ctypeLabel = $row['competency_type'];
                    if ($ctypeLabel == 'tenaga_teknis') $ctypeLabel = 'Tenaga Teknis';
                    elseif ($ctypeLabel == 'pengawas_teknis') $ctypeLabel = 'Pengawas Teknis';
                    elseif ($ctypeLabel == 'pengawas_operasional') $ctypeLabel = 'Pengawas Operasional';
                ?>
                    <tr>
                        <td class="text-center"><?= $i++ ?></td>
                        <td class="text-center"><?= htmlspecialchars($row['scope_of_work']) ?></td>
                        <td class="text-center"><?= htmlspecialchars($ctypeLabel) ?></td>
                        <td><?= htmlspecialchars($row['appointment_number']) ?></td>
                        <td><?= htmlspecialchars($row['employee_name']) ?></td>
                        <td><?= htmlspecialchars($row['company']) ?></td>
                        <td><?= htmlspecialchars($row['department']) ?></td>
                        <td><?= htmlspecialchars($row['competency'] ?: '-') ?></td>
                        <td class="text-center"><?= strtoupper(str_replace('_', ' ', $row['status'])) ?></td>
                        <td><?= htmlspecialchars($row['ktt_name'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($row['action_by'] ?: '-') ?></td>
                        <td class="text-center"><?= $row['action_datetime'] ? date('d-M-Y H:i:s', strtotime($row['action_datetime'])) : '-' ?></td>
                        <td><?= htmlspecialchars($row['notes'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
<?php
$html = ob_get_clean();

if ($format === 'pdf') {
    $vendorAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (file_exists($vendorAutoload)) {
        require_once $vendorAutoload;
        if (class_exists('\Mpdf\Mpdf')) {
            try {
                $mpdf = new \Mpdf\Mpdf(['orientation' => 'L', 'format' => 'A4']);
                $mpdf->WriteHTML($html);
                $mpdf->Output($filename . '.pdf', \Mpdf\Output\Destination::INLINE);
                exit;
            } catch (Exception $e) {
                // If MPDF fails, fallback to browser print view
            }
        }
    }
    
    // Fallback: Show HTML and auto print
    echo $html;
    echo "<script>window.onload = function() { window.print(); }</script>";
    exit;

} else {
    // Excel Download
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename.xls\"");
    header("Pragma: no-cache");
    header("Expires: 0");
    echo $html;
    exit;
}
