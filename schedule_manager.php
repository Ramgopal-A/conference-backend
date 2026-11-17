<?php
// schedule_manager.php - Admin Live Conference Schedule Editor with Conflict Detection (PHP 7.4 Compatible)
session_start();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$ADMIN_PASS = 'Admin@123';
if (isset($_GET['logout'])) {
    unset($_SESSION['sched_admin_auth']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    if (hash_equals($ADMIN_PASS, trim($_POST['admin_password']))) {
        $_SESSION['sched_admin_auth'] = true;
    } else {
        $loginError = 'Invalid admin password.';
    }
}

if (empty($_SESSION['sched_admin_auth'])):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IWA SCED 2026 — Schedule Manager Login</title>
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
        <p>Live Schedule &amp; Slot Management</p>
        <?php if (!empty($loginError)): ?><div class="err"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
        <input type="password" name="admin_password" placeholder="Enter Admin Password" required autofocus>
        <button type="submit">Unlock Schedule Manager</button>
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

try {
    $pdoMoesnt = new PDO("pgsql:host=$host;port=$port;dbname=moesnt", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Database connection to moesnt failed: " . htmlspecialchars($e->getMessage()));
}

$keynoteSpeakers = [];
try {
    $pdoConf = new PDO("pgsql:host=$host;port=$port;dbname=conference_db", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $keynoteSpeakers = $pdoConf->query("SELECT registration_no, COALESCE(full_name, name) AS speaker_name FROM public.keynote_speakers ORDER BY registration_no ASC")->fetchAll();
} catch (PDOException $e) {
    // Non-fatal
}

$abstractOptions = [];
try {
    $abstractOptions = $pdoMoesnt->query("SELECT abstract_id, registration_no, title, author_name FROM public.iwa2026_abstract ORDER BY abstract_id ASC")->fetchAll();
} catch (PDOException $e) {
    // Non-fatal
}

$flashMsg  = '';
$flashType = 'success';

function normalizeTimeInput($t) {
    $t = trim($t);
    if (preg_match('/^\d{2}:\d{2}$/', $t)) {
        return $t . ':00';
    }
    return $t;
}

/**
 * Checks for venue/time overlaps and duplicate presenter/keynote assignments.
 * Returns an array of human-readable conflict descriptions.
 */
function findScheduleConflicts(PDO $pdo, $confDate, $fromTime, $toTime, $venue, $presId, $keyId, $excludeId = 0) {
    $conflicts = [];

    if ($fromTime >= $toTime) {
        $conflicts[] = "Invalid time range: Start time ($fromTime) must be earlier than End time ($toTime).";
        return $conflicts;
    }

    // 1. Check same Date + same Venue + overlapping Time
    $stmtVenue = $pdo->prepare("
        SELECT id, from_time, to_time, venue, program_name, presenter_id, keynote_speaker_id
        FROM public.iwa2026_schedule
        WHERE conf_date = :conf_date
          AND TRIM(LOWER(venue)) = TRIM(LOWER(:venue))
          AND id != :exclude_id
          AND from_time < :to_time
          AND to_time > :from_time
        ORDER BY from_time ASC
    ");
    $stmtVenue->execute([
        ':conf_date'  => $confDate,
        ':venue'      => $venue,
        ':exclude_id' => $excludeId,
        ':from_time'  => $fromTime,
        ':to_time'    => $toTime,
    ]);
    foreach ($stmtVenue->fetchAll() as $c) {
        $who = $c['presenter_id'] ?: ($c['keynote_speaker_id'] ?: $c['program_name']);
        $conflicts[] = "Time/Venue Clash with Slot #{$c['id']} ({$c['venue']}, " .
            substr($c['from_time'], 0, 5) . "–" . substr($c['to_time'], 0, 5) . " — {$who})";
    }

    // 2. Check duplicate Presenter / Abstract ID across schedule
    if ($presId !== '') {
        $stmtPres = $pdo->prepare("
            SELECT id, conf_date, from_time, to_time, venue, program_name
            FROM public.iwa2026_schedule
            WHERE TRIM(LOWER(presenter_id)) = TRIM(LOWER(:pres_id))
              AND id != :exclude_id
            ORDER BY conf_date ASC, from_time ASC
        ");
        $stmtPres->execute([
            ':pres_id'    => $presId,
            ':exclude_id' => $excludeId,
        ]);
        foreach ($stmtPres->fetchAll() as $c) {
            $conflicts[] = "Presenter/Abstract '{$presId}' is already assigned to Slot #{$c['id']} ({$c['conf_date']}, " .
                substr($c['from_time'], 0, 5) . "–" . substr($c['to_time'], 0, 5) . " in {$c['venue']})";
        }
    }

    // 3. Check duplicate Keynote Speaker ID across schedule
    if ($keyId !== '') {
        $stmtKey = $pdo->prepare("
            SELECT id, conf_date, from_time, to_time, venue, program_name
            FROM public.iwa2026_schedule
            WHERE TRIM(LOWER(keynote_speaker_id)) = TRIM(LOWER(:key_id))
              AND id != :exclude_id
            ORDER BY conf_date ASC, from_time ASC
        ");
        $stmtKey->execute([
            ':key_id'     => $keyId,
            ':exclude_id' => $excludeId,
        ]);
        foreach ($stmtKey->fetchAll() as $c) {
            $conflicts[] = "Keynote Speaker '{$keyId}' is already assigned to Slot #{$c['id']} ({$c['conf_date']}, " .
                substr($c['from_time'], 0, 5) . "–" . substr($c['to_time'], 0, 5) . " in {$c['venue']})";
        }
    }

    return $conflicts;
}

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action    = $_POST['action'];
    $forceSave = !empty($_POST['force_save']);

    try {
        if ($action === 'update') {
            $id       = (int)($_POST['id'] ?? 0);
            $confDate = trim($_POST['conf_date'] ?? '');
            $fromTime = normalizeTimeInput($_POST['from_time'] ?? '');
            $toTime   = normalizeTimeInput($_POST['to_time'] ?? '');
            $venue    = trim($_POST['venue'] ?? '');
            $progName = trim($_POST['program_name'] ?? '');
            $presId   = trim($_POST['presenter_id'] ?? '');
            $keyId    = trim($_POST['keynote_speaker_id'] ?? '');

            $conflicts = findScheduleConflicts($pdoMoesnt, $confDate, $fromTime, $toTime, $venue, $presId, $keyId, $id);
            if (!empty($conflicts) && !$forceSave) {
                throw new Exception("Update blocked due to conflict: " . implode(" | ", $conflicts) . " (Tick 'Force save despite conflict' inside Edit if intentional.)");
            }

            $stmt = $pdoMoesnt->prepare("
                UPDATE public.iwa2026_schedule
                SET conf_date = :conf_date,
                    from_time = :from_time,
                    to_time = :to_time,
                    venue = :venue,
                    program_name = :program_name,
                    presenter_id = :presenter_id,
                    keynote_speaker_id = :keynote_speaker_id
                WHERE id = :id
            ");
            $stmt->execute([
                ':conf_date'          => $confDate,
                ':from_time'          => $fromTime,
                ':to_time'            => $toTime,
                ':venue'              => $venue,
                ':program_name'       => $progName,
                ':presenter_id'       => ($presId !== '' ? $presId : null),
                ':keynote_speaker_id' => ($keyId !== '' ? $keyId : null),
                ':id'                 => $id,
            ]);
            $flashMsg = "Slot #$id updated successfully." . (!empty($conflicts) ? " (Saved with override)" : "");

        } elseif ($action === 'create') {
            $confDate = trim($_POST['conf_date'] ?? '2026-11-26');
            $fromTime = normalizeTimeInput($_POST['from_time'] ?? '09:00:00');
            $toTime   = normalizeTimeInput($_POST['to_time'] ?? '09:15:00');
            $venue    = trim($_POST['venue'] ?? 'Pannikkar Hall');
            $progName = trim($_POST['program_name'] ?? 'Presentation');
            $presId   = trim($_POST['presenter_id'] ?? '');
            $keyId    = trim($_POST['keynote_speaker_id'] ?? '');

            $conflicts = findScheduleConflicts($pdoMoesnt, $confDate, $fromTime, $toTime, $venue, $presId, $keyId, 0);
            if (!empty($conflicts) && !$forceSave) {
                throw new Exception("Slot creation blocked due to conflict: " . implode(" | ", $conflicts) . " (Tick 'Force save despite conflict' if intentional.)");
            }

            $stmt = $pdoMoesnt->prepare("
                INSERT INTO public.iwa2026_schedule (conf_date, from_time, to_time, venue, program_name, presenter_id, keynote_speaker_id)
                VALUES (:conf_date, :from_time, :to_time, :venue, :program_name, :presenter_id, :keynote_speaker_id)
            ");
            $stmt->execute([
                ':conf_date'          => $confDate,
                ':from_time'          => $fromTime,
                ':to_time'            => $toTime,
                ':venue'              => $venue,
                ':program_name'       => $progName,
                ':presenter_id'       => ($presId !== '' ? $presId : null),
                ':keynote_speaker_id' => ($keyId !== '' ? $keyId : null),
            ]);
            $flashMsg = "New schedule slot added successfully.";

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdoMoesnt->prepare("DELETE FROM public.iwa2026_schedule WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $flashMsg = "Slot #$id deleted.";

        } elseif ($action === 'swap') {
            $idA = (int)($_POST['swap_id_a'] ?? 0);
            $idB = (int)($_POST['swap_id_b'] ?? 0);

            if ($idA <= 0 || $idB <= 0 || $idA === $idB) {
                throw new Exception("Please enter two different valid Slot IDs to swap.");
            }

            $pdoMoesnt->beginTransaction();
            $sA = $pdoMoesnt->prepare("SELECT * FROM public.iwa2026_schedule WHERE id = ?");
            $sA->execute([$idA]);
            $rowA = $sA->fetch();

            $sB = $pdoMoesnt->prepare("SELECT * FROM public.iwa2026_schedule WHERE id = ?");
            $sB->execute([$idB]);
            $rowB = $sB->fetch();

            if (!$rowA || !$rowB) {
                $pdoMoesnt->rollBack();
                throw new Exception("One or both Slot IDs (#$idA, #$idB) were not found.");
            }

            $upd = $pdoMoesnt->prepare("
                UPDATE public.iwa2026_schedule
                SET program_name = :prog,
                    presenter_id = :pres,
                    keynote_speaker_id = :key
                WHERE id = :id
            ");
            $upd->execute([
                ':prog' => $rowB['program_name'],
                ':pres' => $rowB['presenter_id'],
                ':key'  => $rowB['keynote_speaker_id'],
                ':id'   => $idA,
            ]);
            $upd->execute([
                ':prog' => $rowA['program_name'],
                ':pres' => $rowA['presenter_id'],
                ':key'  => $rowA['keynote_speaker_id'],
                ':id'   => $idB,
            ]);
            $pdoMoesnt->commit();
            $flashMsg = "Swapped sessions between Slot #$idA ({$rowA['from_time']} {$rowA['venue']}) and Slot #$idB ({$rowB['from_time']} {$rowB['venue']}).";
        }
    } catch (Exception $e) {
        $flashMsg  = $e->getMessage();
        $flashType = 'error';
    }
}

$scheduleRows = $pdoMoesnt->query("
    SELECT * FROM public.iwa2026_schedule
    ORDER BY conf_date ASC, from_time ASC, venue ASC, id ASC
")->fetchAll();

// Pre-detect any existing clashes in the table so they can be highlighted
$clashingSlotIds = [];
$totalRows = count($scheduleRows);
for ($i = 0; $i < $totalRows; $i++) {
    for ($j = $i + 1; $j < $totalRows; $j++) {
        $a = $scheduleRows[$i];
        $b = $scheduleRows[$j];

        // Same date + same venue + overlapping time
        if ($a['conf_date'] === $b['conf_date'] &&
            strcasecmp(trim($a['venue']), trim($b['venue'])) === 0 &&
            $a['from_time'] < $b['to_time'] &&
            $a['to_time'] > $b['from_time']) {
            $clashingSlotIds[(int)$a['id']][] = "Overlaps Slot #{$b['id']} in {$b['venue']}";
            $clashingSlotIds[(int)$b['id']][] = "Overlaps Slot #{$a['id']} in {$a['venue']}";
        }

        // Duplicate presenter_id
        if (!empty($a['presenter_id']) && !empty($b['presenter_id']) &&
            strcasecmp(trim($a['presenter_id']), trim($b['presenter_id'])) === 0) {
            $clashingSlotIds[(int)$a['id']][] = "Duplicate Presenter ID with Slot #{$b['id']}";
            $clashingSlotIds[(int)$b['id']][] = "Duplicate Presenter ID with Slot #{$a['id']}";
        }

        // Duplicate keynote_speaker_id
        if (!empty($a['keynote_speaker_id']) && !empty($b['keynote_speaker_id']) &&
            strcasecmp(trim($a['keynote_speaker_id']), trim($b['keynote_speaker_id'])) === 0) {
            $clashingSlotIds[(int)$a['id']][] = "Duplicate Keynote ID with Slot #{$b['id']}";
            $clashingSlotIds[(int)$b['id']][] = "Duplicate Keynote ID with Slot #{$a['id']}";
        }
    }
}

$venuesList = [
    'Pannikkar Hall',
    'Chola Hall',
    'Varuna Hall',
    'German Tent',
    'NIOT Main Building',
    'Manthan Hall',
    'NIOT Lawn'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IWA SCED 2026 — Live Schedule Manager</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f1f5f9; color: #0f172a; margin: 0; }
        .topbar { background: #0f294a; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; box-shadow: 0 3px 10px rgba(0,0,0,0.18); }
        .topbar h1 { margin: 0; font-size: 19px; }
        .topbar-actions { display: flex; gap: 10px; align-items: center; }
        .btn { padding: 8px 14px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-green { background: #10b981; color: #fff; }
        .btn-green:hover { background: #059669; }
        .btn-blue { background: #0284c7; color: #fff; }
        .btn-blue:hover { background: #0369a1; }
        .btn-amber { background: #d97706; color: #fff; }
        .btn-amber:hover { background: #b45309; }
        .btn-red { background: #dc2626; color: #fff; }
        .btn-red:hover { background: #b91c1c; }
        .btn-outline { background: transparent; border: 1px solid #64748b; color: #cbd5e1; }

        .flash { padding: 12px 24px; font-size: 13.5px; font-weight: 600; line-height: 1.4; }
        .flash.success { background: #dcfce7; color: #166534; border-bottom: 1px solid #86efac; }
        .flash.error { background: #fee2e2; color: #991b1b; border-bottom: 1px solid #fca5a5; }

        .controls-panel { background: #fff; padding: 14px 24px; border-bottom: 1px solid #cbd5e1; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; }
        .filter-group, .swap-group { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        select, input[type="text"], input[type="date"], input[type="time"], input[type="number"] {
            padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; background: #fff; color: #0f172a;
        }
        input[type="number"] { width: 95px; }

        .table-wrap { padding: 18px 24px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06); font-size: 13px; }
        th { background: #1e293b; color: #fff; text-align: left; padding: 10px 12px; font-weight: 600; white-space: nowrap; }
        td { padding: 9px 12px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        tr:hover td { background: #f8fafc; }
        tr.row-clash td { background: #fef2f2; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .badge-key { background: #ede9fe; color: #5b21b6; }
        .badge-pres { background: #e0f2fe; color: #0369a1; }
        .badge-clash { background: #fee2e2; color: #b91c1c; border: 1px solid #f87171; margin-left: 4px; }
        .mono { font-family: 'Consolas', monospace; font-weight: 700; }

        /* Modal */
        .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.65); z-index: 200; align-items: center; justify-content: center; padding: 16px; }
        .modal-backdrop.open { display: flex; }
        .modal { background: #fff; border-radius: 12px; width: 100%; max-width: 580px; padding: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.3); max-height: 92vh; overflow-y: auto; }
        .modal h3 { margin: 0 0 14px; color: #0f294a; font-size: 18px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-full { grid-column: 1 / -1; }
        .form-group label { display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 4px; }
        .form-group input, .form-group select { width: 100%; }

        /* Live Conflict Box inside Modal */
        .conflict-box {
            display: none;
            margin-top: 14px;
            padding: 12px 14px;
            border-radius: 8px;
            background: #fef2f2;
            border: 1.5px solid #ef4444;
            color: #991b1b;
            font-size: 12.5px;
        }
        .conflict-box.visible { display: block; }
        .conflict-box strong.title { display: block; font-size: 13px; margin-bottom: 6px; color: #b91c1c; }
        .conflict-box ul { margin: 0 0 8px 18px; padding: 0; }
        .conflict-box li { margin-bottom: 4px; }
        .force-check { display: flex; align-items: center; gap: 6px; font-weight: 700; color: #7f1d1d; cursor: pointer; margin-top: 6px; }

        .ok-box {
            display: none;
            margin-top: 14px;
            padding: 9px 12px;
            border-radius: 8px;
            background: #f0fdf4;
            border: 1px solid #86efac;
            color: #166534;
            font-size: 12.5px;
            font-weight: 600;
        }
        .ok-box.visible { display: block; }

        .modal-footer { margin-top: 18px; display: flex; justify-content: flex-end; gap: 10px; }
    </style>
</head>
<body>

<div class="topbar">
    <h1>IWA SCED 2026 — Live Schedule Manager</h1>
    <div class="topbar-actions">
        <button type="button" class="btn btn-green" onclick="openCreateModal()">+ Add New Slot</button>
        <a href="id_card_generator.php" class="btn btn-outline">ID Card Printer</a>
        <a href="?logout=1" class="btn btn-outline">Logout</a>
    </div>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="flash <?= $flashType ?>"><?= htmlspecialchars($flashMsg) ?></div>
<?php endif; ?>

<div class="controls-panel">
    <div class="filter-group">
        <select id="dayFilter" onchange="filterTable()">
            <option value="all">All Days (<?= count($scheduleRows) ?> slots)</option>
            <option value="2026-11-26">Day 1 — Nov 26, 2026 (Thu)</option>
            <option value="2026-11-27">Day 2 — Nov 27, 2026 (Fri)</option>
            <option value="2026-11-28">Day 3 — Nov 28, 2026 (Sat)</option>
        </select>

        <select id="venueFilter" onchange="filterTable()">
            <option value="all">All Venues / Halls</option>
            <?php foreach ($venuesList as $v): ?>
                <option value="<?= htmlspecialchars(strtolower($v)) ?>"><?= htmlspecialchars($v) ?></option>
            <?php endforeach; ?>
        </select>

        <select id="typeFilter" onchange="filterTable()">
            <option value="all">All Session Types</option>
            <option value="keynote">Keynote Speakers Only</option>
            <option value="presenter">Presentations / Posters Only</option>
            <option value="general">General / Breaks / Ceremonies</option>
            <option value="clash">Conflicts / Clashes Only (<?= count($clashingSlotIds) ?>)</option>
        </select>

        <input type="text" id="searchBox" oninput="filterTable()" placeholder="Search Abstract ID, Speaker, Program..." style="min-width:220px;">
    </div>

    <form method="POST" class="swap-group" onsubmit="return confirm('Swap sessions between these two Slot IDs?');">
        <input type="hidden" name="action" value="swap">
        <strong style="font-size:12.5px;color:#334155;">Quick Swap Slots:</strong>
        <input type="number" name="swap_id_a" placeholder="Slot ID 1" required min="1">
        <span style="font-weight:700;">⇄</span>
        <input type="number" name="swap_id_b" placeholder="Slot ID 2" required min="1">
        <button type="submit" class="btn btn-amber">Swap Sessions</button>
    </form>
</div>

<div class="table-wrap">
    <table id="scheduleTable">
        <thead>
            <tr>
                <th>Slot ID</th>
                <th>Date</th>
                <th>Time</th>
                <th>Venue / Hall</th>
                <th>Program / Session Name</th>
                <th>Presenter / Abstract ID</th>
                <th>Keynote Speaker ID</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($scheduleRows as $row):
                $slotId   = (int)$row['id'];
                $isKey    = !empty($row['keynote_speaker_id']) || stripos($row['program_name'], 'keynote') !== false;
                $isPres   = !empty($row['presenter_id']);
                $rowType  = $isKey ? 'keynote' : ($isPres ? 'presenter' : 'general');
                $hasClash = isset($clashingSlotIds[$slotId]);
                $clashTip = $hasClash ? implode(' | ', array_unique($clashingSlotIds[$slotId])) : '';
                $searchStr = strtolower(
                    $slotId . ' ' . $row['conf_date'] . ' ' . $row['venue'] . ' ' .
                    $row['program_name'] . ' ' . ($row['presenter_id'] ?? '') . ' ' . ($row['keynote_speaker_id'] ?? '')
                );
            ?>
            <tr class="<?= $hasClash ? 'row-clash' : '' ?>"
                data-date="<?= htmlspecialchars($row['conf_date']) ?>"
                data-venue="<?= htmlspecialchars(strtolower($row['venue'])) ?>"
                data-type="<?= $rowType ?>"
                data-clash="<?= $hasClash ? '1' : '0' ?>"
                data-search="<?= htmlspecialchars($searchStr) ?>">
                <td class="mono">
                    #<?= $slotId ?>
                    <?php if ($hasClash): ?>
                        <span class="badge badge-clash" title="<?= htmlspecialchars($clashTip) ?>">CLASH</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($row['conf_date']) ?></td>
                <td class="mono"><?= htmlspecialchars(substr($row['from_time'], 0, 5) . ' – ' . substr($row['to_time'], 0, 5)) ?></td>
                <td><strong><?= htmlspecialchars($row['venue']) ?></strong></td>
                <td>
                    <?= htmlspecialchars($row['program_name']) ?>
                    <?php if ($hasClash): ?>
                        <div style="color:#b91c1c;font-size:11.5px;font-weight:600;margin-top:2px;"><?= htmlspecialchars($clashTip) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($isPres): ?>
                        <span class="badge badge-pres mono"><?= htmlspecialchars($row['presenter_id']) ?></span>
                    <?php else: ?>
                        <span style="color:#94a3b8;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($row['keynote_speaker_id'])): ?>
                        <span class="badge badge-key mono"><?= htmlspecialchars($row['keynote_speaker_id']) ?></span>
                    <?php else: ?>
                        <span style="color:#94a3b8;">—</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <button type="button" class="btn btn-blue" style="padding:5px 10px;font-size:12px;"
                            onclick='openEditModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                        Edit
                    </button>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete Slot #<?= $slotId ?>?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $slotId ?>">
                        <button type="submit" class="btn btn-red" style="padding:5px 9px;font-size:12px;">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Edit / Create Modal -->
<div class="modal-backdrop" id="slotModal">
    <div class="modal">
        <h3 id="modalTitle">Edit Schedule Slot</h3>
        <form method="POST" id="slotForm" onsubmit="return handleFormSubmit(event)">
            <input type="hidden" name="action" id="formAction" value="update">
            <input type="hidden" name="id" id="formId" value="0">

            <div class="form-grid">
                <div class="form-group">
                    <label>Conference Date</label>
                    <select name="conf_date" id="formDate" onchange="checkLiveConflicts()" required>
                        <option value="2026-11-26">2026-11-26 (Day 1 - Thu)</option>
                        <option value="2026-11-27">2026-11-27 (Day 2 - Fri)</option>
                        <option value="2026-11-28">2026-11-28 (Day 3 - Sat)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Venue / Hall</label>
                    <input type="text" name="venue" id="formVenue" list="venueOptions" oninput="checkLiveConflicts()" required>
                    <datalist id="venueOptions">
                        <?php foreach ($venuesList as $v): ?>
                            <option value="<?= htmlspecialchars($v) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="form-group">
                    <label>From Time (HH:MM:SS)</label>
                    <input type="text" name="from_time" id="formFromTime" placeholder="09:00:00" oninput="checkLiveConflicts()" required>
                </div>

                <div class="form-group">
                    <label>To Time (HH:MM:SS)</label>
                    <input type="text" name="to_time" id="formToTime" placeholder="09:20:00" oninput="checkLiveConflicts()" required>
                </div>

                <div class="form-group form-full">
                    <label>Program / Session Name</label>
                    <input type="text" name="program_name" id="formProgram" placeholder="e.g., Presentation or Keynote Speech - Dr. Name" required>
                </div>

                <div class="form-group">
                    <label>Presenter / Abstract ID (Optional)</label>
                    <input type="text" name="presenter_id" id="formPresenterId" list="abstractList" placeholder="IWA/2026/ABS/0001" oninput="checkLiveConflicts()">
                    <datalist id="abstractList">
                        <?php foreach ($abstractOptions as $ab): ?>
                            <option value="<?= htmlspecialchars($ab['abstract_id'] ?? $ab['registration_no'] ?? '') ?>">
                                <?= htmlspecialchars(($ab['author_name'] ?? '') . ' - ' . ($ab['title'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="form-group">
                    <label>Keynote Speaker ID (Optional)</label>
                    <input type="text" name="keynote_speaker_id" id="formKeynoteId" list="keynoteList" placeholder="IWA/2026/KEY/0001" oninput="checkLiveConflicts()">
                    <datalist id="keynoteList">
                        <?php foreach ($keynoteSpeakers as $ks): ?>
                            <option value="<?= htmlspecialchars($ks['registration_no']) ?>">
                                <?= htmlspecialchars($ks['speaker_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>

            <!-- Live Conflict Warning Panel -->
            <div class="conflict-box" id="conflictBox">
                <strong class="title">⚠️ Conflict / Double-Booking Detected:</strong>
                <ul id="conflictList"></ul>
                <label class="force-check">
                    <input type="checkbox" name="force_save" id="forceSaveCheck" value="1">
                    Force save anyway (allow overlapping / shared slot)
                </label>
            </div>

            <div class="ok-box" id="okBox">
                ✓ No time, venue, or speaker conflicts detected for this slot.
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" style="color:#334155;" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-green" id="saveBtn">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Full schedule dataset loaded for instant client-side conflict checking
    const ALL_SLOTS = <?= json_encode($scheduleRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    let currentConflicts = [];

    function normalizeTimeJS(t) {
        t = (t || '').trim();
        if (/^\d{1,2}:\d{2}$/.test(t)) {
            const parts = t.split(':');
            return parts[0].padStart(2, '0') + ':' + parts[1] + ':00';
        }
        if (/^\d:\d{2}:\d{2}$/.test(t)) {
            return '0' + t;
        }
        return t;
    }

    function checkLiveConflicts() {
        const editId   = parseInt(document.getElementById('formId').value || '0', 10);
        const confDate = document.getElementById('formDate').value.trim();
        const venue    = document.getElementById('formVenue').value.trim().toLowerCase();
        const fromTime = normalizeTimeJS(document.getElementById('formFromTime').value);
        const toTime   = normalizeTimeJS(document.getElementById('formToTime').value);
        const presId   = document.getElementById('formPresenterId').value.trim().toLowerCase();
        const keyId    = document.getElementById('formKeynoteId').value.trim().toLowerCase();

        currentConflicts = [];

        if (fromTime && toTime && fromTime >= toTime) {
            currentConflicts.push('Start time (' + fromTime + ') must be earlier than End time (' + toTime + ').');
        }

        ALL_SLOTS.forEach(s => {
            if (parseInt(s.id, 10) === editId) return;

            const sDate  = (s.conf_date || '').trim();
            const sVenue = (s.venue || '').trim().toLowerCase();
            const sFrom  = normalizeTimeJS(s.from_time);
            const sTo    = normalizeTimeJS(s.to_time);
            const sPres  = (s.presenter_id || '').trim().toLowerCase();
            const sKey   = (s.keynote_speaker_id || '').trim().toLowerCase();

            // 1. Same Date + Same Venue + Overlapping Time
            if (confDate && venue && sDate === confDate && sVenue === venue &&
                fromTime && toTime && fromTime < sTo && toTime > sFrom) {
                const label = s.presenter_id || s.keynote_speaker_id || s.program_name;
                currentConflicts.push(
                    `Venue & Time Clash with Slot #${s.id} (${s.venue}, ${sFrom.slice(0,5)}–${sTo.slice(0,5)} — ${label})`
                );
            }

            // 2. Duplicate Presenter / Abstract ID
            if (presId !== '' && sPres !== '' && presId === sPres) {
                currentConflicts.push(
                    `Presenter/Abstract "${s.presenter_id}" is already scheduled in Slot #${s.id} (${sDate}, ${sFrom.slice(0,5)}–${sTo.slice(0,5)} in ${s.venue})`
                );
            }

            // 3. Duplicate Keynote Speaker ID
            if (keyId !== '' && sKey !== '' && keyId === sKey) {
                currentConflicts.push(
                    `Keynote Speaker "${s.keynote_speaker_id}" is already scheduled in Slot #${s.id} (${sDate}, ${sFrom.slice(0,5)}–${sTo.slice(0,5)} in ${s.venue})`
                );
            }
        });

        const conflictBox  = document.getElementById('conflictBox');
        const conflictList = document.getElementById('conflictList');
        const okBox        = document.getElementById('okBox');
        const saveBtn      = document.getElementById('saveBtn');

        if (currentConflicts.length > 0) {
            conflictList.innerHTML = currentConflicts.map(msg => `<li>${escapeHtml(msg)}</li>`).join('');
            conflictBox.classList.add('visible');
            okBox.classList.remove('visible');
            saveBtn.style.background = '#d97706';
        } else {
            conflictBox.classList.remove('visible');
            document.getElementById('forceSaveCheck').checked = false;
            okBox.classList.add('visible');
            saveBtn.style.background = '#10b981';
        }
    }

    function handleFormSubmit(e) {
        checkLiveConflicts();
        if (currentConflicts.length > 0 && !document.getElementById('forceSaveCheck').checked) {
            e.preventDefault();
            alert('Cannot save because this slot conflicts with an existing slot:\n\n• ' + currentConflicts.join('\n• ') + '\n\nResolve the conflict or check "Force save anyway" to override.');
            return false;
        }
        return true;
    }

    function escapeHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function filterTable() {
        const day   = document.getElementById('dayFilter').value;
        const venue = document.getElementById('venueFilter').value;
        const type  = document.getElementById('typeFilter').value;
        const q     = document.getElementById('searchBox').value.trim().toLowerCase();

        document.querySelectorAll('#scheduleTable tbody tr').forEach(tr => {
            const matchDay   = (day === 'all' || tr.dataset.date === day);
            const matchVenue = (venue === 'all' || tr.dataset.venue === venue);
            const matchType  = (type === 'all') ||
                               (type === 'clash' && tr.dataset.clash === '1') ||
                               (type !== 'clash' && tr.dataset.type === type);
            const matchQ     = (q === '' || tr.dataset.search.indexOf(q) !== -1);
            tr.style.display = (matchDay && matchVenue && matchType && matchQ) ? '' : 'none';
        });
    }

    function openEditModal(row) {
        document.getElementById('modalTitle').textContent      = 'Edit Schedule Slot #' + row.id;
        document.getElementById('formAction').value            = 'update';
        document.getElementById('formId').value                = row.id;
        document.getElementById('formDate').value              = row.conf_date;
        document.getElementById('formVenue').value             = row.venue;
        document.getElementById('formFromTime').value          = row.from_time;
        document.getElementById('formToTime').value            = row.to_time;
        document.getElementById('formProgram').value           = row.program_name;
        document.getElementById('formPresenterId').value       = row.presenter_id || '';
        document.getElementById('formKeynoteId').value         = row.keynote_speaker_id || '';
        document.getElementById('forceSaveCheck').checked      = false;
        document.getElementById('slotModal').classList.add('open');
        checkLiveConflicts();
    }

    function openCreateModal() {
        document.getElementById('modalTitle').textContent      = 'Add New Schedule Slot';
        document.getElementById('formAction').value            = 'create';
        document.getElementById('formId').value                = '0';
        document.getElementById('formDate').value              = '2026-11-26';
        document.getElementById('formVenue').value             = 'Pannikkar Hall';
        document.getElementById('formFromTime').value          = '09:00:00';
        document.getElementById('formToTime').value            = '09:20:00';
        document.getElementById('formProgram').value           = '';
        document.getElementById('formPresenterId').value       = '';
        document.getElementById('formKeynoteId').value         = '';
        document.getElementById('forceSaveCheck').checked      = false;
        document.getElementById('slotModal').classList.add('open');
        checkLiveConflicts();
    }

    function closeModal() {
        document.getElementById('slotModal').classList.remove('open');
    }
</script>

</body>
</html>