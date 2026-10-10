<?php
/**
 * NEUST Lab System - RFID Attendance API
 *
 * POST:
 *   rfid=RFID_UID
 *
 * Response states:
 *   TIME_IN
 *   TIME_OUT
 *   ALREADY_COMPLETED
 *   NOT_SCHEDULED
 *   TOO_EARLY_TIMEOUT
 *   NOT_REGISTERED
 *   DUPLICATE_RFID
 *   ERROR
 */

include '../config/db.php';

date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json; charset=utf-8');

/* ---------------------------------------------------------
   RESPONSE HELPER
--------------------------------------------------------- */

function apiResponse(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
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

        $timeOut = trim((string)($schedule['time_out'] ?? ''));

        if (
            $timeOut !== '' &&
            ($latest === '' || $timeOut > $latest)
        ) {
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

    foreach ($schedules as $schedule) {

        $expected = trim((string)($schedule['time_in'] ?? ''));

        if ($expected === '') {
            continue;
        }

        $lateLimit = date(
            'H:i:s',
            strtotime($expected . ' +15 minutes')
        );

        if ($scanTime > $lateLimit) {
            return 'Late';
        }
    }

    return 'Present';
}

function scheduleIsConfigured(array $student): bool
{
    return trim((string)($student['class_schedule'] ?? '')) !== '';
}

/* ---------------------------------------------------------
   REQUEST
--------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiResponse([
        'success' => false,
        'status' => 'ERROR',
        'message' => 'Only POST requests are allowed.'
    ]);
}

if (!ensureTimeOutColumn($conn)) {
    apiResponse([
        'success' => false,
        'status' => 'ERROR',
        'message' => 'The attendance Time Out field could not be prepared.',
        'database_error' => $conn->error
    ]);
}

$rfid = normalizeRFID($_POST['rfid'] ?? '');

if ($rfid === '') {
    apiResponse([
        'success' => false,
        'status' => 'ERROR',
        'message' => 'RFID is empty.'
    ]);
}

if (!preg_match('/^[0-9A-F]+$/', $rfid)) {
    apiResponse([
        'success' => false,
        'status' => 'ERROR',
        'message' => 'Invalid RFID format.',
        'rfid' => $rfid
    ]);
}

$date = date('Y-m-d');
$time = date('H:i:s');
$dayOfWeek = date('l');

/* ---------------------------------------------------------
   FIND STUDENT
--------------------------------------------------------- */

$stmt = $conn->prepare("
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

if (!$stmt) {
    apiResponse([
        'success' => false,
        'status' => 'ERROR',
        'message' => 'Database query error.',
        'database_error' => $conn->error
    ]);
}

$stmt->bind_param('s', $rfid);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {

    $stmt->close();

    apiResponse([
        'success' => false,
        'status' => 'NOT_REGISTERED',
        'message' => 'RFID not registered.',
        'rfid' => $rfid
    ]);
}

if ($result->num_rows > 1) {

    $stmt->close();

    apiResponse([
        'success' => false,
        'status' => 'DUPLICATE_RFID',
        'message' => 'This RFID is registered to more than one student.',
        'rfid' => $rfid
    ]);
}

$student = $result->fetch_assoc();

$stmt->close();

$studentNo = trim((string)$student['student_No']);
$studentName = getStudentName($student);

/* ---------------------------------------------------------
   CHECK REGISTERED CLASS DAY
--------------------------------------------------------- */

$todaySchedules = getTodaySchedules($student, $dayOfWeek);

if (
    scheduleIsConfigured($student) &&
    empty($todaySchedules)
) {
    apiResponse([
        'success' => false,
        'status' => 'NOT_SCHEDULED',
        'message' => $studentName .
            ' is not registered for a class/laboratory schedule today.',
        'student_No' => $studentNo,
        'student_name' => $studentName,
        'rfid' => $rfid,
        'date' => $date,
        'day' => $dayOfWeek
    ]);
}

/* ---------------------------------------------------------
   TRANSACTION
--------------------------------------------------------- */

$conn->begin_transaction();

try {

    $check = $conn->prepare("
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

    if (!$check) {
        throw new Exception(
            'Attendance query error: ' . $conn->error
        );
    }

    $check->bind_param(
        'ss',
        $studentNo,
        $date
    );

    $check->execute();

    $attendanceResult = $check->get_result();

    /* =====================================================
       FIRST SCAN = TIME IN
    ===================================================== */

    if ($attendanceResult->num_rows === 0) {

        $status = calculateStatus(
            $time,
            $todaySchedules
        );

        $insert = $conn->prepare("
            INSERT INTO attendance
                (student_No, date_log, time_in, time_out, status)
            VALUES
                (?, ?, ?, NULL, ?)
        ");

        if (!$insert) {
            throw new Exception(
                'Could not prepare Time In: ' . $conn->error
            );
        }

        $insert->bind_param(
            'ssss',
            $studentNo,
            $date,
            $time,
            $status
        );

        if (!$insert->execute()) {
            throw new Exception(
                'Failed to record Time In: ' . $insert->error
            );
        }

        $insert->close();
        $check->close();

        $conn->commit();

        apiResponse([
            'success' => true,
            'status' => 'TIME_IN',
            'message' => 'Time In recorded successfully.',
            'student_No' => $studentNo,
            'student_name' => $studentName,
            'rfid' => $rfid,
            'date' => $date,
            'day' => $dayOfWeek,
            'time_in' => $time,
            'time_out' => null,
            'attendance_status' => $status
        ]);
    }

    /* =====================================================
       EXISTING RECORD
    ===================================================== */

    $attendance = $attendanceResult->fetch_assoc();

    $existingTimeIn = trim(
        (string)($attendance['time_in'] ?? '')
    );

    $existingTimeOut = trim(
        (string)($attendance['time_out'] ?? '')
    );

    /*
     * THIRD OR LATER SCAN
     */
    if ($existingTimeOut !== '') {

        $check->close();
        $conn->commit();

        apiResponse([
            'success' => false,
            'status' => 'ALREADY_COMPLETED',
            'message' =>
                'Attendance is already completed for today. ' .
                'Only one Time In and one Time Out are allowed.',
            'student_No' => $studentNo,
            'student_name' => $studentName,
            'rfid' => $rfid,
            'date' => $date,
            'time_in' => $existingTimeIn,
            'time_out' => $existingTimeOut
        ]);
    }

    /*
     * SAFETY CHECK
     */
    if ($existingTimeIn === '') {

        $check->close();
        $conn->commit();

        apiResponse([
            'success' => false,
            'status' => 'ERROR',
            'message' =>
                'Attendance record exists but Time In is missing.',
            'student_No' => $studentNo,
            'student_name' => $studentName
        ]);
    }

    /*
     * TIME OUT MUST BE AFTER TIME IN
     */
    if ($time <= $existingTimeIn) {

        $check->close();
        $conn->commit();

        apiResponse([
            'success' => false,
            'status' => 'ERROR',
            'message' => 'Time Out cannot be before Time In.',
            'student_No' => $studentNo,
            'student_name' => $studentName,
            'date' => $date,
            'time_in' => $existingTimeIn
        ]);
    }

    /*
     * IF A REGISTERED SCHEDULE HAS A TIME OUT,
     * DO NOT ACCEPT AN EARLY TIME OUT.
     */
    $scheduledTimeOut = latestTimeOutForToday(
        $todaySchedules
    );

    if (
        $scheduledTimeOut !== '' &&
        $time < $scheduledTimeOut
    ) {

        $check->close();
        $conn->commit();

        apiResponse([
            'success' => false,
            'status' => 'TOO_EARLY_TIMEOUT',
            'message' =>
                'Time Out is not yet allowed. Scheduled Time Out is ' .
                date('h:i A', strtotime($scheduledTimeOut)) . '.',
            'student_No' => $studentNo,
            'student_name' => $studentName,
            'date' => $date,
            'time_in' => $existingTimeIn,
            'scheduled_time_out' => $scheduledTimeOut
        ]);
    }

    /* =====================================================
       SECOND VALID SCAN = TIME OUT
    ===================================================== */

    $update = $conn->prepare("
        UPDATE attendance
        SET time_out = ?
        WHERE student_No = ?
          AND date_log = ?
          AND time_out IS NULL
        LIMIT 1
    ");

    if (!$update) {
        throw new Exception(
            'Could not prepare Time Out: ' . $conn->error
        );
    }

    $update->bind_param(
        'sss',
        $time,
        $studentNo,
        $date
    );

    if (!$update->execute()) {
        throw new Exception(
            'Failed to record Time Out: ' . $update->error
        );
    }

    if ($update->affected_rows !== 1) {
        throw new Exception(
            'Time Out was not recorded because the attendance record was already completed.'
        );
    }

    $update->close();
    $check->close();

    $conn->commit();

    apiResponse([
        'success' => true,
        'status' => 'TIME_OUT',
        'message' => 'Time Out recorded successfully.',
        'student_No' => $studentNo,
        'student_name' => $studentName,
        'rfid' => $rfid,
        'date' => $date,
        'day' => $dayOfWeek,
        'time_in' => $existingTimeIn,
        'time_out' => $time,
        'attendance_status' => $attendance['status'] ?? 'Present'
    ]);

} catch (Throwable $e) {

    $conn->rollback();

    apiResponse([
        'success' => false,
        'status' => 'ERROR',
        'message' => $e->getMessage()
    ]);
}
?>
