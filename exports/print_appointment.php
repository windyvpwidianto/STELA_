<?php
require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once dirname(__DIR__) . '/app/Helpers/auth_helper.php';

if (!isset($_GET['id'])) {
    die('ID tidak valid');
}

$id = intval($_GET['id']);
$db = new Database();

$appointment = $db->query("
    SELECT a.*, 
           e.full_name as employee_name, 
           e.employee_code, 
           e.id_number, 
           e.position, 
           e.contractor_company, 
           e.department,
           e.signature_file,
           e.competency_type,
           e.competency_name,
           e.ruang_lingkup,
           e.supervision_area,
           u.full_name as approved_by_name
    FROM appointments a
    JOIN employees e ON a.employee_id = e.id
    LEFT JOIN users u ON a.approved_by = u.id
    WHERE a.id = ?
", [$id], "i")->fetch_assoc();

if (!$appointment) {
    die('Data tidak ditemukan');
}

// [SECURITY] Authorization check
if (!isSuperadmin() && !isAdmin() && !isKTT()) {
    $user_role = $_SESSION['role'] ?? '';
    $user_company = $_SESSION['company_name'] ?? '';
    $user_dept = $_SESSION['department'] ?? '';

    $isAllowed = false;
    if ($user_role === 'user' && !empty($user_company) && ($appointment['contractor_company'] ?? '') === $user_company) {
        $isAllowed = true;
    } elseif (($user_role === 'department_user' || $user_role === 'dept') && !empty($user_dept) && ($appointment['department'] ?? '') === $user_dept) {
        $isAllowed = true;
    }

    if (!$isAllowed) {
        http_response_code(403);
        die('Akses ditolak: Anda tidak memiliki izin untuk melihat dokumen ini.');
    }
}

// Get all certificate numbers for this employee
$cert_numbers_result = $db->query("
    SELECT cert_number 
    FROM employee_certifications 
    WHERE employee_id = " . intval($appointment['employee_id']) . " 
    ORDER BY id
");

$cert_numbers_array = [];
if ($cert_numbers_result && $cert_numbers_result->num_rows > 0) {
    while ($cert_row = $cert_numbers_result->fetch_assoc()) {
        if (!empty($cert_row['cert_number'])) {
            $cert_numbers_array[] = $cert_row['cert_number'];
        }
    }
}
$appointment['cert_numbers'] = !empty($cert_numbers_array) ? implode(', ', $cert_numbers_array) : '';

if (!$appointment) {
    die('Data tidak ditemukan');
}

// Check if both KTTs have approved (for digital signatures)
$ktt_approvals_check = $db->query("
    SELECT COUNT(*) as total_approvals,
           SUM(CASE WHEN action = 'approve' THEN 1 ELSE 0 END) as approved_count
    FROM ktt_approvals 
    WHERE appointment_id = $id
")->fetch_assoc();

$both_ktt_approved = ($appointment['status'] == 'approved' && 
                      $ktt_approvals_check['approved_count'] >= 2);

// Function to determine document code based on competency type
function getDocumentCode($competency_type) {
    if ($competency_type == 'pengawas_operasional') {
        return 'TT-MGT-FRS-008A';
    } elseif ($competency_type == 'pengawas_teknis') {
        return 'TT-MGT-FRS-008B';
    } elseif ($competency_type == 'tenaga_teknis') {
        return 'TT-MGT-FRS-008C';
    }
    return 'TT-MGT-FRS-008C';
}

// Function to get header title based on competency type
function getHeaderTitle($competency_type) {
    if ($competency_type == 'pengawas_operasional') {
        return 'Pengawas Operasional Pertambangan';
    } elseif ($competency_type == 'pengawas_teknis') {
        return 'Pengawas Teknis';
    } elseif ($competency_type == 'tenaga_teknis') {
        return 'Tenaga Teknis Berkompeten';
    }
    return 'Tenaga Teknis Berkompeten';
}

// Function to get letter content based on competency type and name
function getLetterContent($competency_type, $competency_name, $appointment) {
    $competency_type = strtolower(trim((string) ($competency_type ?? '')));
    $competency_name = strtolower(trim((string) ($competency_name ?? '')));

    // Normalize separators to make name matching deterministic.
    $competency_name = str_replace(['/', '-', '_', '.'], ' ', $competency_name);
    $competency_name = preg_replace('/\s+/', ' ', $competency_name);

    $templatesByType = [
        'pengawas_operasional' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan Kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Pengawas Operasional Pertambangan.</p>',
            'responsibilities' => [
                'Bertanggung jawab kepada KTT untuk keselamatan dan kesehatan semua pekerja tambang yang beraktivitas di lingkup kerjanya;',
                'Melaksanakan inspeksi, pemeriksaan, dan pengujian;',
                'Bertanggung jawab atas keselamatan, kesehatan, dan kesejahteraan dari semua orang yang ditugaskan kepadanya;',
                'Membuat dan menandatangani laporan pemeriksaan, inspeksi, dan pengujian;',
                'Menerapkan Sistem Manajemen Keselamatan Pertambangan;',
                'Mengidentifikasi semua bahaya, menilai, dan mengendalikan risikonya secara tepat dan konsisten di lingkup kerjanya;',
                'Memastikan semua aktivitas berisiko telah memiliki prosedur kerja yang memadai, tersosialisasi, dan diterapkan dengan baik oleh pekerja;',
                'Memastikan semua pekerja telah memiliki kompetensi yang memadai dan diberikan akses untuk bertanya jika kurang paham akan suatu tugas;',
                'Dapat menghentikan suatu pekerjaan jika dianggap berpotensi menimbulkan insiden dan harus segera ditindaklanjuti;'
            ]
        ],
        'pengawas_teknis' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Pengawas Teknis.</p>',
            'responsibilities' => [
                'Bertanggung jawab kepada KTT/PTL untuk keselamatan pemasangan dan pekerjaan serta pemeliharaan yang benar semua Sarana, Prasarana, Instalasi, dan Peralatan Pertambangan (SPIP) yang menjadi tugasnya;',
                'Merencanakan dan menekankan dilaksanakannya jadwal pemeliharaan yang telah direncanakan serta semua perbaikan Sarana, Prasarana, Instalasi, dan Peralatan pertambangan (SPIP) yang dipergunakan.',
                'Mengawasi dan memeriksa semua Sarana, Prasarana, Instalasi, dan Peralatan pertambangan (SPIP) dalam ruang lingkup yang menjadi tanggung jawabnya;',
                'Menjamin bahwa selalu dilaksanakan penyelidikan, pemeriksaan, dan pengujian Sarana, Prasarana, Instalasi, dan Peralatan Pertambangan (SPIP);',
                'Melaksanakan penyelidikan, pemeriksaan, dan pengujian Sarana, Prasarana, Instalasi, dan Peralatan pertambangan (SPIP) sebelum digunakan, setelah dipasang kembali, dan/atau diperbaiki; dan',
                'Membuat dan menandatangani laporan dari penyelidikan, pemeriksaan, dan pengujian Sarana, Prasarana, Instalasi, dan Peralatan pertambangan (SPIP);'
            ]
        ],
        'tenaga_teknis' => [
            'introduction' => '<p>Berdasarkan Peraturan Menteri ESDM dan standar kompetensi nasional, terkait penunjukan Tenaga Teknis Berkompeten.</p>',
            'responsibilities' => [
                'Melaksanakan tugas dan tanggung jawab sesuai dengan kompetensi yang dimiliki;',
                'Menerapkan prosedur keselamatan dan kesehatan kerja dalam pelaksanaan tugas;',
                'Berkoordinasi dengan atasan dan rekan kerja terkait pelaksanaan tugas;',
                'Membuat laporan pelaksanaan tugas secara berkala;',
                'Mematuhi seluruh peraturan dan tata tertib perusahaan;'
            ]
        ]
    ];

    $templatesByCompetencyKeywords = [
        'juru las' => [
            'introduction' => '<p>Berdasarkan peraturan tentang Keselamatan dan Kesehatan Kerja Bidang Pengelasan, terkait penunjukan Juru Las/Welder.</p>',
            'responsibilities' => [
                'Melaksanakan pekerjaan pengelasan sesuai dengan spesifikasi teknis dan standar keselamatan yang berlaku;',
                'Melakukan pemeriksaan visual terhadap hasil pengelasan sebelum diserahkan;',
                'Melakukan perawatan dan pemeliharaan peralatan las yang digunakan;',
                'Menerapkan prosedur K3 (Keselamatan dan Kesehatan Kerja) dalam setiap pekerjaan pengelasan;',
                'Membuat laporan hasil pekerjaan pengelasan yang telah dilaksanakan;',
                'Memastikan area kerja aman dari bahaya kebakaran dan ledakan;'
            ]
        ],
        'juru ledak' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Bertanggung jawab untuk merancang pola pengeboran dan rencana ikatan ledakan.',
                'Bertanggung jawab atas catatan masalah bahan peledak harian, menjaga stok bahan peledak dan pembelian bahan peledak.',
                'Bertanggung jawab untuk menjaga area Magazine serta administrasinya.',
                'Bertanggung jawab untuk merekam kedalaman lubang bor yang sebenarnya, mengukur lubang ledakan basah/kering dan menyesuaikan dengan rencana.',
                'Bertanggung jawab untuk optimalisasi pengeboran dan ledakan dengan mengelola faktor bubuk dan pemilihan area pengeboran berdasarkan jenis batuan.',
                'Memastikan supervisor kontraktor memiliki rencana pengeboran dan rencana pengikatan saat ini di setiap lokasi pengeboran dan peledakan serta kepatuhan kontraktor dengan rencana pengeboran dan peledakan.',
                'Mengelola sistem pengarsipan untuk data dan desain bor dan ledakan.',
                'Mengatur tim Bor dan Ledakan',
                'Menetapkan geometri dan dimensi pengeboran dan pola peledakan.',
                'Menetapkan daerah bahaya peledakan, meledakan lubang ledak, menangani kegagalan peledakan, menyambung sirkit peledakan ke sirkit detonator.',
                'Mengendalikan akibat peledakan dan memastikan hasil peledakan.'
            ]
        ],
        'juru bor' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Mengoperasikan alat bor sesuai prosedur yang aman dan efisien.',
                'Melakukan pemeriksaan rutin terhadap kondisi alat bor dan melakukan pemeliharaan ringan.',
                'Mengatur beban dan arah pengeboran untuk memastikan hasil yang sesuai dengan rencana.',
                'Mematuhi prosedur keselamatan kerja (K3) dan menggunakan alat pelindung diri (APD).',
                'Melaporkan kejadian atau kecelakaan yang terjadi selama pekerjaan.',
                'Berkoordinasi dengan tim kerja dan pengawas untuk memastikan kelancaran operasi.',
                'Mengelola dokumentasi pekerjaan pengeboran dan kondisi alat.',
                'Memastikan kualitas pengeboran sesuai dengan spesifikasi yang ditetapkan.',
                'Pengendalian dan pencegahan bahaya selama proses pengeboran.'
            ]
        ],
        'rigger' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Melaksanakan identifikasi potensi bahaya pengikatan benda kerja dan alat bantu angkat dan angkut;',
                'Mengidentifikasi Beban dengan melakukan pemilihan alat bantu angkat dan angkut serta alat kelengkapannya sesuai dengan kapasitas beban kerja aman;',
                'Menyiapkan dan Memeriksa Peralatan Pengikatan, memastikan semua peralatan seperti alat bantu angkat dan angkut serta alat kelengkapan yang digunakan dalam kondisi baik dan sesuai standar sebelum digunakan;',
                'Melakukan Pengikatan yang Benar, mengikat beban sesuai dengan prosedur dan teknik yang benar untuk mencegah pergeseran atau jatuhnya beban saat pengangkatan;',
                'Berkomunikasi dengan Operator, memberikan sinyal atau instruksi kepada operator pesawat angkat selama proses pengangkatan dan penurunan beban;',
                'Melakukan perawatan alat bantu angkat dan angkut serta alat kelengkapannya.'
            ]
        ],
        'juru ukur' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Melaksanakan survei dan pemetaan rencana dan kemajuan kegiatan eksplorasi, konstruksi, pemasangan Tanda Batas dan penambangan.',
                'Melaksanakan survei dan pemetaan untuk identifikasi area yang memiliki potensi bahaya serta pemantauannya;',
                'Melaksanakan evaluasi, pemutakhiran, dan pengelolaan peta rencana dan kemajuan kegiatan pertambangan;',
                'Mencatat dan mengevaluasi hasil pengukuran yang telah dilakukan sehingga dapat meminimalisir kesalahan dan melakukan tindak koreksi dan pencegahannya;',
                'Melaksanakan staking out, penetapan elevasi sesuai dengan gambar rencana;',
                'Mengawasi survei lapangan yang dilakukan kontraktor untuk memastikan pengukuran dilaksanakan dengan prosedur yang benar dan menjamin data yang diperoleh akurat sesuai dengan kondisi lapangan untuk keperluan peninjauan desain atau detail desain.'
            ]
        ],
        'ahli eksplorasi' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Memastikan seluruh kegiatan eksplorasi dilaksanakan sesuai dengan aturan K3 perusahaan yang berlaku;',
                'Memastikan kegiatan eksplorasi dilaksanakan secara tepat mulai dari pemetaan geologi, penyelidikan geofisika, penyelidikan geokimia, pembuatan parit uji, pembuatan sumur uji, pengeboran, pengambilan sampling atau conto, analisis conto;',
                'Melaksankan survei contoh dan mengkomunikasikan pengelolaan hasil eksplorasi ke atasan;',
                'Mengkomunikasikan dengan atasan terkait kendala-kendala apa saja yang terjadi selama proses eksplorasi;',
                'Membuat laporan hasil eksplorasi yang sudah dilakukan;'
            ]
        ],
        'petugas industrial hygiene' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Melakukan pekerjaan higiene industri secara profesional yang sesuai dengan kode etik profesi.',
                'Melaksanakan peraturan perundang-undangan Republik Indonesia di bidang K3 yang berkaitan dengan higiene industri.',
                'Melaksanakan program higiene industri.',
                'Mengantisipasi dan mengenal risiko kesehatan kerja pada saat fase operasi, maintenance dan gawat darurat.',
                'Melakukan promosi kesehatan tentang pengetahuan bahaya risiko kesehatan di industri.',
                'Melakukan penerapan sistem informasi higiene industri.',
                'Melakukan pengukuran risiko kesehatan kerja di tempat kerja dengan teknik pengumpulan sampel yang benar.',
                'Mengikuti perubahan dan kemajuan di bidang profesi higiene industri untuk meningkatkan kompetensinya.'
            ]
        ],
        'petugas p3k' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Melaksanakan tindakan pertolongan pertama pada kecelakaan.',
                'Menindaklanjuti proses pertolongan lanjutan jika dibutuhkan.',
                'Inspeksi rutin, merawat dan memastikan ketersediaan isi kotak P3K di area kerja.',
                'Mencatat setiap kegiatan pertolongan pertama dalam buku kegiatan.',
                'Melaporkan kegiatan pertolongan pertama kepada pimpinan departemen dan fasilitas kesehatan lanjutan.',
                'Terlibat dalam kegiatan Investigasi kecelakaan kerja (memberikan informasi).'
            ]
        ],
        'juru listrik' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Melindungi keselamatan dan kesehatan tenaga kerja dan orang lain yang berada di dalam lingkungan tempat kerja dari potensi bahaya listrik;',
                'Menciptakan instalasi listrik yang aman, andal, dan memberikan keselamatan bangunan beserta isinya;',
                'Membuat rencana pemeriksaan, pengujian, pemeliharaan, dan perawatan instalasi listrik untuk menjamin instalasi beroperasi dengan aman;',
                'Merancang hubungan pembumian utama dari sistem pembumian;',
                'Membuat rencana penyakelaran yang aman;',
                'Melakukan pemeriksaan sistem pembumian paling sedikit 1 kali setiap 6 bulan.'
            ]
        ],
        'petugas proteksi radiasi' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Menerapkan persyaratan proteksi radiasi yang meliputi: justifikasi, limitasi dosis, optimasi proteksi, dan keselamatan radiasi sesuai peraturan yang berlaku.',
                'Memastikan tersedianya fasilitas dan/atau peralatan yang sesuai dengan sifat dan risiko pemanfaat radiasi yang ada di area kerja.',
                'Memastikan tersedianya perlengkapan proteksi radiasi jika diperlukan sesuai risiko di area kerja.',
                'Menetapkan prosedur aman yang bisa melindungi pekerja dari paparan radiasi.',
                'Memastikan tersedianya rambu keselamatan sesuai risiko.',
                'Melakukan analisa terhadap pekerja (termasuk dirinya) dengan risiko terpapar agar memiliki kompetensi yang cukup untuk bekerja secara aman.',
                'Memastikan daftar dan jadwal atau rencana pemeriksaan secara teratur terhadap alat-alat yang relevan dengan pekerjaan radiasi.',
                'Melakukan uji coba terhadap peralatan, sarana, prasarana, yang berhubungan dengan, atau terpapar dengan risiko radiasi.'
            ]
        ],
        'petugas bahan kimia' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Menjalankan peraturan dan undang-undang tentang K3 bidang kimia di lingkungan kerja yang kondusif.',
                'Melakukan proses identifikasi secara terperinci dan bermuara pada kegiatan evaluasi, kemudian melakukan beragam pengendalian terhadap potensi bahaya dalam rangka menyimpan, penggunaan dan distribusi bahan kimia sesuai K3 yang berlaku.',
                'Mampu menjalankan cara kerja aman dalam penanganan bahan kimia yang berbahaya sehingga mengurangi risiko yang tinggi terjadinya beragam kecelakaan di kawasan industri kimia.',
                'Mampu menerapkan metode pengukuran terhadap bahan-bahan kimia yang sesuai dengan kapasitas di kawasan lingkungan kerja.',
                'Mampu melaksanakan beragam pengendalian bahaya demi terciptanya lingkungan kerja yang aman dan jauh dari bahaya selama berada di lingkungan kerja.'
            ]
        ],
        'petugas perencanaan tambang' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Kepatuhan penuh terhadap peraturan pemerintah dan PT Meares Soputan Mining, Kesehatan Keselamatan dan Lingkungan dalam semua kegiatan.',
                'Membuat jadwal untuk perencanaan tambang mingguan dan bulanan serta pengembangan tempat pembuangan, termasuk strategi pengelolaan air dan sedimen.',
                'Memproduksi rencana anggaran tambang tahunan dan prakiraan triwulanan yang sedang berlangsung, termasuk jadwal modal dan biaya operasi untuk operasi penambangan.',
                'Mengembangkan rencana umur tambang dan analisis biaya untuk pengembangan model ekonomi untuk analisis keuangan. Model ekonomi digunakan untuk menghasilkan cangkang lubang tambang yang dioptimalkan, desain tambang, dan pembuatan strategi tingkat batas untuk operasi penambangan emas.',
                'Pengembangan rencana manajemen konstruksi untuk proyek konstruksi tambang.'
            ]
        ],
        'pengolahan mineral' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Mengelola kinerja operasi pabrik proses. Memastikan target produksi dan keselamatan terpenuhi atau terlampaui melalui operasi yang efisien. Berkoordinasi dengan personel pemeliharaan untuk melaksanakan pemeliharaan terencana dan memperbaiki kerusakan peralatan secara cepat.',
                'Optimalisasi Pabrik Proses untuk merancang kapasitas, untuk mencapai anggaran minimum dan target KPI.',
                'Fasilitas Penyimpanan Tailing (TSF), pengelolaan air, dan pemusnahan sianida: Mengelola TSF untuk meminimalkan risiko jangka pendek, menengah dan panjang bagi perusahaan. Memastikan kecukupan pasokan air proses dan air baku untuk mencapai target produksi.',
                'Manajemen operasi pabrik tetap bergerak. Pastikan bahwa peralatan pabrik bergerak dan tetap dioperasikan dan dipelihara dalam kondisi optimal.',
                'Manajemen penyedia layanan kontrak. Mengembangkan perjanjian layanan dan ruang lingkup pekerjaan untuk secara ringkas menguraikan ruang lingkup pekerjaan konsultan termasuk waktu dan hasil kerja.',
                'Masukan ke dalam rencana anggaran produksi tahunan dan prakiraan triwulanan yang sedang berjalan, termasuk tingkat penggunaan reagen/konsumsi, produksi dan data pemulihan. Rencana ringkas untuk mencapai produksi pelaku sesuai kebutuhan dan memenuhi kriteria EMP dan SMS.',
                'Mengembangkan Permintaan Belanja Modal. Berikan analisis singkat tentang permintaan belanja modal dengan analisis manfaat dan rekomendasi untuk pembelian.'
            ]
        ],
        'petugas pemadam kebakaran' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Bertanggung jawab kepada Pengawas Teknis dan/atau Kepala Teknik Tambang (KTT) atas keselamatan pelaksanaan kegiatan penanggulangan kebakaran sesuai ketentuan peraturan perundang-undangan;',
                'Melaksanakan kegiatan pencegahan, pengendalian, dan penanggulangan kebakaran di area pertambangan sesuai prosedur yang berlaku;',
                'Menjamin dilaksanakannya kesiapsiagaan peralatan pemadam kebakaran dalam kondisi laik operasi dan siap digunakan setiap saat;',
                'Melaksanakan pemeriksaan, pemeliharaan, dan pengujian terhadap peralatan pemadam kebakaran secara berkala;',
                'Mengidentifikasi potensi bahaya kebakaran serta melaksanakan tindakan pencegahan sesuai dengan standar keselamatan kerja;',
                'Melaksanakan tindakan pemadaman kebakaran dan/atau penanganan keadaan darurat secara cepat, tepat, dan aman;',
                'Menjamin dilaksanakannya pengamanan area terdampak kebakaran untuk mencegah meluasnya bahaya dan melindungi pekerja, peralatan, serta lingkungan;',
                'Menghentikan kegiatan di area terdampak apabila ditemukan kondisi yang berpotensi menimbulkan kebakaran atau membahayakan keselamatan;',
                'Melakukan koordinasi dengan tim tanggap darurat lainnya dalam penanganan kebakaran dan keadaan darurat;',
                'Membuat dan menyampaikan laporan pelaksanaan kegiatan pencegahan dan penanggulangan kebakaran, termasuk setiap kejadian kebakaran dan/atau kondisi tidak normal yang terjadi.'
            ]
        ],
        'petugas ventilasi' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Bertanggung jawab kepada Pengawas Teknis dan/atau Kepala Teknik Tambang (KTT) atas keselamatan pelaksanaan sistem ventilasi tambang sesuai ketentuan peraturan perundang-undangan;',
                'Melaksanakan pengelolaan sistem ventilasi tambang sesuai dengan rencana teknis ventilasi yang telah ditetapkan;',
                'Menjamin dilaksanakannya penyediaan udara segar yang cukup serta pengenceran dan pengeluaran gas, debu, dan kontaminan lainnya dari area kerja;',
                'Melaksanakan pemantauan kualitas dan kuantitas udara tambang, meliputi aliran udara, kandungan gas, suhu, dan kelembaban;',
                'Menjamin dilaksanakannya pemeriksaan, pengujian, dan pemeliharaan sarana dan prasarana ventilasi, termasuk kipas, ducting, regulator, dan alat ukur;',
                'Mengidentifikasi potensi bahaya yang berkaitan dengan ventilasi, termasuk akumulasi gas berbahaya, kekurangan oksigen, dan penyebaran debu;',
                'Menghentikan kegiatan pada area tertentu apabila kondisi ventilasi tidak memenuhi persyaratan keselamatan;',
                'Memberikan rekomendasi teknis kepada Pengawas Teknis terkait perbaikan dan pengendalian sistem ventilasi;',
                'Berkoordinasi dengan unit kerja terkait dalam rangka menjamin efektivitas sistem ventilasi tambang;',
                'Membuat dan menyampaikan laporan hasil pemantauan, pemeriksaan, dan evaluasi sistem ventilasi secara berkala.'
            ]
        ],
        'juru derek' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Bertanggung jawab kepada Pengawas Teknis dan/atau Kepala Teknik Tambang (KTT) atas keselamatan pelaksanaan kegiatan pengangkatan dan pemindahan beban serta penggunaan peralatan angkat sesuai ketentuan peraturan perundang-undangan;',
                'Melaksanakan kegiatan pengikatan, pengangkatan, dan pemindahan beban sesuai dengan rencana kerja dan prosedur yang telah ditetapkan;',
                'Menjamin dilaksanakannya penggunaan alat angkat dan alat bantu angkat (rigging) sesuai standar operasional prosedur serta dalam kondisi laik operasi;',
                'Melaksanakan pemeriksaan terhadap peralatan angkat dan alat bantu angkat sebelum digunakan, termasuk sling, shackle, hook, dan perlengkapan lainnya;',
                'Mengatur dan memberikan isyarat kepada operator alat angkat dalam pelaksanaan pengangkatan dan pemindahan beban;',
                'Mengawasi dan menjamin kestabilan serta keamanan beban selama proses pengangkatan, pemindahan, dan penurunan;',
                'Menjamin dilaksanakannya pengamanan area kerja pengangkatan untuk mencegah potensi bahaya terhadap pekerja dan peralatan;',
                'Menghentikan kegiatan pengangkatan apabila ditemukan kondisi tidak aman dan/atau peralatan tidak laik operasi sesuai kewenangannya;',
                'Membuat dan menyampaikan laporan pelaksanaan kegiatan pengangkatan, termasuk apabila terjadi penyimpangan dan/atau gangguan selama kegiatan berlangsung.'
            ]
        ],
        'ahli geologi' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Melaksanakan kegiatan eksplorasi dan pemetaan geologi.',
                'Melakukan estimasi sumber daya dan cadangan.',
                'Menyusun model geologi dan distribusi material.',
                'Melakukan pengendalian kadar (grade control) pada kegiatan produksi.',
                'Menyusun laporan geologi secara berkala.',
            ]
        ],
        'ahli penambangan' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Menyusun perencanaan tambang (mine plan) jangka pendek, menengah, dan Panjang.',
                'Membuat desain teknis tambang meliputi pit, lereng, jalan angkut, disposal, dan drainase.',
                'Mengendalikan kegiatan pemboran, peledakan, penggalian, pemuatan, dan pengangkutan.',
                'Mengawasi pencapaian target produksi sesuai rencana.',
                'Melakukan pengendalian kualiatas material (ore/coal dan waste).',
                'Mengelola system dewatering dan kondisi tambang agar tetap operasional.',
                'Melakukan evaluasi kinerja alat dan efisiensi operasional.',
                'Menyusun laporan kegiatan operasional secara berkala.'
            ]
        ],
        'ahli pengolahan' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Mengoperasikan dan mengendalikan fasilitas pengolahan/pemurnian.',
                'Mengatur proses crushing, screening, washing, atau proses lainnya.',
                'Mengontrol kualitas produk dan tingkat perolehan (recovery).',
                'Melakukan optimasi proses pengolahan.',
                'Mengelola limbah hasil pengolahan.'
            ]
        ],
        'dokter' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Memberikan pelayanan kesehatan dasar kepada seluruh pekerja tambang.',
                'Memberikan tindakan medis sesuai dengan kompetensi kedokteran yang dimiliki.',
                'Menentukan status fit/unit to work untuk pekerja yang sakit atau cedera.',
                'Melaksanakan penanganan medis pada kecelakaan kerja (trauma, luka berat, dll).',
                'Mengurangi risiko fatalitas kecelakaan kerja dengan memastikan langkah-langkah medis tepat waktu.',
                'Merujuk pekerja ke fasilitas kesehatan lanjutan jika diperlukan.',
                'Mengelola klinik perusahaan, termasuk pengelolaan obat dan alat kesehatan.',
                'Memberikan edukasi kesehatan untuk pekerja terkait pencegahan penyakit kerja.',
                'Menjamin pelayanan medis berjalan secara profesional dan sesuai standar kedokteran yang berlaku.',
                'Penanganan medis dilakukan dengan cepat dan tepat sesuai kondisi kesehatan pekerja.',
                'Menjaga kerahasiaan data medis pekerja dan hanya memberikan informasi medis yang diperlukan sesuai regulasi.',
                'Mendukung penerapan K3 tambang dengan memastikan setiap pekerja yang sakit atau cedera mendapatkan perawatan medis yang sesuai sebelum melanjutkan pekerjaan.'
            ]
        ],
        'tim tanggap darurat' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Memelihara ketrampilan Dasar Penyelamatan di Tambang Terbuka yang telah dilatih dengan mengikuti kegiatan penyegaran internal mingguan.',
                'Memelihara peralatan Penyelamatan perorangan yang telah dibagikan dan selalu membawa saat mengikuti kegiatan penyegaran internal mingguan atau panggilan darurat.',
                'Berpartisipasi dalam memelihara peralatan tanggap darurat dengan menghadiri kegiatan perawatan/pemeliharaan yang telah dijadwalkan.',
                'Memelihara kesehatan tubuh dengan melakukan latihan secara rutin dan beristirahat cukup selesai bekerja.',
                'Berkumpul di TERT station apabila ada panggilan darurat. Anda dapat meninggalkan pekerjaan utama anda dan pastikan dalam keadaan aman sebelum berkumpul di TERT Station. (Untuk anggota yang tidak dalam jadwal bekerja akan diaktifkan sesuai kebutuhan).',
                'Melakukan tugas penyelamatan dasar seperti pemadaman kebakaran, penyelamatan dan penanggulangan bahan kimia di bawah pimpinan TERT Commander.',
                'Membantu memberikan informasi darurat dan melakukan perlindungan terhadap manusia, dengan memperhitungkan orang-orang yang berada di luar zona bahaya atau melakukan perlindungan di area tempat terjadinya keadaan darurat.',
                'Bertanggung jawab untuk memastikan peralatan tanggap darurat di area yang menjadi tanggung jawabnya terpelihara dan siap pakai.',
                'Partisipasi yang diberikan sebagai anggota TERT akan dijadikan nilai tambah dalam penilaian kinerja karyawan tahunan.',
                'Manajemen akan memberikan insentif bagi anggota TERT setiap bulan dengan mempertimbangkan keaktifan anggota dalam setiap kegiatan yang dilakukan.',
                'Upah lembur akan dibayarkan bagi anggota TERT yang berhak (sesuai grade karyawan) apabila ada panggilan darurat yang mengharuskan yang bersangkutan bekerja melebihi waktu normal.'
            ]
        ],
        'operator alat angkat dan angkut' => [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Membuat dan/atau memahami risiko pekerjaan dan melakukan mitigasi yang diperlukan;',
                'Memahami prosedur kerja penggunaan peralatan;',
                'Mengoperasikan peralatan sesuai dengan kompetensi;',
                'Melakukan analisa beban sebelum melakukan pekerjaan pengangkatan atau pengangkutan;',
                'Memastikan alat dalam kondisi baik sebelum bekerja, termasuk checklist atau metode lainnya yang relevan;',
                'Segera melaporkan jika ditemukan ketidaksesuaian seperti kerusakan peralatan, keterlambatan pemeriksaan atau perawatan, dan ketidaksesuaian lainnya terhadap prosedur perawatan peralatan;'
            ]
        ],
    ];
    // If competency type is explicitly Pengawas Teknis, always use the
    // Pengawas Teknis template � do not override it with keyword-based
    // templates (e.g., 'rigger', 'juru las', etc.).
    if ($competency_type === 'pengawas_teknis' && isset($templatesByType['pengawas_teknis'])) {
        return $templatesByType['pengawas_teknis'];
    }

    if ($competency_name !== '' && strpos($competency_name, 'paramedis') !== false) {
        return [
            'introduction' => '<p>Berdasarkan KEPMEN ESDM No. 1827 K/30/MEM/2018 tentang Pedoman Pelaksanaan kaidah Teknik Pertambangan yang Baik dan KEPDIRJEN ESDM 185.K/37.04/DJB/2019 Lampiran IV, Elemen III, terkait penunjukan Tenaga Teknis pertambangan yang berkompeten.</p>',
            'responsibilities' => [
                'Memberikan pertolongan pertama pada kecelakaan (P3K) di area tambang.',
                'Menstabilkan kondisi pasien sebelum dikirim ke klinik atau rumah sakit.',
                'Menyusun dan melaksanakan prosedur darurat medis untuk kecelakaan tambang.',
                'Melakukan monitoring kesehatan pekerjaan yang mengalami gangguan kesehatan ringan.',
                'Menjaga kesiapan fasilitas P3K dan memastikan alat medis selalu siap digunakan.',
                'Menjamin kesiapsiagaan dan kecepatan respon dalam penanganan kecelakaan.',
                'Mengurangi risiko fatalitas dengan penanganan yang tepat dan cepat.'
            ]
        ];
    }

    foreach ($templatesByCompetencyKeywords as $keyword => $template) {
        if ($competency_name !== '' && strpos($competency_name, $keyword) !== false) {
            return $template;
        }
    }

    if (isset($templatesByType[$competency_type])) {
        return $templatesByType[$competency_type];
    }

    return [
        'introduction' => '<p>Berdasarkan kebutuhan operasional perusahaan dan mempertimbangkan kompetensi yang dimiliki.</p>',
        'responsibilities' => [
            'Melaksanakan tugas dan tanggung jawab sesuai dengan jabatan yang diberikan;',
            'Menerapkan prosedur keselamatan dan kesehatan kerja dalam pelaksanaan tugas;',
            'Berkoordinasi dengan atasan dan rekan kerja terkait pelaksanaan tugas;',
            'Membuat laporan pelaksanaan tugas secara berkala;',
            'Mematuhi seluruh peraturan dan tata tertib perusahaan;'
        ]
    ];
}

