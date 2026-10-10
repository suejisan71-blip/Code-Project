<?php
/**
 * Shared NEUST RFID attendance transition handler.
 * Both process.php and rfid_api.php use this same function so one scan
 * cannot be interpreted differently by two endpoints.
 */

date_default_timezone_set('Asia/Manila');

function normalizeRFID($rfid): string {
    return strtoupper(str_replace([':', '-', ' '], '', trim((string)$rfid)));
}

function ensureTimeOutColumn(mysqli $conn): bool {
    $check = $conn->query("SHOW COLUMNS FROM attendance LIKE 'time_out'");
    if ($check && $check->num_rows > 0) return true;

    @$conn->query("ALTER TABLE attendance ADD COLUMN time_out TIME NULL AFTER time_in");
    $check = $conn->query("SHOW COLUMNS FROM attendance LIKE 'time_out'");
    return $check && $check->num_rows > 0;
}

function scheduleForToday(array $student, string $day): array {
    if (!array_key_exists('class_schedule', $student)) {
        return [];
    }

    $raw = trim((string)($student['class_schedule'] ?? ''));
    if ($raw === '') return [];

    $schedule = json_decode($raw, true);
    if (!is_array($schedule)) return [];

    $matches = [];
    foreach ($schedule as $item) {
        if (!is_array($item)) continue;
        if (strcasecmp(trim((string)($item['day'] ?? '')), $day) !== 0) continue;

        $timeIn = trim((string)($item['time_in'] ?? ''));
        $timeOut = trim((string)($item['time_out'] ?? ''));
        if ($timeIn === '') continue;

        $matches[] = [
            'time_in' => $timeIn,
            'time_out' => $timeOut
        ];
    }
    return $matches;
}

function wasDuplicateScan(string $rfid, string $date): bool {
    $last = $_SESSION['last_rfid_event'] ?? null;
    if (!is_array($last)) return false;

    return ($last['rfid'] ?? '') === $rfid
        && ($last['date'] ?? '') === $date
        && (time() - (int)($last['timestamp'] ?? 0)) <= 5;
}

function rememberScan(string $rfid, string $date, string $action): void {
    $_SESSION['last_rfid_event'] = [
        'rfid' => $rfid,
        'date' => $date,
        'action' => $action,
        'timestamp' => time()
    ];
}

