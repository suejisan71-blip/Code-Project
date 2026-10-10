<?php
/**
 * NEUST Lab System - Attendance Records
 */

session_start();
date_default_timezone_set('Asia/Manila');

require_once '../config/db.php';

if (!isset($_SESSION['user'])) {
    header('Location: ../index.php');
    exit();
}

// =====================================================
// HELPER FUNCTIONS
// =====================================================

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getStudentName(array $row): string {
    $first = trim((string)($row['first_name'] ?? $row['first_Name'] ?? ''));
    $middle = trim((string)($row['middle_name'] ?? $row['middle_Name'] ?? ''));
    $last = trim((string)($row['last_name'] ?? $row['last_Name'] ?? ''));
    $extension = trim((string)($row['extension_name'] ?? $row['extension_Name'] ?? ''));

    $parts = array_filter([$first, $middle, $last, $extension], fn($v) => trim((string)$v) !== '');
    return implode(' ', $parts);
}

function getRegisteredSubjects($subjectValue): array {
    if ($subjectValue === null || trim((string)$subjectValue) === '') {
        return [];
    }

    $decoded = json_decode((string)$subjectValue, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $subjects = [];

        $flatten = function ($items) use (&$subjects, &$flatten) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    if (isset($item['subject'])) {
                        $value = trim((string)$item['subject']);
                        if ($value !== '') $subjects[] = $value;
                    } elseif (isset($item['name'])) {
                        $value = trim((string)$item['name']);
                        if ($value !== '') $subjects[] = $value;
                    } elseif (isset($item['code'])) {
                        $value = trim((string)$item['code']);
                        if ($value !== '') $subjects[] = $value;
                    } else {
                        $flatten($item);
                    }
                } elseif (is_string($item) || is_numeric($item)) {
                    $value = trim((string)$item);
                    if ($value !== '') $subjects[] = $value;
                }
            }
        };

        $flatten($decoded);

        $subjects = array_values(array_unique($subjects));
        return $subjects;
    }

    $parts = preg_split('/\s*(?:,|\||;)\s*/', (string)$subjectValue);
    $parts = array_values(array_filter(array_map('trim', $parts), fn($v) => $v !== ''));

    return array_values(array_unique($parts));
}

function statusInfo($status): array {
    $upper = strtoupper(trim((string)$status));
    if (in_array($upper, ['PRESENT', 'TIME_IN', 'IN', 'ON TIME'], true)) {
        return ['present', 'Present', 'bi-check-circle-fill'];
    }
    if (in_array($upper, ['LATE', 'LATE_IN'], true)) {
        return ['late', 'Late', 'bi-clock-fill'];
    }
    if (in_array($upper, ['ABSENT', 'NO SHOW'], true)) {
        return ['absent', 'Absent', 'bi-x-circle-fill'];
    }
    if (in_array($upper, ['OUT', 'TIME_OUT'], true)) {
        return ['out', 'Time Out', 'bi-box-arrow-right'];
    }
    if (in_array($upper, ['MISSING SCHEDULE', 'MISSING_SCHEDULE', 'MISSING'], true)) {
        return ['missing', 'Missing Schedule', 'bi-exclamation-triangle-fill'];
    }
    return ['default', $status !== '' ? $status : '—', 'bi-info-circle-fill'];
}

function getTimeOutStatus($timeOutVal, $mainStatus): array {
    if (!empty($timeOutVal)) {
        return ['out', 'Time Out', 'bi-box-arrow-right'];
    }
    return ['default', '—', 'bi-dash-circle'];
}

function formatDateValue($date): string {
    if (!$date) return '—';
    $ts = strtotime((string)$date);
    return $ts ? date('M d, Y', $ts) : (string)$date;
}

function formatTimeValue($time): string {
    if (!$time) return '—';
    $ts = strtotime((string)$time);
    return $ts ? date('h:i A', $ts) : (string)$time;
}

