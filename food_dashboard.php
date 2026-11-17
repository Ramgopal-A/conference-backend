<?php
// food_dashboard.php - Admin Food Reservation & Meal Scan Tracker (PHP 7.4 Compatible)
session_start();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$ADMIN_PASS = 'Admin@123';
if (isset($_GET['logout'])) {
    unset($_SESSION['food_admin_auth']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    if (hash_equals($ADMIN_PASS, trim($_POST['admin_password']))) {
        $_SESSION['food_admin_auth'] = true;
    } else {
        $loginError = 'Invalid admin password.';
    }
}

if (empty($_SESSION['food_admin_auth'])):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IWA SCED 2026 — Food &amp; Meal Tracker Login</title>
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
        <p>Food Reservation &amp; Catering Counter</p>
        <?php if (!empty($loginError)): ?><div class="err"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
        <input type="password" name="admin_password" placeholder="Enter Admin Password" required autofocus>
        <button type="submit">Unlock Food Dashboard</button>
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

$pdoConf   = null;
$pdoMoesnt = null;
$dbErrors  = [];

try {
    $pdoConf = new PDO("pgsql:host=$host;port=$port;dbname=conference_db", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    $dbErrors[] = "conference_db: " . $e->getMessage();
}

try {
    $pdoMoesnt = new PDO("pgsql:host=$host;port=$port;dbname=moesnt", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    $dbErrors[] = "moesnt: " . $e->getMessage();
}

function pickCol(array $row, array $candidates, $default = '') {
    foreach ($candidates as $col) {
        if (isset($row[$col]) && trim((string)$row[$col]) !== '' && strtolower(trim((string)$row[$col])) !== 'null') {
            return trim((string)$row[$col]);
        }
    }
    return $default;
}

// 1. Build Participant Directory (Delegates, Sponsors/Exhibitors, Keynote Speakers, Organizers)
$directory = [];

if ($pdoMoesnt) {
    try {
        foreach ($pdoMoesnt->query("SELECT * FROM public.iwa2026_registration")->fetchAll() as $r) {
            $reg = pickCol($r, ['registration_no']);
            if ($reg === '') continue;
            $directory[strtolower($reg)] = [
                'registration_no' => $reg,
                'full_name'       => pickCol($r, ['fullname', 'full_name', 'name'], 'Delegate'),
                'organization'    => pickCol($r, ['organization', 'affiliation', 'institute', 'cname'], 'Delegate'),
                'role'            => pickCol($r, ['category', 'pcategory', 'participant_category'], 'Delegate'),
                'diet'            => pickCol($r, ['food_pref', 'food_preference', 'diet', 'dietary', 'meal_pref', 'food_type'], ''),
                'food_reserved'   => pickCol($r, ['food_reserved', 'meal_reserved', 'is_food', 'food', 'accommodation_food'], ''),
            ];
        }
    } catch (PDOException $e) {}

    try {
        foreach ($pdoMoesnt->query("SELECT * FROM public.iwa2026_spregistration WHERE COALESCE(delflag, 'N') != 'Y'")->fetchAll() as $r) {
            $reg = pickCol($r, ['registration_no']);
            if ($reg === '') continue;
            $isEx = (stripos($reg, '/EX/') !== false);
            $directory[strtolower($reg)] = [
                'registration_no' => $reg,
                'full_name'       => pickCol($r, ['fullname', 'full_name', 'name'], 'Representative'),
                'organization'    => pickCol($r, ['cname', 'organization'], 'Sponsor / Exhibitor'),
                'role'            => $isEx ? 'Exhibitor' : 'Sponsor',
                'diet'            => pickCol($r, ['food_pref', 'food_preference', 'diet', 'dietary', 'meal_pref'], ''),
                'food_reserved'   => pickCol($r, ['food_reserved', 'meal_reserved', 'food'], ''),
            ];
        }
    } catch (PDOException $e) {}
}

if ($pdoConf) {
    try {
        foreach ($pdoConf->query("SELECT * FROM public.keynote_speakers")->fetchAll() as $r) {
            $reg = pickCol($r, ['registration_no']);
            if ($reg === '') continue;
            $directory[strtolower($reg)] = [
                'registration_no' => $reg,
                'full_name'       => pickCol($r, ['full_name', 'name'], 'Keynote Speaker'),
                'organization'    => pickCol($r, ['organization', 'affiliation', 'country'], 'Keynote Speaker'),
                'role'            => 'Keynote Speaker',
                'diet'            => pickCol($r, ['food_pref', 'diet'], ''),
                'food_reserved'   => 'Yes',
            ];
        }
    } catch (PDOException $e) {}

    try {
        foreach ($pdoConf->query("SELECT * FROM public.local_organizers")->fetchAll() as $r) {
            $idPad = str_pad((string)($r['id'] ?? '1'), 4, '0', STR_PAD_LEFT);
            $reg   = pickCol($r, ['registration_no'], 'IWA/2026/ORG/' . $idPad);
            $directory[strtolower($reg)] = [
                'registration_no' => $reg,
                'full_name'       => pickCol($r, ['full_name', 'name'], 'Local Organizer'),
                'organization'    => 'NIOT Organizing Team',
                'role'            => 'Local Organizer',
                'diet'            => '',
                'food_reserved'   => 'Yes',
            ];
        }
    } catch (PDOException $e) {}
}

// 2. Auto-discover meal/food tables in conference_db and moesnt
function findFoodTables(?PDO $pdo) {
    if (!$pdo) return [];
    $stmt = $pdo->query("
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = 'public'
          AND table_type = 'BASE TABLE'
          AND (
              table_name ILIKE '%meal%'
              OR table_name ILIKE '%food%'
              OR table_name ILIKE '%catering%'
              OR table_name ILIKE '%dining%'
          )
        ORDER BY table_name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$records = [];
$detectedSources = [];

foreach ([['db' => 'conference_db', 'pdo' => $pdoConf], ['db' => 'moesnt', 'pdo' => $pdoMoesnt]] as $conn) {
    $pdo = $conn['pdo'];
    if (!$pdo) continue;

    foreach (findFoodTables($pdo) as $tbl) {
        $detectedSources[] = $conn['db'] . '.' . $tbl;
        try {
            $rows = $pdo->query("SELECT * FROM public.\"$tbl\" ORDER BY 1 DESC")->fetchAll();
            foreach ($rows as $r) {
                $regNo = pickCol($r, ['registration_no', 'reg_no', 'user_id', 'participant_id', 'qr_code', 'qid']);
                if ($regNo === '') continue;

                $meta = $directory[strtolower($regNo)] ?? [
                    'registration_no' => $regNo,
                    'full_name'       => pickCol($r, ['full_name', 'fullname', 'name', 'user_name'], 'Participant'),
                    'organization'    => pickCol($r, ['organization', 'affiliation', 'cname'], '—'),
                    'role'            => pickCol($r, ['role', 'category'], 'Attendee'),
                    'diet'            => '',
                ];

                $mealType = pickCol($r, ['meal_type', 'meal', 'food_type', 'session_type', 'slot', 'category'], 'Lunch');
                $dietPref = pickCol($r, ['diet', 'dietary', 'food_pref', 'preference', 'veg_nonveg'], $meta['diet'] ?: 'Standard');
                $rawTime  = pickCol($r, ['scanned_at', 'scan_time', 'reserved_at', 'created_at', 'updated_at', 'timestamp', 'meal_date', 'conf_date'], '');
                $confDate = '';
                if (preg_match('/(\d{4}-\d{2}-\d{2})/', $rawTime, $m)) {
                    $confDate = $m[1];
                } else {
                    $confDate = pickCol($r, ['conf_date', 'date', 'meal_date'], date('Y-m-d'));
                }

                $rawStatus = strtolower(pickCol($r, ['status', 'scan_status', 'claimed', 'is_claimed', 'is_scanned'], 'claimed'));
                $isClaimed = !in_array($rawStatus, ['reserved', 'pending', 'booked', '0', 'false', 'no', 'n'], true);

                $records[] = [
                    'registration_no' => $meta['registration_no'],
                    'full_name'       => $meta['full_name'],
                    'organization'    => $meta['organization'],
                    'role'            => $meta['role'],
                    'meal_type'       => ucwords(str_replace('_', ' ', $mealType)),
                    'diet'            => ucwords(str_replace('_', ' ', $dietPref)),
                    'conf_date'       => $confDate,
                    'timestamp'       => $rawTime,
                    'status'          => $isClaimed ? 'Claimed / Scanned' : 'Reserved (Pending)',
                    'is_claimed'      => $isClaimed,
                    'source'          => $tbl,
                ];
            }
        } catch (PDOException $e) {
            $dbErrors[] = "Table $tbl: " . $e->getMessage();
        }
    }
}

// If no separate meal table rows exist yet, populate baseline food reservations from registered participants
$usingDirectoryFallback = false;
if (empty($records) && !empty($directory)) {
    $usingDirectoryFallback = true;
    foreach ($directory as $p) {
        $flag = strtolower(trim((string)($p['food_reserved'] ?? '')));
        // Include all registered attendees unless explicitly marked 'no' / 'n' / '0'
        if (in_array($flag, ['no', 'n', '0', 'false'], true)) continue;

        $records[] = [
            'registration_no' => $p['registration_no'],
            'full_name'       => $p['full_name'],
            'organization'    => $p['organization'],
            'role'            => $p['role'],
            'meal_type'       => 'Conference Lunch',
            'diet'            => $p['diet'] !== '' ? ucwords($p['diet']) : 'Standard',
            'conf_date'       => '2026-11-26',
            'timestamp'       => 'Registered Pass',
            'status'          => 'Reserved (Pending)',
            'is_claimed'      => false,
            'source'          => 'registration_directory',
        ];
    }
}

// Aggregate KPI metrics
$uniqueUsers = [];
$claimedCount = 0;
$pendingCount = 0;
$mealBreakdown = [];
$dietBreakdown = [];

foreach ($records as $rec) {
    $uniqueUsers[strtolower($rec['registration_no'])] = true;
    if ($rec['is_claimed']) {
        $claimedCount++;
    } else {
        $pendingCount++;
    }
    $mKey = $rec['meal_type'];
    $mealBreakdown[$mKey] = ($mealBreakdown[$mKey] ?? 0) + 1;

    $dKey = $rec['diet'];
    $dietBreakdown[$dKey] = ($dietBreakdown[$dKey] ?? 0) + 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IWA SCED 2026 — Food Reservation &amp; Meal Counter</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f1f5f9; color: #0f172a; margin: 0; }
        .topbar { background: #0f294a; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; position: sticky; top: 0; z-index: 100; box-shadow: 0 3px 10px rgba(0,0,0,0.18); }
        .topbar h1 { margin: 0; font-size: 19px; }
        .nav-links { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .btn { padding: 7px 13px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-outline { background: transparent; border: 1px solid #64748b; color: #e2e8f0; }
        .btn-outline:hover { background: #1e3a5f; }
        .btn-green { background: #10b981; color: #fff; }
        .btn-green:hover { background: #059669; }

        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; padding: 20px 24px 8px; }
        .kpi-card { background: #fff; border-radius: 10px; padding: 18px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-left: 4px solid #0284c7; }
        .kpi-card.green { border-left-color: #10b981; }
        .kpi-card.amber { border-left-color: #f59e0b; }
        .kpi-card.purple { border-left-color: #7c3aed; }
        .kpi-label { font-size: 12px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.4px; }
        .kpi-value { font-size: 28px; font-weight: 800; color: #0f172a; margin-top: 4px; }
        .kpi-sub { font-size: 12px; color: #475569; margin-top: 4px; }

        .breakdown-bar { display: flex; flex-wrap: wrap; gap: 10px; padding: 10px 24px; }
        .pill { background: #fff; border: 1px solid #cbd5e1; border-radius: 999px; padding: 6px 14px; font-size: 12.5px; font-weight: 600; color: #1e293b; }
        .pill strong { color: #0284c7; }

        .controls { background: #fff; margin: 10px 24px 0; padding: 14px 18px; border-radius: 10px 10px 0 0; border-bottom: 1px solid #e2e8f0; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; }
        .controls-left { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        select, input[type="text"] { padding: 7px 11px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; background: #fff; }
        input[type="text"] { min-width: 240px; }

        .table-wrap { padding: 0 24px 28px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 0 0 10px 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06); font-size: 13px; }
        th { background: #1e293b; color: #fff; text-align: left; padding: 10px 12px; font-weight: 600; }
        td { padding: 9px 12px; border-bottom: 1px solid #e2e8f0; }
        tr:hover td { background: #f8fafc; }
        .mono { font-family: 'Consolas', monospace; font-weight: 700; }
        .badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .badge-claimed { background: #dcfce7; color: #166534; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .info-banner { background: #eff6ff; color: #1e40af; padding: 10px 24px; font-size: 12.5px; border-bottom: 1px solid #bfdbfe; }
    </style>
</head>
<body>

<div class="topbar">
    <h1>IWA SCED 2026 — Food Reservation &amp; Catering Dashboard</h1>
    <div class="nav-links">
        <a href="hall_occupancy.php" class="btn btn-outline">Hall Occupancy</a>
        <a href="schedule_manager.php" class="btn btn-outline">Schedule Manager</a>
        <a href="id_card_generator.php" class="btn btn-outline">ID Card Printer</a>
        <a href="?logout=1" class="btn btn-outline">Logout</a>
    </div>
</div>

<?php if ($usingDirectoryFallback): ?>
    <div class="info-banner">
        Showing all <strong><?= count($uniqueUsers) ?></strong> eligible participants with food passes from the registration directory. Live meal scan counts will update automatically as QR codes are scanned at Manthan Hall.
    </div>
<?php endif; ?>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Total Users Reserved Food</div>
        <div class="kpi-value"><?= count($uniqueUsers) ?></div>
        <div class="kpi-sub">Unique registered participants</div>
    </div>
    <div class="kpi-card purple">
        <div class="kpi-label">Total Meal Entries</div>
        <div class="kpi-value"><?= count($records) ?></div>
        <div class="kpi-sub">Across all sessions &amp; days</div>
    </div>
    <div class="kpi-card green">
        <div class="kpi-label">Meals Claimed / Scanned</div>
        <div class="kpi-value"><?= $claimedCount ?></div>
        <div class="kpi-sub">Verified at dining counter</div>
    </div>
    <div class="kpi-card amber">
        <div class="kpi-label">Reserved (Pending Claim)</div>
        <div class="kpi-value"><?= $pendingCount ?></div>
        <div class="kpi-sub">Remaining meals to be served</div>
    </div>
</div>

<div class="breakdown-bar">
    <?php foreach ($mealBreakdown as $mName => $cnt): ?>
        <span class="pill"><?= htmlspecialchars($mName) ?>: <strong><?= $cnt ?></strong></span>
    <?php endforeach; ?>
    <?php foreach ($dietBreakdown as $dName => $cnt): ?>
        <span class="pill">Diet (<?= htmlspecialchars($dName) ?>): <strong><?= $cnt ?></strong></span>
    <?php endforeach; ?>
</div>

<div class="controls">
    <div class="controls-left">
        <select id="mealFilter" onchange="filterRows()">
            <option value="all">All Meal Types</option>
            <?php foreach (array_keys($mealBreakdown) as $mName): ?>
                <option value="<?= htmlspecialchars(strtolower($mName)) ?>"><?= htmlspecialchars($mName) ?></option>
            <?php endforeach; ?>
        </select>

        <select id="statusFilter" onchange="filterRows()">
            <option value="all">All Statuses</option>
            <option value="claimed">Claimed / Scanned Only</option>
            <option value="pending">Reserved (Pending) Only</option>
        </select>

        <input type="text" id="searchInput" oninput="filterRows()" placeholder="Search Name, Reg No (IWA/2026/...), Org...">
    </div>

    <div style="display:flex;gap:8px;align-items:center;">
        <span style="font-size:12.5px;color:#475569;">Showing <strong id="visibleRows"><?= count($records) ?></strong> rows</span>
        <button type="button" class="btn btn-green" onclick="exportCsv()">Export CSV</button>
    </div>
</div>

<div class="table-wrap">
    <table id="foodTable">
        <thead>
            <tr>
                <th>#</th>
                <th>Registration No</th>
                <th>Participant Name</th>
                <th>Role / Category</th>
                <th>Organization</th>
                <th>Meal Type</th>
                <th>Dietary</th>
                <th>Date / Timestamp</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($records as $idx => $r):
                $searchStr = strtolower($r['registration_no'] . ' ' . $r['full_name'] . ' ' . $r['organization'] . ' ' . $r['role'] . ' ' . $r['meal_type']);
            ?>
            <tr data-meal="<?= htmlspecialchars(strtolower($r['meal_type'])) ?>"
                data-status="<?= $r['is_claimed'] ? 'claimed' : 'pending' ?>"
                data-search="<?= htmlspecialchars($searchStr) ?>">
                <td><?= $idx + 1 ?></td>
                <td class="mono"><?= htmlspecialchars($r['registration_no']) ?></td>
                <td><strong><?= htmlspecialchars($r['full_name']) ?></strong></td>
                <td><?= htmlspecialchars($r['role']) ?></td>
                <td><?= htmlspecialchars($r['organization']) ?></td>
                <td><?= htmlspecialchars($r['meal_type']) ?></td>
                <td><?= htmlspecialchars($r['diet']) ?></td>
                <td class="mono" style="font-size:12px;"><?= htmlspecialchars($r['timestamp'] ?: $r['conf_date']) ?></td>
                <td>
                    <span class="badge <?= $r['is_claimed'] ? 'badge-claimed' : 'badge-pending' ?>">
                        <?= htmlspecialchars($r['status']) ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
    function filterRows() {
        const meal   = document.getElementById('mealFilter').value;
        const status = document.getElementById('statusFilter').value;
        const q      = document.getElementById('searchInput').value.trim().toLowerCase();
        let shown    = 0;

        document.querySelectorAll('#foodTable tbody tr').forEach(tr => {
            const okMeal   = (meal === 'all' || tr.dataset.meal === meal);
            const okStatus = (status === 'all' || tr.dataset.status === status);
            const okQ      = (q === '' || tr.dataset.search.indexOf(q) !== -1);
            if (okMeal && okStatus && okQ) {
                tr.style.display = '';
                shown++;
            } else {
                tr.style.display = 'none';
            }
        });
        document.getElementById('visibleRows').textContent = shown;
    }

    function exportCsv() {
        const rows = [['Registration No', 'Name', 'Role', 'Organization', 'Meal Type', 'Dietary', 'Timestamp', 'Status']];
        document.querySelectorAll('#foodTable tbody tr').forEach(tr => {
            if (tr.style.display === 'none') return;
            const cells = Array.from(tr.querySelectorAll('td')).slice(1).map(td => '"' + td.innerText.trim().replace(/"/g, '""') + '"');
            rows.push(cells);
        });
        const blob = new Blob([rows.map(r => r.join(',')).join('\n')], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'IWA2026_Food_Reservations.csv';
        link.click();
    }
</script>

</body>
</html>