// Generate letter content
if (!empty($appointment['letter_content'])) {
    $custom_content = $appointment['letter_content'];
} else {
    $letter_content = getLetterContent($appointment['competency_type'], $appointment['competency_name'], $appointment);
    
    $type_labels = [
        'pengawas_operasional' => 'Pengawas Operasional',
        'pengawas_teknis' => 'Pengawas Teknis',
        'tenaga_teknis' => 'Tenaga Teknis'
    ];
    $competency_type_display = $type_labels[$appointment['competency_type']] ?? $appointment['competency_type'];
    
    $custom_content = $letter_content['introduction'];
    $custom_content .= '<p><strong>Maka dengan mempertimbangkan kompetensi yang dimiliki maka bersama ini anda ditunjuk sebagai; ' . htmlspecialchars($competency_type_display);
    if (!empty($appointment['competency_name'])) {
        $custom_content .= ' - ' . htmlspecialchars($appointment['competency_name']);
    }
    $custom_content .= ' dengan tugas, tanggung jawab dan wewenang sebagai berikut:</strong></p>';
    $custom_content .= '<ol>';
    foreach ($letter_content['responsibilities'] as $responsibility) {
        $custom_content .= '<li>' . htmlspecialchars($responsibility) . '</li>';
    }
    $custom_content .= '</ol>';
}