// Check database schema capabilities
$hasTimeOutCol = false;
$checkTimeOut = $conn->query("SHOW COLUMNS FROM attendance LIKE 'time_out'");
if ($checkTimeOut && $checkTimeOut->num_rows > 0) {
    $hasTimeOutCol = true;
}

// =====================================================
// AUTOMATED MISSING SCHEDULE EVALUATION LOGIC
// =====================================================
function evaluateMissingSchedules($conn) {
    $checkCol = $conn->query("SHOW COLUMNS FROM students LIKE 'class_schedule'");
    if (!$checkCol || $checkCol->num_rows === 0) {
        return;
    }

    $studentsRes = $conn->query("SELECT student_No, class_schedule FROM students WHERE class_schedule IS NOT NULL AND class_schedule != ''");
    if (!$studentsRes) {
        return;
    }

    $todayDate = date('Y-m-d');
    $currentDayName = date('l');
    $currentTime = date('H:i:s');

    while ($student = $studentsRes->fetch_assoc()) {
        $studentNo = $student['student_No'];
        $schedule = json_decode($student['class_schedule'], true);

        if (!is_array($schedule)) continue;

        foreach ($schedule as $sched) {
            $day = $sched['day'] ?? '';
            $timeIn = $sched['time_in'] ?? '';
            $timeOut = $sched['time_out'] ?? '';

            if (!$day || !$timeIn || !$timeOut) continue;

            if (strcasecmp($day, $currentDayName) === 0) {
                if ($currentTime > $timeOut) {
                    checkAndMarkMissing($conn, $studentNo, $todayDate, $timeIn);
                }
            }
        }
    }
}

