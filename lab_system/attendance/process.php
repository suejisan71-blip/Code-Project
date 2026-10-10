<?php
/**
 * NEUST Lab System - RFID Attendance Scan Processor
 *
 * Attendance rules:
 * 1. RFID must belong to exactly one registered student.
 * 2. If the student has a class schedule, today's day must be registered.
 * 3. First valid scan of the day = TIME IN.
 * 4. Second valid scan of the same day = TIME OUT.
 * 5. TIME IN cannot be recorded twice.
 * 6. TIME OUT cannot be recorded twice.
 * 7. TIME OUT cannot happen before TIME IN.
 * 8. If a scheduled Time Out exists, a Time Out scan before that time is rejected.
 * 9. All comparisons use Asia/Manila.
 */

include '../config/db.php';
session_start();

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user'])) {
    header("Location: ../index.php");
    exit();
}

/* ---------------------------------------------------------
   DATABASE COMPATIBILITY
--------------------------------------------------------- */

function ensureTimeOutColumn(mysqli $conn): bool
{
    $check = $conn->query("SHOW COLUMNS FROM attendance LIKE 'time_out'");

    if ($check && $check->num_rows > 0) {
        return true;
    }

    // Automatically add the column so an old attendance table
    // can use the Time Out feature without manual migration.
    return (bool)$conn->query(
        "ALTER TABLE attendance ADD COLUMN time_out TIME NULL AFTER time_in"
    );
}

/* ---------------------------------------------------------
   HELPERS
--------------------------------------------------------- */

