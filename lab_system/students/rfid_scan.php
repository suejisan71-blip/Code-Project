<?php

include '../config/db.php';
session_start();
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized. Please log in first.'
    ]);
    exit;
}

function normalizeRFID($rfid)
{
    $rfid = strtoupper(trim((string)$rfid));
    return str_replace([':', '-', ' '], '', $rfid);
}

function getClassSchedule($scheduleData)
{
    if ($scheduleData === null || trim((string)$scheduleData) === '') {
        return [];
    }

    $schedule = json_decode($scheduleData, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($schedule)) {
        return $schedule;
    }

    return [];
}

$rfid = normalizeRFID($_POST['rfid'] ?? $_GET['rfid'] ?? '');

if ($rfid === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'No RFID code received.'
    ]);
    exit;
}

$nowDate = date('Y-m-d');
$nowTime = date('H:i:s');
$dayName = date('l');
$displayDate = date('m/d/Y');
$displayTime = date('h:i A');

$stmt = $conn->prepare("SELECT * FROM students WHERE REPLACE(REPLACE(REPLACE(UPPER(TRIM(student_RFID_Code)), ':', ''), '-', ''), ' ', '') = ? LIMIT 1");

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $conn->error
    ]);
    exit;
}

$stmt->bind_param('s', $rfid);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
$stmt->close();

if (!$student) {
    echo json_encode([
        'success' => false,
        'code' => 'RFID_NOT_REGISTERED',
        'message' => 'RFID is not registered to any student.',
        'date' => $displayDate,
        'time' => $displayTime,
        'timezone' => 'Asia/Manila'
    ]);
    exit;
}

$schedule = getClassSchedule($student['class_schedule'] ?? null);
$matchedSchedule = null;

foreach ($schedule as $item) {
    if (!is_array($item)) {
        continue;
    }

    $scheduledDay = trim((string)($item['day'] ?? ''));
    $scheduledIn = trim((string)($item['time_in'] ?? ''));
    $scheduledOut = trim((string)($item['time_out'] ?? ''));

    if ($scheduledDay !== $dayName || $scheduledIn === '' || $scheduledOut === '') {
        continue;
    }

    if ($nowTime >= $scheduledIn && $nowTime <= $scheduledOut) {
        $matchedSchedule = [
            'day' => $scheduledDay,
            'time_in' => $scheduledIn,
            'time_out' => $scheduledOut
        ];
        break;
    }
}

if (!$matchedSchedule) {
    echo json_encode([
        'success' => false,
        'code' => 'OUTSIDE_SCHEDULE',
        'message' => 'RFID detected, but the student is not within the registered class schedule right now.',
        'student' => trim(($student['first_name'] ?? '') . ' ' . ($student['last_Name'] ?? '')),
        'student_no' => $student['student_No'] ?? '',
        'date' => $displayDate,
        'time' => $displayTime,
        'day' => $dayName,
        'timezone' => 'Asia/Manila'
    ]);
    exit;
}

$scheduledIn = $matchedSchedule['time_in'];
$scheduledOut = $matchedSchedule['time_out'];

// Find an attendance record for the same student, date and schedule window.
$find = $conn->prepare("\n    SELECT *\n    FROM attendance_records\n    WHERE stud_id = ?\n      AND attendance_date = ?\n      AND day_name = ?\n      AND scheduled_time_in = ?\n      AND scheduled_time_out = ?\n    ORDER BY attendance_id DESC\n    LIMIT 1\n");

if (!$find) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Attendance table error: ' . $conn->error
    ]);
    exit;
}

$find->bind_param('issss', $student['stud_id'], $nowDate, $dayName, $scheduledIn, $scheduledOut);
$find->execute();
$attendanceResult = $find->get_result();
$attendance = $attendanceResult->fetch_assoc();
$find->close();

$fullName = trim(
    ($student['first_name'] ?? '') . ' ' .
    ($student['middle_Name'] ?? '') . ' ' .
    ($student['last_Name'] ?? '') . ' ' .
    ($student['extension_name'] ?? '')
);

if (!$attendance) {
    $insert = $conn->prepare("\n        INSERT INTO attendance_records\n        (\n            stud_id,\n            student_No,\n            student_RFID_Code,\n            attendance_date,\n            day_name,\n            scheduled_time_in,\n            scheduled_time_out,\n            actual_time_in,\n            status,\n            created_at\n        )\n        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'IN', NOW())\n    ");

    if (!$insert) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to prepare attendance record: ' . $conn->error
        ]);
        exit;
    }

    $insert->bind_param(
        'isssssss',
        $student['stud_id'],
        $student['student_No'],
        $student['student_RFID_Code'],
        $nowDate,
        $dayName,
        $scheduledIn,
        $scheduledOut,
        $nowTime
    );

    if (!$insert->execute()) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to save Time In: ' . $insert->error
        ]);
        $insert->close();
        exit;
    }

    $insert->close();

    echo json_encode([
        'success' => true,
        'code' => 'TIME_IN_RECORDED',
        'message' => 'Time In recorded successfully.',
        'student' => $fullName,
        'student_no' => $student['student_No'] ?? '',
        'date' => $displayDate,
        'time' => $displayTime,
        'day' => $dayName,
        'scheduled_time' => date('h:i A', strtotime($scheduledIn)) . ' - ' . date('h:i A', strtotime($scheduledOut)),
        'timezone' => 'Asia/Manila'
    ]);
    exit;
}

if (empty($attendance['actual_time_out'])) {
    $update = $conn->prepare("\n        UPDATE attendance_records\n        SET actual_time_out = ?, status = 'OUT', updated_at = NOW()\n        WHERE attendance_id = ?\n    ");

    if (!$update) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to prepare Time Out update: ' . $conn->error
        ]);
        exit;
    }

    $update->bind_param('si', $nowTime, $attendance['attendance_id']);

    if (!$update->execute()) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to save Time Out: ' . $update->error
        ]);
        $update->close();
        exit;
    }

    $update->close();

    echo json_encode([
        'success' => true,
        'code' => 'TIME_OUT_RECORDED',
        'message' => 'Time Out recorded successfully.',
        'student' => $fullName,
        'student_no' => $student['student_No'] ?? '',
        'date' => $displayDate,
        'time' => $displayTime,
        'day' => $dayName,
        'scheduled_time' => date('h:i A', strtotime($scheduledIn)) . ' - ' . date('h:i A', strtotime($scheduledOut)),
        'timezone' => 'Asia/Manila'
    ]);
    exit;
}

echo json_encode([
    'success' => false,
    'code' => 'ALREADY_COMPLETED',
    'message' => 'This student already has both Time In and Time Out recorded for this class schedule.',
    'student' => $fullName,
    'student_no' => $student['student_No'] ?? '',
    'date' => $displayDate,
    'time' => $displayTime,
    'day' => $dayName,
    'timezone' => 'Asia/Manila'
]);