function checkAndMarkMissing($conn, $studentNo, $dateLog, $schedIn) {
    $stmt = $conn->prepare("
        SELECT status FROM attendance 
        WHERE student_No = ? AND date_log = ?
        LIMIT 1
    ");
    if (!$stmt) return;

    $stmt->bind_param("ss", $studentNo, $dateLog);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        $insert = $conn->prepare("
            INSERT INTO attendance (student_No, date_log, time_in, status)
            VALUES (?, ?, ?, 'MISSING SCHEDULE')
        ");
        if ($insert) {
            $insert->bind_param("sss", $studentNo, $dateLog, $schedIn);
            $insert->execute();
            $insert->close();
        }
    }
    $stmt->close();
}

// Run schedule evaluation routine
evaluateMissingSchedules($conn);

// =====================================================
// FETCH ALL ATTENDANCE RECORDS
// =====================================================
$timeOutSelect = $hasTimeOutCol ? "a.time_out AS attendance_time_out," : "NULL AS attendance_time_out,";

$sql = "
    SELECT
        a.student_No AS attendance_student_no,
        a.date_log AS attendance_date,
        a.time_in AS attendance_time,
        {$timeOutSelect}
        a.status AS attendance_status,
        s.student_No AS student_no,
        s.first_name, s.first_Name,
        s.middle_name, s.middle_Name,
        s.last_name, s.last_Name,
        s.extension_name, s.extension_Name,
        s.subject
    FROM attendance AS a
    LEFT JOIN (
        SELECT s1.*
        FROM students AS s1
        INNER JOIN (
            SELECT TRIM(CAST(student_No AS CHAR)) AS student_no_key, MIN(stud_id) AS keep_id
            FROM students
            WHERE student_No IS NOT NULL AND TRIM(CAST(student_No AS CHAR)) <> ''
            GROUP BY TRIM(CAST(student_No AS CHAR))
        ) AS keepers
            ON keepers.keep_id = s1.stud_id
    ) AS s
        ON TRIM(CAST(a.student_No AS CHAR)) = TRIM(CAST(s.student_No AS CHAR))
    ORDER BY a.date_log DESC, a.time_in DESC
";

$result = $conn->query($sql);
$records = [];
$dbError = '';

if ($result === false) {
    $dbError = $conn->error;
} else {
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
    $result->free();
}

// =====================================================
// SAFETY: ONE DISPLAY ROW PER STUDENT + DATE
// =====================================================
$uniqueRecords = [];
foreach ($records as $row) {
    $studentKey = trim((string)($row['attendance_student_no'] ?? ''));
    $dateKey = trim((string)($row['attendance_date'] ?? ''));
    $key = $studentKey . '|' . $dateKey;

    if (!isset($uniqueRecords[$key])) {
        $uniqueRecords[$key] = $row;
        continue;
    }

    $existing = $uniqueRecords[$key];
    $existingIn = trim((string)($existing['attendance_time'] ?? ''));
    $newIn = trim((string)($row['attendance_time'] ?? ''));
    $existingOut = trim((string)($existing['attendance_time_out'] ?? ''));
    $newOut = trim((string)($row['attendance_time_out'] ?? ''));

    if ($existingIn === '' || ($newIn !== '' && strtotime($newIn) < strtotime($existingIn))) {
        $existing['attendance_time'] = $newIn;
    }

    if ($newOut !== '' && ($existingOut === '' || strtotime($newOut) > strtotime($existingOut))) {
        $existing['attendance_time_out'] = $newOut;
    }

    if ($existing['attendance_status'] === '' || $existing['attendance_status'] === null) {
        $existing['attendance_status'] = $row['attendance_status'] ?? '';
    }

    $uniqueRecords[$key] = $existing;
}
$records = array_values($uniqueRecords);

$totalRecords = count($records);
$presentCount = 0; $lateCount = 0; $absentCount = 0; $missingCount = 0; $statusOptions = [];

foreach ($records as $row) {
    [$class, $label] = statusInfo($row['attendance_status'] ?? '');
    if ($class === 'present') $presentCount++;
    elseif ($class === 'late') $lateCount++;
    elseif ($class === 'absent') $absentCount++;
    elseif ($class === 'missing') $missingCount++;

    $upper = strtoupper(trim((string)($row['attendance_status'] ?? '')));
    if ($upper !== '') $statusOptions[$upper] = $label;
}
ksort($statusOptions);

// CSV Export Handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="neust_attendance_' . date('Y-m-d_H-i-s') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    
    $headers = ['Student No', 'Full Name', 'Date', 'Time In', 'Time In Status'];
    if ($hasTimeOutCol) {
        $headers[] = 'Time Out';
        $headers[] = 'Time Out Status';
    }
    fputcsv($out, $headers);

    foreach ($records as $row) {
        [$inClass, $inText] = statusInfo($row['attendance_status'] ?? '');
        [$outClass, $outText] = getTimeOutStatus($row['attendance_time_out'] ?? '', $row['attendance_status'] ?? '');

        $rowExport = [
            $row['attendance_student_no'] ?? '',
            getStudentName($row) ?: 'Student information unavailable',
            $row['attendance_date'] ?? '',
            $row['attendance_time'] ?? '',
            $inText
        ];
        if ($hasTimeOutCol) {
            $rowExport[] = $row['attendance_time_out'] ?? '';
            $rowExport[] = $outText;
        }
        fputcsv($out, $rowExport);
    }
    fclose($out);
    exit();
}