$doc_code = getDocumentCode($appointment['competency_type']);
$header_title = getHeaderTitle($appointment['competency_type']);

// Handle AJAX save request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_content') {
    
    // 1. Ambil token dari POST data atau dari HTTP Header
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    
    // 2. Validasi token dengan session
    if (empty($csrf_token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        http_response_code(403); // Forbidden
        echo json_encode([
            'success' => false, 
            'message' => 'Validasi keamanan (CSRF) gagal. Permintaan ditolak.'
        ]);
        exit();
    }

    // 3. Jika lolos validasi, lanjutkan proses simpan data
    $new_content = $_POST['content'] ?? '';
    
    $stmt = $db->prepare("UPDATE appointments SET letter_content = ? WHERE id = ?");
    $stmt->bind_param("si", $new_content, $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Konten berhasil disimpan']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan konten']);
    }
    exit();
}

// Check if user can edit (admin or ktt)
$can_edit = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'ktt']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surat Penunjukan - <?php echo htmlspecialchars($appointment['appointment_number']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #F9FAFB;
        }
        
        /* Toolbar Styles */
        .toolbar {
            background: white;
            padding: 15px 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .toolbar h2 {
            flex: 1;
            margin: 0;
            color: #333;
            font-size: 18px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            text-decoration: none;
            font-weight: 600;
        }
        
        .btn-edit {
            background: #f59e0b;
            color: white;
        }
        
        .btn-edit:hover {
            background: #d97706;
        }
        
        .btn-save {
            background: #2E7D32;
            color: white;
        }
        
        .btn-save:hover {
            background: #1B5E20;
        }
        
        .btn-print {
            background: #37474F;
            color: white;
        }
        
        .btn-print:hover {
            background: #616161;
        }
        
        .btn-close {
            background: #6c757d;
            color: white;
        }
        
        .btn-close:hover {
            background: #5a6268;
        }
        
        /* Editor Container */
        .editor-container {
            max-width: 1200px;
            margin: 20px auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 30px;
            display: none;
        }
        
        .editor-container.active {
            display: block;
        }
        
        .ck-editor__editable {
            min-height: 400px;
            font-family: 'Times New Roman', Times, serif;
            font-size: 11pt;
            line-height: 1.6;
        }
        
        /* Table styling in CKEditor */
        .ck-editor__editable table {
            border-collapse: collapse;
            font-size: 9pt;
            font-family: 'Times New Roman', Times, serif;
            line-height: 1.0;
        }
        
        .ck-editor__editable table td,
        .ck-editor__editable table th {
            border: 0.3px solid #000;
            padding: 1px 2px;
        }
        
        .ck-editor__editable table th {
            font-weight: bold;
            background: #F9FAFB;
        }
        
        /* Print Container */
        .print-container {
            max-width: 210mm;
            margin: 20px auto;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 20px;
        }
        
        .print-container.hidden {
            display: none;
        }
        
        /* Header Styles */
        .header {
            border-bottom: 4px solid #808080;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .header-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .header-table td {
            vertical-align: middle;
        }
        
        .header-logo-cell {
            width: 150px;
            text-align: center;
        }
        
        .header-logo {
            width: 80px;
            height: 80px;
            object-fit: contain;
        }
        
        .header-content {
            text-align: center;
            padding: 0 15px;
        }
        
        .header h1 {
            margin: 0;
            font-size: 14pt;
            font-weight: bold;
            color: #000;
            font-family: 'Times New Roman', Times, serif;
        }
        
        .header h2 {
            margin: 2px 0;
            font-size: 12pt;
            font-weight: normal;
            font-family: 'Times New Roman', Times, serif;
        }
        
        .header p {
            margin: 1px 0;
            font-size: 11pt;
            font-weight: bold;
            font-family: 'Times New Roman', Times, serif;
        }
        
        /* Data Table Styles */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
            font-size: 10pt;
            font-family: 'Times New Roman', Times, serif;
        }
        
        .data-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #000;
            vertical-align: top;
        }
        
        .data-table tr:first-child td {
            border-top: 1px solid #000;
        }
        
        .data-table td:first-child {
            width: 150px;
            font-weight: normal;
        }
        
        .data-table td:nth-child(2) {
            width: 20px;
            text-align: center;
        }
        
        /* Content Section - Wrapper for data-table, content-area, signature-table */
        .content {
            margin: 20px 0;
            position: relative;
            z-index: 10;
        }
        
        /* Content Area */
        .content-area {
            padding: 0;
            font-family: 'Times New Roman', Times, serif;
            font-size: 10pt;
            line-height: 1.6;
            text-align: justify;
        }
        
        .content-area p {
            margin: 8px 0;
        }
        
        .content-area ul {
            margin: 10px 0;
            padding-left: 0;
            list-style: none;
        }
        
        .content-area ul li {
            margin: 6px 0;
            padding-left: 20px;
            text-indent: -20px;
        }
        
        .content-area ul li:before {
            content: "� ";
            font-weight: bold;
        }
        
        .content-area ol {
            margin: 10px 0;
            padding-left: 20px;
            list-style: decimal;
        }
        
        .content-area ol li {
            margin: 6px 0;
            padding-left: 5px;
            text-indent: 0;
        }
        
        /* Tables in Content Area - Similar to Footer Style */
        .content-area table {
            width: 100%;
            border-collapse: collapse;
            margin: 5px 0;
            font-size: 9pt;
            font-family: 'Times New Roman', Times, serif;
            line-height: 1.0;
        }
        
        .content-area table td,
        .content-area table th {
            padding: 1px 2px;
            border: 0.3px solid #000;
            vertical-align: top;
            text-align: left;
        }
        
        .content-area table th {
            font-weight: bold;
            background: #F9FAFB;
        }
        
        /* Figure from CKEditor */
        .content-area figure {
            margin: 10px 0;
        }
        
        .content-area figure table {
            margin: 0;
        }
        
        /* Signature Table */
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0;
            font-size: 10pt;
            font-family: 'Times New Roman', Times, serif;
        }
        
        .signature-table td {
            padding: 8px;
            border: 1px solid #000;
            text-align: center;
            vertical-align: middle;
        }
        
        .signature-table td:first-child {
            border-left: none;
        }
        
        .signature-table td:last-child {
            border-right: none;
        }
        
        .signature-table td div {
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .signature-img {
            max-width: 120px;
            max-height: 50px;
            object-fit: contain;
        }
        
        /* Footer Styles */
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 4px solid #808080;
        }
        
        .footer-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 7pt;
            font-family: 'Times New Roman', Times, serif;
        }
        
        .footer-table td {
            padding: 2px 4px;
            border: 0.3px solid #000;
            vertical-align: top;
        }
        
        .footer-table td:first-child {
            border-left: none;
        }
        
        .footer-table td:last-child {
            border-right: none;
        }
        
        .footer-table .label {
            font-weight: bold;
            width: 110px;
        }
        
        /* Alert */
        .alert {
            padding: 12px 20px;
            border-radius: 5px;
            margin: 20px auto;
            max-width: 1200px;
            display: none;
        }
        
        .alert.show {
            display: block;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        @media screen and (max-width: 768px) {
            .toolbar h2 {
                width: 100%;
                margin-bottom: 10px;
            }
            
            .print-container {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Toolbar -->
    <div class="toolbar">
        <h2><i class="fas fa-file-alt"></i> Surat Penunjukan - <?php echo htmlspecialchars($appointment['appointment_number']); ?></h2>
        
        <?php if ($can_edit): ?>
        <button class="btn btn-edit" id="btnToggleEdit" onclick="toggleEditMode()">
            <i class="fas fa-edit"></i> <span id="editBtnText">Edit Surat</span>
        </button>
        <button class="btn btn-save" id="btnSave" onclick="saveContent()" style="display: none;">
            <i class="fas fa-save"></i> Simpan
        </button>
        <?php endif; ?>

        <?php if ($appointment['status'] != 'draft'): ?>
        <a href="print_appointment_pdf.php?id=<?php echo $id; ?>" class="btn btn-print">
            <i class="fas fa-print"></i> Print
        </a>
        <?php endif; ?>
        <button class="btn btn-close" onclick="window.close()">
            <i class="fas fa-times"></i> Tutup
        </button>
    </div>
    
    <!-- Alert Container -->
    <div id="alertContainer"></div>
    
    <!-- Editor Container -->
    <?php if ($can_edit): ?>
    <div class="editor-container" id="editorContainer">
        <h3 style="margin-bottom: 20px; color: #333;"><i class="fas fa-keyboard"></i> Edit Konten Surat</h3>
        <div id="editor"><?php echo $custom_content; ?></div>
    </div>
    <?php endif; ?>
    
    <!-- Print Container -->
    <div class="print-container" id="printContainer">
        <div class="content-wrapper">
            <!-- HEADER SECTION -->
            <div class="header">
                <table class="header-table">
                    <tr>
                        <td class="header-logo-cell">
                            <img src="../assets/Logo/LOGO_MSM_TTN.png" alt="Logo" class="header-logo">
                        </td>
                        <td class="header-content">
                            <h1>TOKA TINDUNG PROJECT</h1>
                            <h2><strong>Surat Penunjukan <?php echo htmlspecialchars($header_title); ?></strong></h2>
                            <p><?php echo htmlspecialchars($doc_code); ?></p>
                        </td>
                        <td class="header-logo-cell">
                            <img src="../assets/Logo/LOGO_ARCHI.png" alt="Logo" class="header-logo">
                        </td>
                    </tr>
                </table>
            </div>
            <!-- END HEADER SECTION -->
            
            <!-- CONTENT SECTION -->
            <div class="content">
                <!-- Data Table -->
                <table class="data-table">
                <tr>
                    <td>Nama Lengkap</td>
                    <td>:</td>
                    <td><?php echo htmlspecialchars($appointment['employee_name']); ?></td>
                </tr>
                <tr>
                    <td>Badge ID</td>
                    <td>:</td>
                    <td><?php echo htmlspecialchars($appointment['employee_code']); ?></td>
                </tr>
                <tr>
                    <td>Jabatan</td>
                    <td>:</td>
                    <td><?php echo htmlspecialchars($appointment['position']); ?></td>
                </tr>
                <tr>
                    <td>Perusahaan</td>
                    <td>:</td>
                    <td><?php echo htmlspecialchars($appointment['contractor_company'] ?? 'PT Mahakam Sumber Mandiri'); ?></td>
                </tr>
                <?php if (!empty($appointment['competency_name'])): ?>
                <tr>
                    <td>Kompetensi</td>
                    <td>:</td>
                    <td><?php echo htmlspecialchars($appointment['competency_name']); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($appointment['competency_type'] == 'pengawas_operasional'): ?>
                    <?php if (!empty($appointment['ruang_lingkup'])): ?>
                    <tr>
                        <td>Lingkup Tugas</td>
                        <td>:</td>
                        <td>
                            <?php 
                            $contractor_name = $appointment['contractor_company'] ?? 'PT Mahakam Sumber Mandiri';
                            $ruang_lingkup = $appointment['ruang_lingkup'];
                            echo 'Di ' . htmlspecialchars($contractor_name) . ' untuk area kerja ' . htmlspecialchars($ruang_lingkup);
                            ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($appointment['competency_type'] == 'pengawas_operasional'): ?>
                    <?php if (!empty($appointment['supervision_area'])): ?>
                    <tr>
                        <td>Area Pengawasan</td>
                        <td>:</td>
                        <td><?php echo htmlspecialchars($appointment['supervision_area']); ?></td>
                    </tr>
                    <?php endif; ?>
                <?php endif; ?>
                <tr>
                    <td>No Registrasi</td>
                    <td>:</td>
                    <td>
                        <?php echo htmlspecialchars($appointment['appointment_number']); ?>
                        <span style="margin-left: 50px;">Sertifikat No:
                        <?php 
                        if (!empty($appointment['cert_numbers'])) {
                            echo htmlspecialchars($appointment['cert_numbers']);
                        } else {
                            echo '-';
                        }
                        ?></span>
                    </td>
                </tr>
            </table>
            
            <!-- Content Area -->
            <div class="content-area" id="contentDisplay">
                <?php echo $custom_content; ?>
            </div>
            
            <!-- Signature Table -->
            <table class="signature-table">
                <tr>
                    <td style="width: 50%;">
                        <strong>Ditunjuk oleh KTT MSM</strong>
                    </td>
                    <td style="width: 50%;">
                        <strong>Ditunjuk oleh KTT TTN</strong>
                    </td>
                </tr>
                <tr>
                    <td>
                        <div>
                            <?php 
                            if ($both_ktt_approved): 
                                $ktt_msm_sig = '../assets/uploads/signatures/signature_KTT_MSM.png';
                                if (file_exists($ktt_msm_sig)): 
                            ?>
                                <img src="<?php echo htmlspecialchars($ktt_msm_sig); ?>" alt="Tanda Tangan KTT MSM" class="signature-img">
                            <?php 
                                endif;
                            endif; 
                            ?>
                        </div>
                        <strong>TEJO PRIHANTORO</strong>
                    </td>
                    <td>
                        <div>
                            <?php 
                            if ($both_ktt_approved): 
                                $ktt_ttn_sig = '../assets/uploads/signatures/signature_KTT_TTN.png';
                                if (file_exists($ktt_ttn_sig)): 
                            ?>
                                <img src="<?php echo htmlspecialchars($ktt_ttn_sig); ?>" alt="Tanda Tangan KTT TTN" class="signature-img">
                            <?php 
                                endif;
                            endif; 
                            ?>
                        </div>
                        <strong>AGUNG PRAPTONO</strong>
                    </td>
                </tr>
                <tr>
                    <td>Tgl: <?php echo date('d/m/Y', strtotime($appointment['appointment_date'])); ?></td>
                    <td>Tgl: <?php echo date('d/m/Y', strtotime($appointment['appointment_date'])); ?></td>
                </tr>
            </table>
            </div>
            <!-- END CONTENT SECTION -->
            
            <!-- FOOTER SECTION -->
            <div class="footer">
                <?php
                $doc_name = 'Surat Penunjukan ' . $header_title;
                
                // Prepare date strings for footer
                $tanggal_terbit = '25 Maret 2025';
                $tanggal_tinjau = '25 Maret 2028';
                ?>
                <table class="footer-table">
                    <tr>
                        <td class="label">Nama Dokumen</td>
                        <td colspan="3"><?php echo htmlspecialchars($doc_name); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Ditetapkan Oleh</td>
                        <td>Kepala Teknik Tambang</td>
                        <td class="label">Tanggal Terbit</td>
                        <td><?php echo htmlspecialchars($tanggal_terbit); ?></td>
                    </tr>
                    <tr>
                        <td class="label">No Dokumen</td>
                        <td><?php echo htmlspecialchars($doc_code); ?></td>
                        <td class="label">Tanggal Tinjau Ulang</td>
                        <td><?php echo htmlspecialchars($tanggal_tinjau); ?></td>
                    </tr>
                    <tr>
                        <td class="label">No Revisi</td>
                        <td>00</td>
                        <td colspan="2"><span style="color: #d32f2f;">Dokumen terkendali dan valid hanya ada di sharepoint Archi Indonesia</span></td>
                    </tr>
                </table>
            </div>
            <!-- END FOOTER SECTION -->
        </div>
    </div>
    
    <?php if ($can_edit): ?>
    <!-- CKEditor 5 Document Editor -->
    <script src="https://cdn.ckeditor.com/ckeditor5/40.2.0/decoupled-document/ckeditor.js"></script>
    
    <script>
        let editor;
        let isEditMode = false;
        
        // Initialize CKEditor
        DecoupledEditor
            .create(document.querySelector('#editor'), {
                toolbar: {
                    items: [
                        'heading', '|',
                        'fontSize', 'fontFamily', 'fontColor', 'fontBackgroundColor', '|',
                        'bold', 'italic', 'underline', 'strikethrough', '|',
                        'alignment', '|',
                        'numberedList', 'bulletedList', '|',
                        'indent', 'outdent', '|',
                        'link', 'blockQuote', 'insertTable', '|',
                        'undo', 'redo'
                    ]
                },
                heading: {
                    options: [
                        { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                        { model: 'heading1', view: 'h1', title: 'Heading 1', class: 'ck-heading_heading1' },
                        { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
                        { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' }
                    ]
                },
                fontSize: {
                    options: [9, 10, 11, 12, 14, 16, 18, 20, 22, 24]
                },
                table: {
                    contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells']
                }
            })
            .then(newEditor => {
                editor = newEditor;
                const toolbarContainer = document.querySelector('#editorContainer');
                const editorElement = document.querySelector('#editor');
                toolbarContainer.insertBefore(editor.ui.view.toolbar.element, editorElement);
                
                // Update preview on content change
                editor.model.document.on('change:data', () => {
                    updateContentDisplay();
                });
            })
            .catch(error => {
                console.error('Error initializing editor:', error);
            });
        
        function toggleEditMode() {
            isEditMode = !isEditMode;
            const editorContainer = document.getElementById('editorContainer');
            const printContainer = document.getElementById('printContainer');
            const btnSave = document.getElementById('btnSave');
            const editBtnText = document.getElementById('editBtnText');
            
            if (isEditMode) {
                editorContainer.classList.add('active');
                printContainer.classList.add('hidden');
                btnSave.style.display = 'inline-flex';
                editBtnText.textContent = 'Lihat Preview';
            } else {
                editorContainer.classList.remove('active');
                printContainer.classList.remove('hidden');
                btnSave.style.display = 'none';
                editBtnText.textContent = 'Edit Surat';
                updateContentDisplay();
            }
        }
        
        function updateContentDisplay() {
            const content = editor.getData();
            document.getElementById('contentDisplay').innerHTML = content;
        }
        
      function saveContent() {
    const content = editor.getData();
    const csrfToken = "<?php echo $_SESSION['csrf_token']; ?>"; 

    // Membuat objek FormData baru
    const formData = new FormData();
    formData.append('action', 'save_content');
    formData.append('csrf_token', csrfToken); // Masukkan token ke FormData
    formData.append('content', content);

    fetch(window.location.href, {
        method: 'POST',
        body: formData // Langsung kirim objek formData tanpa mengubah headers
    })
    .then(response => response.json())
    .then(data => {
        showAlert(data.success ? 'success' : 'error', data.message);
        if (data.success) {
            updateContentDisplay();
        }
    })
    .catch(error => {
        showAlert('error', 'Terjadi kesalahan: ' + error);
    });
}
        function showAlert(type, message) {
            const alertContainer = document.getElementById('alertContainer');
            const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
            const iconClass = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
            
            alertContainer.innerHTML = `
                <div class="alert ${alertClass} show">
                    <i class="fas ${iconClass}"></i> ${message}
                </div>
            `;
            
            setTimeout(() => {
                alertContainer.innerHTML = '';
            }, 5000);
        }
    </script>
    <?php endif; ?>
</body>
</html>