function processAttendance(mysqli $conn, string $rfid, string $date, string $time, string $day): array {
    if ($rfid === '') {
        return ['success'=>false, 'status'=>'ERROR', 'message'=>'No RFID code submitted!'];
    }

    if (!preg_match('/^[0-9A-F]+$/', $rfid)) {
        return ['success'=>false, 'status'=>'ERROR', 'message'=>'Invalid RFID format.'];
    }

    if (!ensureTimeOutColumn($conn)) {
        return ['success'=>false, 'status'=>'ERROR', 'message'=>'The attendance table is missing the Time Out field. Please add the time_out column.'];
    }

    $conn->begin_transaction();

    try {
        // Lock the matching student so simultaneous requests cannot select different states.
        $studentQuery = $conn->prepare("
            SELECT *
            FROM students
            WHERE REPLACE(REPLACE(REPLACE(UPPER(TRIM(student_RFID_Code)), ':', ''), '-', ''), ' ', '') = ?
            LIMIT 1
            FOR UPDATE
        ");
        if (!$studentQuery) throw new Exception('Database query error: ' . $conn->error);

        $studentQuery->bind_param('s', $rfid);
        $studentQuery->execute();
        $studentResult = $studentQuery->get_result();

        if ($studentResult->num_rows === 0) {
            $studentQuery->close();
            $conn->rollback();
            return ['success'=>false, 'status'=>'NOT_REGISTERED', 'message'=>'RFID tag not registered in system!'];
        }

        if ($studentResult->num_rows > 1) {
            $studentQuery->close();
            $conn->rollback();
            return ['success'=>false, 'status'=>'DUPLICATE_RFID', 'message'=>'This RFID is registered to more than one student.'];
        }

        $student = $studentResult->fetch_assoc();
        $studentQuery->close();

        $studentNo = trim((string)($student['student_No'] ?? ''));
        $name = trim(
            ($student['first_name'] ?? $student['first_Name'] ?? '') . ' ' .
            ($student['middle_Name'] ?? $student['middle_name'] ?? '') . ' ' .
            ($student['last_name'] ?? $student['last_Name'] ?? '') . ' ' .
            ($student['extension_name'] ?? $student['extension_Name'] ?? '')
        );

        // If a class schedule exists, the student must be registered for today's class day.
        $todaySchedules = scheduleForToday($student, $day);
        $hasScheduleData = !empty(trim((string)($student['class_schedule'] ?? '')));

        if ($hasScheduleData && count($todaySchedules) === 0) {
            $conn->rollback();
            return [
                'success'=>false,
                'status'=>'NOT_SCHEDULED_TODAY',
                'message'=>'This student is not registered for a class today.',
                'student_No'=>$studentNo,
                'student_name'=>$name,
                'rfid'=>$rfid,
                'date'=>$date
            ];
        }

        // There must be exactly ONE attendance row for each student/date.
        // Lock ALL existing rows first so old duplicate rows cannot create another
        // Time In/Time Out state. If old duplicates exist, merge them safely into
        // the earliest Time In + latest Time Out and remove the redundant rows.
        $attendanceQuery = $conn->prepare("
            SELECT student_No, date_log, time_in, time_out, status
            FROM attendance
            WHERE student_No = ? AND date_log = ?
            ORDER BY time_in ASC
            FOR UPDATE
        ");
        if (!$attendanceQuery) throw new Exception('Attendance query error: ' . $conn->error);

        $attendanceQuery->bind_param('ss', $studentNo, $date);
        $attendanceQuery->execute();
        $attendanceResult = $attendanceQuery->get_result();
        $attendanceRows = [];
        while ($row = $attendanceResult->fetch_assoc()) {
            $attendanceRows[] = $row;
        }
        $attendanceQuery->close();

        if (count($attendanceRows) > 1) {
            $mergedTimeIn = '';
            $mergedTimeOut = '';
            $mergedStatus = 'Present';

            foreach ($attendanceRows as $row) {
                $rowIn = trim((string)($row['time_in'] ?? ''));
                $rowOut = trim((string)($row['time_out'] ?? ''));
                $rowStatus = trim((string)($row['status'] ?? ''));

                if ($rowIn !== '' && ($mergedTimeIn === '' || strtotime($rowIn) < strtotime($mergedTimeIn))) {
                    $mergedTimeIn = $rowIn;
                }
                if ($rowOut !== '' && ($mergedTimeOut === '' || strtotime($rowOut) > strtotime($mergedTimeOut))) {
                    $mergedTimeOut = $rowOut;
                }
                if ($rowStatus !== '' && strtoupper($rowStatus) !== 'MISSING SCHEDULE') {
                    $mergedStatus = $rowStatus;
                }
            }

            if ($mergedTimeOut !== '') {
                $mergedStatus = 'Completed';
            }

            $deleteDuplicates = $conn->prepare("DELETE FROM attendance WHERE student_No = ? AND date_log = ?");
            if (!$deleteDuplicates) throw new Exception('Could not prepare duplicate attendance cleanup: ' . $conn->error);
            $deleteDuplicates->bind_param('ss', $studentNo, $date);
            if (!$deleteDuplicates->execute()) throw new Exception('Could not remove redundant attendance rows: ' . $deleteDuplicates->error);
            $deleteDuplicates->close();

            $restore = $conn->prepare("INSERT INTO attendance (student_No, date_log, time_in, time_out, status) VALUES (?, ?, ?, ?, ?)");
            if (!$restore) throw new Exception('Could not restore merged attendance: ' . $conn->error);
            $restoreTimeOut = ($mergedTimeOut !== '') ? $mergedTimeOut : null;
            $restore->bind_param('sssss', $studentNo, $date, $mergedTimeIn, $restoreTimeOut, $mergedStatus);
            if (!$restore->execute()) throw new Exception('Could not restore merged attendance: ' . $restore->error);
            $restore->close();

            // Re-read the single canonical row after cleanup.
            $attendanceQuery = $conn->prepare("
                SELECT student_No, date_log, time_in, time_out, status
                FROM attendance
                WHERE student_No = ? AND date_log = ?
                LIMIT 1
                FOR UPDATE
            ");
            if (!$attendanceQuery) throw new Exception('Attendance re-check error: ' . $conn->error);
            $attendanceQuery->bind_param('ss', $studentNo, $date);
            $attendanceQuery->execute();
            $attendanceResult = $attendanceQuery->get_result();
        }

        // FIRST SCAN -> TIME IN
        if ($attendanceResult->num_rows === 0) {
            $status = 'Present';

            foreach ($todaySchedules as $schedule) {
                $expectedIn = $schedule['time_in'];
                $expectedTimestamp = strtotime($expectedIn);
                $actualTimestamp = strtotime($time);
                if ($expectedTimestamp !== false && $actualTimestamp !== false && $actualTimestamp > ($expectedTimestamp + 15 * 60)) {
                    $status = 'Late';
                    break;
                }
            }

            $insert = $conn->prepare("INSERT INTO attendance (student_No, date_log, time_in, time_out, status) VALUES (?, ?, ?, NULL, ?)");
            if (!$insert) throw new Exception('Could not prepare Time In: ' . $conn->error);

            $insert->bind_param('ssss', $studentNo, $date, $time, $status);
            if (!$insert->execute()) throw new Exception('Failed to record Time In: ' . $insert->error);
            $insert->close();
            $attendanceQuery->close();
            $conn->commit();

            rememberScan($rfid, $date, 'TIME_IN');
            return [
                'success'=>true,
                'status'=>'TIME_IN',
                'message'=>'Time In recorded successfully for ' . $name . '.',
                'student_No'=>$studentNo,
                'student_name'=>$name,
                'rfid'=>$rfid,
                'date'=>$date,
                'time_in'=>$time
            ];
        }

        $attendance = $attendanceResult->fetch_assoc();
        $attendanceQuery->close();

        $timeIn = trim((string)($attendance['time_in'] ?? ''));
        $timeOut = trim((string)($attendance['time_out'] ?? ''));

        // THIRD+ SCAN -> BLOCK. Do not insert another row and do not alter the completed row.
        if ($timeOut !== '') {
            $conn->rollback();
            return [
                'success'=>false,
                'status'=>'ALREADY_COMPLETED',
                'message'=>'This student has already completed Time In and Time Out for today. Additional scans are not allowed.',
                'student_No'=>$studentNo,
                'student_name'=>$name,
                'rfid'=>$rfid,
                'date'=>$date,
                'time_in'=>$timeIn,
                'time_out'=>$timeOut
            ];
        }

        // SECOND SCAN -> TIME OUT on the same row.
        if ($timeIn === '') {
            $conn->rollback();
            return ['success'=>false, 'status'=>'ERROR', 'message'=>'The existing attendance record has no Time In. No Time Out was recorded.'];
        }

        // Never allow Time Out before Time In.
        if (strtotime($time) < strtotime($timeIn)) {
            $conn->rollback();
            return [
                'success'=>false,
                'status'=>'INVALID_TIME_OUT',
                'message'=>'Time Out cannot be earlier than Time In.',
                'student_No'=>$studentNo,
                'student_name'=>$name
            ];
        }

        // If the registered schedule has a Time Out, do not allow an early Time Out.
        $scheduledOutPassed = true;
        $hasScheduledOut = false;
        foreach ($todaySchedules as $schedule) {
            if ($schedule['time_out'] !== '') {
                $hasScheduledOut = true;
                if (strtotime($time) < strtotime($schedule['time_out'])) {
                    $scheduledOutPassed = false;
                }
            }
        }

        if ($hasScheduledOut && !$scheduledOutPassed) {
            $conn->rollback();
            return [
                'success'=>false,
                'status'=>'TOO_EARLY_TIME_OUT',
                'message'=>'Time Out is not allowed yet. Please scan after the registered class Time Out.',
                'student_No'=>$studentNo,
                'student_name'=>$name,
                'time_in'=>$timeIn
            ];
        }

        $update = $conn->prepare("UPDATE attendance SET time_out = ?, status = 'Completed' WHERE student_No = ? AND date_log = ? AND (time_out IS NULL OR time_out = '') LIMIT 1");
        if (!$update) throw new Exception('Could not prepare Time Out: ' . $conn->error);

        $update->bind_param('sss', $time, $studentNo, $date);
        if (!$update->execute()) throw new Exception('Failed to record Time Out: ' . $update->error);

        if ($update->affected_rows !== 1) {
            $update->close();
            $conn->rollback();
            return ['success'=>false, 'status'=>'ALREADY_COMPLETED', 'message'=>'Time Out was already recorded. Additional scans are not allowed.'];
        }
        $update->close();
        $conn->commit();

        rememberScan($rfid, $date, 'TIME_OUT');
        return [
            'success'=>true,
            'status'=>'TIME_OUT',
            'message'=>'Time Out recorded successfully for ' . $name . '.',
            'student_No'=>$studentNo,
            'student_name'=>$name,
            'rfid'=>$rfid,
            'date'=>$date,
            'time_in'=>$timeIn,
            'time_out'=>$time
        ];

    } catch (Throwable $e) {
        $conn->rollback();
        return [
            'success'=>false,
            'status'=>'ERROR',
            'message'=>'Attendance processing failed.',
            'database_error'=>$e->getMessage()
        ];
    }
}


?>