$displayUser = trim(($_SESSION['user']['first_name'] ?? 'Admin') . ' ' . ($_SESSION['user']['last_name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NEUST Lab System | Attendance Records</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary: #38bdf8;
            --navy: #0f172a;
            --muted: #64748b;
            --border: #e2e8f0;
            --surface: #ffffff;
            --page: #eef4fb;
        }

        * { box-sizing: border-box; }

        html, body { min-height: 100%; }

        body {
            min-height: 100vh;
            font-family: "Segoe UI", Arial, sans-serif;
            background:
                radial-gradient(circle at top right, rgba(56,189,248,.25), transparent 30%),
                linear-gradient(135deg, #001f3f 0%, #0056b3 48%, #38bdf8 100%);
            color: #172033;
            margin: 0;
        }

        .sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            width: 260px;
            padding: 22px 15px;
            background: linear-gradient(180deg, #0f172a 0%, #172554 100%);
            color: #fff;
            z-index: 1000;
            overflow-y: auto;
        }

        .logo {
            width: 75px;
            height: 75px;
            object-fit: contain;
            display: block;
            margin: 0 auto 10px;
            padding: 6px;
            border-radius: 50%;
            background: #fff;
        }

        .brand-title {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
            text-align: center;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            margin: 6px 0;
            border-radius: 12px;
            color: #cbd5e1;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: .2s;
        }

        .sidebar a:hover,
        .sidebar a.active {
            color: #fff;
            background: linear-gradient(90deg, #2563eb, #38bdf8);
        }

        .topbar {
            margin-left: 260px;
            min-height: 70px;
            padding: 0 clamp(16px, 3vw, 32px);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            background: rgba(255,255,255,.15);
            backdrop-filter: blur(15px);
            color: white;
        }

        .content {
            margin-left: 260px;
            padding: clamp(18px, 3vw, 32px);
            min-width: 0;
        }

        .page-heading {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .page-heading h2 {
            font-size: clamp(22px, 3vw, 30px);
        }

        .card-box {
            background: rgba(255,255,255,.97);
            border-radius: 20px;
            padding: clamp(15px, 2vw, 25px);
            box-shadow: 0 20px 50px rgba(0,0,0,.16);
            overflow: hidden;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            min-width: 0;
            background: #fff;
            padding: 18px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,.05);
            border: 1px solid var(--border);
        }

        .stat-value {
            font-size: 24px;
            font-weight: 800;
            color: var(--navy);
        }

        .filters {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) minmax(150px, 170px) minmax(150px, 170px) auto;
            gap: 10px;
            margin-bottom: 18px;
        }

        .filter-control {
            min-height: 42px;
        }

        .table-shell {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: #fff;
            -webkit-overflow-scrolling: touch;
        }

        .records-table {
            width: 100%;
            min-width: 1080px;
            border-collapse: separate;
            border-spacing: 0;
        }

        .records-table th {
            padding: 13px 12px;
            background: #f8fafc;
            font-size: 11px;
            font-weight: 800;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: .04em;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 2;
        }

        .records-table td {
            padding: 14px 12px;
            border-bottom: 1px solid #eef2f7;
            font-size: 13px;
            vertical-align: middle;
        }

        .records-table tbody tr {
            transition: background .15s ease;
        }

        .records-table tbody tr:hover {
            background: #f8fbff;
        }

        .records-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .student-no {
            font-weight: 800;
            color: var(--primary);
            white-space: nowrap;
        }

        .student-name {
            min-width: 180px;
            font-weight: 700;
            color: #1e293b;
        }

        .subject-cell {
            min-width: 180px;
            white-space: nowrap;
        }

        .subject-empty {
            color: #94a3b8;
            font-size: 12px;
            font-style: italic;
        }

        .view-subjects-btn {
            border: 0;
            background: transparent;
            color: var(--primary);
            font-size: 13px;
            font-weight: 700;
            padding: 0;
            margin: 0;
            cursor: pointer;
        }

        .view-subjects-btn:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }

        .status-badge.present { background: #dcfce7; color: #166534; }
        .status-badge.late { background: #fef3c7; color: #92400e; }
        .status-badge.absent { background: #fee2e2; color: #991b1b; }
        .status-badge.out { background: #e0f2fe; color: #0369a1; }
        .status-badge.missing { background: #ffedd5; color: #c2410c; }
        .status-badge.default { background: #f1f5f9; color: #475569; }

        .empty-state {
            text-align: center;
            padding: 55px 20px;
            color: #64748b;
        }

        .subject-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(15,23,42,.65);
            backdrop-filter: blur(5px);
            z-index: 2000;
        }

        .subject-modal.show {
            display: flex;
        }

        .subject-modal-card {
            width: min(720px, 100%);
            max-height: min(80vh, 700px);
            overflow: hidden;
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 25px 80px rgba(0,0,0,.3);
            animation: modalIn .18s ease-out;
        }

        @keyframes modalIn {
            from { opacity: 0; transform: translateY(12px) scale(.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .subject-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
        }

        .subject-modal-body {
            padding: 20px;
            overflow-y: auto;
            max-height: calc(80vh - 85px);
        }

        .subject-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .subject-list li {
            padding: 12px 13px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #f8fafc;
            color: #1e293b;
            font-size: 13px;
            font-weight: 650;
        }

        .subject-list li::before {
            content: "✓";
            display: inline-flex;
            margin-right: 8px;
            color: #16a34a;
            font-weight: 900;
        }

        .close-modal {
            width: 36px;
            height: 36px;
            border: 0;
            border-radius: 10px;
            background: #f1f5f9;
            color: #475569;
            font-size: 18px;
        }

        .close-modal:hover {
            background: #e2e8f0;
        }

        .table-count {
            color: #64748b;
            font-size: 12px;
            margin-bottom: 10px;
        }

        @media (max-width: 1200px) {
            .stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .filters { grid-template-columns: 1fr 160px 160px auto; }
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 220px;
            }

            .topbar,
            .content {
                margin-left: 220px;
            }

            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }

            .filters {
                grid-template-columns: 1fr 1fr;
            }

            .filters button {
                width: 100%;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                position: relative;
                inset: auto;
                width: 100%;
                height: auto;
                max-height: none;
                padding: 15px;
            }

            .sidebar .logo {
                width: 58px;
                height: 58px;
            }

            .sidebar a {
                display: inline-flex;
                width: auto;
                margin: 3px;
            }

            .topbar,
            .content {
                margin-left: 0;
            }

            .topbar {
                min-height: 62px;
                padding: 12px 16px;
            }

            .topbar h4 {
                font-size: 15px;
            }

            .content {
                padding: 16px;
            }

            .page-heading {
                align-items: stretch;
            }

            .page-heading .export-btn {
                width: 100%;
            }

            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .stat-card {
                padding: 14px;
            }

            .stat-value {
                font-size: 21px;
            }

            .filters {
                grid-template-columns: 1fr;
            }

            .subject-list {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats {
                grid-template-columns: 1fr 1fr;
            }

            .stat-card {
                padding: 12px;
            }

            .stat-value {
                font-size: 19px;
            }

            .topbar > div {
                font-size: 12px;
            }

            .card-box {
                border-radius: 15px;
                padding: 12px;
            }

            .subject-modal {
                padding: 10px;
            }

            .subject-modal-card {
                border-radius: 16px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            * { scroll-behavior: auto !important; animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <img src="../assets/neust_logo.png" class="logo" alt="Logo">
    <h2 class="brand-title">NEUST Lab System</h2>
    <hr style="border-color: rgba(255,255,255,0.1); margin: 15px 0;">
    <a href="../dashboard.php"><i class="bi bi-grid-fill"></i> Dashboard</a>
    <a href="../students/add.php"><i class="bi bi-person-plus-fill"></i> Add Student</a>
    <a href="../students/list.php"><i class="bi bi-people-fill"></i> Student List</a>
    <a href="system_logs.php"><i class="bi bi-clock-history"></i> System Logs</a>
    <a href="records.php" class="active"><i class="bi bi-calendar-check-fill"></i> Records</a>
    <a href="../logout.php" style="color: #fca5a5;"><i class="bi bi-box-arrow-right"></i> Logout</a>
</aside>

<header class="topbar">
    <h4 class="m-0 fw-bold"><i class="bi bi-calendar-check-fill"></i> Attendance Records</h4>
    <div><i class="bi bi-person-circle"></i> <?= e($displayUser) ?></div>
</header>

<main class="content">
    <div class="d-flex justify-content-between align-items-center mb-3 text-white">
        <div>
            <h2 class="fw-bold m-0">Attendance History</h2>
            <p class="m-0 small opacity-75">View attendance logs and each student's registered subject/s</p>
        </div>
        <a href="records.php?export=csv" class="btn btn-light fw-bold"><i class="bi bi-download"></i> Export CSV</a>
    </div>

    <?php if ($dbError): ?>
        <div class="alert alert-danger">Database error: <?= e($dbError) ?></div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat-card">
            <div class="small text-muted fw-bold">Total Logs</div>
            <div class="stat-value"><?= $totalRecords ?></div>
        </div>
        <div class="stat-card">
            <div class="small text-success fw-bold">Present</div>
            <div class="stat-value text-success"><?= $presentCount ?></div>
        </div>
        <div class="stat-card">
            <div class="small text-warning fw-bold">Late</div>
            <div class="stat-value text-warning"><?= $lateCount ?></div>
        </div>
        <div class="stat-card">
            <div class="small text-danger fw-bold">Absent</div>
            <div class="stat-value text-danger"><?= $absentCount ?></div>
        </div>
        <div class="stat-card">
            <div class="small text-danger fw-bold" style="color: #c2410c !important;">Missing Sched</div>
            <div class="stat-value" style="color: #c2410c;"><?= $missingCount ?></div>
        </div>
    </div>

    <div class="card-box">
        <div class="filters">
            <input type="search" id="searchInput" class="form-control" placeholder="Search student name, number, or subject...">
            <input type="date" id="dateInput" class="form-control">
            <select id="statusInput" class="form-select">
                <option value="">All Statuses</option>
                <?php foreach ($statusOptions as $k => $v): ?>
                    <option value="<?= e($k) ?>"><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-secondary" onclick="resetFilters()"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
        </div>

        <div class="table-count">
            Showing <strong id="visibleCount"><?= count($records) ?></strong> of <strong><?= count($records) ?></strong> attendance record(s)
        </div>
        <div class="table-shell">
            <table class="records-table">
                <thead>
                    <tr>
                        <th>Student No</th>
                        <th>Student Name</th>
                        <th>Registered Subject/s</th>
                        <th>Date</th>
                        <th>Time In</th>
                        <th>Time In Status</th>
                        <?php if ($hasTimeOutCol): ?>
                            <th>Time Out</th>
                            <th>Time Out Status</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="recordsBody">
                <?php foreach ($records as $row): ?>
                    <?php
                        $sNo = trim((string)($row['attendance_student_no'] ?? ''));
                        $name = getStudentName($row);
                        $subjects = getRegisteredSubjects($row['subject'] ?? '');
                        
                        [$sClass, $sText, $sIcon] = statusInfo($row['attendance_status'] ?? '');
                        [$outClass, $outText, $outIcon] = getTimeOutStatus($row['attendance_time_out'] ?? '', $row['attendance_status'] ?? '');

                        $dateRaw = $row['attendance_date'] ?? '';
                        $subjectSearch = strtolower(implode(' ', $subjects));
                        $subjectPayload = e(json_encode($subjects, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT));
                    ?>
                    <tr class="record-row" 
                        data-search="<?= e(strtolower($sNo . ' ' . $name . ' ' . $subjectSearch)) ?>" 
                        data-date="<?= e($dateRaw) ?>" 
                        data-status="<?= e(strtoupper(trim((string)($row['attendance_status'] ?? '')))) ?>">
                        <td class="fw-bold text-primary"><?= e($sNo ?: '—') ?></td>
                        <td class="student-name"><?= e($name ?: 'Student Info Unavailable') ?></td>
                        <td class="subject-cell">
                            <?php if ($subjects): ?>
                                <button type="button" class="view-subjects-btn"
                                        data-subjects="<?= $subjectPayload ?>"
                                        data-student="<?= e($name ?: $sNo) ?>"
                                        onclick="viewSubjects(this)">
                                    <i class="bi bi-eye-fill"></i> View <?= count($subjects) ?> subject<?= count($subjects) === 1 ? '' : 's' ?>
                                </button>
                            <?php else: ?>
                                <span class="subject-empty">No registered subject found</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(formatDateValue($dateRaw)) ?></td>
                        <td class="fw-bold"><?= e(formatTimeValue($row['attendance_time'] ?? '')) ?></td>
                        <td>
                            <span class="status-badge <?= e($sClass) ?>">
                                <i class="bi <?= e($sIcon) ?>"></i> <?= e($sText) ?>
                            </span>
                        </td>
                        <?php if ($hasTimeOutCol): ?>
                            <td class="fw-bold"><?= e(formatTimeValue($row['attendance_time_out'] ?? '')) ?></td>
                            <td>
                                <span class="status-badge <?= e($outClass) ?>">
                                    <i class="bi <?= e($outIcon) ?>"></i> <?= e($outText) ?>
                                </span>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$records): ?>
                    <tr>
                        <td colspan="<?= $hasTimeOutCol ? '8' : '6' ?>">
                            <div class="empty-state">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                <strong>No attendance records found</strong>
                                <div class="small mt-1">Attendance records will appear here after a student scans.</div>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<div class="subject-modal" id="subjectModal" role="dialog" aria-modal="true" aria-labelledby="subjectModalTitle">
    <div class="subject-modal-card">
        <div class="subject-modal-header">
            <div>
                <div class="small text-muted fw-bold">REGISTERED SUBJECT/S</div>
                <h5 class="m-0 fw-bold" id="subjectModalTitle">Student Subjects</h5>
            </div>
            <button type="button" class="close-modal" onclick="closeSubjects()" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="subject-modal-body">
            <ul class="subject-list" id="subjectList"></ul>
        </div>
    </div>
</div>

<script>
const searchInput = document.getElementById('searchInput');
const dateInput = document.getElementById('dateInput');
const statusInput = document.getElementById('statusInput');
const rows = Array.from(document.querySelectorAll('.record-row'));

function filterData() {
    const s = searchInput.value.toLowerCase().trim();
    const d = dateInput.value;
    const st = statusInput.value.toUpperCase();
    let visible = 0;

    rows.forEach(r => {
        const matchS = !s || r.dataset.search.includes(s);
        const matchD = !d || r.dataset.date === d;
        const matchSt = !st || r.dataset.status === st;
        const show = matchS && matchD && matchSt;
        r.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    const visibleCount = document.getElementById('visibleCount');
    if (visibleCount) visibleCount.textContent = visible;
}

function viewSubjects(button) {
    let subjects = [];

    try {
        subjects = JSON.parse(button.dataset.subjects || '[]');
    } catch (error) {
        subjects = [];
    }

    const list = document.getElementById('subjectList');
    const title = document.getElementById('subjectModalTitle');

    title.textContent = (button.dataset.student || 'Student') + ' — Registered Subjects';
    list.innerHTML = '';

    subjects.forEach((subject, index) => {
        const li = document.createElement('li');
        li.textContent = subject;
        list.appendChild(li);
    });

    if (!subjects.length) {
        const li = document.createElement('li');
        li.textContent = 'No registered subjects found.';
        list.appendChild(li);
    }

    document.getElementById('subjectModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeSubjects() {
    document.getElementById('subjectModal').classList.remove('show');
    document.body.style.overflow = '';
}

document.getElementById('subjectModal').addEventListener('click', function(event) {
    if (event.target === this) closeSubjects();
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') closeSubjects();
});

function resetFilters() {
    searchInput.value = '';
    dateInput.value = '';
    statusInput.value = '';
    filterData();
}

searchInput.addEventListener('input', filterData);
dateInput.addEventListener('change', filterData);
statusInput.addEventListener('change', filterData);
</script>
</body>
</html>