<?php
// hall_occupancy.php - Admin Hall-Wise Live Occupancy & Entry/Exit Dashboard (PHP 7.4 Compatible)
session_start();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$ADMIN_PASS = 'Admin@123';
if (isset($_GET['logout'])) {
    unset($_SESSION['hall_admin_auth']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    if (hash_equals($ADMIN_PASS, trim($_POST['admin_password']))) {
        $_SESSION['hall_admin_auth'] = true;
    } else {
        $loginError = 'Invalid admin password.';
    }
}

if (empty($_SESSION['hall_admin_auth'])):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IWA SCED 2026 — Hall Occupancy Login</title>
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
        <p>Live Hall-Wise Occupancy Monitor</p>
        <?php if (!empty($loginError)): ?><div class="err"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
        <input type="password" name="admin_password" placeholder="Enter Admin Password" required autofocus>
        <button type="submit">Unlock Hall Monitor</button>
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

try {
    $pdoConf = new PDO("pgsql:host=$host;port=$port;dbname=conference_db", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {}

try {
    $pdoMoesnt = new PDO("pgsql:host=$host;port=$port;dbname=moesnt", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {}

function pickColVal(array $row, array $candidates, $default = '') {
    foreach ($candidates as $col) {
        if (isset($row[$col]) && trim((string)$row[$col]) !== '' && strtolower(trim((string)$row[$col])) !== 'null') {
            return trim((string)$row[$col]);
        }
    }
    return $default;
}

function normalizeHallName($raw) {
    $s = trim((string)$raw);
    if ($s === '') return 'Unassigned Hall';
    $low = strtolower($s);
    if (strpos($low, 'pannikkar') !== false) return 'Pannikkar Hall';
    if (strpos($low, 'chola') !== false) return 'Chola Hall';
    if (strpos($low, 'varuna') !== false) return 'Varuna Hall';
    if (strpos($low, 'german') !== false) return 'German Tent';
    if (strpos($low, 'manthan') !== false) return 'Manthan Hall';
    if (strpos($low, 'main') !== false) return 'NIOT Main Building';
    return ucwords($s);
}

// 1. Build Participant Directory
$directory = [];
if ($pdoMoesnt) {
    try {
        foreach ($pdoMoesnt->query("SELECT * FROM public.iwa2026_registration")->fetchAll() as $r) {
            $reg = pickColVal($r, ['registration_no']);
            if ($reg === '') continue;
            $directory[strtolower($reg)] = [
                'registration_no' => $reg,
                'full_name'       => pickColVal($r, ['fullname', 'full_name', 'name'], 'Delegate'),
                'organization'    => pickColVal($r, ['organization', 'affiliation', 'institute', 'cname'], 'Delegate'),
                'role'            => pickColVal($r, ['category', 'pcategory', 'participant_category'], 'Delegate'),
            ];
        }
    } catch (PDOException $e) {}

    try {
        foreach ($pdoMoesnt->query("SELECT * FROM public.iwa2026_spregistration WHERE COALESCE(delflag, 'N') != 'Y'")->fetchAll() as $r) {
            $reg = pickColVal($r, ['registration_no']);
            if ($reg === '') continue;
            $directory[strtolower($reg)] = [
                'registration_no' => $reg,
                'full_name'       => pickColVal($r, ['fullname', 'full_name', 'name'], 'Representative'),
                'organization'    => pickColVal($r, ['cname', 'organization'], 'Sponsor / Exhibitor'),
                'role'            => (stripos($reg, '/EX/') !== false) ? 'Exhibitor' : 'Sponsor',
            ];
        }
    } catch (PDOException $e) {}
}

if ($pdoConf) {
    try {
        foreach ($pdoConf->query("SELECT * FROM public.keynote_speakers")->fetchAll() as $r) {
            $reg = pickColVal($r, ['registration_no']);
            if ($reg === '') continue;
            $directory[strtolower($reg)] = [
                'registration_no' => $reg,
                'full_name'       => pickColVal($r, ['full_name', 'name'], 'Keynote Speaker'),
                'organization'    => pickColVal($r, ['organization', 'affiliation', 'country'], 'Keynote Speaker'),
                'role'            => 'Keynote Speaker',
            ];
        }
    } catch (PDOException $e) {}

    try {
        foreach ($pdoConf->query("SELECT * FROM public.local_organizers")->fetchAll() as $r) {
            $idPad = str_pad((string)($r['id'] ?? '1'), 4, '0', STR_PAD_LEFT);
            $reg   = pickColVal($r, ['registration_no'], 'IWA/2026/ORG/' . $idPad);
            $directory[strtolower($reg)] = [
                'registration_no' => $reg,
                'full_name'       => pickColVal($r, ['full_name', 'name'], 'Organizer'),
                'organization'    => 'NIOT Organizing Team',
                'role'            => 'Local Organizer',
            ];
        }
    } catch (PDOException $e) {}
}

// 2. Initialize Standard Halls + Any Venues from conference_db.venues
$hallStats = [];
$defaultHalls = [
    'Pannikkar Hall',
    'Chola Hall',
    'Varuna Hall',
    'German Tent',
    'Manthan Hall',
    'NIOT Main Building',
];
foreach ($defaultHalls as $h) {
    $hallStats[$h] = [
        'hall_name'       => $h,
        'capacity'        => 0,
        'inside_count'    => 0,
        'total_entries'   => 0,
        'total_exits'     => 0,
        'current_session' => '',
    ];
}

$venueIdMap = [];
if ($pdoConf) {
    try {
        foreach ($pdoConf->query("SELECT * FROM public.venues")->fetchAll() as $v) {
            $hName = normalizeHallName(pickColVal($v, ['venue_name', 'name', 'hall_name', 'title']));
            $vId   = pickColVal($v, ['id', 'venue_id']);
            if ($vId !== '') {
                $venueIdMap[$vId] = $hName;
            }
            if (!isset($hallStats[$hName])) {
                $hallStats[$hName] = [
                    'hall_name'       => $hName,
                    'capacity'        => 0,
                    'inside_count'    => 0,
                    'total_entries'   => 0,
                    'total_exits'     => 0,
                    'current_session' => '',
                ];
            }
            $hallStats[$hName]['capacity'] = (int)pickColVal($v, ['capacity', 'max_capacity', 'seats'], '0');
        }
    } catch (PDOException $e) {}
}

// Attach current/upcoming session label from iwa2026_schedule for today's date
if ($pdoMoesnt) {
    try {
        $today = date('Y-m-d');
        $nowT  = date('H:i:s');
        $stmtSch = $pdoMoesnt->prepare("
            SELECT venue, program_name, from_time, to_time
            FROM public.iwa2026_schedule
            WHERE conf_date = :d AND from_time <= :t AND to_time >= :t
        ");
        $stmtSch->execute([':d' => $today, ':t' => $nowT]);
        foreach ($stmtSch->fetchAll() as $s) {
            $hName = normalizeHallName($s['venue']);
            if (isset($hallStats[$hName])) {
                $hallStats[$hName]['current_session'] = $s['program_name'] . ' (' . substr($s['from_time'], 0, 5) . '–' . substr($s['to_time'], 0, 5) . ')';
            }
        }
    } catch (PDOException $e) {}
}

// 3. Auto-discover venue/hall scan tables across conference_db and moesnt
function findVenueScanTables(?PDO $pdo) {
    if (!$pdo) return [];
    $stmt = $pdo->query("
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = 'public'
          AND table_type = 'BASE TABLE'
          AND (
              table_name ILIKE '%venue_scan%'
              OR table_name ILIKE '%hall%'
              OR table_name ILIKE '%room_scan%'
              OR table_name ILIKE '%entry%'
              OR table_name ILIKE '%attendance%'
          )
        ORDER BY table_name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$rawScans = [];
foreach ([['db' => 'conference_db', 'pdo' => $pdoConf], ['db' => 'moesnt', 'pdo' => $pdoMoesnt]] as $conn) {
    $pdo = $conn['pdo'];
    if (!$pdo) continue;

    foreach (findVenueScanTables($pdo) as $tbl) {
        try {
            $rows = $pdo->query("SELECT * FROM public.\"$tbl\" ORDER BY 1 ASC")->fetchAll();
            foreach ($rows as $r) {
                $regNo = pickColVal($r, ['registration_no', 'reg_no', 'user_id', 'participant_id', 'qr_code', 'qid']);
                if ($regNo === '') continue;

                $rawHall = pickColVal($r, ['venue_name', 'hall_name', 'venue', 'hall', 'location', 'room']);
                if ($rawHall === '') {
                    $vId = pickColVal($r, ['venue_id', 'hall_id']);
                    $rawHall = $venueIdMap[$vId] ?? 'NIOT Main Building';
                }
                $hallName = normalizeHallName($rawHall);

                $actionRaw = strtolower(pickColVal($r, ['scan_type', 'action', 'direction', 'status', 'entry_exit', 'type'], 'in'));
                $isExit    = in_array($actionRaw, ['out', 'exit', 'checkout', 'check_out', 'leave', 'left'], true);
                $ts        = pickColVal($r, ['scanned_at', 'scan_time', 'created_at', 'timestamp', 'entry_time'], '');

                $rawScans[] = [
                    'registration_no' => $regNo,
                    'hall_name'       => $hallName,
                    'is_exit'         => $isExit,
                    'action_label'    => $isExit ? 'EXIT' : 'ENTRY',
                    'timestamp'       => $ts,
                ];
            }
        } catch (PDOException $e) {}
    }
}

// 4. Compute Current Hall Occupancy (tracks latest location per participant)
$userCurrentHall = []; // lower(reg_no) => ['hall' => ..., 'time' => ..., 'inside' => bool]

foreach ($rawScans as $sc) {
    $h = $sc['hall_name'];
    if (!isset($hallStats[$h])) {
        $hallStats[$h] = [
            'hall_name'       => $h,
            'capacity'        => 0,
            'inside_count'    => 0,
            'total_entries'   => 0,
            'total_exits'     => 0,
            'current_session' => '',
        ];
    }

    $key = strtolower($sc['registration_no']);
    if ($sc['is_exit']) {
        $hallStats[$h]['total_exits']++;
        $userCurrentHall[$key] = [
            'registration_no' => $sc['registration_no'],
            'hall_name'       => $h,
            'timestamp'       => $sc['timestamp'],
            'inside'          => false,
        ];
    } else {
        $hallStats[$h]['total_entries']++;
        $userCurrentHall[$key] = [
            'registration_no' => $sc['registration_no'],
            'hall_name'       => $h,
            'timestamp'       => $sc['timestamp'],
            'inside'          => true,
        ];
    }
}

$insideOccupants = [];
foreach ($userCurrentHall as $key => $state) {
    if (!$state['inside']) continue;
    $h = $state['hall_name'];
    $hallStats[$h]['inside_count']++;

    $meta = $directory[$key] ?? [
        'registration_no' => $state['registration_no'],
        'full_name'       => 'Participant',
        'organization'    => '—',
        'role'            => 'Attendee',
    ];

    $insideOccupants[] = [
        'registration_no' => $meta['registration_no'],
        'full_name'       => $meta['full_name'],
        'organization'    => $meta['organization'],
        'role'            => $meta['role'],
        'hall_name'       => $h,
        'entry_time'      => $state['timestamp'] ?: 'Checked In',
    ];
}

$totalInsideAllHalls = count($insideOccupants);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IWA SCED 2026 — Hall-Wise Occupancy Monitor</title>
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
        .btn-blue { background: #0284c7; color: #fff; }

        .summary-strip { background: #fff; padding: 12px 24px; border-bottom: 1px solid #cbd5e1; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; font-size: 13.5px; }
        .hall-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; padding: 20px 24px; }
        .hall-card {
            background: #fff;
            border-radius: 12px;
            padding: 18px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            border: 2px solid transparent;
            cursor: pointer;
            transition: transform 0.12s, border-color 0.15s;
        }
        .hall-card:hover { transform: translateY(-2px); border-color: #38bdf8; }
        .hall-card.active { border-color: #0284c7; background: #f0f9ff; }
        .hall-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
        .hall-name { font-size: 16px; font-weight: 800; color: #0f294a; }
        .live-dot { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; color: #059669; background: #dcfce7; padding: 2px 8px; border-radius: 999px; }
        .hall-count { font-size: 34px; font-weight: 900; color: #0284c7; margin: 8px 0 4px; line-height: 1; }
        .hall-count span { font-size: 13px; font-weight: 600; color: #64748b; }
        .hall-meta { font-size: 12px; color: #475569; margin-top: 6px; display: flex; justify-content: space-between; }
        .hall-session { margin-top: 8px; padding-top: 8px; border-top: 1px dashed #cbd5e1; font-size: 11.5px; color: #0f294a; font-weight: 600; }

        .controls { background: #fff; margin: 0 24px; padding: 14px 18px; border-radius: 10px 10px 0 0; border-bottom: 1px solid #e2e8f0; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; }
        select, input[type="text"] { padding: 7px 11px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; background: #fff; }
        input[type="text"] { min-width: 240px; }

        .table-wrap { padding: 0 24px 28px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 0 0 10px 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06); font-size: 13px; }
        th { background: #1e293b; color: #fff; text-align: left; padding: 10px 12px; font-weight: 600; }
        td { padding: 9px 12px; border-bottom: 1px solid #e2e8f0; }
        tr:hover td { background: #f8fafc; }
        .mono { font-family: 'Consolas', monospace; font-weight: 700; }
        .badge-hall { background: #e0f2fe; color: #0369a1; padding: 3px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 700; }
    </style>
</head>
<body>

<div class="topbar">
    <h1>IWA SCED 2026 — Hall-Wise Live Occupancy Monitor</h1>
    <div class="nav-links">
        <a href="food_dashboard.php" class="btn btn-outline">Food Tracker</a>
        <a href="schedule_manager.php" class="btn btn-outline">Schedule Manager</a>
        <a href="id_card_generator.php" class="btn btn-outline">ID Card Printer</a>
        <a href="?logout=1" class="btn btn-outline">Logout</a>
    </div>
</div>

<div class="summary-strip">
    <div>
        Total Participants Currently Inside All Halls:
        <strong style="font-size:17px;color:#0284c7;margin-left:6px;"><?= $totalInsideAllHalls ?></strong>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
        <label style="font-size:12.5px;cursor:pointer;">
            <input type="checkbox" id="autoRefreshCheck" onchange="toggleAutoRefresh(this.checked)">
            Auto-refresh every 15s
        </label>
        <button type="button" class="btn btn-blue" onclick="location.reload()">Refresh Now</button>
    </div>
</div>

<!-- Hall-Wise Occupancy Cards -->
<div class="hall-grid">
    <?php foreach ($hallStats as $hName => $st): ?>
        <div class="hall-card" data-hall-card="<?= htmlspecialchars(strtolower($hName)) ?>" onclick="selectHallCard('<?= htmlspecialchars(strtolower($hName), ENT_QUOTES) ?>')">
            <div class="hall-top">
                <div class="hall-name"><?= htmlspecialchars($hName) ?></div>
                <span class="live-dot">● LIVE</span>
            </div>
            <div class="hall-count">
                <?= (int)$st['inside_count'] ?>
                <span>inside<?= $st['capacity'] > 0 ? ' / ' . (int)$st['capacity'] . ' cap.' : '' ?></span>
            </div>
            <div class="hall-meta">
                <span>Total Entries: <strong><?= (int)$st['total_entries'] ?></strong></span>
                <span>Exits: <strong><?= (int)$st['total_exits'] ?></strong></span>
            </div>
            <?php if ($st['current_session'] !== ''): ?>
                <div class="hall-session">Now: <?= htmlspecialchars($st['current_session']) ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- Occupant Filter & Table -->
<div class="controls">
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
        <select id="hallFilter" onchange="filterOccupants()">
            <option value="all">All Halls (<?= $totalInsideAllHalls ?> inside)</option>
            <?php foreach ($hallStats as $hName => $st): ?>
                <option value="<?= htmlspecialchars(strtolower($hName)) ?>">
                    <?= htmlspecialchars($hName) ?> (<?= (int)$st['inside_count'] ?> inside)
                </option>
            <?php endforeach; ?>
        </select>

        <input type="text" id="searchInput" oninput="filterOccupants()" placeholder="Search Name, Registration No, or Org...">
    </div>

    <div style="display:flex;gap:8px;align-items:center;">
        <span style="font-size:12.5px;color:#475569;">Showing <strong id="visibleCount"><?= $totalInsideAllHalls ?></strong> occupants</span>
        <button type="button" class="btn btn-green" onclick="exportHallCsv()">Export CSV</button>
    </div>
</div>

<div class="table-wrap">
    <table id="hallTable">
        <thead>
            <tr>
                <th>#</th>
                <th>Registration No</th>
                <th>Participant Name</th>
                <th>Role / Category</th>
                <th>Organization</th>
                <th>Current Hall</th>
                <th>Entry Time</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($insideOccupants)): ?>
                <tr>
                    <td colspan="7" style="text-align:center;padding:24px;color:#64748b;">
                        No hall entry scans recorded yet. As attendees scan their badges via <strong>Venue Scan</strong>, their live hall counts and names will appear here.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($insideOccupants as $idx => $occ):
                    $searchStr = strtolower($occ['registration_no'] . ' ' . $occ['full_name'] . ' ' . $occ['organization'] . ' ' . $occ['role'] . ' ' . $occ['hall_name']);
                ?>
                <tr data-hall="<?= htmlspecialchars(strtolower($occ['hall_name'])) ?>"
                    data-search="<?= htmlspecialchars($searchStr) ?>">
                    <td><?= $idx + 1 ?></td>
                    <td class="mono"><?= htmlspecialchars($occ['registration_no']) ?></td>
                    <td><strong><?= htmlspecialchars($occ['full_name']) ?></strong></td>
                    <td><?= htmlspecialchars($occ['role']) ?></td>
                    <td><?= htmlspecialchars($occ['organization']) ?></td>
                    <td><span class="badge-hall"><?= htmlspecialchars($occ['hall_name']) ?></span></td>
                    <td class="mono" style="font-size:12px;"><?= htmlspecialchars($occ['entry_time']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
    let refreshTimer = null;

    function toggleAutoRefresh(enabled) {
        if (refreshTimer) clearInterval(refreshTimer);
        if (enabled) {
            refreshTimer = setInterval(() => location.reload(), 15000);
        }
    }

    function selectHallCard(hallKey) {
        const select = document.getElementById('hallFilter');
        select.value = (select.value === hallKey) ? 'all' : hallKey;
        filterOccupants();
    }

    function filterOccupants() {
        const hall = document.getElementById('hallFilter').value;
        const q    = document.getElementById('searchInput').value.trim().toLowerCase();
        let shown  = 0;

        document.querySelectorAll('.hall-card').forEach(card => {
            card.classList.toggle('active', card.dataset.hallCard === hall);
        });

        document.querySelectorAll('#hallTable tbody tr[data-hall]').forEach(tr => {
            const okHall = (hall === 'all' || tr.dataset.hall === hall);
            const okQ    = (q === '' || tr.dataset.search.indexOf(q) !== -1);
            if (okHall && okQ) {
                tr.style.display = '';
                shown++;
            } else {
                tr.style.display = 'none';
            }
        });
        document.getElementById('visibleCount').textContent = shown;
    }

    function exportHallCsv() {
        const rows = [['Registration No', 'Name', 'Role', 'Organization', 'Current Hall', 'Entry Time']];
        document.querySelectorAll('#hallTable tbody tr[data-hall]').forEach(tr => {
            if (tr.style.display === 'none') return;
            const cells = Array.from(tr.querySelectorAll('td')).slice(1).map(td => '"' + td.innerText.trim().replace(/"/g, '""') + '"');
            rows.push(cells);
        });
        const blob = new Blob([rows.map(r => r.join(',')).join('\n')], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'IWA2026_Hall_Occupancy.csv';
        link.click();
    }
</script>

</body>
</html>