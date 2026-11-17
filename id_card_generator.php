<?php
// id_card_generator.php - Official IWA SCED 2026 ID Card & Scannable QR Generator (PHP 7.4 Compatible)
session_start();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$ADMIN_PASS = 'Admin@123';
if (isset($_GET['logout'])) {
    unset($_SESSION['id_admin_auth']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    if (hash_equals($ADMIN_PASS, trim($_POST['admin_password']))) {
        $_SESSION['id_admin_auth'] = true;
    } else {
        $loginError = 'Invalid admin password.';
    }
}

if (empty($_SESSION['id_admin_auth'])):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IWA SCED 2026 — ID Card Generator Login</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .login-box { background: #1e293b; padding: 32px; border-radius: 12px; width: 100%; max-width: 360px; box-shadow: 0 10px 25px rgba(0,0,0,0.4); }
        h2 { margin: 0 0 8px; font-size: 20px; color: #38bdf8; }
        p { margin: 0 0 20px; font-size: 13px; color: #94a3b8; }
        input { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid #334155; background: #0f172a; color: #fff; font-size: 14px; box-sizing: border-box; margin-bottom: 14px; }
        button { width: 100%; padding: 11px; border: none; border-radius: 8px; background: #0284c7; color: #fff; font-weight: 600; font-size: 14px; cursor: pointer; }
        button:hover { background: #0369a1; }
        .err { color: #f87171; font-size: 13px; margin-bottom: 12px; }
    </style>
</head>
<body>
    <form method="POST" class="login-box">
        <h2>IWA SCED 2026</h2>
        <p>Official Conference ID Badge Printer</p>
        <?php if (!empty($loginError)): ?><div class="err"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
        <input type="password" name="admin_password" placeholder="Enter Admin Password" required autofocus>
        <button type="submit">Unlock Badge Generator</button>
    </form>
</body>
</html>
<?php
exit;
endif;

$host = 'localhost';
$port = '5432';
$user = 'postgres';
$pass = 'Ramgopal@123';

$participants = [];
$dbErrors = [];

function pickFirstNonEmpty(array $row, array $keys, $default = '') {
    foreach ($keys as $k) {
        if (isset($row[$k]) && trim((string)$row[$k]) !== '' && strtolower(trim((string)$row[$k])) !== 'null') {
            return trim((string)$row[$k]);
        }
    }
    return $default;
}

// 1. Fetch Delegates/Presenters & Sponsors/Exhibitors from moesnt
try {
    $pdoMoesnt = new PDO("pgsql:host=$host;port=$port;dbname=moesnt", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Delegates / Presenters
    $regRows = $pdoMoesnt->query("SELECT * FROM public.iwa2026_registration ORDER BY registration_no ASC")->fetchAll();
    foreach ($regRows as $r) {
        $regNo = pickFirstNonEmpty($r, ['registration_no']);
        if ($regNo === '') continue;

        $title = pickFirstNonEmpty($r, ['title', 'salutation']);
        $rawName = pickFirstNonEmpty($r, ['fullname', 'full_name', 'name'], 'Participant');
        $fullName = ($title !== '' && stripos($rawName, $title) !== 0) ? ($title . '. ' . $rawName) : $rawName;
        $fullName = str_replace('..', '.', $fullName);

        $org = pickFirstNonEmpty($r, ['organization', 'affiliation', 'institute', 'cname', 'college'], 'IWA SCED 2026 Delegate');

        $participants[] = [
            'registration_no' => $regNo,
            'full_name'       => $fullName,
            'organization'    => $org,
            'role_label'      => 'DELEGATE',
            'process_group'   => 'delegate',
            'badge_color'     => '#0284c7',
            'email'           => pickFirstNonEmpty($r, ['email']),
        ];
    }

    // Sponsors, Exhibitors & Complimentary (-A, -B, -C) Delegates
    $spRows = $pdoMoesnt->query("
        SELECT * FROM public.iwa2026_spregistration 
        WHERE COALESCE(delflag, 'N') != 'Y' 
        ORDER BY registration_no ASC
    ")->fetchAll();

    foreach ($spRows as $r) {
        $regNo = pickFirstNonEmpty($r, ['registration_no']);
        if ($regNo === '') continue;

        $title = pickFirstNonEmpty($r, ['title']);
        $rawName = pickFirstNonEmpty($r, ['fullname', 'full_name', 'name'], 'Representative');
        $fullName = ($title !== '' && stripos($rawName, $title) !== 0) ? ($title . '. ' . $rawName) : $rawName;
        $fullName = str_replace('..', '.', $fullName);

        $org = pickFirstNonEmpty($r, ['cname', 'organization'], 'Sponsor / Exhibitor');
        $pcat = pickFirstNonEmpty($r, ['pcategory'], 'Sponsors');
        $isExhibitor = (stripos($regNo, '/EX/') !== false || stripos($pcat, 'Exhibitor') !== false);

        $participants[] = [
            'registration_no' => $regNo,
            'full_name'       => $fullName,
            'organization'    => $org,
            'role_label'      => $isExhibitor ? 'EXHIBITOR' : 'SPONSOR',
            'process_group'   => 'sponsor',
            'badge_color'     => $isExhibitor ? '#0f766e' : '#b45309',
            'email'           => pickFirstNonEmpty($r, ['email']),
        ];
    }
} catch (PDOException $e) {
    $dbErrors[] = "moesnt DB error: " . $e->getMessage();
}

// 2. Fetch Keynote Speakers from conference_db
try {
    $pdoConf = new PDO("pgsql:host=$host;port=$port;dbname=conference_db", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $keyRows = $pdoConf->query("SELECT * FROM public.keynote_speakers ORDER BY registration_no ASC")->fetchAll();
    foreach ($keyRows as $r) {
        $regNo = pickFirstNonEmpty($r, ['registration_no']);
        if ($regNo === '') continue;

        $fullName = pickFirstNonEmpty($r, ['full_name', 'name'], 'Keynote Speaker');
        $org = pickFirstNonEmpty($r, ['organization', 'affiliation', 'designation', 'country'], 'Keynote Speaker');

        $participants[] = [
            'registration_no' => $regNo,
            'full_name'       => $fullName,
            'organization'    => $org,
            'role_label'      => 'SPEAKER',
            'process_group'   => 'speaker',
            'badge_color'     => '#6d28d9',
            'email'           => pickFirstNonEmpty($r, ['email']),
        ];
    }
} catch (PDOException $e) {
    $dbErrors[] = "conference_db error: " . $e->getMessage();
}

$scriptDir        = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$qrEndpoint       = $scriptDir . '/api/qrcode.php';
$assetDir         = $scriptDir . '/assets/badge';

// Fixed Back-Side QR Code URLs
$fixedScheduleUrl = 'https://www.niot.res.in/IWA2026/program_schedule.php';
$fixedAppUrl      = 'https://www.niot.res.in/IWA2026apk/IWA.APK';
$scheduleQrUrl    = $qrEndpoint . '?qid=' . rawurlencode($fixedScheduleUrl);
$appQrUrl         = $qrEndpoint . '?qid=' . rawurlencode($fixedAppUrl);
$websiteQrAsset   = $assetDir . '/app_qr.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IWA SCED 2026 — Official Conference ID Badge Generator</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #e2e8f0;
            color: #0f172a;
            margin: 0;
            padding: 0;
        }

        /* --- ADMIN TOOLBAR --- */
        .toolbar {
            background: #0f294a;
            color: #fff;
            padding: 14px 24px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 4px 12px rgba(0,0,0,0.18);
        }
        .toolbar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
        }
        .toolbar-top h1 { margin: 0; font-size: 19px; }
        .nav-links {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .toolbar-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        select, input[type="text"] {
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #475569;
            font-size: 13.5px;
            background: #fff;
            color: #0f172a;
        }
        input[type="text"] { min-width: 270px; }
        .btn {
            padding: 8px 14px;
            border: none;
            border-radius: 6px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-primary { background: #10b981; color: #fff; }
        .btn-primary:hover { background: #059669; }
        .btn-secondary { background: #334155; color: #fff; }
        .btn-secondary:hover { background: #475569; }
        .btn-nav {
            background: transparent;
            border: 1px solid #64748b;
            color: #e2e8f0;
            padding: 6px 12px;
            font-size: 12.5px;
        }
        .btn-nav:hover { background: #1e3a5f; border-color: #94a3b8; }
        .toggle-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13.5px;
            background: #1e3a5f;
            padding: 7px 12px;
            border-radius: 6px;
            cursor: pointer;
        }
        .stats-bar {
            background: #cbd5e1;
            padding: 7px 24px;
            font-size: 12.5px;
            color: #1e293b;
            display: flex;
            justify-content: space-between;
        }

        /* --- BADGE GRID --- */
        .badge-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 22px;
            padding: 24px;
            justify-content: center;
        }
        .id-card-wrapper {
            background: #fff;
            padding: 12px;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border: 2px solid transparent;
        }
        .id-card-wrapper.selected {
            border-color: #10b981;
            background: #f0fdf4;
        }
        .select-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 1px dashed #94a3b8;
            font-size: 13px;
            font-weight: 600;
        }
        .badge-pair {
            display: flex;
            gap: 6mm;
            align-items: stretch;
        }

        /* --- OFFICIAL TEMPLATE DIMENSIONS: 95mm x 135mm --- */
        .badge-face {
            width: 95mm;
            height: 135mm;
            background: #ffffff;
            border: 1.5px solid #0f294a;
            border-radius: 6px;
            padding: 4mm 5mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            text-align: center;
            position: relative;
            overflow: hidden;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Top Conference Title */
        .conf-header-text {
            font-size: 8.5pt;
            font-weight: 800;
            color: #0f294a;
            line-height: 1.25;
            letter-spacing: 0.2px;
            text-transform: uppercase;
        }

        /* IWA SCED 2026 Logo Row */
        .iwa-brand-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin: 1.5mm 0;
        }
        .iwa-brand-row img {
            height: 11mm;
            width: auto;
            object-fit: contain;
        }
        .iwa-fallback {
            display: inline-flex;
            align-items: baseline;
            gap: 6px;
            font-weight: 900;
        }
        .iwa-fallback .iwa-txt {
            font-size: 20pt;
            color: #000;
            border-bottom: 3.5px solid #00a8e8;
            line-height: 0.9;
        }
        .iwa-fallback .sced-txt {
            font-size: 14pt;
            color: #0284c7;
        }

        /* Role Ribbon */
        .role-ribbon {
            color: #ffffff;
            font-size: 11pt;
            font-weight: 800;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            padding: 4.5px 8px;
            border-radius: 4px;
            margin: 1.5mm 0;
        }

        /* Participant Details */
        .participant-block {
            margin: 1.5mm 0;
        }
        .p-name {
            font-size: 14pt;
            font-weight: 800;
            color: #000000;
            line-height: 1.18;
            margin-bottom: 2px;
            word-break: break-word;
        }
        .p-reg {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 10pt;
            font-weight: 700;
            color: #0369a1;
            margin-bottom: 2px;
        }
        .p-org {
            font-size: 9pt;
            font-weight: 600;
            color: #334155;
            line-height: 1.22;
            max-height: 22pt;
            overflow: hidden;
        }

        /* Front QR Code + Registration ID Row */
        .qr-section {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 4px 8px;
            background: #f8fafc;
            margin: 1.5mm 0;
        }
        .qr-section img {
            width: 24mm;
            height: 24mm;
            display: block;
            background: #fff;
            padding: 2px;
            border: 1px solid #e2e8f0;
        }
        .qr-meta {
            text-align: left;
        }
        .qr-meta .qr-lbl {
            font-size: 7.5pt;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 700;
        }
        .qr-meta .qr-val {
            font-family: 'Consolas', monospace;
            font-size: 9.5pt;
            font-weight: 800;
            color: #0f172a;
            margin-top: 2px;
        }

        /* Bottom Host Logos & Date/Venue Footer */
        .bottom-logos {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            padding-top: 2mm;
            border-top: 1px solid #e2e8f0;
        }
        .bottom-logos img {
            height: 12.5mm;
            max-width: 44%;
            object-fit: contain;
        }
        .venue-footer {
            font-size: 7.8pt;
            line-height: 1.25;
            color: #0f294a;
            margin-top: 1.5mm;
        }
        .venue-footer strong {
            display: block;
            font-size: 8.5pt;
            font-weight: 800;
        }

        /* --- BACK FACE STYLES (1 Official Website QR on Top + 2 QRs Below) --- */
        .back-face {
            justify-content: space-between;
            background: #ffffff;
        }
        .back-assist {
            font-size: 8.5pt;
            color: #1e293b;
            line-height: 1.35;
        }
        .back-notice {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 5px;
            padding: 5px 8px;
            font-size: 8pt;
            font-weight: 700;
            color: #0f294a;
            line-height: 1.3;
        }
        .back-website-block {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .back-website-hdr {
            font-size: 8.5pt;
            font-weight: 800;
            color: #0f294a;
            text-transform: uppercase;
            margin-bottom: 3px;
            letter-spacing: 0.4px;
        }
        .back-website-block img {
            width: 21mm;
            height: 21mm;
            border: 1px solid #cbd5e1;
            padding: 2px;
            border-radius: 4px;
            background: #fff;
        }
        .back-qr-grid {
            display: flex;
            justify-content: space-around;
            gap: 8px;
            padding-top: 3px;
            border-top: 1px dashed #cbd5e1;
        }
        .back-qr-item {
            text-align: center;
            flex: 1;
        }
        .back-qr-item img {
            width: 20mm;
            height: 20mm;
            border: 1px solid #cbd5e1;
            padding: 2px;
            border-radius: 4px;
            background: #fff;
        }
        .back-qr-item div {
            font-size: 7.5pt;
            font-weight: 700;
            color: #1e293b;
            margin-top: 2px;
        }

        body.hide-back .back-face {
            display: none !important;
        }

        /* --- PRINT RULES --- */
        @media print {
            @page {
                size: A4 portrait;
                margin: 6mm;
            }
            body { background: #fff !important; }
            .toolbar, .stats-bar, .select-bar, .no-print { display: none !important; }
            .badge-grid {
                gap: 6mm;
                padding: 0;
            }
            .id-card-wrapper {
                box-shadow: none !important;
                border: none !important;
                background: transparent !important;
                padding: 0 !important;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .id-card-wrapper:not(.selected) {
                display: none !important;
            }
        }
    </style>
</head>
<body>

<div class="toolbar no-print">
    <div class="toolbar-top">
        <h1>IWA SCED 2026 — Official Conference ID Card Generator</h1>
        <div class="nav-links">
            <a href="schedule_manager.php" class="btn btn-nav">Schedule Manager</a>
            <a href="food_dashboard.php" class="btn btn-nav">Food Tracker</a>
            <a href="hall_occupancy.php" class="btn btn-nav">Hall Occupancy</a>
            <a href="?logout=1" class="btn btn-nav">Logout</a>
        </div>
    </div>
    <div class="toolbar-controls">
        <select id="processFilter" onchange="filterCards()">
            <option value="all">Process: All Participants (<?= count($participants) ?>)</option>
            <option value="delegate">Process 1: Delegates &amp; Presenters</option>
            <option value="sponsor">Process 2: Sponsors &amp; Exhibitors (-A/-B/-C)</option>
            <option value="speaker">Process 3: Keynote Speakers</option>
        </select>

        <input type="text" id="searchInput" oninput="filterCards()" placeholder="Search Name, Registration No, or Org...">

        <label class="toggle-label">
            <input type="checkbox" id="toggleBackSide" checked onchange="document.body.classList.toggle('hide-back', !this.checked)">
            Include Back Side
        </label>

        <button type="button" class="btn btn-secondary" onclick="selectAllVisible(true)">Select Visible</button>
        <button type="button" class="btn btn-secondary" onclick="selectAllVisible(false)">Clear</button>

        <button type="button" class="btn btn-primary" onclick="printSelectedBadges()">
            Print Selected (<span id="selectedCount">0</span>)
        </button>
    </div>
</div>

<div class="stats-bar no-print">
    <div>Showing <strong id="visibleCount"><?= count($participants) ?></strong> of <strong><?= count($participants) ?></strong> records</div>
    <div>Enable <strong>Background graphics</strong> in your browser print dialog so role ribbons print in color.</div>
</div>

<?php if (!empty($dbErrors)): ?>
    <div style="background:#fee2e2;color:#991b1b;padding:10px 24px;font-size:13px;" class="no-print">
        <?= htmlspecialchars(implode(' | ', $dbErrors)) ?>
    </div>
<?php endif; ?>

<div class="badge-grid" id="badgeGrid">
    <?php foreach ($participants as $p):
        $qrUrl      = $qrEndpoint . '?qid=' . rawurlencode($p['registration_no']);
        $searchBlob = strtolower($p['full_name'] . ' ' . $p['registration_no'] . ' ' . $p['organization'] . ' ' . $p['role_label'] . ' ' . $p['email']);
    ?>
    <div class="id-card-wrapper"
         data-process="<?= htmlspecialchars($p['process_group']) ?>"
         data-search="<?= htmlspecialchars($searchBlob) ?>">

        <div class="select-bar no-print">
            <label>
                <input type="checkbox" class="card-checkbox" onchange="toggleCardSelect(this)">
                Select Badge
            </label>
            <button type="button" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;" onclick="printSingleCard(this)">
                Print Single
            </button>
        </div>

        <div class="badge-pair">
            <!-- ==================== FRONT OF ID CARD ==================== -->
            <div class="badge-face front-face">
                <div class="conf-header-text">
                    THE 2nd INTERNATIONAL WATER ASSOCIATION<br>
                    CONFERENCE ON SUSTAINABLE COASTAL AND ESTUARINE DEVELOPMENT
                </div>

                <div class="iwa-brand-row">
                    <img src="<?= htmlspecialchars($assetDir . '/iwa_sced_logo.png') ?>"
                         alt="IWA SCED 2026"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                    <div class="iwa-fallback" style="display:none;">
                        <span class="iwa-txt">IWA</span>
                        <span class="sced-txt">SCED 2026</span>
                    </div>
                </div>

                <div class="role-ribbon" style="background-color: <?= htmlspecialchars($p['badge_color']) ?>;">
                    <?= htmlspecialchars($p['role_label']) ?>
                </div>

                <div class="participant-block">
                    <div class="p-name"><?= htmlspecialchars($p['full_name']) ?></div>
                    <div class="p-reg"><?= htmlspecialchars($p['registration_no']) ?></div>
                    <div class="p-org"><?= htmlspecialchars($p['organization']) ?></div>
                </div>

                <div class="qr-section">
                    <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR <?= htmlspecialchars($p['registration_no']) ?>" loading="lazy">
                    <div class="qr-meta">
                        <div class="qr-lbl">Scan Registration ID</div>
                        <div class="qr-val"><?= htmlspecialchars($p['registration_no']) ?></div>
                    </div>
                </div>

                <div>
                    <div class="bottom-logos">
                        <img src="<?= htmlspecialchars($assetDir . '/niot_logo.png') ?>"
                             alt="NIOT"
                             onerror="this.style.display='none';">
                        <img src="<?= htmlspecialchars($assetDir . '/iwa_lrm_logo.png') ?>"
                             alt="IWA Lake and Reservoir Management"
                             onerror="this.style.display='none';">
                    </div>
                    <div class="venue-footer">
                        <strong>26–28 NOVEMBER 2026</strong>
                        National Institute of Ocean Technology (NIOT), Chennai, India
                    </div>
                </div>
            </div>

            <!-- ==================== BACK OF ID CARD ==================== -->
            <div class="badge-face back-face">
                <div class="iwa-brand-row" style="margin-top:1mm;">
                    <img src="<?= htmlspecialchars($assetDir . '/iwa_sced_logo.png') ?>"
                         alt="IWA SCED 2026"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                    <div class="iwa-fallback" style="display:none;">
                        <span class="iwa-txt">IWA</span>
                        <span class="sced-txt">SCED 2026</span>
                    </div>
                </div>

                <div class="back-assist">
                    For assistance, please contact:<br>
                    <strong>sced-conf.niot@gov.in</strong>
                </div>

                <div class="back-notice">
                    This badge is non-transferable.<br>
                    Please wear it visibly during conference sessions.
                </div>

                <!-- 1. Official Website QR (Centered on Top) -->
                <div class="back-website-block">
                    <div class="back-website-hdr">For Official Website</div>
                    <img src="<?= htmlspecialchars($websiteQrAsset) ?>" alt="Official Website QR">
                </div>

                <!-- 2. Fixed Schedule & Mobile App QRs (Side-by-Side Below) -->
                <div class="back-qr-grid">
                    <div class="back-qr-item">
                        <img src="<?= htmlspecialchars($scheduleQrUrl) ?>" alt="QR code for Schedule">
                        <div>QR code for Schedule</div>
                    </div>
                    <div class="back-qr-item">
                        <img src="<?= htmlspecialchars($appQrUrl) ?>" alt="QR code for Mobile App">
                        <div>QR code for Mobile App</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
    function updateSelectedCount() {
        const count = document.querySelectorAll('.id-card-wrapper.selected').length;
        document.getElementById('selectedCount').textContent = count;
    }

    function toggleCardSelect(checkbox) {
        const wrapper = checkbox.closest('.id-card-wrapper');
        wrapper.classList.toggle('selected', checkbox.checked);
        updateSelectedCount();
    }

    function filterCards() {
        const process = document.getElementById('processFilter').value;
        const query = document.getElementById('searchInput').value.trim().toLowerCase();
        const cards = document.querySelectorAll('.id-card-wrapper');
        let visible = 0;

        cards.forEach(card => {
            const matchesProcess = (process === 'all' || card.dataset.process === process);
            const matchesQuery = (query === '' || card.dataset.search.indexOf(query) !== -1);
            if (matchesProcess && matchesQuery) {
                card.style.display = '';
                visible++;
            } else {
                card.style.display = 'none';
            }
        });

        document.getElementById('visibleCount').textContent = visible;
    }

    function selectAllVisible(state) {
        document.querySelectorAll('.id-card-wrapper').forEach(card => {
            if (card.style.display !== 'none') {
                const cb = card.querySelector('.card-checkbox');
                cb.checked = state;
                card.classList.toggle('selected', state);
            }
        });
        updateSelectedCount();
    }

    function printSingleCard(btn) {
        document.querySelectorAll('.id-card-wrapper').forEach(c => {
            c.classList.remove('selected');
            c.querySelector('.card-checkbox').checked = false;
        });
        const wrapper = btn.closest('.id-card-wrapper');
        wrapper.classList.add('selected');
        wrapper.querySelector('.card-checkbox').checked = true;
        updateSelectedCount();
        window.print();
    }

    function printSelectedBadges() {
        if (document.querySelectorAll('.id-card-wrapper.selected').length === 0) {
            alert('Please select at least one participant badge to print.');
            return;
        }
        window.print();
    }
</script>

</body>
</html>