function normalizeRFID($rfid): string
{
    return strtoupper(
        str_replace([':', '-', ' '], '', trim((string)$rfid))
    );
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getStudentName(array $student): string
{
    $parts = [
        trim((string)($student['first_name'] ?? $student['first_Name'] ?? '')),
        trim((string)($student['middle_name'] ?? $student['middle_Name'] ?? '')),
        trim((string)($student['last_name'] ?? $student['last_Name'] ?? '')),
        trim((string)($student['extension_name'] ?? $student['extension_Name'] ?? ''))
    ];

    $parts = array_filter($parts, fn($v) => $v !== '');
    return implode(' ', $parts);
}

function decodeSchedule($value): array
{
    if ($value === null || trim((string)$value) === '') {
        return [];
    }

    $data = json_decode((string)$value, true);

    return is_array($data) ? $data : [];
}

/**
 * Return today's registered schedule.
 *
 * The current student registration stores schedules in
 * students.class_schedule as:
 * [
 *   {"day":"Monday","time_in":"08:00:00","time_out":"17:00:00"}
 * ]
 */
function getTodaySchedules(array $student, string $dayOfWeek): array
{
    $schedules = decodeSchedule($student['class_schedule'] ?? '');
    $today = [];

    foreach ($schedules as $schedule) {
        if (!is_array($schedule)) {
            continue;
        }

        $day = trim((string)($schedule['day'] ?? ''));

        if (strcasecmp($day, $dayOfWeek) !== 0) {
            continue;
        }

        $timeIn = trim((string)($schedule['time_in'] ?? ''));
        $timeOut = trim((string)($schedule['time_out'] ?? ''));

        if ($timeIn === '' && $timeOut === '') {
            continue;
        }

        $today[] = [
            'time_in'  => $timeIn,
            'time_out' => $timeOut
        ];
    }

    return $today;
}

function latestTimeOutForToday(array $schedules): string
{
    $latest = '';

    foreach ($schedules as $schedule) {
        $timeOut = $schedule['time_out'] ?? '';

        if ($timeOut !== '' && ($latest === '' || $timeOut > $latest)) {
            $latest = $timeOut;
        }
    }

    return $latest;
}

function calculateStatus(string $scanTime, array $schedules): string
{
    if (empty($schedules)) {
        return 'Present';
    }

    $status = 'Present';

    foreach ($schedules as $schedule) {
        $expected = trim((string)($schedule['time_in'] ?? ''));

        if ($expected === '') {
            continue;
        }

        $lateLimit = date('H:i:s', strtotime($expected . ' +15 minutes'));

        if ($scanTime > $lateLimit) {
            $status = 'Late';
        }
    }

    return $status;
}

/**
 * Check whether today's class is registered.
 *
 * If class_schedule is completely empty, the system falls back
 * to normal daily attendance so old student records still work.
 */
function scheduleIsConfigured(array $student): bool
{
    return !empty(trim((string)($student['class_schedule'] ?? '')));
}

/* ---------------------------------------------------------
   START PROCESSING
--------------------------------------------------------- */

$schemaReady = ensureTimeOutColumn($conn);

$rfid = normalizeRFID($_POST['rfid'] ?? '');

$date = date('Y-m-d');
$time = date('H:i:s');
$dayOfWeek = date('l');

$message = '';
$alert = 'danger';
$icon = 'bi-x-circle-fill';

if (!$schemaReady) {
    $message = 'The attendance Time Out field could not be prepared. Database error: ' . $conn->error;
} elseif ($rfid === '') {
    $message = 'No RFID code submitted!';
} elseif (!preg_match('/^[0-9A-F]+$/', $rfid)) {
    $message = 'Invalid RFID format.';
} else {

    /* -----------------------------------------------------
       FIND STUDENT BY NORMALIZED RFID
    ----------------------------------------------------- */

    $studentQuery = $conn->prepare("
        SELECT *
        FROM students
        WHERE REPLACE(
                REPLACE(
                    REPLACE(
                        UPPER(TRIM(student_RFID_Code)),
                        ':',
                        ''
                    ),
                    '-',
                    ''
                ),
                ' ',
                ''
            ) = ?
    ");

    if (!$studentQuery) {
        $message = 'Database query error: ' . $conn->error;
    } else {

        $studentQuery->bind_param('s', $rfid);
        $studentQuery->execute();
        $studentResult = $studentQuery->get_result();

        if ($studentResult->num_rows === 0) {

            $message = 'RFID tag is not registered in the system.';

        } elseif ($studentResult->num_rows > 1) {

            $message = 'This RFID is registered to more than one student. Please fix the duplicate RFID first.';

        } else {

            $student = $studentResult->fetch_assoc();

            $studentNo = trim((string)$student['student_No']);
            $studentName = getStudentName($student);

            /* -------------------------------------------------
               CHECK TODAY'S REGISTERED SUBJECT/SCHEDULE DAY
            ------------------------------------------------- */

            $todaySchedules = getTodaySchedules($student, $dayOfWeek);

            if (scheduleIsConfigured($student) && empty($todaySchedules)) {

                $message = $studentName .
                    ' is not registered for a laboratory schedule today (' .
                    $dayOfWeek . '). No attendance was recorded.';

                $alert = 'warning';
                $icon = 'bi-calendar-x-fill';

            } else {

                /*
                 * Transaction + row lock prevents rapid/double scans
                 * from creating conflicting Time In / Time Out states.
                 */
                $conn->begin_transaction();

                try {

                    $checkQuery = $conn->prepare("
                        SELECT
                            student_No,
                            date_log,
                            time_in,
                            time_out,
                            status
                        FROM attendance
                        WHERE student_No = ?
                          AND date_log = ?
                        ORDER BY time_in DESC
                        LIMIT 1
                        FOR UPDATE
                    ");

                    if (!$checkQuery) {
                        throw new Exception('Attendance query error: ' . $conn->error);
                    }

                    $checkQuery->bind_param('ss', $studentNo, $date);
                    $checkQuery->execute();
                    $checkResult = $checkQuery->get_result();

                    /* =========================================
                       FIRST SCAN = TIME IN
                    ========================================= */

                    if ($checkResult->num_rows === 0) {

                        $status = calculateStatus($time, $todaySchedules);

                        $insert = $conn->prepare("
                            INSERT INTO attendance
                                (student_No, date_log, time_in, time_out, status)
                            VALUES
                                (?, ?, ?, NULL, ?)
                        ");

                        if (!$insert) {
                            throw new Exception('Could not prepare Time In: ' . $conn->error);
                        }

                        $insert->bind_param(
                            'ssss',
                            $studentNo,
                            $date,
                            $time,
                            $status
                        );

                        if (!$insert->execute()) {
                            throw new Exception('Failed to record Time In: ' . $insert->error);
                        }

                        $insert->close();

                        $conn->commit();

                        $message = 'TIME IN recorded successfully for ' .
                            $studentName . ' (' . $status . ') at ' .
                            date('h:i A', strtotime($time)) . '.';

                        $alert = ($status === 'Late') ? 'warning' : 'success';
                        $icon = ($status === 'Late')
                            ? 'bi-clock-fill'
                            : 'bi-box-arrow-in-right';

                    } else {

                        /* =========================================
                           EXISTING ROW = TIME OUT OR COMPLETED
                        ========================================= */

                        $attendance = $checkResult->fetch_assoc();

                        $existingTimeIn = trim((string)($attendance['time_in'] ?? ''));
                        $existingTimeOut = trim((string)($attendance['time_out'] ?? ''));

                        if ($existingTimeOut !== '') {

                            /*
                             * Third and later scans are NEVER accepted.
                             */
                            $conn->commit();

                            $message = 'Attendance is already completed for ' .
                                $studentName .
                                ' today. Only one Time In and one Time Out are allowed.';

                            $alert = 'warning';
                            $icon = 'bi-shield-check';

                        } elseif ($existingTimeIn === '') {

                            $conn->commit();

                            $message = 'The attendance record is incomplete. Time In is missing. Please contact the administrator.';

                            $alert = 'danger';
                            $icon = 'bi-exclamation-triangle-fill';

                        } else {

                            /*
                             * Do not allow Time Out before Time In.
                             * This also prevents a duplicate RFID scan
                             * at the exact same moment from becoming
                             * an invalid Time Out.
                             */
                            if ($time <= $existingTimeIn) {

                                $conn->commit();

                                $message = 'Time Out cannot be recorded before Time In.';

                                $alert = 'warning';
                                $icon = 'bi-clock-history';

                            } else {

                                /*
                                 * If a scheduled Time Out exists,
                                 * reject early Time Out but do NOT consume
                                 * the Time Out scan. The student can scan
                                 * again later at the proper time.
                                 */
                                $scheduledTimeOut = latestTimeOutForToday($todaySchedules);

                                if (
                                    $scheduledTimeOut !== '' &&
                                    $time < $scheduledTimeOut
                                ) {

                                    $conn->commit();

                                    $message = 'Time Out is not yet allowed for ' .
                                        $studentName .
                                        '. Scheduled Time Out is ' .
                                        date('h:i A', strtotime($scheduledTimeOut)) .
                                        '.';

                                    $alert = 'warning';
                                    $icon = 'bi-hourglass-split';

                                } else {

                                    $update = $conn->prepare("
                                        UPDATE attendance
                                        SET time_out = ?
                                        WHERE student_No = ?
                                          AND date_log = ?
                                          AND time_out IS NULL
                                        LIMIT 1
                                    ");

                                    if (!$update) {
                                        throw new Exception('Could not prepare Time Out: ' . $conn->error);
                                    }

                                    $update->bind_param(
                                        'sss',
                                        $time,
                                        $studentNo,
                                        $date
                                    );

                                    if (!$update->execute()) {
                                        throw new Exception('Failed to record Time Out: ' . $update->error);
                                    }

                                    if ($update->affected_rows !== 1) {
                                        throw new Exception('Time Out was not recorded because the attendance record was already completed.');
                                    }

                                    $update->close();

                                    $conn->commit();

                                    $message = 'TIME OUT recorded successfully for ' .
                                        $studentName . ' at ' .
                                        date('h:i A', strtotime($time)) . '.';

                                    $alert = 'success';
                                    $icon = 'bi-box-arrow-right';
                                }
                            }
                        }
                    }

                    $checkQuery->close();

                } catch (Throwable $exception) {

                    $conn->rollback();

                    $message = $exception->getMessage();
                    $alert = 'danger';
                    $icon = 'bi-x-circle-fill';
                }
            }
        }

        $studentQuery->close();
    }
}

$displayUser = trim(
    ($_SESSION['user']['first_name'] ?? 'Admin') . ' ' .
    ($_SESSION['user']['last_name'] ?? '')
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NEUST Lab System | RFID Attendance</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            min-height: 100vh;
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #001f3f, #0056b3, #38bdf8);
            margin: 0;
        }

        .sidebar {
            width: 260px;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            background: linear-gradient(180deg, #0f172a 0%, #172554 100%);
            color: white;
            padding: 25px 15px;
            overflow-y: auto;
            z-index: 1000;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            color: #cbd5e1;
            text-decoration: none;
            border-radius: 12px;
            margin: 6px 0;
            font-weight: 600;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: linear-gradient(90deg, #2563eb, #38bdf8);
            color: white;
        }

        .topbar {
            margin-left: 260px;
            min-height: 75px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            background: rgba(255,255,255,.15);
            backdrop-filter: blur(15px);
            color: white;
        }

        .content {
            margin-left: 260px;
            padding: 40px;
        }

        .result-card {
            max-width: 540px;
            margin: 40px auto;
            border-radius: 24px;
            background: #ffffff;
            padding: 42px 32px;
            text-align: center;
            box-shadow: 0 20px 50px rgba(0,0,0,.30);
        }

        .result-icon {
            font-size: 68px;
            margin-bottom: 15px;
        }

        .rule-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 15px;
            padding: 16px;
            text-align: left;
            font-size: 13px;
            color: #475569;
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }

            .topbar,
            .content {
                margin-left: 0;
            }

            .content {
                padding: 20px;
            }
        }
    </style>
</head>
<body>

<div class="sidebar">
    <img
        src="../assets/neust_logo.png"
        style="width:75px;height:75px;background:white;border-radius:50%;display:block;margin:auto;padding:6px;"
        alt="NEUST Logo"
    >

    <h4 class="text-center fw-bold mt-2 mb-4">NEUST Lab System</h4>

    <a href="../dashboard.php">
        <i class="bi bi-grid-fill"></i> Dashboard
    </a>

    <a href="../students/add.php">
        <i class="bi bi-person-plus-fill"></i> Add Student
    </a>

    <a href="../students/list.php">
        <i class="bi bi-people-fill"></i> Student List
    </a>

    <a href="system_logs.php" class="active">
        <i class="bi bi-clock-history"></i> System Logs
    </a>

    <a href="records.php">
        <i class="bi bi-calendar-check-fill"></i> Records
    </a>

    <a href="../logout.php" style="color:#fca5a5;">
        <i class="bi bi-box-arrow-right"></i> Logout
    </a>
</div>

<div class="topbar">
    <strong>
        <i class="bi bi-qr-code-scan"></i>
        RFID Attendance Scan
    </strong>

    <div>
        <i class="bi bi-person-circle"></i>
        <?= e($displayUser); ?>
    </div>
</div>

<div class="content">

    <div class="result-card">

        <i class="bi <?= e($icon); ?> result-icon text-<?= e($alert); ?>"></i>

        <h3 class="fw-bold mb-3">
            Attendance Status
        </h3>

        <div class="alert alert-<?= e($alert); ?> fw-semibold">
            <?= e($message); ?>
        </div>

        <div class="rule-box mt-4">
            <div class="fw-bold mb-2">
                <i class="bi bi-info-circle-fill"></i>
                RFID Attendance Rules
            </div>

            <div>
                <i class="bi bi-1-circle"></i>
                First valid scan of the day = <strong>Time In</strong>
            </div>

            <div class="mt-1">
                <i class="bi bi-2-circle"></i>
                Second valid scan = <strong>Time Out</strong>
            </div>

            <div class="mt-1">
                <i class="bi bi-shield-check"></i>
                Third and later scans are blocked.
            </div>

            <div class="mt-1">
                <i class="bi bi-calendar-check"></i>
                Registered class schedules are checked by day.
            </div>
        </div>

        <a
            href="scan.php"
            class="btn btn-primary w-100 py-2 rounded-3 fw-bold mt-4"
        >
            <i class="bi bi-arrow-repeat"></i>
            Scan Another Tag
        </a>

    </div>

</div>

</body>
</html>
